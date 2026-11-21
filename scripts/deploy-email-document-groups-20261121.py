#!/usr/bin/env python3
"""Allowlisted shared-document metadata release. No evidence decisions, grants or sending."""

import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import sys
import tempfile
from concurrent.futures import ThreadPoolExecutor

HOME = Path('/home/servernetcloud')
APP = HOME / 'servernet_app'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
BASE = '741a88685f3cc4123faa07097703ff7481ab1cbb'
RELEASE = Path(__file__).absolute().parent
MANIFEST = 'document-groups-source-manifest.json'
MIGRATION = '2026_11_21_150000_create_external_email_consent_document_groups'
MIGRATION_PATH = 'database/migrations/' + MIGRATION + '.php'
BATCH_TABLE = 'external_email_consent_document_batches'
ITEM_TABLE = 'external_email_consent_document_items'
NEW_TABLES = [BATCH_TABLE, ITEM_TABLE]
OLD_TABLES = ['email_marketing_preferences', 'email_marketing_preference_events', 'external_email_consent_reviews', 'external_email_consent_review_events']
OLD_MIGRATIONS = ['2026_11_21_120000_create_email_marketing_preferences', '2026_11_21_130000_create_external_email_consent_reviews',
                  '2026_11_21_140000_create_external_email_consent_review_events']
NEW_COLUMNS = {
    BATCH_TABLE: 'id document_reference record_count created_by created_at'.split(),
    ITEM_TABLE: 'id batch_id review_id'.split(),
}
ADDITIONS = {
    MIGRATION_PATH,
    'app/Models/ExternalEmailConsentDocumentBatch.php',
    'app/Models/ExternalEmailConsentDocumentItem.php',
    'app/Http/Controllers/Admin/EmailEvidenceDocumentController.php',
}
# Install and verify the two empty source-link tables before any route exposure.
# Both controllers precede their matching updated views; new route precedes the partial.
ORDER = [
    MIGRATION_PATH,
    'app/Models/ExternalEmailConsentDocumentBatch.php',
    'app/Models/ExternalEmailConsentDocumentItem.php',
    'lang/fa/email-evidence-review.php',
    'lang/en/email-evidence-review.php',
    'lang/tr/email-evidence-review.php',
    'app/Models/ExternalEmailConsentReview.php',
    'app/Http/Controllers/Admin/EmailMarketingController.php',
    'app/Http/Controllers/Admin/EmailEvidenceDecisionController.php',
    'app/Http/Controllers/Admin/EmailEvidenceDocumentController.php',
    'routes/email-marketing-admin.php',
    'resources/views/admin/email-evidence-review-detail.blade.php',
    'resources/views/admin/email-evidence-review.blade.php',
]

LOCKS = [HOME / '.servernet-web-release.lock', HOME / 'servernet-release.lock',
         HOME / '.codex-web-deploy.lock', APP / 'storage/framework/legal-credit-release.lock']
BOOT = ("require '/home/servernetcloud/servernet_app/vendor/autoload.php'; "
        "$app=require '/home/servernetcloud/servernet_app/bootstrap/app.php'; "
        "$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ")


class Halt(RuntimeError):
    pass


def require(condition, message):
    if not condition:
        raise Halt(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def sha256(value):
    return isinstance(value, str) and re.fullmatch('[a-f0-9]{64}', value) is not None


def safe(path):
    require(path.is_absolute() and path.resolve() == path and not path.is_symlink(), 'Unsafe path: ' + str(path))
    return path


def current(path):
    safe(path)
    if not path.exists():
        return None
    require(path.is_file() and path.stat().st_uid == os.geteuid(), 'Unexpected file or owner: ' + str(path))
    return path.read_bytes()


def checksum(path):
    raw = current(path)
    return None if raw is None else digest(raw)


def mode_group(path):
    info = path.stat()
    return stat.S_IMODE(info.st_mode), info.st_gid


def private_write(path, raw):
    safe(path.parent)
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    safe(path)
    with path.open('xb') as f:
        f.write(raw)
        f.flush()
        os.fsync(f.fileno())
    require(stat.S_IMODE(path.stat().st_mode) == 0o600, 'Private artifact had unexpected mode')


def receipt(name, value):
    private_write(RELEASE / name, (json.dumps(value, sort_keys=True, indent=2) + '\n').encode())


def read_receipt(name):
    raw = current(RELEASE / name)
    require(raw is not None and stat.S_IMODE((RELEASE / name).stat().st_mode) == 0o600, 'Missing or nonprivate receipt: ' + name)
    obj = json.loads(raw)
    require(isinstance(obj, dict), 'Invalid receipt: ' + name)
    return obj


@contextlib.contextmanager
def locked():
    fds = []
    try:
        for path in LOCKS:
            safe(path)
            fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
            fds.append(fd)
            info = os.fstat(fd)
            require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid(), 'Unsafe lock: ' + path.name)
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise Halt('Concurrent release: ' + path.name)
        yield
    finally:
        for fd in reversed(fds):
            os.close(fd)


