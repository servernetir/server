"""Build and guard the limited cloud-store/Shahkar release; never runs remotely itself.

Release v8 follows the installed cloud-store-experience-20261018-v6 release.
It clears stale cross-location GET selections and keeps sold-out city rows
informational while preserving valid same-city choices.
V1 was inventoried and unlocked without any target writes. Its inventory exposed
published provider filtering/alert changes that must be preserved in this packet.
V3 and V4 were staged privately only, without inventory or application writes.
V7 reached inventory only, without application writes; byte-only drift was caught
before prepare/apply. V8 replaces that uninstalled candidate after reconciliation.
V2, V5 and V6 were installed and activated before this candidate.

Local: python scripts/build-cloud-store-release-20261018.py --build
Upload the resulting directory OUTSIDE public_html, with directory mode 0700 and
files 0600. On the server use its copied release.py, in this order:
  --inventory --home /home/servernetcloud
  (review observed bytes, reconcile drift, then supply reviewed-before.json)
  --prepare --home /home/servernetcloud --reviewed-before reviewed-before.json
  --apply --home /home/servernetcloud
  (web OPcache/cache activation and independent acceptance are separate)
  --unlock --home /home/servernetcloud

Inventory holds a durable cooperative lock through acceptance. It cannot stop
other deployment mechanisms: human coordination remains required. No financial
or provider call, bootstrap, migration, cache clear or OPcache reset is performed.
There is deliberately no blanket policy rollback: old IranSalesGate has no
blocksProduct(), and reverting policy can reopen prohibited sales. Private
backups plus the journal support a reviewed, dependency-aware recovery.
"""
from pathlib import Path
import argparse
import datetime
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
import tarfile
import uuid

RELEASE = 'cloud-store-experience-20261018-v8'
LOCK = '.cloud-store-experience-deploy.lock'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
SOURCES = '''app/Http/Controllers/Account/BuilderCheckoutController.php
app/Http/Controllers/Account/CloudStoreController.php
app/Http/Controllers/Account/PaymentController.php
app/Http/Controllers/Account/StoreController.php
app/Http/Controllers/Account/VerificationController.php
app/Http/Controllers/Admin/SettingsController.php
app/Http/Controllers/CatalogController.php
app/Http/Controllers/CloudCatalogController.php
app/Http/Controllers/HourlyVpsController.php
app/Http/Controllers/OrderSummaryController.php
app/Http/Controllers/SiteController.php
app/Services/CatalogPricing.php
app/Services/Cloud/CloudAddons.php
app/Services/Cloud/CloudProvisioner.php
app/Services/Cloud/CloudPublicQuote.php
app/Services/Customer/IranPurchaseReadiness.php
app/Services/Customer/IranSalesGate.php
app/Services/Customer/IranSalesVisibility.php
app/Services/Customer/KycReview.php
app/Services/MenuManager.php
app/Services/Payment/PaymentService.php
app/Services/Provisioning/ProvisioningService.php
app/Services/ServerMenu.php
lang/en/cloud-store.php
lang/en/iran-sales.php
lang/en/ui.php
lang/fa/cloud-store.php
lang/fa/iran-sales.php
lang/fa/ui.php
lang/tr/cloud-store.php
lang/tr/iran-sales.php
lang/tr/ui.php
public/assets/css/cloud-store-experience.css
public/assets/js/cloud-store-experience.js
public/assets/js/cloud-store-geography.js
public/assets/js/cloud-store-globe.js
resources/views/account/cloud-store.blade.php
resources/views/account/partials/cloud-store-location.blade.php
resources/views/account/partials/cloud-store-name.blade.php
resources/views/admin/settings/general.blade.php
resources/views/pages/cloud-location.blade.php
resources/views/pages/cloud.blade.php
resources/views/pages/home.blade.php
resources/views/pages/hosting.blade.php
resources/views/pages/order-summary.blade.php
resources/views/pages/solutions.blade.php
resources/views/pages/vps-hourly.blade.php
resources/views/panel/layout.blade.php
resources/views/partials/atlas-network.blade.php
resources/views/partials/cloud-locations-links.blade.php
resources/views/partials/header.blade.php'''.splitlines()


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat()


