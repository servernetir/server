#!/usr/bin/env python3
"""Scoped, reversible deployment of the tested review-only dashboard."""
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import stat
import subprocess
import sys
import tempfile

BASE = Path('/home/servernetcloud')
APP = BASE / 'servernet_app'
RELEASE = BASE / 'deploy-backups/email-dashboard-unified-20261121-6053726f'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
TARGET = '6053726f38669156d027b00b51c03a36c34c52d9'
FILES = {
    'app/Http/Controllers/Admin/EmailEvidenceReviewController.php': ('922b52d4e856a38e50356f32e990dc92e06e7406205fa3a3cc63c8623b29218c', '36b2e9795eba52ee144c04691aa95e419630e8c7e140b9eb9ca5694eb949b25d'),
    'app/Http/Controllers/Admin/EmailMarketingController.php': ('825968c13e43e161a29b0b641f38a806247c3dfef215bb83c55c84cfba2e6947', 'e3c55877dfd97fdb375684278e95484533432f12291f56bf69faad4246231815'),
    'resources/views/admin/email-evidence-review.blade.php': ('bb0593e72e7651914473557f0e7206b512da9a22f44ca6b72e4d6ebec12c7ab2', '8d056600367e1a1095ecd2ae6004f04b278d4d7b37cc0537b211cc08f5cc8979'),
    'resources/views/admin/email-marketing.blade.php': ('b1f65df22c5c9874f39c1abb5033f70257ed36c00004d883e061e73fff3e718f', '0679efa39f78245a18cfbf26a06dde07060ad096d108de930d6f1323637d1d12'),
}
LOCKS = [BASE / '.servernet-web-release.lock', BASE / 'servernet-release.lock', BASE / '.codex-web-deploy.lock', APP / 'storage/framework/legal-credit-release.lock']


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def safe(path):
    require(path.is_absolute() and path.resolve() == path and not path.is_symlink(), 'Unsafe path: '+str(path))
    return path


def data(path):
    safe(path)
    require(path.is_file() and path.stat().st_uid == os.geteuid(), 'Unexpected file or owner: '+str(path))
    return path.read_bytes()


def private_write(path, content):
    safe(path.parent)
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    safe(path)
    with path.open('xb') as f:
        f.write(content)
        f.flush()
        os.fsync(f.fileno())
    require(stat.S_IMODE(path.stat().st_mode) == 0o600, 'Unexpected private file mode')


def receipt(name, value):
    private_write(RELEASE/name, (json.dumps(value, sort_keys=True)+'\n').encode())


@contextlib.contextmanager
def locked():
    fds = []
    try:
        for path in LOCKS:
            safe(path)
            fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
            fds.append(fd)
            st = os.fstat(fd)
            require(stat.S_ISREG(st.st_mode) and st.st_uid == os.geteuid(), 'Unexpected lock owner/type')
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    finally:
        for fd in reversed(fds):
            os.close(fd)


def check_context():
    os.umask(0o077)
    require(pwd.getpwuid(os.geteuid()).pw_name == 'servernetcloud', 'Unexpected deployment account')
    require(safe(APP).is_dir() and Path(PHP).is_file(), 'Missing application or PHP')
    require(safe(RELEASE).is_dir() and RELEASE.stat().st_uid == os.geteuid() and stat.S_IMODE(RELEASE.stat().st_mode) == 0o700, 'Unexpected release directory')


def check_live(path, expected, mode=None):
    live = APP/path
    raw = data(live)
    require(digest(raw) == expected, 'Live hash mismatch: '+path)
    if mode is not None:
        require(stat.S_IMODE(live.stat().st_mode) == mode, 'Live mode mismatch: '+path)
    return raw


def lint(path):
    p = subprocess.run([PHP, '-l', str(path)], cwd=APP, capture_output=True)
    require(p.returncode == 0, 'PHP lint failed: '+path.name)


def lint_blade(path, num):
    prefix = "require '/home/servernetcloud/servernet_app/vendor/autoload.php'; $app=require '/home/servernetcloud/servernet_app/bootstrap/app.php'; $app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "
    php_code = prefix+"echo app('blade.compiler')->compileString(file_get_contents("+json.dumps(str(path))+"));"
    p = subprocess.run([PHP, '-r', php_code], cwd=APP, capture_output=True)
    require(p.returncode == 0 and bool(p.stdout), 'Blade compile failed: '+path.name)
    preview = RELEASE/('compiled-preview-'+str(num)+'.php')
    private_write(preview, p.stdout)
    lint(preview)


