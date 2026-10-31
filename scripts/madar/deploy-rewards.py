#!/usr/bin/env python3
"""Guarded config-only reward update. No application bootstrap, schema or financial writes."""
import hashlib
import importlib.util
from pathlib import Path
import sys

CORE_SHA='00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de'

def load_core():
    path=Path(__file__).with_name('deploy-vpstc.py')
    if path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest()!=CORE_SHA:
        raise RuntimeError('pinned_release_core_mismatch')
    spec=importlib.util.spec_from_file_location('madar_reward_core',path)
    core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
    core.BASE='b4b5b555094c2a5af5717ce6a277eae2efe823c7'
    core.RUNTIME_PATHS=('config/madar.php',)
    core.NEW_PATHS=frozenset()
    return core

if __name__=='__main__':
    try:sys.exit(load_core().main())
    except Exception as e:
        import json
        print(json.dumps({'status':'stopped','reason':str(e) if isinstance(e,RuntimeError) else 'private_release_error'}));sys.exit(1)