def no_cached_config_or_routes():
    cache = safe(APP / 'bootstrap/cache')
    require(cache.is_dir(), 'Application cache directory missing')
    config = cache / 'config.php'
    require(not config.exists() and not config.is_symlink() and not list(cache.glob('routes*.php')),
            'Unexpected route/config cache; no cache changes performed')


def check_context(target):
    os.umask(0o077)
    require(pwd.getpwuid(os.geteuid()).pw_name == 'servernetcloud', 'Wrong deployment account')
    require(safe(APP).is_dir() and Path(PHP).is_file() and (APP / 'artisan').is_file(), 'Application or PHP binary missing')
    require(safe(RELEASE).is_dir() and RELEASE == HOME / 'deploy-backups' / ('email-document-groups-20261121-' + target[:8]),
            'Wrong release directory')
    require(RELEASE.stat().st_uid == os.geteuid() and stat.S_IMODE(RELEASE.stat().st_mode) == 0o700, 'Release must be private')
    no_cached_config_or_routes()


def manifest(target, manifest_hash):
    raw = current(RELEASE / MANIFEST)
    require(raw is not None and digest(raw) == manifest_hash and stat.S_IMODE((RELEASE / MANIFEST).stat().st_mode) == 0o600,
            'Pinned manifest hash or mode mismatch')
    doc = json.loads(raw)
    require(set(doc) == {'base_commit', 'target_commit', 'files'} and doc['base_commit'] == BASE and doc['target_commit'] == target,
            'Unexpected manifest identity')
    rows = doc['files']
    require(isinstance(rows, list) and len(rows) == 13 and {e['path'] for e in rows} == set(ORDER), 'Manifest is outside exact 13-file allowlist')
    for e in rows:
        require(set(e) == {'path', 'status', 'before', 'after'} and sha256(e['after']), 'Invalid manifest entry')
        if e['path'] in ADDITIONS:
            require(e['status'] == 'added' and e['before'] is None, 'Invalid new-file entry')
        else:
            require(e['status'] == 'modified' and sha256(e['before']) and e['before'] != e['after'], 'Invalid modified-file entry')
    return {e['path']: e for e in rows}


def fetch(target, e):
    url = 'https://raw.githubusercontent.com/servernetir/server/' + target + '/website/' + e['path']
    r = subprocess.run(['curl', '-fSsL', '--proto', '=https', '--proto-redir', '=https', '--connect-timeout', '10', '--max-time', '45', url],
                       capture_output=True)
    require(r.returncode == 0 and digest(r.stdout) == e['after'], 'Pinned source download/hash failed: ' + e['path'])
    return r.stdout


def php_output(code, label):
    r = subprocess.run([PHP, '-r', BOOT + code], cwd=APP, capture_output=True, timeout=45)
    # Never expose raw exceptions, credentials, query data or environment values.
    require(r.returncode == 0, 'PHP check failed: ' + label)
    return r.stdout