def private_parents(path):
    if not path.exists():
        private_parents(path.parent)
        path.mkdir(mode=0o700)
        os.chmod(path, 0o700)


def write_private(path, data):
    private_parents(path.parent)
    with path.open('xb') as stream:
        os.chmod(path, 0o600)
        stream.write(data if isinstance(data, bytes) else data.encode('utf-8'))
        stream.flush()
        os.fsync(stream.fileno())


def write_json(path, data):
    write_private(path, json.dumps(data, indent=2, ensure_ascii=False) + '\n')


def read_json(path):
    return json.loads(path.read_text(encoding='utf-8'))


def rank(source):
    if source.startswith('public/'):
        return 10
    if source.startswith('lang/'):
        return 20
    if source == 'app/Services/Customer/IranSalesGate.php':
        return 30
    if source.startswith('app/Services/Customer/Iran'):
        return 31
    if source in ['app/Services/Cloud/CloudAddons.php',
                  'app/Services/Cloud/CloudPublicQuote.php',
                  'app/Services/Payment/PaymentService.php',
                  'app/Services/Provisioning/ProvisioningService.php',
                  'app/Services/Cloud/CloudProvisioner.php']:
        return 40
    if source.startswith('app/'):
        return 50
    if source.startswith('resources/views/account/partials/'):
        return 60
    return 70


def entries():
    result = []
    for source in SOURCES:
        result.append({'source': source, 'path': 'servernet_app/' + source, 'order': rank(source)})
        if source.startswith('public/'):
            result.append({'source': source, 'path': 'public_html/' + source[len('public/'):], 'order': 9})
    return sorted(result, key=lambda item: (item['order'], item['path']))


def build(args):
    repo = Path(__file__).resolve().parent.parent
    out = (args.output or repo / 'website/storage/framework/testing' / RELEASE).resolve()
    if os.name == 'nt' and not str(out).startswith('\\\\?\\'):
        out = Path('\\\\?\\' + str(out))
    require(not out.exists(), 'Output already exists; inspect it, do not rerun blindly: ' + str(out))
    archive = out.with_name(out.name + '.tar.gz')
    require(not archive.exists(), 'Archive already exists; inspect it before rebuilding')
    changed = subprocess.check_output(['git', 'diff', '--name-only', 'origin/develop...HEAD', '--',
                                      'website/app', 'website/lang', 'website/resources', 'website/public'],
                                     cwd=repo, text=True).splitlines()
    require(set(changed) == {'website/' + source for source in SOURCES}, 'Runtime diff changed: review fixed allowlist')
    require(len(SOURCES) == 51 and len(entries()) == 55, 'Unexpected scope')
    verifier = repo / 'scripts/verify-cloud-store-release-20261018.php'
    require(verifier.is_file(), 'Acceptance verifier is not ready')
    activator = repo / 'scripts/activate-cloud-store-release-20261018.php'
    require(activator.is_file(), 'Activation helper is not ready')
    out.mkdir(parents=True, mode=0o700)
    for source in SOURCES:
        path = repo / 'website' / source
        require(path.is_file() and not path.is_symlink(), 'Unsafe/missing source: ' + source)
        write_private(out / 'payload' / source, path.read_bytes())
        baseline = subprocess.run(['git', 'show', 'origin/develop:website/' + source],
                                  cwd=repo, capture_output=True)
        if baseline.returncode == 0:
            write_private(out / 'baseline' / source, baseline.stdout)
        else:
            absent = subprocess.run(['git', 'ls-tree', '--name-only', 'origin/develop', '--', 'website/' + source],
                                    cwd=repo, capture_output=True)
            require(absent.returncode == 0 and not absent.stdout.strip(), 'Could not read baseline source: ' + source)
    rows = entries()
    for item in rows:
        item['after'] = sha(out / 'payload' / item['source'])
        item['baseline_sha256'] = sha(out / 'baseline' / item['source'])
    head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=repo, text=True).strip()
    base = subprocess.check_output(['git', 'rev-parse', 'origin/develop'], cwd=repo, text=True).strip()
    modified = subprocess.check_output(['git', 'diff', '--name-only', 'HEAD', '--',
                                       *['website/' + source for source in SOURCES]], cwd=repo, text=True).splitlines()
    write_json(out / 'candidate-manifest.json', {'release': RELEASE, 'created_utc': now(),
               'head': head, 'origin_develop': base, 'modified_sources': modified, 'entries': rows})
    write_private(out / 'release.py', Path(__file__).read_bytes())
    write_private(out / verifier.name, verifier.read_bytes())
    write_private(out / activator.name, activator.read_bytes())
    write_private(out / 'README.txt', __doc__ + '\nReviewed-before format: {"candidate_sha256":"<hash of candidate-manifest.json>",'
                  '"entries":[{"path":"servernet_app/...","before":"<inventory hash or null>"}, ...]}\n'
                  'Review ALL 55 observed targets against source and published baseline before signing this file.\n'
                  'Use inventory.json candidate_sha256 and entries. Do not auto-approve observed drift.\n')
    def archive_mode(info):
        info.uid = info.gid = 0
        info.uname = info.gname = ''
        info.mode = 0o700 if info.isdir() else 0o600
        return info
    # The archive contains one private directory. Extract as the cPanel account,
    # never as root; confirm owner/modes before invoking release.py.
    with tarfile.open(archive, 'x:gz') as stream:
        stream.add(out, arcname=RELEASE, filter=archive_mode)
    os.chmod(archive, 0o600)
    print(json.dumps({'built': str(out), 'source_files': 51, 'target_files': 55,
                      'candidate_sha256': sha(out / 'candidate-manifest.json'),
                      'archive': str(archive), 'archive_sha256': sha(archive)}))


