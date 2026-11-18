<?php

declare(strict_types=1);

// Runs only against an explicitly supplied LOCAL database socket. Creates and
// drops its own randomly named database; never uses the production config.
require dirname(__DIR__) . '/api/early-access-lib.php';

use function ChapterScript\{validate, ipKey, rateLimit, saveApplication, originAllowed};

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function connection(?string $database = null): PDO
{
    $socket = getenv('CHAPTERSCRIPT_TEST_SOCKET');
    if (!$socket || !str_starts_with($socket, '/')) {
        throw new RuntimeException('Set CHAPTERSCRIPT_TEST_SOCKET to an isolated local MariaDB socket.');
    }
    return new PDO('mysql:unix_socket=' . $socket . ($database ? ';dbname=' . $database : '') . ';charset=utf8mb4',
        getenv('CHAPTERSCRIPT_TEST_USER') ?: 'root', getenv('CHAPTERSCRIPT_TEST_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
}

function sample(): array
{
    return ['name' => 'Test Author', 'email' => 'author@example.test', 'story' => 'An immersive museum story.',
        'tools' => ['Blender', 'Reality Composer Pro'], 'making' => ['Museum or exhibition', 'Narrative story'],
        'role' => 'Artist or designer', 'updates' => 'Yes', '_honey' => ''];
}

if (($argv[1] ?? '') === '--worker') {
    try {
        $pdo = connection($argv[2]);
        if ($argv[3] === 'rate') {
            exit(rateLimit($pdo, str_repeat('c', 64), [900 => 5, 86400 => 20], (int) $argv[4]) === 0 ? 0 : 10);
        }
        [$data] = validate(sample());
        saveApplication($pdo, $data);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage());
        exit(20);
    }
}

function workers(string $database, string $mode, int $number): array
{
    $workers = [];
    $now = time();
    for ($i = 0; $i < $number; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $database, $mode, (string) $now],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $codes = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        check(in_array($code, [0, 10], true), 'Concurrent worker failed: ' . $output);
        $codes[] = $code;
    }
    return array_count_values($codes);
}

function request(string $url, string $body, array $headers = [], string $method = 'POST'): array
{
    $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true,
        'timeout' => 10, 'header' => implode("\r\n", $headers), 'content' => $body]]);
    $body = file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), $body, implode("\n", $responseHeaders)];
}