def db_info():
    # Only integer aggregate counts, known decision-status keys, table metadata
    # and ledger counts are returned. Never fetch addresses, digests, record IDs
    # or document/evidence references into the deployment process or logs.
    code = r"""
$s=\Illuminate\Support\Facades\Schema::class; $d=\Illuminate\Support\Facades\DB::class;
$tables=[]; $counts=[]; $ledger=[];
foreach (""" + json.dumps(OLD_TABLES + ['migrations', 'users'] + NEW_TABLES) + r""" as $n) $tables[$n]=$s::hasTable($n);
foreach (""" + json.dumps(OLD_TABLES) + r""" as $n) if($tables[$n]) $counts[$n]=$d::table($n)->count();
if($tables['migrations']) foreach (""" + json.dumps(OLD_MIGRATIONS + [MIGRATION]) + r""" as $n) $ledger[$n]=$d::table('migrations')->where('migration',$n)->count();
$new=[]; foreach (""" + json.dumps(NEW_TABLES) + r""" as $n) {
    $new[$n]=['count'=>null,'columns'=>[],'indexes'=>[],'foreign'=>[]];
    if($tables[$n]) {
        $new[$n]=['count'=>$d::table($n)->count(),'columns'=>$s::getColumnListing($n),'indexes'=>$s::getIndexes($n),'foreign'=>$s::getForeignKeys($n)];
    }
}
$statuses=[]; if($tables['external_email_consent_reviews']&&$tables['external_email_consent_review_events']) {
    $latest=$d::table('external_email_consent_review_events')->selectRaw('review_id, MAX(id) AS latest_id')->groupBy('review_id');
    $rows=$d::table('external_email_consent_review_events AS e')->joinSub($latest,'latest',fn($j)=>$j->on('e.id','=','latest.latest_id'))
       ->selectRaw('e.decision, COUNT(*) AS total')->groupBy('e.decision')->get();
    $statuses=['pending'=>0,'verified'=>0,'rejected'=>0,'revoked'=>0];
    foreach($rows as $row) {
        if(!in_array($row->decision,['verified','rejected','revoked'],true)) throw new RuntimeException('unexpected decision category');
        $statuses[$row->decision]=(int)$row->total;
    }
    $statuses['pending']=$counts['external_email_consent_reviews']-$statuses['verified']-$statuses['rejected']-$statuses['revoked'];
}
echo json_encode(['driver'=>$d::connection()->getDriverName(),'tables'=>$tables,'old_counts'=>$counts,'review_statuses'=>$statuses,'ledger'=>$ledger,'new'=>$new,'main'=>(bool)config('email-marketing.enabled',false),'evidence'=>(bool)config('email-marketing.evidence_review_enabled',false),'cached'=>$app->configurationIsCached()||$app->routesAreCached()]);
"""
    return json.loads(php_output(code, 'aggregate schema, review status and ledger'))


def verify_snapshot(snapshot):
    require(set(snapshot['old_counts']) == set(OLD_TABLES) and all(type(n) is int and n >= 0 for n in snapshot['old_counts'].values()), 'Incomplete aggregate counts')
    require(set(snapshot['review_statuses']) == {'pending', 'verified', 'rejected', 'revoked'} and
            all(type(n) is int and n >= 0 for n in snapshot['review_statuses'].values()) and
            sum(snapshot['review_statuses'].values()) == snapshot['old_counts']['external_email_consent_reviews'],
            'Incomplete aggregate review statuses')


def snapshot(db):
    result = {'old_counts': db['old_counts'], 'review_statuses': db['review_statuses']}
    verify_snapshot(result)
    return result


def verify_base(db, baseline=None):
    require(db['driver'] in ('mysql', 'mariadb') and all(db['tables'][n] is True for n in OLD_TABLES + ['migrations', 'users']),
            'Expected database/tables missing')
    require(all(db['ledger'][n] == 1 for n in OLD_MIGRATIONS), 'Existing migration ledger mismatch')
    require(db['main'] is True and db['evidence'] is True and db['cached'] is False, 'Existing evidence review not enabled or cached configuration/routes')
    observed = snapshot(db)
    if baseline is not None:
        require(observed == baseline, 'Existing review counts or decision statuses changed; stop and retain history')


def empty_before(db, baseline=None):
    verify_base(db, baseline)
    require(all(db['tables'][n] is False and db['new'][n]['count'] is None for n in NEW_TABLES) and db['ledger'][MIGRATION] == 0,
            'Existing or partial document grouping migration; do not replay')


def restrictive_foreign(fks, name, column, table):
    return any(i.get('name') == name and i.get('columns') == [column]
               and i.get('foreign_table') == table and i.get('foreign_columns') == ['id']
               and str(i.get('on_delete', '')).lower() == 'restrict' for i in fks)