def private_bundle(bundle):
    require(os.name == 'posix', 'Remote operations require the production POSIX filesystem')
    require(bundle.stat().st_uid == os.geteuid() and stat.S_IMODE(bundle.stat().st_mode) == 0o700,
            'Release directory must be owned by executor, mode 0700')
    for path in bundle.rglob('*'):
        require(not path.is_symlink(), 'Symlink in private release')
        require(path.stat().st_uid == os.geteuid(), 'Wrong release owner')
        require(stat.S_IMODE(path.stat().st_mode) == (0o700 if path.is_dir() else 0o600),
                'Private directory/file modes must be 0700/0600: ' + str(path.relative_to(bundle)))


def candidate(bundle):
    packet = read_json(bundle / 'candidate-manifest.json')
    expected = entries()
    rows = packet['entries']
    require(packet.get('release') == RELEASE and len(rows) == 55, 'Wrong release packet')
    require([{k: row[k] for k in ['source', 'path', 'order']} for row in rows] == expected,
            'Candidate allowlist/order changed')
    for row in rows:
        require(sha(bundle / 'payload' / row['source']) == row['after'], 'Payload drift: ' + row['source'])
        require(sha(bundle / 'baseline' / row['source']) == row['baseline_sha256'], 'Baseline drift: ' + row['source'])
    return packet


def target(home, rel):
    require(rel in {row['path'] for row in entries()}, 'Target outside fixed allowlist')
    path = home / rel
    current = path
    while current != home:
        require(not current.is_symlink(), 'Symlink target/ancestor: ' + str(current))
        current = current.parent
    require(path.parent.is_dir(), 'Target parent missing: ' + rel)
    require(not path.exists() or path.is_file(), 'Non-file target: ' + rel)
    require(not path.exists() or path.stat().st_uid == os.geteuid(), 'Target owned by another account: ' + rel)
    if path.exists():
        require(not (stat.S_IMODE(path.stat().st_mode) & 0o7000), 'Special target mode: ' + rel)
    return path


