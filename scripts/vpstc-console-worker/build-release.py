"""Build a private, deterministic console release; no network or production access."""
import base64
import hashlib
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
BASE = '826a1e6ee62a84e44bba071e31b2a4a1a07881fd'
BROKER_BASE = 'a81f4b0a020aab5642d120fc140f98247289e735'
BROKER = 'app/Services/Cloud/VpsTcConsoleBroker.php'
PROTOCOL_BASE = '0741a3d54872dc6170e57462beccbc48b08d5165'
PROTOCOL = 'app/Services/Cloud/VpsTcConsole.php'
NEW = ['app/Services/Cloud/VpsTcConsole.php', 'app/Services/Cloud/VpsTcConsoleBroker.php',
       'app/Console/Commands/VpsTcConsoleProbe.php']
PATCH = ['app/Http/Controllers/Account/CloudServerController.php', 'routes/web.php', 'app/Http/Middleware/SecurityHeaders.php']

def git(*args):
    return subprocess.check_output(['git', *args], cwd=ROOT).decode()

def encode(s):
    return base64.b64encode(s.encode()).decode()

def build(headers_only=False, broker_only=False, protocol_only=False):
    if sum([headers_only, broker_only, protocol_only]) > 1:
        raise RuntimeError('Choose one release scope')
    base = BROKER_BASE if broker_only else PROTOCOL_BASE if protocol_only else BASE
    files = []
    for path in ([] if headers_only or broker_only or protocol_only else NEW):
        files.append({'path': path, 'kind': 'new', 'content': encode((ROOT / 'website' / path).read_text())})
    for path in ([PROTOCOL] if protocol_only else [BROKER] if broker_only else ['app/Http/Middleware/SecurityHeaders.php'] if headers_only else PATCH):
        diff = git('diff', '--no-ext-diff', '--unified=3', base, '--', 'website/' + path)
        hunks = []
        for line in diff.splitlines(keepends=True):
            if line.startswith('@@ '):
                hunks.append({'before': '', 'after': ''})
            elif hunks and line[0:1] in (' ', '+', '-'):
                if not line.startswith('+'):
                    hunks[-1]['before'] += line[1:]
                if not line.startswith('-'):
                    hunks[-1]['after'] += line[1:]
        if path == 'routes/web.php':
            # Insert at the unique PHP opening tag; preserve the existing broker
            # and its live-only comment/rate limit byte for byte.
            anchor = '// Dedicated HMAC endpoint; no customer session grants broker access.\n'
            current = (ROOT / 'website' / path).read_text()
            addition = current.split(anchor, 1)[0].removeprefix('<?php\n\n')
            if not addition.endswith('\n\n'):
                raise RuntimeError('Unexpected route block boundary')
            hunks = [{'before': '<?php\n', 'after': '<?php\n\n' + addition[:-1]}]
        if path == 'app/Http/Middleware/SecurityHeaders.php':
            anchor = "            $csp = implode('; ', [\n"
            current = (ROOT / 'website' / path).read_text()
            addition = current.split('            // The separate VPS relay', 1)[1].split(anchor, 1)[0]
            addition = '            // The separate VPS relay' + addition
            hunks = [{'before': anchor, 'after': addition + anchor}]
        source = git('show', base + ':website/' + path)
        for h in hunks:
            if not h['before'] or source.count(h['before']) != 1:
                raise RuntimeError('Nonunique patch context: ' + path)
            source = source.replace(h['before'], h['after'])
        if not hunks or source != (ROOT / 'website' / path).read_text():
            raise RuntimeError('Patch does not reproduce Git source: ' + path)
        files.append({'path': path, 'kind': 'patch', 'hunks': [
            {k: encode(v) for k, v in h.items()} for h in hunks]})
    return (json.dumps({'schema': 1, 'files': files}, ensure_ascii=False, indent=2) + '\n').encode()

if __name__ == '__main__':
    output = Path(sys.argv[1]).resolve()
    if ROOT == output or ROOT in output.parents:
        raise RuntimeError('Private release output must be outside the repository')
    output.mkdir(mode=0o700)
    payload = build(headers_only='--headers-only' in sys.argv[2:], broker_only='--broker-only' in sys.argv[2:], protocol_only='--protocol-only' in sys.argv[2:])
    helper = (Path(__file__).with_name('release.php')).read_text()
    helper = helper.replace('__PAYLOAD_SHA256__', hashlib.sha256(payload).hexdigest())
    for name, data in [('payload.json', payload), ('release.php', helper.encode())]:
        with (output / name).open('xb') as f:
            f.write(data)
        (output / name).chmod(0o600)
    print(json.dumps({'files': [x['path'] for x in json.loads(payload)['files']], 'payload_sha256': hashlib.sha256(payload).hexdigest(),
                      'helper_sha256': hashlib.sha256(helper.encode()).hexdigest()}))