def verify_document_schema(db, baseline=None, require_empty=False):
    verify_base(db, baseline)
    require(all(db['tables'][n] is True for n in NEW_TABLES) and db['ledger'][MIGRATION] == 1, 'Source-link schema or migration ledger mismatch')
    for n in NEW_TABLES:
        table = db['new'][n]
        require(set(table['columns']) == set(NEW_COLUMNS[n]) and len(table['columns']) == len(NEW_COLUMNS[n]), 'Unexpected source-link schema columns')
        require(any(i.get('primary') is True and i.get('columns') == ['id'] for i in table['indexes']), 'Missing source-link primary key')
        require(type(table['count']) is int and table['count'] >= 0, 'Unexpected source-link count')
        if require_empty:
            require(table['count'] == 0, 'New source-link table unexpectedly nonempty; retain history')
    items = db['new'][ITEM_TABLE]
    require(any(i.get('name') == 'ext_email_document_review_unique' and i.get('columns') == ['review_id']
                and i.get('unique') is True for i in items['indexes']), 'Missing unique per-review link index')
    require(len(items['foreign']) == 2 and restrictive_foreign(items['foreign'], 'ext_email_document_batch_fk', 'batch_id', BATCH_TABLE)
            and restrictive_foreign(items['foreign'], 'ext_email_document_review_fk', 'review_id', 'external_email_consent_reviews'),
            'Expected restrictive source-link foreign keys missing')

def verify_routes(with_documents):
    # Inspect route metadata only; no controller dispatch or writes.
    code = r"""
$app->make(\Illuminate\Contracts\Http\Kernel::class);
$r=$app['router']->getRoutes(); $csrf=\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class;
$admin=function($route) use ($csrf,$app) {
   if(!$route) throw new RuntimeException('missing admin route');
   $m=$route->gatherMiddleware(); $x=$route->excludedMiddleware();
   if(!in_array('auth:web',$m,true)||!in_array('admin',$m,true)||!in_array('web',$m,true)||in_array($csrf,$x,true)) throw new RuntimeException('admin route guard');
   $hasCsrf=false; foreach($app['router']->gatherRouteMiddleware($route) as $mw) {
      $class=explode(':',$mw,2)[0]; if(in_array($class,[$csrf,\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class],true)||is_a($class,$csrf,true)) $hasCsrf=true;
   }
   if(!$hasCsrf) throw new RuntimeException('admin CSRF middleware');
};
foreach(['index','sample','stage','suppress','evidence.index','evidence.sample','evidence.stage'] as $name) $admin($r->getByName('admin.email-marketing.'.$name));
$existing=['review'=>['admin/email-marketing/evidence/{review}/review',['GET','HEAD'],'review'],'decide'=>['admin/email-marketing/evidence/{review}/decision',['POST'],'decide']];
foreach($existing as $name=>$spec) {
   $route=$r->getByName('admin.email-marketing.evidence.'.$name);
   $admin($route); $methods=$route->methods(); sort($methods); $expected=$spec[1]; sort($expected);
   if($methods!==$expected||$route->uri()!==$spec[0]||$route->getActionName()!==\App\Http\Controllers\Admin\EmailEvidenceDecisionController::class.'@'.$spec[2]) throw new RuntimeException('existing route mismatch');
   if($name==='decide'&&!in_array('throttle:10,1',$route->gatherMiddleware(),true)) throw new RuntimeException('missing decision throttle');
   if(!\App\Support\EmailMarketingPrivacy::sensitive(\Illuminate\Http\Request::create('/'.str_replace('{review}','1',$spec[0])))) throw new RuntimeException('missing privacy policy');
}
$doc=$r->getByName('admin.email-marketing.evidence.documents'); $n=0;
if(""" + ('true' if with_documents else 'false') + r""") {
   $admin($doc);
   if($doc->methods()!==['POST']||$doc->uri()!=='admin/email-marketing/evidence/documents'||$doc->getActionName()!==\App\Http\Controllers\Admin\EmailEvidenceDocumentController::class.'@store'||!in_array('throttle:3,1',$doc->gatherMiddleware(),true)) throw new RuntimeException('document route mismatch');
   if(!\App\Support\EmailMarketingPrivacy::sensitive(\Illuminate\Http\Request::create('/admin/email-marketing/evidence/documents'))) throw new RuntimeException('missing document privacy policy'); $n=1;
} elseif($doc) throw new RuntimeException('unexpected document route');
$expectedNames=['admin.email-marketing.index','admin.email-marketing.sample','admin.email-marketing.stage','admin.email-marketing.suppress','admin.email-marketing.evidence.index','admin.email-marketing.evidence.sample','admin.email-marketing.evidence.stage','admin.email-marketing.evidence.review','admin.email-marketing.evidence.decide'];
if($n) $expectedNames[]='admin.email-marketing.evidence.documents';
$actual=[]; foreach($r as $route) if(str_starts_with((string)$route->getName(),'admin.email-marketing.')) $actual[]=$route->getName();
sort($expectedNames); sort($actual); if($expectedNames!==$actual) throw new RuntimeException('unexpected email-marketing route scope');
if(!in_array('admin.',config('pagecache.exclude_prefixes',[]),true)||!in_array('/admin',config('pagecache.exclude_paths',[]),true)) throw new RuntimeException('admin cache exclusion missing');
echo json_encode(['document_routes'=>$n,'decision_routes'=>2]);
"""
    require(json.loads(php_output(code, 'guarded route metadata')) == {'document_routes': 1 if with_documents else 0, 'decision_routes': 2}, 'Route verification failed')

