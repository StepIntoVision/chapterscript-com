"""Exercise .htaccess with macOS Apache in a disposable localhost document root."""

from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError
from urllib.request import urlopen


project = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="chapterscript-headers-") as directory:
    temp = Path(directory)
    public = temp / "public"
    public.mkdir()
    shutil.copy(project / ".htaccess", public / ".htaccess")
    for path in ["index.html", "404.html", "assets/css/site.css", "assets/js/early-access.js"]:
        target = public / path
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(project / path, target)
    for path in ["README.md", "CLAUDE.md", "EARLY_ACCESS_FORM_WORKING.md", "nested/notes.MD", "nested/notes.markdown"]:
        target = public / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text("PRIVATE DOCUMENT FIXTURE")

    # CGI uses the same Apache response-header table as PHP/FastCGI. Verify that
    # the site policy adds restrictions without replacing an application's CSP.
    cgi = public / "response.cgi"
    cgi.write_text("#!/bin/sh\nprintf '%s\\r\\n' 'Status: 405 Method Not Allowed' 'Content-Type: application/json' \"Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'\" 'Cache-Control: no-store' '' '{\"ok\":false}'\n")
    cgi.chmod(0o700)

    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    config = temp / "httpd.conf"
    modules = ["mpm_prefork", "unixd", "authz_core", "headers", "mime", "dir", "cgi"]
    config.write_text("\n".join([
        f'ServerRoot "{temp}"',
        *[f"LoadModule {name}_module /usr/libexec/apache2/mod_{name}.so" for name in modules],
        f"Listen 127.0.0.1:{port}", "ServerName localhost",
        f'PidFile "{temp / "httpd.pid"}"', f'ErrorLog "{temp / "error.log"}"',
        "LogLevel warn", "TypesConfig /etc/apache2/mime.types",
        f'DocumentRoot "{public}"', f'<Directory "{public}">',
        "AllowOverride All", "Require all granted", "Options +ExecCGI -Indexes",
        "AddHandler cgi-script .cgi", "DirectoryIndex index.html", "</Directory>",
    ]) + "\n")
    subprocess.run(["/usr/sbin/httpd", "-t", "-f", str(config)], check=True)
    server = subprocess.Popen(["/usr/sbin/httpd", "-X", "-f", str(config)])
    try:
        for _ in range(100):
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                    break
            except OSError:
                if server.poll() is not None:
                    raise RuntimeError((temp / "error.log").read_text())
                time.sleep(0.05)

        def request(path):
            try:
                response = urlopen(f"http://127.0.0.1:{port}{path}", timeout=5)
            except HTTPError as error:
                response = error
            with response:
                body = response.read()
                headers = response.headers
                assert headers["Strict-Transport-Security"] == "max-age=31536000", path
                assert headers["X-Frame-Options"] == "DENY", path
                assert headers["X-Content-Type-Options"] == "nosniff", path
                assert headers["Referrer-Policy"] == "same-origin", path
                assert "frame-ancestors 'none'" in ",".join(headers.get_all("Content-Security-Policy", [])), path
                return response.status, headers, body

        for path in ["/", "/404.html", "/assets/css/site.css", "/assets/js/early-access.js"]:
            status, headers, body = request(path)
            assert status == 200, (path, status)
            if path.endswith(".css"):
                assert "text/css" in headers["Content-Type"]
            if path.endswith(".js"):
                assert "javascript" in headers["Content-Type"]
        for path in ["/README.md", "/CLAUDE.md", "/EARLY_ACCESS_FORM_WORKING.md", "/nested/notes.MD", "/nested/notes.markdown"]:
            status, _, body = request(path)
            assert status == 403 and b"PRIVATE DOCUMENT FIXTURE" not in body, path
        assert request("/does-not-exist")[0] == 404
        status, headers, _ = request("/response.cgi")
        assert status == 405, status
        policy = ",".join(headers.get_all("Content-Security-Policy", []))
        assert "default-src 'none'" in policy and "base-uri 'none'" in policy, policy
        assert "object-src 'none'" in policy, policy
        assert headers["Cache-Control"] == "no-store"
        print("PASS Apache: Markdown denied, HTML/CSS/JS served, headers on success/errors, application CSP preserved")
    finally:
        server.terminate()
        server.wait(timeout=10)