def acquire_lock(home, bundle):
    lock = home / LOCK
    require(not lock.exists(), 'A deployment lock already exists; inspect owner and coordinate')
    lock.mkdir(mode=0o700)
    token = str(uuid.uuid4())
    write_json(lock / 'owner.json', {'release': RELEASE, 'bundle': str(bundle), 'token': token, 'utc': now()})
    write_json(bundle / 'lock-owner.json', {'token': token})


def check_lock(home, bundle):
    lock = home / LOCK
    require(lock.is_dir() and not lock.is_symlink(), 'Deployment lock missing/unsafe')
    require(lock.stat().st_uid == os.geteuid() and stat.S_IMODE(lock.stat().st_mode) == 0o700, 'Unsafe lock owner/mode')
    owner = read_json(lock / 'owner.json')
    local = read_json(bundle / 'lock-owner.json')
    require(owner['token'] == local['token'] and owner['bundle'] == str(bundle), 'Another release owns lock')


def inventory(home, bundle, packet):
    require(not (bundle / 'inventory.json').exists(), 'Inventory already exists; do not rerun blindly')
    acquire_lock(home, bundle)
    rows = []
    classifications = {'candidate': [], 'baseline': [], 'absent': [], 'drift': []}
    for item in packet['entries']:
        path = target(home, item['path'])
        data = path.read_bytes() if path.exists() else None
        before = hashlib.sha256(data).hexdigest() if data is not None else None
        mode = stat.S_IMODE(path.stat().st_mode) if data is not None else 0o644
        if data is not None:
            write_private(bundle / 'observed' / item['path'], data)
        classification = ('candidate' if before == item['after'] else 'absent' if before is None
                          else 'baseline' if before == item['baseline_sha256'] else 'drift')
        classifications[classification].append(item['path'])
        rows.append(dict(item, before=before, mode=mode, classification=classification,
                         size=len(data) if data is not None else None))
    write_json(bundle / 'inventory.json', {'release': RELEASE, 'utc': now(),
               'candidate_sha256': sha(bundle / 'candidate-manifest.json'), 'entries': rows})
    observed_archive = bundle / 'observed.tar.gz'
    if (bundle / 'observed').is_dir():
        with tarfile.open(observed_archive, 'x:gz') as archive:
            archive.add(bundle / 'observed', arcname='observed')
        os.chmod(observed_archive, 0o600)
    print(json.dumps({'inventory': len(rows), 'target_writes': 0, 'lock_held': True,
                      'candidate_sha256': sha(bundle / 'candidate-manifest.json'),
                      'counts': {key: len(value) for key, value in classifications.items()},
                      'classified_paths': classifications, 'observed_archive': observed_archive.name
                      if observed_archive.exists() else None}))


def inventory_rows(home, bundle):
    observed = read_json(bundle / 'inventory.json')
    require(observed['candidate_sha256'] == sha(bundle / 'candidate-manifest.json'), 'Candidate changed after inventory')
    rows = observed['entries']
    require(len(rows) == 55 and [row['path'] for row in rows] == [row['path'] for row in entries()], 'Invalid inventory')
    for item in rows:
        live = target(home, item['path'])
        require(sha(live) == item['before'], 'Live drift: ' + item['path'])
        require(not live.exists() or stat.S_IMODE(live.stat().st_mode) == item['mode'],
                'Live permission drift: ' + item['path'])
        require(sha(bundle / 'observed' / item['path']) == item['before'], 'Observed backup drift: ' + item['path'])
        require(sha(bundle / 'payload' / item['source']) == item['after'], 'Payload drift: ' + item['path'])
    return rows