def lint(path):
    r = subprocess.run([PHP, '-l', str(path)], cwd=APP, capture_output=True, timeout=30)
    require(r.returncode == 0, 'PHP lint failed: ' + path.name)


def lint_source(stage, path):
    if path.endswith('.blade.php'):
        compiled = php_output("echo app('blade.compiler')->compileString(file_get_contents(" + json.dumps(str(stage)) + "));", 'Blade syntax')
        require(bool(compiled), 'Empty Blade compilation')
        artifact = RELEASE / 'compiled' / path
        private_write(artifact, compiled)
        lint(artifact)
    else:
        lint(stage)


def check_live(e, which='before'):
    dest = APP / e['path']
    require(safe(dest.parent).is_dir(), 'Missing application directory: ' + e['path'])
    value = checksum(dest)
    require(value == e[which], 'Exact live hash mismatch: ' + e['path'])
    if value is not None:
        require(mode_group(dest) == (e['mode'], e['group']), 'Destination metadata changed: ' + e['path'])


def artifacts(e):
    path = e['path']
    stage = RELEASE / 'source' / path
    require(checksum(stage) == e['after'] and stat.S_IMODE(stage.stat().st_mode) == 0o600, 'Staged source changed: ' + path)
    if e['before'] is not None:
        backup = RELEASE / 'backup' / path
        require(checksum(backup) == e['before'] and stat.S_IMODE(backup.stat().st_mode) == 0o600, 'Backup changed: ' + path)


def prepare(target, manifest_hash):
    require(not (RELEASE / 'prepared.json').exists() and not (RELEASE / 'apply-started.json').exists(), 'Release already prepared or attempted')
    entries = manifest(target, manifest_hash)
    files = {}
    for path in ORDER:
        dest = APP / path
        require(safe(dest.parent).is_dir(), 'Missing application directory: ' + path)
        before = checksum(dest)
        require(before == entries[path]['before'], 'Exact live preimage mismatch: ' + path)
        mode, group = mode_group(dest) if before is not None else (0o644, dest.parent.stat().st_gid)
        require(mode <= 0o777, 'Unsupported file mode: ' + path)
        files[path] = dict(entries[path], mode=mode, group=group)
    baseline = db_info()
    empty_before(baseline)
    verify_routes(False)
    with ThreadPoolExecutor(max_workers=4) as pool:
        sources = list(pool.map(lambda path: fetch(target, entries[path]), ORDER))
    for path, source in zip(ORDER, sources):
        e = files[path]
        check_live(e)
        if e['before'] is not None:
            live = current(APP / path)
            require(digest(live) == e['before'], 'Concurrent live edit: ' + path)
            private_write(RELEASE / 'backup' / path, live)
        stage = RELEASE / 'source' / path
        private_write(stage, source)
        lint_source(stage, path)
        artifacts(e)
    for e in files.values():
        check_live(e)
    no_cached_config_or_routes()
    empty_before(db_info(), snapshot(baseline))
    receipt('prepared.json', {'base': BASE, 'target': target, 'manifest_sha256': manifest_hash, 'files': files, 'baseline': snapshot(baseline)})
    print('SN_DOCUMENT_PREPARED', json.dumps({'files': 13, 'additions': 4, 'backups': 9, 'lint': 'passed', 'database': 'unchanged', **snapshot(baseline)}))


def prepared(target, manifest_hash):
    p = read_receipt('prepared.json')
    require(p['base'] == BASE and p['target'] == target and p['manifest_sha256'] == manifest_hash, 'Prepared identity mismatch')
    entries = manifest(target, manifest_hash)
    require(set(p['files']) == set(ORDER), 'Invalid prepared receipt')
    verify_snapshot(p['baseline'])
    for path, e in p['files'].items():
        require({k: v for k, v in e.items() if k not in ('mode', 'group')} == entries[path], 'Prepared manifest mismatch: ' + path)
        require(type(e['mode']) is int and 0 <= e['mode'] <= 0o777 and type(e['group']) is int and e['group'] >= 0, 'Bad file metadata: ' + path)
        if e['before'] is None:
            require(e['mode'] == 0o644, 'Bad addition mode')
        artifacts(e)
    return p


