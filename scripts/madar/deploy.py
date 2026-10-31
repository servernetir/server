#!/usr/bin/env python3
"""Scoped Madar release: private plan/backup, guarded install, two named migrations.

prepare is read-only for app/data. install requires the reviewed plan hash.
No financial campaign, grant, message, scheduler or customer is created.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys

BASE = '0861aab6d5452db065a7e1cbaaa3c9d3cd0bddeb'
CORE_SHA = '00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de'
MIGRATIONS = (
    'database/migrations/2026_10_31_180000_create_madar_participation_hub.php',
    'database/migrations/2026_10_31_190000_publish_fixed_madar_missions.php',
)
NEW = (
    'app/Models/MadarMessage.php', 'app/Models/MadarMission.php', 'app/Models/MadarParticipation.php',
    *('app/Services/Madar/'+n+'.php' for n in ('MadarHub','MadarPublishing','MadarClaims','MadarEvidence','MadarRewards','MadarReview')),
    'config/madar.php', *MIGRATIONS,
    *('lang/'+locale+'/madar.php' for locale in ('fa','en','tr')),
    'public/assets/css/madar.css', 'public/assets/js/madar.js',
    'runtime-assets/assets/css/madar.css', 'runtime-assets/assets/js/madar.js',
    *('resources/views/'+p+'.blade.php' for p in ('madar/feedback','madar/mission','madar/reward','madar/thread',
       'account/madar/index','account/madar/show','admin/madar/index','admin/madar/show')),
    'app/Http/Controllers/Account/MadarController.php','app/Http/Controllers/Admin/MadarController.php',
    'routes/madar-account.php','routes/madar-admin.php',
)
RUNTIME = NEW + (
    'app/Services/Marketing/CampaignManager.php','app/Services/Marketing/DiscountEngine.php',
    'app/Services/Growth/HermesSectionOversight.php','config/hermes_growth.php',
    'app/Http/Controllers/Account/AccountController.php','resources/views/admin/layout.blade.php','routes/web.php',
)


def load_core():
    path = Path(__file__).with_name('deploy-vpstc.py')
    if path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest() != CORE_SHA:
        raise RuntimeError('pinned_core_hash_mismatch')
    spec = importlib.util.spec_from_file_location('madar_scoped_core', path)
    core = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    core.BASE, core.RUNTIME_PATHS, core.NEW_PATHS = BASE, RUNTIME, frozenset(NEW)
    fetch, lint = core.fetch_source, core.lint
    def source(commit, relative):
        return fetch(commit, 'public/'+relative[len('runtime-assets/'):] if relative.startswith('runtime-assets/') else relative)
    def destination(root, relative):
        if relative not in RUNTIME:
            raise core.ReleaseError('path_outside_release_scope')
        if relative.startswith('public/'):
            anchor, tail = root.parent/'public_html', relative[len('public/'):]
        elif relative.startswith('runtime-assets/'):
            anchor, tail = root/'public', relative[len('runtime-assets/'):]
        else:
            anchor, tail = root, relative
        if anchor.is_symlink() or not anchor.is_dir() or anchor.resolve() != anchor:
            raise core.ReleaseError('invalid_destination_root')
        path = anchor/tail
        for cursor in (path, *path.parents):
            if cursor == anchor.parent:
                break
            if cursor.is_symlink():
                raise core.ReleaseError('symlink_in_release_path:'+relative)
        if path.exists() and not path.is_file():
            raise core.ReleaseError('nonregular_release_file:'+relative)
        return path
    core.checked_file = destination
    core.fetch_source = source
    core.lint = lambda php,path,relative: lint(php,path,relative) if relative.endswith('.php') else None
    return core


def php_json(php, code, *args):
    result = subprocess.run([php,'-r',code,*map(str,args)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if result.returncode:
        raise RuntimeError('private_php_step_failed')
    try:
        return json.loads(result.stdout)
    except (ValueError,UnicodeDecodeError) as exc:
        raise RuntimeError('private_php_output_invalid') from exc


BOOT = "require $argv[1].'/vendor/autoload.php';$app=require $argv[1].'/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"


def inspect_database(root, php):
    return php_json(php, BOOT + r'''
$tables=['madar_campaigns','madar_participations','madar_messages','madar_artifacts','madar_missions'];
$names=['2026_10_31_180000_create_madar_participation_hub','2026_10_31_190000_publish_fixed_madar_missions'];
$exists=[];foreach($tables as $t){$exists[$t]=Illuminate\Support\Facades\Schema::hasTable($t);}
echo json_encode(['driver'=>Illuminate\Support\Facades\DB::connection()->getDriverName(),
 'migration_rows'=>Illuminate\Support\Facades\DB::table('migrations')->orderBy('id')->get()->toArray(),
 'madar_tables'=>$exists,'required_migrations'=>$names],JSON_THROW_ON_ERROR);
''', root)


def activation_helper(core, root, release, plan, php):
    paths=[str(core.checked_file(root,row['path'])) for row in plan['files'] if row['path'].endswith('.php')]
    views=[core.checked_file(root,row['path']) for row in plan['files'] if row['path'].endswith('.blade.php')]
    compiled=php_json(php,BOOT+r'''
echo json_encode(array_map(fn($path)=>$app->make('blade.compiler')->getCompiledPath($path),array_slice($argv,2)),JSON_THROW_ON_ERROR);
''',root,*views)
    snapshots=[]
    for index,filename in enumerate(compiled):
        path=Path(filename)
        if not path.is_relative_to(root/'storage/framework/views') or path.is_symlink():
            raise RuntimeError('compiled_view_path_invalid')
        before=path.read_bytes() if path.is_file() else None
        if before is not None:core.private_write(release/'before-compiled'/str(index),before)
        snapshots.append({'path':str(path),'before_sha256':core.digest(before) if before is not None else None})
    php_json(php,BOOT+r'''
foreach(array_slice($argv,2) as $path){$app->make('blade.compiler')->compile($path);}echo json_encode(['compiled'=>true],JSON_THROW_ON_ERROR);
''',root,*views)
    for row in snapshots:
        core.lint(php,Path(row['path']),'compiled-view.php')
        row['after_sha256']=core.digest(Path(row['path']).read_bytes())
    core.private_write(release/'compiled-manifest.json',core.canonical({'files':snapshots}))
    paths+=compiled
    helper_name='servernet-madar-activate-'+plan['target'][:12]+'.php'
    template=Path(__file__).with_name('activation.php.template').read_text()
    values={'__AUTOLOAD__':str(root/'vendor/autoload.php'),'__BOOTSTRAP__':str(root/'bootstrap/app.php'),
            '__HELPER__':helper_name,'__TARGETS__':json.dumps(paths),'__RECEIPT__':str(release/'activation.json')}
    for key,value in values.items():
        template=template.replace(key,value.replace("'","\\'"))
    private=release/'activation.php'
    core.private_write(private,template.encode())
    core.lint(php,private,'activation.php')
    helper=root.parent/'public_html'/helper_name
    if helper.exists() or helper.is_symlink():
        raise RuntimeError('activation_helper_already_exists')
    core.atomic_write(helper,template.encode(),0o644)
    return {'helper_name':helper_name,'helper_sha256':core.digest(template.encode()),'compiled_paths':compiled}


def run():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode',choices=['prepare','install','verify','cleanup'])
    parser.add_argument('--root',required=True);parser.add_argument('--target',required=True)
    parser.add_argument('--php',default='/opt/cpanel/ea-php84/root/usr/bin/php')
    parser.add_argument('--release-dir');parser.add_argument('--plan-sha256')
    args=parser.parse_args();core=load_core();root=core.checked_root(args.root);target=core.validate_sha(args.target)
    with core.release_locks(root):
        if args.mode=='prepare':
            if (root/'bootstrap/cache/config.php').exists():
                raise RuntimeError('config_cache_needs_review_before_install')
            state=inspect_database(root,args.php)
            if state['driver']!='mysql' or any(state['madar_tables'].values()):
                raise RuntimeError('unexpected_existing_madar_schema')
            release,plan=core.build_plan(root,target,args.php,source_reader=core.fetch_source)
            core.private_write(release/'database-before.json',core.canonical(state))
            result={'status':'planned','release_dir':str(release),'plan_sha256':plan['plan_sha256'],
                    'files':len(plan['files']),'changed':sum(row['change'] for row in plan['files']),
                    'preserved_live_paths':[row['path'] for row in plan['files'] if row['preserved_live_drift']],
                    'target':target,'application_writes':0}
        else:
            if not args.release_dir or not args.plan_sha256:
                raise RuntimeError('reviewed_plan_required')
            release=Path(args.release_dir);plan=core.load_plan(root,target,release,args.plan_sha256)
            if args.mode=='install':
                current=inspect_database(root,args.php)
                before=json.loads((release/'database-before.json').read_text())
                if current['madar_tables']!=before['madar_tables'] or any(current['madar_tables'].values()):
                    raise RuntimeError('schema_changed_since_plan')
                if (root/'bootstrap/cache/config.php').exists():
                    raise RuntimeError('config_cache_changed_since_plan')
                core.apply_plan(root,release,plan,args.php)
                # New tables only; exact paths prevent running unrelated pending migrations or seeders.
                migration=php_json(args.php,BOOT+r'''
$results=[];foreach(array_slice($argv,2) as $path){$status=Illuminate\Support\Facades\Artisan::call('migrate',
['--path'=>$path,'--force'=>true,'--no-interaction'=>true]);if($status!==0){throw new RuntimeException('named_migration_failed');}$results[]=$path;}
echo json_encode(['migrations'=>$results,'catalog_ready'=>app(App\Services\Madar\MadarHub::class)->catalogReady(),
 'campaign_controls'=>Illuminate\Support\Facades\DB::table('madar_campaigns')->count(),
 'published_missions'=>Illuminate\Support\Facades\DB::table('madar_missions')->count(),
 'participations'=>Illuminate\Support\Facades\DB::table('madar_participations')->count()],JSON_THROW_ON_ERROR);
''',root,*MIGRATIONS)
                if migration['catalog_ready'] is not True or migration['campaign_controls']!=8:
                    raise RuntimeError('schema_acceptance_failed')
                activation=activation_helper(core,root,release,plan,args.php)
                result={'status':'installed','target':target,'files':sum(row['change'] for row in plan['files']),
                        **migration,**activation,'financial_campaigns_created':0,'activation_pending':True}
                core.private_write(release/'madar-install.json',core.canonical(result))
            else:
                for row in plan['files']:core.assert_live(root,row,installed=True)
                install=json.loads((release/'madar-install.json').read_text())
                activation=json.loads((release/'activation.json').read_text()) if (release/'activation.json').is_file() else None
                if not activation or activation.get('activated') is not True:
                    raise RuntimeError('web_activation_receipt_required')
                if args.mode=='cleanup':
                    helper=root.parent/'public_html'/install['helper_name']
                    if helper.exists():
                        if helper.is_symlink() or core.digest(helper.read_bytes())!=install['helper_sha256']:
                            raise RuntimeError('activation_helper_hash_changed')
                        helper.unlink()
                result={'status':'verified' if args.mode=='verify' else 'verified_and_helper_removed',
                        'target':target,'files':len(plan['files']),'activation':activation}
                if args.mode=='cleanup':core.private_write(release/'madar-final.json',core.canonical(result))
        print(json.dumps(result,sort_keys=True))


if __name__=='__main__':
    try:run()
    except Exception as exc:
        # Server output stays in private files; do not expose SQL, credentials or customer content.
        reason=str(exc) if isinstance(exc,RuntimeError) else type(exc).__name__
        print(json.dumps({'status':'failed','reason':reason}));sys.exit(2)