def lint(home, bundle, php):
    def run(command):
        result = subprocess.run(command, cwd=home / 'servernet_app', capture_output=True, text=True)
        require(result.returncode == 0, 'PHP validation failed: ' + result.stdout + result.stderr)
    for source in SOURCES:
        if source.endswith('.php') and not source.endswith('.blade.php'):
            run([php, '-l', str(bundle / 'payload' / source)])
    compiled = bundle / 'compiled-check'
    compiled.mkdir(mode=0o700)
    php_code = r'''require $argv[1].'/vendor/autoload.php';
$c = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $argv[3]);
file_put_contents($argv[3].'/'.basename($argv[2]).'.php', $c->compileString(file_get_contents($argv[2])));
'''
    for source in SOURCES:
        if source.endswith('.blade.php'):
            source_path = bundle / 'payload' / source
            # Each basename is unique in this fixed manifest except no repeated files.
            output = compiled / (source_path.name + '.php')
            require(not output.exists(), 'Duplicate Blade basename')
            run([php, '-r', php_code, str(home / 'servernet_app'), str(source_path), str(compiled)])
            os.chmod(output, 0o600)
            run([php, '-l', str(output)])


def prepare(args, home, bundle):
    require(not (bundle / 'manifest.json').exists() and not (bundle / 'PREPARED.json').exists(), 'Already prepared')
    require(args.reviewed_before is not None, 'Explicit reviewed-before file required after source reconciliation')
    review_path = args.reviewed_before.resolve()
    require(review_path.parent == bundle, 'Reviewed-before must be in the private release directory')
    review = read_json(review_path)
    require(review['candidate_sha256'] == sha(bundle / 'candidate-manifest.json'), 'Review is for another candidate')
    rows = inventory_rows(home, bundle)
    approved = review['entries']
    require(len(approved) == 55 and len({row['path'] for row in approved}) == 55, 'Review must cover 55 unique targets')
    require({row['path']: row['before'] for row in approved} == {row['path']: row['before'] for row in rows},
            'Reviewed before hashes do not match inventory')
    lint(home, bundle, args.php)
    for item in rows:
        if item['before'] is not None:
            write_private(bundle / 'backup' / item['path'], (bundle / 'observed' / item['path']).read_bytes())
            require(sha(bundle / 'backup' / item['path']) == item['before'], 'Backup mismatch')
    write_json(bundle / 'manifest.json', rows)
    write_json(bundle / 'PREPARED.json', {'utc': now(), 'target_writes': 0, 'manifest_sha256': sha(bundle / 'manifest.json'),
               'review_sha256': sha(review_path), 'php_lint': True, 'blade_compile_lint': True})
    print(json.dumps({'prepared': 55, 'target_writes': 0, 'lock_held': True}))


