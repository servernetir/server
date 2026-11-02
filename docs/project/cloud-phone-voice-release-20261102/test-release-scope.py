#!/usr/bin/env python3
"""Static scope policies only. Native PHP rehearsal is a separate command."""
from pathlib import Path
root=Path(__file__).parent
engine=(root/'engine.php').read_text(); launcher=(root/'launcher.php.template').read_text()
checks={
 'fixed release': "private const RELEASE = 'cloud-phone-voice-20261102'" in engine,
 'fixed apk': engine.count("'public_html/downloads/servernet-voice/ServerNet-Voice-0.1.0-preview.apk'")==1,
 'private binary sources': "^sources/[A-Za-z0-9]" in engine and "stream_copy_to_stream($in, $out)" in engine,
 'binary checksum': "copy_hash_mismatch" in engine and "copy_source_drift" in engine,
 'no apk buffering': "file_get_contents(self::STAGE.'/candidates/file-'" not in engine,
 'config cache stop': "configuration_cached" in engine and "configurationIsCached" in engine,
 'only solution config': "unset($prior['cloud-phone'], $next['cloud-phone'])" in engine and "other_solution_changed" in engine,
 'three shared locks': all(x in engine for x in ('.servernet-web-release.lock','servernet-release.lock','.codex-web-deploy.lock')),
 'scoped cache keys': "['solutions/cloud-phone', 'en/solutions/cloud-phone', 'tr/solutions/cloud-phone']" in engine,
 'no broad reset': all(x not in engine for x in ('opcache_reset(', 'Cache::flush(', 'Artisan::call(', 'shell_exec(')),
 'fixed auth and csrf': "->middleware(['web', 'auth:web', 'admin'])" in launcher and 'csrf_field()' in launcher,
 'no HTTP commands': all(x not in launcher for x in ("input('command')", "input('path')", "input('content')")),
 'engine payload pins': all(x in launcher for x in ('CPV_ENGINE_SHA256','CPV_PAYLOAD_SHA256',"hash_file('sha256', $engine)","hash_file('sha256', $payload)")),
 'rollback CAS': 'rollback_concurrent_drift' in engine and 'rollback_newer_file_rejected' in engine,
}
for name,ok in checks.items():
 if not ok: raise SystemExit('FAILED: '+name)
print(f'{len(checks)} static release scope checks passed; native PHP tests not implied.')
