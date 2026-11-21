#!/usr/bin/env python3
"""Guarded, scoped install. Uses the preview's live preimages and retains receipts."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile

HOME = Path('/home/servernetcloud')
ROOT = HOME / 'servernet_app'
RECEIPTS = HOME / 'deploy-backups/blog-20261121'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
SOURCE = 'e2350091d7dc18617a410f9025d053c836f776a7'
TARGETS = json.loads('[{"path": "app/Console/Commands/PublishDailyBlog.php", "sha256": "eaaf520a1ab129dbfda5f091f62252af39b013de0073da751142245a2483dda7"}, {"path": "app/Http/Controllers/Admin/GrowthController.php", "sha256": "e1a933985263215ff726bfc2c8f31634bea6e60fd30a0dafafa1697c9d3b3369"}, {"path": "app/Services/Growth/DailyBlogArticle.php", "sha256": "1340a90cb2d34af2926a1c8bc52ea791c703e7b2d1bfcd2eeb9fa18b6288d84a"}, {"path": "app/Services/Growth/DailyBlogPublisher.php", "sha256": "e99f8b3b0e9fc63376faeb88dac8b2deda08cada08fa334483c6221ab453bd75"}, {"path": "app/Services/Growth/DailyBlogSources.php", "sha256": "ad8228ee4f8753abd9d12ab6984a2ebc9d3c9e6cd717bedee56e5feb203735e7"}, {"path": "app/Services/Growth/GrowthClaims.php", "sha256": "0f49af73b8b1744d3479263b298ffc5d7a3e536b5aa390810c63c5b31c38d9a6"}, {"path": "config/daily_blog.php", "sha256": "723b76c04aa98a0f1a7719a73480fa3da839b803ce63e4d8c15869d7a6e0d950"}, {"path": "resources/views/admin/growth/index.blade.php", "sha256": "7ff7d4f0cae2b638b1746db7fef638fe5df11ed1e6aefdc6915e014f994e3640"}, {"path": "resources/views/admin/growth/daily-blog.blade.php", "sha256": "c1f7a8b75eb75e44c37d35655e3ea91c009ece00bbb1af8f74a7d6f4bb9e276e"}, {"path": "lang/fa/daily-blog.php", "sha256": "5778731ad898c0d896bd46f289589abea595a1cab15955317e1b120821bda7a1"}, {"path": "lang/en/daily-blog.php", "sha256": "ec6f77f2ec4e1c9134f649c6751cdcdc4bba60c83e865edd43f419e1155f1bc4"}, {"path": "lang/tr/daily-blog.php", "sha256": "b3ccd062ee59b3d041656d209d0f80afd828acca2bfd5ceb2f0098b939ea5c5c"}, {"path": "app/Services/Growth/SearchConsoleClient.php", "sha256": "93e7c1d7e5e31a419dbff2c5b8b1288c5a0126a561124e8bd7f17890a7be5ebd"}, {"path": "app/Services/Growth/GoogleDiscovery.php", "sha256": "0b6c81549825dcfae8629b47e9fdacae7d00c40e19eaf4a4e1014a07555eb007"}, {"path": "app/Console/Commands/GoogleDiscoverBlog.php", "sha256": "d5aaefa685f554a4207a4e7531214cd297c775f85f93e07486acaf2607fd22a1"}, {"path": "app/Http/Controllers/Admin/SearchConsoleController.php", "sha256": "eef565f139021f991df5e91a9e54ddf511fddb2d3a7289a5e84abb95acfef6d2"}, {"path": "app/Http/Controllers/Admin/CalendarController.php", "sha256": "e7c444f5617b238ce2a86ff269258864b96b367073f89177d7183a631dc5bb56"}, {"path": "routes/ai-growth.php", "sha256": "ce114122af6b85952156eff8ebc4f576e9eb13e3983906a7ff1b96a42a0863a2"}, {"path": "resources/views/admin/growth/google-discovery.blade.php", "sha256": "af546726ba7e19b028f745d5e2b0b3fdebf1055212a62028f8b65ed4fcbc6eb9"}, {"path": "lang/fa/google-discovery.php", "sha256": "0d8d14d9ef6bb1315cc169bfe28116b49237d4935ae5ab85b8fa1927e68e1571"}, {"path": "lang/en/google-discovery.php", "sha256": "e5c1d142b6ec843d336062e32fffe622209d4df0621bde1c7c7d272e883bee0e"}, {"path": "lang/tr/google-discovery.php", "sha256": "b57fc5587cf268ac40bb939bb07edd3aaadba462cb76625792da265d9def5aeb"}]')


def sha(data):
    return hashlib.sha256(data).hexdigest()


def signature(path):
    assert path.resolve() == path and not path.is_symlink(), 'symlink_refused'
    if not path.exists():
        return None
    assert path.is_file() and path.stat().st_uid == os.getuid(), 'file_identity_rejected'
    return sha(path.read_bytes())


def atomic(path, data, mode):
    assert path.parent.resolve() == path.parent and not path.is_symlink()
    fd, temp = tempfile.mkstemp(prefix='.blog-release-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temp, mode)
        os.replace(temp, path)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def main():
    assert Path.home() == HOME and ROOT.resolve() == ROOT
    assert not (RECEIPTS / 'installed.json').exists(), 'already_installed'
    locks = []
    for name in ('.servernet-web-release.lock', 'servernet-release.lock', '.codex-web-deploy.lock'):
        fd = os.open(HOME / name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        stream = os.fdopen(fd, 'a+b')
        assert os.fstat(fd).st_uid == os.getuid()
        fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        locks.append(stream)
    manifest = json.loads((RECEIPTS / 'manifest.json').read_text())
    assert manifest['source'] == SOURCE
    assert [(r['path'], r['sha256']) for r in manifest['files']] == [(r['path'], r['sha256']) for r in TARGETS], 'manifest_scope_changed'
    stage = Path(manifest['stage'])
    assert stage.resolve() == stage and str(stage).startswith('/tmp/servernet-blog-20261121-')
    # Keep the live Hermes action allow-list. Only the Google routes belong to this release.
    row = next(r for r in manifest['files'] if r['path'] == 'routes/ai-growth.php')
    route = stage / row['path']
    original_route_sha = row['sha256']
    old = "['search', 'solution', 'suggest', 'candidate', 'feedback', 'products', 'changes']"
    new = "['search', 'solution', 'suggest', 'candidate', 'feedback', 'products', 'changes', 'curriculum', 'oversight', 'assess']"
    assert old in (RECEIPTS / 'before' / row['path']).read_text()
    if new in route.read_text():
        assert sha(route.read_bytes()) == original_route_sha
        route.write_text(route.read_text().replace(new, old, 1))
    else:
        reconstructed = route.read_text().replace(old, new, 1).encode()
        assert sha(reconstructed) == original_route_sha, 'unexpected_route_overlay'
    row['github_sha256'] = row['sha256']
    row['sha256'] = sha(route.read_bytes())
    manifest['overlay_note'] = 'Preserved live Hermes action list; added only four Google discovery routes.'
    assert not list((ROOT / 'bootstrap/cache').glob('routes*.php')), 'route_cache_requires_separate_activation'
    for row in manifest['files']:
        assert signature(ROOT / row['path']) == row['before_sha256'], 'live_baseline_changed:' + row['path']
        assert signature(stage / row['path']) == row['sha256'], 'staged_source_changed'
    compiler = stage / 'lint-blade.php'
    compiler.write_text('<?php require $argv[1]."/vendor/autoload.php"; $app=require $argv[1]."/bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); file_put_contents($argv[4],app("blade.compiler")->compileString(file_get_contents($argv[3])));')
    for index, row in enumerate(manifest['files']):
        path = stage / row['path']
        if row['path'].endswith('.blade.php'):
            compiled = stage / ('lint-' + str(index) + '.php')
            subprocess.run([PHP, '-d', 'display_errors=stderr', str(compiler), str(ROOT), str(stage), str(path), str(compiled)], check=True, capture_output=True, timeout=30)
            path = compiled
        subprocess.run([PHP, '-l', str(path)], check=True, capture_output=True)
    installed = []
    try:
        ordered = sorted(manifest['files'], key=lambda r: (0 if r['before_sha256'] is None else 3 if r['path'].startswith('routes/') else 2 if '/Controllers/' in r['path'] or r['path'].endswith('index.blade.php') else 1))
        for row in ordered:
            target = ROOT / row['path']
            assert signature(target) == row['before_sha256'], 'source_changed_during_install'
            assert target.parent.is_dir()
            atomic(target, (stage / row['path']).read_bytes(), row['before_mode'])
            installed.append(row)
            assert signature(target) == row['sha256'], 'after_hash_mismatch'
        for row in installed:
            assert signature(ROOT / row['path']) == row['sha256']
    except BaseException:
        for row in reversed(installed):
            target = ROOT / row['path']
            assert signature(target) == row['sha256'], 'newer_change_blocks_rollback'
            if row['before_sha256'] is not None:
                backup = (RECEIPTS / 'before' / row['path']).read_bytes()
                assert sha(backup) == row['before_sha256']
                atomic(target, backup, row['before_mode'])
            else:
                # Keep any newly installed file as evidence outside the active application.
                os.replace(target, stage / ('rollback-new-' + str(manifest['files'].index(row))))
        raise
    (RECEIPTS / 'installed.json').write_text(json.dumps(manifest, indent=2))
    os.chmod(RECEIPTS / 'installed.json', 0o600)
    print('INSTALL_OK', len(installed), 'files; PHP/Blade lint and destination hashes verified', flush=True)
    print('PRESERVED live Hermes action list; no shared cache flush; no migration', flush=True)
    assert locks


if __name__ == '__main__':
    main()