def apply(home, bundle):
    require((bundle / 'PREPARED.json').exists(), 'Prepare and reconcile first')
    require(not (bundle / 'APPLY_STARTED.json').exists(), 'Apply already started; inspect journal, do not rerun')
    prepared = read_json(bundle / 'PREPARED.json')
    require(prepared['manifest_sha256'] == sha(bundle / 'manifest.json'), 'Prepared manifest drift')
    rows = inventory_rows(home, bundle)
    require(rows == read_json(bundle / 'manifest.json'), 'Manifest/inventory mismatch')
    for row in rows:
        require(sha(bundle / 'backup' / row['path']) == row['before'], 'Backup drift: ' + row['path'])
    write_json(bundle / 'APPLY_STARTED.json', {'utc': now(), 'activation': 'not performed'})
    journal_path = bundle / 'journal.jsonl'
    write_private(journal_path, b'')
    completed = []
    try:
        for row in rows:
            path = target(home, row['path'])
            require(sha(path) == row['before'], 'Concurrent live drift: ' + row['path'])
            require(not path.exists() or stat.S_IMODE(path.stat().st_mode) == row['mode'],
                    'Concurrent permission drift: ' + row['path'])
            if row['before'] != row['after']:
                temp = path.with_name('.' + path.name + '.' + RELEASE + '.tmp')
                require(not temp.exists() and not temp.is_symlink(), 'Temporary target already exists')
                write_private(temp, (bundle / 'payload' / row['source']).read_bytes())
                os.chmod(temp, row['mode'])
                require(sha(temp) == row['after'], 'Temporary payload hash mismatch')
                require(sha(path) == row['before'], 'Concurrent drift immediately before replace: ' + row['path'])
                require(not path.exists() or stat.S_IMODE(path.stat().st_mode) == row['mode'],
                        'Concurrent permission drift immediately before replace: ' + row['path'])
                os.replace(temp, path)
                parent_fd = os.open(str(path.parent), os.O_RDONLY)
                try:
                    os.fsync(parent_fd)
                finally:
                    os.close(parent_fd)
            require(sha(path) == row['after'], 'Installed hash mismatch: ' + row['path'])
            completed.append(row['path'])
            with journal_path.open('ab') as journal:
                journal.write((json.dumps({'path': row['path'], 'after': row['after'], 'utc': now()}) + '\n').encode())
                journal.flush()
                os.fsync(journal.fileno())
        for row in rows:
            require(sha(target(home, row['path'])) == row['after'], 'Final installed hash drift: ' + row['path'])
        changed = sum(row['before'] != row['after'] for row in rows)
        write_json(bundle / 'APPLIED.json', {'utc': now(), 'files': len(rows), 'changed_files': changed, 'web_opcache_activation': 'pending',
                   'acceptance': 'pending', 'provider_calls': 0, 'database_writes': 0})
        print(json.dumps({'installed': len(rows), 'changed_files': changed, 'activation': 'pending', 'acceptance': 'pending', 'lock_held': True}))
    except Exception as error:
        write_json(bundle / 'FAILED.json', {'utc': now(), 'completed': completed, 'reason': str(error),
                   'recovery': 'Private backups/journal retained; no automatic policy rollback; lock remains held'})
        raise


def unlock(home, bundle):
    require(not (bundle / 'LOCK_RELEASED.json').exists(), 'Lock already released')
    rows = read_json(bundle / 'inventory.json')['entries'] if (bundle / 'inventory.json').exists() else []
    if (bundle / 'APPLY_STARTED.json').exists():
        require((bundle / 'APPLIED.json').exists(), 'Partial installation: retain lock for reviewed recovery')
        require(all(sha(target(home, row['path'])) == row['after'] for row in rows), 'Installed state drift')
    else:
        require(all(sha(target(home, row['path'])) == row['before'] for row in rows), 'Preflight state drift')
    lock = home / LOCK
    (lock / 'owner.json').unlink()
    lock.rmdir()
    write_json(bundle / 'LOCK_RELEASED.json', {'utc': now(), 'note': 'Acceptance status must be recorded separately'})
    print(json.dumps({'lock_released': True}))


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    actions = parser.add_mutually_exclusive_group(required=True)
    for action in ['build', 'inventory', 'prepare', 'apply', 'unlock', 'check_live']:
        actions.add_argument('--' + action.replace('_', '-'), action='store_true')
    parser.add_argument('--output', type=Path)
    parser.add_argument('--home', type=Path, default=Path('/home/servernetcloud'))
    parser.add_argument('--reviewed-before', type=Path)
    parser.add_argument('--php', default=PHP)
    args = parser.parse_args()
    if args.build:
        build(args)
        return
    bundle = Path(__file__).resolve().parent
    home = args.home.resolve()
    require(home.is_dir() and home.stat().st_uid == os.geteuid(), 'Wrong deployment home/owner')
    require(bundle.parent == home and not bundle.is_symlink(), 'Release must be directly under account home, outside webroot')
    private_bundle(bundle)
    packet = candidate(bundle)
    if args.inventory:
        inventory(home, bundle, packet)
        return
    check_lock(home, bundle)
    if args.prepare:
        prepare(args, home, bundle)
    elif args.apply:
        apply(home, bundle)
    elif args.unlock:
        unlock(home, bundle)
    elif args.check_live:
        inventory_rows(home, bundle)
        print(json.dumps({'before_hashes_match': 55, 'target_writes': 0, 'lock_held': True}))


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('STOP: ' + str(exc), file=sys.stderr)
        sys.exit(1)