def prepare():
    require(not (RELEASE/'prepared.json').exists(), 'Already prepared')
    modes = {}
    for path, (before, _) in FILES.items():
        check_live(path, before)
        modes[path] = stat.S_IMODE((APP/path).stat().st_mode)
    for num, (path, (before, after)) in enumerate(FILES.items()):
        live = check_live(path, before, modes[path])
        url = 'https://raw.githubusercontent.com/servernetir/server/'+TARGET+'/website/'+path
        p = subprocess.run(['curl','-fSsL','--connect-timeout','10','--max-time','45',url], capture_output=True)
        require(p.returncode == 0 and digest(p.stdout) == after, 'Pinned GitHub source failed: '+path)
        private_write(RELEASE/'backup'/path, live)
        stage = RELEASE/'source'/path
        private_write(stage, p.stdout)
        if path.endswith('.blade.php'):
            lint_blade(stage, num)
        else:
            lint(stage)
    for path, (before, after) in FILES.items():
        check_live(path, before, modes[path])
        require(digest(data(RELEASE/'backup'/path)) == before and digest(data(RELEASE/'source'/path)) == after, 'Artifact mismatch: '+path)
    receipt('prepared.json', {'target': TARGET, 'modes': modes})
    print('SN_UNIFIED_PREPARED: 4 exact preimages, 4 private backups, PHP and Blade lint passed; live unchanged')


def prepared():
    p = json.loads(data(RELEASE/'prepared.json'))
    require(p['target'] == TARGET and set(p['modes']) == set(FILES), 'Unexpected receipt')
    for path, (before, after) in FILES.items():
        require(digest(data(RELEASE/'backup'/path)) == before and digest(data(RELEASE/'source'/path)) == after, 'Artifact mismatch: '+path)
        require(isinstance(p['modes'][path], int) and 0 <= p['modes'][path] <= 0o777, 'Invalid mode')
    return p['modes']


def atomic_replace(path, content, mode):
    dest = safe(APP/path)
    fd, name = tempfile.mkstemp(prefix='.email-dashboard-unified-', dir=dest.parent)
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(content)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(name, mode)
        os.replace(name, dest)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def apply():
    require(not (RELEASE/'applied.json').exists() and not (RELEASE/'apply-failed.json').exists(), 'Apply already attempted')
    modes = prepared()
    for path, (before, _) in FILES.items():
        check_live(path, before, modes[path])
    attempted = []
    try:
        for path, (before, after) in FILES.items():
            check_live(path, before, modes[path])
            attempted.append(path)
            atomic_replace(path, data(RELEASE/'source'/path), modes[path])
            check_live(path, after, modes[path])
        for path, (_, after) in FILES.items():
            check_live(path, after, modes[path])
        receipt('applied.json', {'target': TARGET, 'files': 4})
    except Exception:
        restored, unknown = [], []
        for path in reversed(attempted):
            before, after = FILES[path]
            try:
                current = digest(data(APP/path))
                if current == after:
                    atomic_replace(path, data(RELEASE/'backup'/path), modes[path])
                    check_live(path, before, modes[path])
                    restored.append(path)
                elif current != before:
                    unknown.append(path)
            except Exception:
                unknown.append(path)
        receipt('apply-failed.json', {'restored': restored, 'preserved_unknown': unknown})
        raise
    print('SN_UNIFIED_APPLIED: 4 files, exact hashes matched; no data, config, mail or cache changes')


def rollback():
    require((RELEASE/'applied.json').exists() and not (RELEASE/'rolled-back.json').exists(), 'No active release')
    modes = prepared()
    for path, (before, after) in FILES.items():
        require(digest(data(APP/path)) in (before, after) and stat.S_IMODE((APP/path).stat().st_mode) == modes[path], 'Unknown live changes, stop: '+path)
    for path in reversed(FILES):
        before, after = FILES[path]
        if digest(data(APP/path)) == after:
            atomic_replace(path, data(RELEASE/'backup'/path), modes[path])
        check_live(path, before, modes[path])
    receipt('rolled-back.json', {'target': TARGET})
    print('SN_UNIFIED_ROLLED_BACK: 4 original hashes verified')


if __name__ == '__main__':
    try:
        check_context()
        actions = {'prepare': prepare, 'apply': apply, 'rollback': rollback}
        require(len(sys.argv) == 2 and sys.argv[1] in actions, 'Specify prepare, apply or rollback')
        with locked():
            actions[sys.argv[1]]()
    except RuntimeError as e:
        print('SN_UNIFIED_STOP:', str(e))
        sys.exit(1)
    except Exception as e:
        print('SN_UNIFIED_STOP:', type(e).__name__, 'retain private release state')
        sys.exit(1)
