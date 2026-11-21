#!/usr/bin/env python3
"""Stage only reviewed blog/discovery sources; never boot the live app or mutate it."""
import concurrent.futures
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import urllib.error
import urllib.request

HOME = Path('/home/servernetcloud')
ROOT = HOME / 'servernet_app'
RECEIPTS = HOME / 'deploy-backups/blog-20261121'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'
SOURCE = 'e2350091d7dc18617a410f9025d053c836f776a7'
BASE = 'e6538b2a3ca4640780a540f6a4e09c8bb986f5e3'
TARGETS = json.loads('[{"path": "app/Console/Commands/PublishDailyBlog.php", "sha256": "eaaf520a1ab129dbfda5f091f62252af39b013de0073da751142245a2483dda7"}, {"path": "app/Http/Controllers/Admin/GrowthController.php", "sha256": "e1a933985263215ff726bfc2c8f31634bea6e60fd30a0dafafa1697c9d3b3369"}, {"path": "app/Services/Growth/DailyBlogArticle.php", "sha256": "1340a90cb2d34af2926a1c8bc52ea791c703e7b2d1bfcd2eeb9fa18b6288d84a"}, {"path": "app/Services/Growth/DailyBlogPublisher.php", "sha256": "e99f8b3b0e9fc63376faeb88dac8b2deda08cada08fa334483c6221ab453bd75"}, {"path": "app/Services/Growth/DailyBlogSources.php", "sha256": "ad8228ee4f8753abd9d12ab6984a2ebc9d3c9e6cd717bedee56e5feb203735e7"}, {"path": "app/Services/Growth/GrowthClaims.php", "sha256": "0f49af73b8b1744d3479263b298ffc5d7a3e536b5aa390810c63c5b31c38d9a6"}, {"path": "config/daily_blog.php", "sha256": "723b76c04aa98a0f1a7719a73480fa3da839b803ce63e4d8c15869d7a6e0d950"}, {"path": "resources/views/admin/growth/index.blade.php", "sha256": "7ff7d4f0cae2b638b1746db7fef638fe5df11ed1e6aefdc6915e014f994e3640"}, {"path": "resources/views/admin/growth/daily-blog.blade.php", "sha256": "c1f7a8b75eb75e44c37d35655e3ea91c009ece00bbb1af8f74a7d6f4bb9e276e"}, {"path": "lang/fa/daily-blog.php", "sha256": "5778731ad898c0d896bd46f289589abea595a1cab15955317e1b120821bda7a1"}, {"path": "lang/en/daily-blog.php", "sha256": "ec6f77f2ec4e1c9134f649c6751cdcdc4bba60c83e865edd43f419e1155f1bc4"}, {"path": "lang/tr/daily-blog.php", "sha256": "b3ccd062ee59b3d041656d209d0f80afd828acca2bfd5ceb2f0098b939ea5c5c"}, {"path": "app/Services/Growth/SearchConsoleClient.php", "sha256": "93e7c1d7e5e31a419dbff2c5b8b1288c5a0126a561124e8bd7f17890a7be5ebd"}, {"path": "app/Services/Growth/GoogleDiscovery.php", "sha256": "0b6c81549825dcfae8629b47e9fdacae7d00c40e19eaf4a4e1014a07555eb007"}, {"path": "app/Console/Commands/GoogleDiscoverBlog.php", "sha256": "d5aaefa685f554a4207a4e7531214cd297c775f85f93e07486acaf2607fd22a1"}, {"path": "app/Http/Controllers/Admin/SearchConsoleController.php", "sha256": "eef565f139021f991df5e91a9e54ddf511fddb2d3a7289a5e84abb95acfef6d2"}, {"path": "app/Http/Controllers/Admin/CalendarController.php", "sha256": "e7c444f5617b238ce2a86ff269258864b96b367073f89177d7183a631dc5bb56"}, {"path": "routes/ai-growth.php", "sha256": "ce114122af6b85952156eff8ebc4f576e9eb13e3983906a7ff1b96a42a0863a2"}, {"path": "resources/views/admin/growth/google-discovery.blade.php", "sha256": "af546726ba7e19b028f745d5e2b0b3fdebf1055212a62028f8b65ed4fcbc6eb9"}, {"path": "lang/fa/google-discovery.php", "sha256": "0d8d14d9ef6bb1315cc169bfe28116b49237d4935ae5ab85b8fa1927e68e1571"}, {"path": "lang/en/google-discovery.php", "sha256": "e5c1d142b6ec843d336062e32fffe622209d4df0621bde1c7c7d272e883bee0e"}, {"path": "lang/tr/google-discovery.php", "sha256": "b57fc5587cf268ac40bb939bb07edd3aaadba462cb76625792da265d9def5aeb"}]')


