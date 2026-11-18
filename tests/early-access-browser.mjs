// Uses a fresh Chrome profile and stubbed fetch; never submits to a live service.
// Run: node tests/early-access-browser.mjs
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, rm, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import net from 'node:net';

const profile = await mkdtemp(join(tmpdir(), 'chapterscript-browser-'));
const chromePath = process.env.CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const listener = net.createServer();
await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
const port = listener.address().port;
await new Promise(resolve => listener.close(resolve));
const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', resolve('.')], { stdio: 'ignore' });
const browser = spawn(chromePath, ['--headless=new', '--no-first-run', '--no-default-browser-check',
  '--disable-background-networking', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });
let ws;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
try {
  let debugPort;
  for (let i = 0; i < 100; i++) {
    try { debugPort = Number((await readFile(join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]); break; }
    catch { await delay(50); }
  }
  assert.ok(debugPort, 'Chrome did not start');
  const tabs = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
  ws = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let next = 1;
  const pending = new Map();
  ws.onmessage = event => {
    const result = JSON.parse(event.data);
    if (result.id && pending.has(result.id)) {
      const { resolve, reject } = pending.get(result.id); pending.delete(result.id);
      result.error ? reject(new Error(JSON.stringify(result.error))) : resolve(result.result);
    }
  };
  function send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = next++; pending.set(id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params }));
    });
  }
  async function evaluate(expression) {
    const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    assert.ok(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
    return result.result.value;
  }
  await send('Network.enable');
  await send('Network.setBlockedURLs', { urls: ['https://*'] });
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
  async function load() {
    await send('Page.navigate', { url: `http://127.0.0.1:${port}/` });
    for (let i = 0; i < 100; i++) {
      if (await evaluate("document.readyState === 'complete' && document.querySelector('#access-form')?.noValidate")) return;
      await delay(20);
    }
    throw new Error('Form did not initialize');
  }
  async function submit(response, extra = '') {
    await load();
    return evaluate(`(async () => {
      document.querySelector('#f-name').value = 'Browser Author';
      document.querySelector('#f-email').value = 'browser@example.test';
      document.querySelector('#f-story').value = 'A story from the browser test.';
      for (const name of ['tools[]', 'making[]']) {
        const options = document.getElementsByName(name); options[0].checked = true; options[1].checked = true;
      }
      ${extra}
      window.fetch = async (url, options) => {
        window.requestData = {url, ...options, body: JSON.parse(options.body)};
        ${response}
      };
      document.querySelector('#access-form').requestSubmit();
      await new Promise(resolve => setTimeout(resolve, 40));
      return {
        request: window.requestData,
        sent: !document.querySelector('#sent').hidden,
        formHidden: document.querySelector('#access-form').hidden,
        error: !document.querySelector('#form-error').hidden,
        message: document.querySelector('#form-error-message').textContent,
        story: document.querySelector('#f-story').value,
        disabled: document.querySelector('#submit').disabled,
        emailLinks: document.querySelectorAll('a[href^="mailto:"]').length,
      };
    })()`);
  }
  let result = await submit('return {ok: true, json: async () => ({ok: true})};');
  assert.equal(result.request.url, '/api/early-access.php');
  assert.equal(result.request.body.name, 'Browser Author');
  assert.deepEqual(result.request.body.tools, ['Reality Composer Pro', 'Unity']);
  assert.deepEqual(result.request.body.making, ['Narrative story', 'Immersive film']);
  assert.equal(result.request.body._honey, '');
  assert.ok(!('_subject' in result.request.body));
  assert.equal(result.sent, true); assert.equal(result.formHidden, true);
  console.log('PASS real DOM: local endpoint, multi-select JSON, explicit success');

  for (const response of [
    'return {ok: true, json: async () => ({})};',
    'return {ok: true, json: async () => { throw new SyntaxError("HTML instead of JSON"); }};',
    'return {ok: false, json: async () => ({ok: false, message: "Please wait 15 minutes."})};',
    'return {ok: false, json: async () => ({ok: false, message: "Correct your story.", errors: {story: "More detail needed."}})};',
    'throw new TypeError("Failed to fetch");',
    'throw new DOMException("Timed out", "AbortError");',
  ]) {
    result = await submit(response);
    assert.equal(result.sent, false); assert.equal(result.formHidden, false);
    assert.equal(result.error, true); assert.equal(result.disabled, false);
    assert.equal(result.story, 'A story from the browser test.');
    assert.equal(result.emailLinks, 0);
  }
  assert.ok(result.message.includes('retrying will not create a duplicate'));
  console.log('PASS real DOM: malformed success, validation, throttling, network/timeout failures preserve answers');

  result = await submit('return {ok: true, json: async () => ({ok: true})};', "document.querySelector('[name=_honey]').value = 'bot';");
  assert.equal(result.request.body._honey, 'bot', 'Honeypot must reach the server');
  result = await submit('throw new Error("Must not submit");', "document.querySelector('#f-email').value = 'invalid';");
  assert.equal(result.request, undefined);
  console.log('PASS real DOM: server honeypot forwarding and client validation');
  for (const width of [1280, 390, 320]) {
    await send('Emulation.setDeviceMetricsOverride', {width, height: 900, deviceScaleFactor: 1, mobile: false});
    await load();
    assert.ok(await evaluate("Array.from(document.querySelectorAll('#early-access, #early-access *')).every(el => el.getBoundingClientRect().right <= window.innerWidth + 1)"), `Form overflow at ${width}px`);
    await evaluate("document.querySelector('#early-access').scrollIntoView()");
  }
  await send('Emulation.setScriptExecutionDisabled', {value: true});
  await send('Page.navigate', {url: `http://127.0.0.1:${port}/`});
  await delay(200);
  assert.equal(await evaluate("document.querySelector('#access-form').noValidate"), false);
  assert.equal(await evaluate("document.querySelector('#access-form').getAttribute('action')"), '/api/early-access.php');
  assert.equal(await evaluate("getComputedStyle(document.querySelector('#access-form')).opacity"), '1');
  console.log('PASS responsive widths and JavaScript-disabled native form');
} finally {
  ws?.close(); browser.kill(); server.kill();
  await delay(300);
  await rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
