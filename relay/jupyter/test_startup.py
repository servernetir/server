"""Local HTTP integration test; uses no GPU or provider/customer credentials.

Run only in a disposable virtual environment containing jupyter-server==2.21.1
and jupyter-server-proxy==4.6.0. The startup command registers its extension
under that virtual environment's sys.prefix. This is not an image/TLS test.
"""

import http.server
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        return None


class Application(http.server.BaseHTTPRequestHandler):
    calls = 0

    def log_message(self, *args):
        pass

    def send_json(self, status, body):
        payload = json.dumps(body, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self):
        type(self).calls += 1
        if self.path != "/health":
            self.send_json(404, {"error": "application_route_not_found"})
            return
        self.send_json(200, {"ok": True, "method": "GET"})

    def do_POST(self):
        type(self).calls += 1
        payload = self.rfile.read(int(self.headers.get("Content-Length", 0)))
        self.send_json(200, {"ok": True, "body": json.loads(payload)})


def unused_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


class StartupIntegration(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if sys.prefix == sys.base_prefix:
            raise RuntimeError("Use a disposable virtual environment, not a system prefix")
        cls.directory = tempfile.TemporaryDirectory(prefix="jupyter-proxy-integration-")
        cls.root = Path(cls.directory.name)
        cls.processes = []
        cls.logs = []
        cls.log_paths = {}
        cls.token = secrets.token_hex(24)
        cls.opener = urllib.request.build_opener(NoRedirect())
        cls.application = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Application)
        cls.application_thread = threading.Thread(target=cls.application.serve_forever, daemon=True)
        cls.application_thread.start()
        cls.application_port = cls.application.server_port
        cls.command = json.loads(Path(__file__).with_name("startup-command.json").read_text())["command"]
        cls.env = dict(os.environ)
        cls.env.update({
            "JUPYTER_CONFIG_DIR": str(cls.root / "config"),
            "JUPYTER_RUNTIME_DIR": str(cls.root / "runtime"),
            "JUPYTER_DATA_DIR": str(cls.root / "data"),
            "IPYTHONDIR": str(cls.root / "ipython"),
            "JUPYTER_TOKEN": cls.token,
            "NOTEBOOK_ARGS": "--ip=*",
            "PATH": str(Path(sys.executable).parent) + os.pathsep + os.environ.get("PATH", ""),
        })
        try:
            # Installed but explicitly unloaded: reproduce the missing route.
            baseline_port = unused_port()
            baseline_config = cls.root / "baseline_config.py"
            baseline_config.write_text("c.ServerApp.jpserver_extensions = {'jupyter_server_proxy': False}\n")
            baseline = cls.spawn(
                [sys.executable, "-m", "jupyter_server", *cls.server_args(baseline_port),
                 "--config=" + str(baseline_config)],
                cls.env,
                "baseline",
            )
            cls.wait_ready(baseline, baseline_port)
            cls.baseline_status, _ = cls.request(baseline_port, "/health")
            baseline.terminate()  # Disposable local test process, never a customer service.
            baseline.wait(timeout=15)

            # Entrypoint/launcher shims stand in for the Docker Stacks image.
            # They invoke the real Jupyter Server; they do not emulate proxy/auth.
            cls.port = unused_port()
            shim_directory = cls.root / "bin"
            shim_directory.mkdir()
            cls.entrypoint_record = cls.root / "entrypoint.jsonl"
            for name, prefix in [("tini", ["-g", "--"]), ("start.sh", [])]:
                entrypoint = shim_directory / name
                entrypoint.write_text(
                    "#!" + sys.executable + "\n"
                    "import json, os, sys\n"
                    "args=sys.argv[1:]\n"
                    "prefix=" + repr(prefix) + "\n"
                    "assert args[:len(prefix)] == prefix\n"
                    "args=args[len(prefix):]\n"
                    "with open(os.environ['PROXY_TEST_ENTRYPOINT_RECORD'],'a') as f: "
                    "f.write(json.dumps({'name':" + repr(name) + ", 'next':args[0]})+'\\n')\n"
                    "os.execvp(args[0],args)\n"
                )
                entrypoint.chmod(0o700)
            cls.launcher_record = cls.root / "launcher.json"
            shim = shim_directory / "start-notebook.py"
            shim.write_text(
                "#!" + sys.executable + "\n"
                "import json, os, sys\n"
                "from pathlib import Path\n"
                "Path(os.environ['PROXY_TEST_LAUNCHER_RECORD']).write_text(json.dumps({"
                "'args':sys.argv[1:],'notebook_args':os.environ.get('NOTEBOOK_ARGS'),"
                "'token_present':bool(os.environ.get('JUPYTER_TOKEN'))}))\n"
                "args=json.loads(os.environ['PROXY_TEST_SERVER_ARGS'])\n"
                "os.execv(sys.executable,[sys.executable,'-m','jupyter_server',*args])\n"
            )
            shim.chmod(0o700)
            enabled_env = dict(cls.env)
            enabled_env.update({
                "PATH": str(shim_directory) + os.pathsep + cls.env["PATH"],
                "PROXY_TEST_LAUNCHER_RECORD": str(cls.launcher_record),
                "PROXY_TEST_ENTRYPOINT_RECORD": str(cls.entrypoint_record),
                "PROXY_TEST_SERVER_ARGS": json.dumps(cls.server_args(cls.port)),
            })
            cls.enabled = cls.spawn(cls.command, enabled_env, "enabled")
            cls.wait_ready(cls.enabled, cls.port)
        except BaseException:
            cls.tearDownClass()
            raise

    @classmethod
    def server_args(cls, port):
        return [
            "--ServerApp.ip=127.0.0.1", "--ServerApp.port=" + str(port),
            "--ServerApp.port_retries=0", "--ServerApp.open_browser=False",
            "--ServerApp.allow_root=True",  # Local disposable environment only.
            "--ServerApp.root_dir=" + str(cls.root),
            "--IdentityProvider.token=" + cls.token,
        ]

    @classmethod
    def spawn(cls, command, env, name):
        log = (cls.root / (name + ".log")).open("w")
        cls.logs.append(log)
        process = subprocess.Popen(command, env=env, cwd=cls.root, stdout=log, stderr=log)
        cls.processes.append(process)
        cls.log_paths[process.pid] = cls.root / (name + ".log")
        return process

    @classmethod
    def wait_ready(cls, process, port):
        deadline = time.monotonic() + 40
        while time.monotonic() < deadline:
            if process.poll() is not None:
                diagnostic = cls.log_paths[process.pid].read_text()[-2500:].replace(cls.token, "[test token]")
                raise RuntimeError("Local Jupyter test process exited before readiness:\n" + diagnostic)
            try:
                response = cls.opener.open("http://127.0.0.1:%d/login" % port, timeout=1)
                response.close()
                return
            except (urllib.error.URLError, TimeoutError, ConnectionError):
                time.sleep(0.1)
        raise RuntimeError("Local Jupyter test did not become ready")

    @classmethod
    def request(cls, port, path, *, token=None, method="GET", body=None):
        # Header authentication only: no cookies and no token in the URL.
        url = "http://127.0.0.1:%d/proxy/%d%s" % (port, cls.application_port, path)
        headers = {"Authorization": "token " + (token or cls.token)}
        payload = None if body is None else json.dumps(body, ensure_ascii=False).encode()
        if payload is not None:
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(url, data=payload, headers=headers, method=method)
        try:
            with cls.opener.open(request, timeout=5) as response:
                return response.status, response.read()
        except urllib.error.HTTPError as error:
            return error.code, error.read()

    def test_extension_preload_restores_missing_proxy_route(self):
        self.assertEqual(self.baseline_status, 404)
        status, body = self.request(self.port, "/health")
        self.assertEqual(status, 200)
        self.assertTrue(json.loads(body)["ok"])

    def test_header_only_post_with_persian_body(self):
        payload = {"message": "آزمون اتصال", "sample": 1}
        status, body = self.request(self.port, "/echo", method="POST", body=payload)
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(body)["body"], payload)

    def test_invalid_token_never_reaches_the_application(self):
        calls = Application.calls
        status, _ = self.request(self.port, "/health", token="deliberately-invalid-test-token")
        self.assertIn(status, [302, 401, 403])
        self.assertEqual(Application.calls, calls)

    def test_application_404_is_preserved(self):
        status, body = self.request(self.port, "/does-not-exist")
        self.assertEqual(status, 404)
        self.assertEqual(json.loads(body), {"error": "application_route_not_found"})

    def test_original_image_entrypoint_chain_is_preserved(self):
        records = [json.loads(line) for line in self.entrypoint_record.read_text().splitlines()]
        self.assertEqual(records, [
            {"name": "tini", "next": "start.sh"},
            {"name": "start.sh", "next": "/bin/bash"},
        ])

    def test_launcher_keeps_the_existing_auth_and_network_environment(self):
        record = json.loads(self.launcher_record.read_text())
        self.assertEqual(record["args"], [])
        self.assertEqual(record["notebook_args"], "--ip=*")
        self.assertTrue(record["token_present"])

    @classmethod
    def tearDownClass(cls):
        for process in getattr(cls, "processes", []):
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
        for log in getattr(cls, "logs", []):
            log.close()
        if hasattr(cls, "application"):
            cls.application.shutdown()
            cls.application.server_close()
        if hasattr(cls, "directory"):
            cls.directory.cleanup()


if __name__ == "__main__":
    unittest.main(verbosity=2)