def sha(data):
    return hashlib.sha256(data).hexdigest()


def fetch(commit, path):
    url = 'https://raw.githubusercontent.com/servernetir/server/' + commit + '/website/' + path
    with urllib.request.urlopen(url, timeout=25) as response:
        return response.read(500000)


def lock_all():
    handles = []
    for name in ('.servernet-web-release.lock', 'servernet-release.lock', '.codex-web-deploy.lock'):
        fd = os.open(HOME / name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        stream = os.fdopen(fd, 'a+b')
        assert os.fstat(fd).st_uid == os.getuid()
        fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        handles.append(stream)
    return handles


def main():
    assert Path.home() == HOME and ROOT.resolve() == ROOT
    assert not RECEIPTS.exists(), 'receipt_already_exists'
    locks = lock_all()
    stage = Path(tempfile.mkdtemp(prefix='servernet-blog-20261121-'))
    os.chmod(stage, 0o700)
    RECEIPTS.mkdir(mode=0o700)
    rows = []

    def prepare(target):
        path = target['path']
        destination = ROOT / path
        assert destination.resolve() == destination, 'symlink_refused'
        candidate = fetch(SOURCE, path)
        assert sha(candidate) == target['sha256'], 'github_source_mismatch'
        staged = stage / path
        staged.parent.mkdir(parents=True, exist_ok=True)
        staged.write_bytes(candidate)
        before = destination.read_bytes() if destination.exists() else None
        mode = destination.stat().st_mode & 0o777 if before is not None else 0o644
        status = 'new' if before is None else 'overlay'
        if before == candidate:
            status = 'current'
        elif before is not None:
            try:
                if before == fetch(BASE, path):
                    status = 'base'
            except urllib.error.HTTPError as error:
                if error.code != 404:
                    raise
        if before is not None:
            backup = RECEIPTS / 'before' / path
            backup.parent.mkdir(parents=True, exist_ok=True)
            backup.write_bytes(before)
            os.chmod(backup, 0o600)
        if path.endswith('.php') and not path.endswith('.blade.php'):
            subprocess.run([PHP, '-l', str(staged)], check=True, capture_output=True)
        return dict(target, before_sha256=sha(before) if before is not None else None,
                    before_mode=mode, status=status)

    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        rows = list(pool.map(prepare, TARGETS))
    manifest = {'source': SOURCE, 'base': BASE, 'root': str(ROOT), 'stage': str(stage), 'files': rows}
    output = RECEIPTS / 'manifest.json'
    output.write_text(json.dumps(manifest, indent=2))
    os.chmod(output, 0o600)
    print('PREVIEW_OK', len(rows), 'files', flush=True)
    print('COUNTS', {status: sum(r['status'] == status for r in rows) for status in ['new', 'base', 'current', 'overlay']}, flush=True)
    print('OVERLAYS', [r['path'] for r in rows if r['status'] == 'overlay'], flush=True)
    print('ROUTE_CACHE', [p.name for p in (ROOT / 'bootstrap/cache').glob('routes*.php')], flush=True)
    print('MANIFEST', output, flush=True)
    assert locks


if __name__ == '__main__':
    main()