def atomic_replace(e, data, expected, replace_existing):
    dest = safe(APP / e['path'])
    fd, name = tempfile.mkstemp(prefix='.email-document-release-', dir=dest.parent)
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
            os.fchown(f.fileno(), -1, e['group'])
            os.fchmod(f.fileno(), e['mode'])
        require(checksum(dest) == expected, 'Concurrent file changed; retain: ' + e['path'])
        if replace_existing:
            require(mode_group(dest) == (e['mode'], e['group']), 'Concurrent file metadata changed: ' + e['path'])
            os.replace(name, dest)
        else:
            # No-overwrite creation: refuse an added path created concurrently.
            os.link(name, dest)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def install(e):
    artifacts(e)
    check_live(e)
    atomic_replace(e, current(RELEASE / 'source' / e['path']), e['before'], e['before'] is not None)
    check_live(e, 'after')


def scoped_migration(pretend):
    # One exact migration file; never include unrelated pending migrations.
    argv = [PHP, 'artisan', 'migrate', '--path=' + MIGRATION_PATH, '--force', '--no-interaction', '--no-ansi']
    if pretend:
        argv.append('--pretend')
    r = subprocess.run(argv, cwd=APP, capture_output=True, timeout=75)
    require(r.returncode == 0, 'Scoped migration failed; preserve partial source-link schema and ledger, do not replay')
    if pretend:
        sql = r.stdout.lower()
        targets = re.findall(rb'\b(?:create|alter)\s+table\s+(?:if\s+not\s+exists\s+)?[\"\x60]?([a-z0-9_]+)', sql)
        create_targets = re.findall(rb'\bcreate\s+table\s+(?:if\s+not\s+exists\s+)?[\"\x60]?([a-z0-9_]+)', sql)
        references = re.findall(rb'\breferences\s+[\"\x60]?([a-z0-9_]+)[\"\x60]?\s*\(', sql)
        require(set(targets) == {n.encode() for n in NEW_TABLES} and sorted(create_targets) == sorted(n.encode() for n in NEW_TABLES)
                and sorted(references) == sorted([BATCH_TABLE.encode(), b'external_email_consent_reviews'])
                and len(re.findall(rb'\bon\s+delete\s+restrict\b', sql)) == 2
                and not re.search(rb'\b(?:drop|truncate|rename)\s+(?:table|index|database)\b|\b(?:delete\s+from|update\s+[\"\x60]?\w+|(?:insert|replace)\s+into)\b', sql),
                'Unexpected migration pretend SQL; do not apply')
        receipt('migration-pretend.json', {'path': MIGRATION_PATH, 'target_tables': NEW_TABLES, 'checked': True})
    else:
        receipt('migration-applied.json', {'path': MIGRATION_PATH, 'target_tables': NEW_TABLES, 'ok': True})

def restore_runtime(p, strict=False):
    """Restore only our modified bytes; preserve new source-link files and history."""
    restored, preserved_unknown, retained_additions = [], [], []
    files = p['files']
    if strict:
        # No rollback of known files if any of the paths has an unknown edit.
        for e in files.values():
            dest = APP / e['path']
            value = checksum(dest)
            require(value in (e['before'], e['after']) and (value is None or mode_group(dest) == (e['mode'], e['group'])),
                    'Unknown live edit; stop rollback: ' + e['path'])
    for path in reversed(ORDER):
        e = files[path]
        try:
            value = checksum(APP / path)
            known = value in (e['before'], e['after']) and (value is None or mode_group(APP / path) == (e['mode'], e['group']))
            if not known:
                preserved_unknown.append(path)
                continue
            if e['before'] is None:
                # Keep additive dependencies dormant so in-flight requests remain
                # valid during rollback; never remove the migration file or tables.
                if value == e['after']:
                    retained_additions.append(path)
                continue
            if value == e['after']:
                artifacts(e)
                atomic_replace(e, current(RELEASE / 'backup' / path), e['after'], True)
                check_live(e)
                restored.append(path)
        except Exception:
            preserved_unknown.append(path)
    return {'restored': restored, 'preserved_unknown': preserved_unknown, 'retained_additions': retained_additions,
            'document_schema': 'preserved; no down migration'}


