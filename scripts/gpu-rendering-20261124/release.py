#!/usr/bin/env python3
"""Reuse the guarded release engine for the measured GPU rendering fallback."""
import hashlib
import importlib.util
from pathlib import Path
import sys
import urllib.request

ENGINE_COMMIT = '12e2bc6868524b0eaed40ced57d4a9d47c46c994'
ENGINE_PATH = 'scripts/search-growth-20261124/release.py'
ENGINE_SHA256 = '1cf55c18261d113819abbf301ac727c70c055bcf9f12b3aba4fe9b3dedca1dea'
sys.dont_write_bytecode = True


def main():
    with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/'+ENGINE_COMMIT+'/'+ENGINE_PATH, timeout=20) as response:
        source = response.read()
    assert hashlib.sha256(source).hexdigest() == ENGINE_SHA256, 'Release engine changed'
    engine_path = Path(__file__).resolve().parent / 'reviewed-engine.py'
    assert not engine_path.is_symlink()
    if engine_path.exists():
        assert hashlib.sha256(engine_path.read_bytes()).hexdigest() == ENGINE_SHA256
    else:
        with engine_path.open('xb') as handle:
            handle.write(source)
        engine_path.chmod(0o600)
    spec = importlib.util.spec_from_file_location('mail_canonical_engine', engine_path)
    engine = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(engine)
    engine.RELEASE = 'gpu-rendering-20261124'
    engine.PREFIX = 'scripts/'+engine.RELEASE+'/'
    engine.main()


if __name__ == '__main__':
    main()