$database = 'chapterscript_test_' . bin2hex(random_bytes(6));
$temp = sys_get_temp_dir() . '/' . $database;
$admin = connection();
$server = null;
try {
    $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
    $pdo = connection($database);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/setup/schema.sql'));
    [$valid, $errors] = validate(sample());
    check(!$errors && $valid['updates'] === 1, 'Valid form rejected');
    $invalid = [
        ['name' => ''], ['name' => ['nested']], ['email' => 'invalid'], ['email' => str_repeat('a', 255)],
        ['story' => 'short'], ['story' => str_repeat('a', 3001)], ['role' => 'invented'],
        ['tools' => 'Blender'], ['making' => [['nested']]], ['tools' => ['Unlisted']],
        ['updates' => true], ['_honey' => []], ['extra' => 'unrecognized'], ['name' => "bad\0name"],
    ];
    foreach ($invalid as $change) {
        check((bool) validate(array_replace(sample(), $change))[1], 'Invalid field was accepted: ' . json_encode($change));
    }
    $unicode = array_replace(sample(), ['name' => 'Élodie 🎬', 'story' => str_repeat('🎬', 3000)]);
    check(!validate($unicode)[1], 'Unicode limit rejected valid characters');
    check(originAllowed(['HTTP_ORIGIN' => 'https://chapterscript.com'], ['https://chapterscript.com']), 'Valid origin rejected');
    check(!originAllowed(['HTTP_ORIGIN' => 'https://chapterscript.com.attacker.test'], ['https://chapterscript.com']), 'Lookalike origin accepted');
    check(!originAllowed([], ['https://chapterscript.com']), 'Missing origin evidence accepted');
    check(originAllowed(['HTTP_REFERER' => 'https://chapterscript.com/path'], ['https://chapterscript.com']), 'Referer fallback failed');
    $secret = bin2hex(random_bytes(32));
    check(ipKey('2001:db8::1', $secret) === ipKey('2001:0db8:0:0:0:0:0:1', $secret), 'IPv6 normalization failed');
    echo "PASS validation, Unicode, origin checks, IP normalization\n";

    $valid['name'] = "O'Neil <script>alert(1)</script>";
    saveApplication($pdo, $valid);
    $row = $pdo->query('SELECT * FROM early_access_applications')->fetch();
    check($row['name'] === $valid['name'], 'Literal text was not stored safely');
    check(json_decode($row['tools'], true) === sample()['tools'], 'Multi-select answers lost');
    $duplicate = $valid;
    $duplicate['email'] = strtoupper($valid['email']);
    $duplicate['name'] = 'Overwrite attempt'; $duplicate['updates'] = 0;
    saveApplication($pdo, $duplicate);
    check((int) $pdo->query('SELECT COUNT(*) FROM early_access_applications')->fetchColumn() === 1, 'Duplicate stored');
    check($pdo->query('SELECT name FROM early_access_applications')->fetchColumn() === $valid['name'], 'Duplicate overwrote answers');
    check((int) $pdo->query('SELECT updates FROM early_access_applications')->fetchColumn() === 1, 'Duplicate overwrote consent');
    $pdo->exec('TRUNCATE early_access_applications');
    check(workers($database, 'save', 8)[0] === 8, 'Concurrent duplicate handling failed');
    check((int) $pdo->query('SELECT COUNT(*) FROM early_access_applications')->fetchColumn() === 1, 'Concurrent duplicate stored');
    echo "PASS persistence, literal SQL/XSS text, arrays, consent, concurrent duplicates\n";

    $now = time(); $key = str_repeat('a', 64);
    for ($i = 0; $i < 5; $i++) check(rateLimit($pdo, $key, [900 => 5, 86400 => 20], $now) === 0, 'Allowed request blocked');
    check(rateLimit($pdo, $key, [900 => 5, 86400 => 20], $now) === 900, 'Burst limit failed');
    for ($i = 0; $i < 16; $i++) rateLimit($pdo, $key, [900 => 5, 86400 => 20], $now);
    check(rateLimit($pdo, $key, [900 => 5, 86400 => 20], $now + 901) === 85499, 'Daily limit failed after burst expiry');
    check(rateLimit($pdo, $key, [900 => 5, 86400 => 20], $now + 86401) === 0, 'Expired counters did not reset');
    $pdo->exec('TRUNCATE early_access_rate_limits');
    $counts = workers($database, 'rate', 16);
    check(($counts[0] ?? 0) === 5 && ($counts[10] ?? 0) === 11, 'Concurrent rate-limit race');
    $pdo->exec('TRUNCATE early_access_rate_limits');
    echo "PASS burst/daily limits, expiry, 16 concurrent attempts (exactly five allowed)\n";

    mkdir($temp . '/public_html/api', 0700, true); mkdir($temp . '/private', 0700);
    foreach (['early-access.php', 'early-access-lib.php'] as $file) copy(dirname(__DIR__) . '/api/' . $file, $temp . '/public_html/api/' . $file);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    check($listener !== false, 'Cannot allocate test HTTP port');
    $address = stream_socket_get_name($listener, false); fclose($listener);
    $origin = 'http://' . $address;
    $config = ['database' => ['host' => 'localhost', 'name' => $database, 'username' => getenv('CHAPTERSCRIPT_TEST_USER') ?: 'root', 'password' => getenv('CHAPTERSCRIPT_TEST_PASSWORD') ?: 'test-password'],
        'rate_limit_secret' => $secret, 'allowed_origins' => [$origin]];
    // Create a dedicated test user, so the endpoint exercises the real connection code.
    $testUser = 'm_' . bin2hex(random_bytes(5));
    $admin->exec("CREATE USER '$testUser'@'localhost' IDENTIFIED BY 'test-password'");
    $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$database`.* TO '$testUser'@'localhost'");
    $config['database']['username'] = $testUser; $config['database']['password'] = 'test-password';
    file_put_contents($temp . '/private/config.php', '<?php return ' . var_export($config, true) . ';');
    $server = proc_open([PHP_BINARY, '-d', 'pdo_mysql.default_socket=' . getenv('CHAPTERSCRIPT_TEST_SOCKET'), '-S', $address, '-t', $temp . '/public_html'],
        [0 => ['pipe', 'r'], 1 => ['file', $temp . '/http.log', 'a'], 2 => ['file', $temp . '/http.log', 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 50; $i++) {
        $socket = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
        if ($socket) { fclose($socket); break; }
        usleep(20000);
    }
    $url = $origin . '/api/early-access.php';
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Origin: ' . $origin];
    $body = json_encode(sample());
    // Distinguish authentication failures from config-file and secret mistakes.
    $badPassword = $config;
    $badPassword['database']['password'] = 'incorrect-test-password';
    $badUsername = $config;
    $badUsername['database']['username'] = 'missing_' . bin2hex(random_bytes(5));
    $missingSecret = $config;
    unset($missingSecret['rate_limit_secret']);
    $configCases = [
        ['<?php return ' . var_export($badPassword, true) . ';', 'stage=connect_database PDOException', 'driver_code=1045'],
        ['<?php return ' . var_export($badUsername, true) . ';', 'stage=connect_database PDOException', 'driver_code=1045'],
        ['<?php return ' . var_export($missingSecret, true) . ';', 'stage=validate_rate_limit_secret_and_ip RuntimeException', 'driver_code=0'],
        ['<?php return [;', 'stage=load_config ParseError', 'driver_code=0'],
    ];
    foreach ($configCases as [$source, $expectedStage, $expectedCode]) {
        $beforeLog = strlen(file_get_contents($temp . '/http.log'));
        file_put_contents($temp . '/private/config.php', $source);
        [$status, $response] = request($url, $body, $headers);
        $newLog = substr(file_get_contents($temp . '/http.log'), $beforeLog);
        check($status === 503, 'Bad configuration did not fail safely');
        check(str_contains($newLog, $expectedStage) && str_contains($newLog, $expectedCode), 'Incorrect configuration diagnostic: ' . $newLog);
        check(!str_contains($newLog . $response, 'incorrect-test-password') && !str_contains($newLog . $response, $secret), 'Configuration diagnostic leaked a secret');
        check((int) $pdo->query('SELECT COUNT(*) FROM early_access_rate_limits')->fetchColumn() === 0, 'Configuration failure reached rate limiting');
    }
    // Correctly quoted PHP config preserves punctuation in strong passwords.
    $punctuationPassword = 'Test-\'\\$Password-123!';
    $admin->exec("ALTER USER '$testUser'@'localhost' IDENTIFIED BY " . $admin->quote($punctuationPassword));
    $punctuationConfig = $config;
    $punctuationConfig['database']['password'] = $punctuationPassword;
    file_put_contents($temp . '/private/config.php', '<?php return ' . var_export($punctuationConfig, true) . ';');
    [$status] = request($url, $body, $headers);
    check($status === 200, 'Correctly quoted punctuation password failed');
    $admin->exec("ALTER USER '$testUser'@'localhost' IDENTIFIED BY 'test-password'");
    file_put_contents($temp . '/private/config.php', '<?php return ' . var_export($config, true) . ';');
    $pdo->exec('TRUNCATE early_access_rate_limits');
    echo "PASS wrong password/user produce 1045; config syntax/secret failures differ; punctuation password works\n";
    // A third-party page must not exhaust a visitor's allowance via rejected POSTs.
    foreach ([
        ['Origin: https://evil.test', 'Sec-Fetch-Site: cross-site'],
        ['Origin: https://evil.test'],
        ['Referer: https://evil.test/form'],
        ['Origin: ' . $origin, 'Sec-Fetch-Site: cross-site'],
        [],
    ] as $untrustedHeaders) {
        for ($i = 0; $i < 5; $i++) {
            [$status] = request($url, 'name=ignored', ['Content-Type: application/x-www-form-urlencoded', ...$untrustedHeaders]);
            check($status === 403, 'Untrusted origin was not rejected before rate limiting');
        }
    }
    check((int) $pdo->query('SELECT COUNT(*) FROM early_access_rate_limits')->fetchColumn() === 0, 'Untrusted requests consumed rate-limit allowance');
    [$status] = request($url, $body, $headers);
    check($status === 200, 'Untrusted requests locked out a legitimate submission');
    $pdo->exec('TRUNCATE early_access_rate_limits');
    echo "PASS rejected cross-site/missing-origin requests leave the legitimate submission allowance intact\n";
    $pdo->exec('TRUNCATE early_access_applications');
    $cases = [
        [200, $body, $headers, 'POST'],
        [422, json_encode(array_replace(sample(), ['story' => 'no'])), $headers, 'POST'],
        [400, '{broken', $headers, 'POST'],
        [400, '[]', $headers, 'POST'],
        [413, str_repeat('x', 32769), $headers, 'POST'],
        [403, $body, ['Content-Type: application/json', 'Origin: https://evil.test'], 'POST'],
        [403, $body, ['Content-Type: application/json'], 'POST'],
        [403, $body, [...$headers, 'Sec-Fetch-Site: cross-site'], 'POST'],
        [415, $body, ['Content-Type: text/plain', 'Accept: application/json', 'Origin: ' . $origin], 'POST'],
        [405, '', $headers, 'GET'],
    ];
    foreach ($cases as [$expected, $payload, $requestHeaders, $method]) {
        $pdo->exec('TRUNCATE early_access_rate_limits');
        [$status, $response, $returnedHeaders] = request($url, $payload, $requestHeaders, $method);
        check($status === $expected, "HTTP expected $expected got $status: $response");
        $decoded = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        check($decoded['ok'] === ($expected === 200), 'Incorrect success acknowledgment');
        check(str_contains($returnedHeaders, 'Cache-Control: no-store'), 'Response may be cached');
        check(!str_contains($returnedHeaders, 'Access-Control-Allow-Origin'), 'Unexpected CORS');
    }
    $pdo->exec('TRUNCATE early_access_rate_limits');
    [$status] = request($url, json_encode(array_replace(sample(), ['_honey' => 'spam', 'email' => 'bot@example.test'])), $headers);
    check($status === 200 && (int) $pdo->query('SELECT COUNT(*) FROM early_access_applications')->fetchColumn() === 1, 'Honeypot saved a bot');
    for ($i = 0; $i < 4; $i++) request($url, '{broken', [...$headers, 'X-Forwarded-For: 192.0.2.' . $i]);
    [$status, $response, $returnedHeaders] = request($url, $body, [...$headers, 'X-Forwarded-For: 198.51.100.1', 'CF-Connecting-IP: 198.51.100.2']);
    check($status === 429 && str_contains($returnedHeaders, 'Retry-After:'), 'Spoofed header bypassed limit or missing Retry-After');
    echo "PASS HTTP status/JSON contracts, honeypot, rejected-attempt counting, spoofed proxy headers\n";

    $pdo->exec('TRUNCATE early_access_rate_limits');
    $form = sample(); $form['email'] = 'nojs@example.test';
    [$status, $response] = request($url, http_build_query($form), ['Content-Type: application/x-www-form-urlencoded', 'Referer: ' . $origin . '/']);
    check($status === 200 && str_contains($response, '<h1>Thank you.'), 'Native form failed');
    $row = $pdo->query("SELECT * FROM early_access_applications WHERE email = 'nojs@example.test'")->fetch();
    check(json_decode($row['tools'], true) === $form['tools'], 'Native multi-select lost');
    $form['name'] = '<script>alert(1)</script>'; $form['story'] = 'short';
    [$status, $response] = request($url, http_build_query($form), ['Content-Type: application/x-www-form-urlencoded', 'Origin: ' . $origin]);
    check($status === 422 && str_contains($response, '&lt;script&gt;') && !str_contains($response, '<script>'), 'Native form XSS or lost values');
    check(str_contains($response, 'value="Blender" checked'), 'Native retry lost checkbox');
    rename($temp . '/private/config.php', $temp . '/private/disabled.php');
    [$status, $response] = request($url, $body, $headers);
    check($status === 503 && !str_contains($response, 'private/config') && !str_contains($response, 'test-password'), 'Missing config leaked details');
    check(str_contains(file_get_contents($temp . '/http.log'), 'stage=locate_config'), 'Missing-config diagnostic absent');
    rename($temp . '/private/disabled.php', $temp . '/private/config.php');
    $pdo->exec('DROP TABLE early_access_applications');
    [$status, $response] = request($url, $body, $headers);
    check($status === 503 && !str_contains($response, 'SQLSTATE'), 'Database failure falsely succeeded or leaked SQL');
    $log = file_get_contents($temp . '/http.log');
    check(str_contains($log, 'stage=save_application PDOException code=42S02 driver_code=1146'), 'Database diagnostic absent');
    check(!str_contains($log, 'test-password') && !str_contains($log, 'author@example.test'), 'Diagnostics exposed credentials or answers');
    [$status, $response] = request($url, http_build_query($form), ['Content-Type: application/x-www-form-urlencoded', 'Origin: ' . $origin]);
    // Validation still precedes the missing applications table.
    check($status === 422, 'Validation should still work on storage failure');
    echo "PASS native form, escaped retry with answers, missing config and database failure\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $admin->exec("DROP DATABASE IF EXISTS `$database`");
    if (isset($testUser)) $admin->exec("DROP USER IF EXISTS '$testUser'@'localhost'");
    if (is_dir($temp)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($temp);
    }
}