def apply(target, manifest_hash):
    require(not (RELEASE / 'apply-started.json').exists(), 'Apply was already attempted; inspect or rollback, do not replay')
    p = prepared(target, manifest_hash)
    for e in p['files'].values():
        check_live(e)
    no_cached_config_or_routes()
    empty_before(db_info(), p['baseline'])
    verify_routes(False)
    receipt('apply-started.json', {'target': target, 'manifest_sha256': manifest_hash})
    try:
        files = p['files']
        install(files[MIGRATION_PATH])
        scoped_migration(pretend=True)
        check_live(files[MIGRATION_PATH], 'after')
        no_cached_config_or_routes()
        empty_before(db_info(), p['baseline'])
        for path in ORDER[1:]:
            check_live(files[path])
        scoped_migration(pretend=False)
        verify_document_schema(db_info(), p['baseline'], require_empty=True)
        # No review status or prior table count changes, and both source-link
        # tables remain empty immediately before exposing the new POST route.
        for path in ORDER[1:]:
            if path == 'routes/email-marketing-admin.php':
                no_cached_config_or_routes()
                verify_document_schema(db_info(), p['baseline'], require_empty=True)
            install(files[path])
        no_cached_config_or_routes()
        for e in files.values():
            check_live(e, 'after')
        verify_routes(True)
        db = db_info()
        # Verify the release did not change review decisions or create groups.
        # If a legitimate concurrent admin action changes these counts, stop
        # and restore only our code; never reverse those records.
        verify_document_schema(db, p['baseline'], require_empty=True)
        counts = {n: db['new'][n]['count'] for n in NEW_TABLES}
        receipt('applied.json', {'target': target, 'manifest_sha256': manifest_hash, 'files': 13, 'document_routes': 1,
                                'document_counts': counts, **snapshot(db)})
        print('SN_DOCUMENT_APPLIED', json.dumps({'files': 13, 'document_routes': 1, 'migration': 'applied-once',
              'document_counts': counts, **snapshot(db), 'mail': 'not sent', 'grant': 'none', 'review_status_changes': 'none'}))
    except Exception:
        result = restore_runtime(p)
        receipt('apply-failed.json', result)
        print('SN_DOCUMENT_RETAINED', json.dumps(result))
        raise


def rollback(target, manifest_hash):
    start = read_receipt('apply-started.json')
    require(start == {'target': target, 'manifest_sha256': manifest_hash} and not (RELEASE / 'rolled-back.json').exists(), 'No matching active release')
    p = prepared(target, manifest_hash)
    no_cached_config_or_routes()
    result = restore_runtime(p, strict=True)
    require(not result['preserved_unknown'], 'Unknown live changes preserved; reconcile before retry')
    verify_routes(False)
    db = db_info(); verify_base(db)
    # Interrupted DDL may leave partial schema. Never run a down migration,
    # remove source-link records, or modify migration ledger entries.
    result.update({'target': target, 'document_tables': {n: db['tables'][n] for n in NEW_TABLES},
                   'document_counts': {n: db['new'][n]['count'] for n in NEW_TABLES},
                   'document_ledger': db['ledger'][MIGRATION], **snapshot(db)})
    receipt('rolled-back.json', result)
    print('SN_DOCUMENT_ROLLED_BACK', json.dumps(result))

def main():
    require(len(sys.argv) == 4 and sys.argv[1] in ('prepare', 'apply', 'rollback'),
            'Usage: release.py prepare|apply|rollback target_commit manifest_sha256')
    action, target, manifest_hash = sys.argv[1:]
    require(re.fullmatch('[a-f0-9]{40}', target) is not None and target != BASE and sha256(manifest_hash), 'Expected exact immutable target and manifest SHA256')
    check_context(target)
    with locked():
        check_context(target)
        {'prepare': prepare, 'apply': apply, 'rollback': rollback}[action](target, manifest_hash)


if __name__ == '__main__':
    try:
        main()
    except Halt as e:
        print('SN_DOCUMENT_STOP', str(e))
        sys.exit(1)
    except Exception as e:
        # Avoid displaying exceptions containing server/DB values.
        print('SN_DOCUMENT_STOP', type(e).__name__, 'retain private release state')
        sys.exit(1)
