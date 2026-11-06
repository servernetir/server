#!/usr/bin/env python3
"""Use the pinned, same-account, HTTP-denied FastCGI activation client."""
import hashlib
import importlib.util
from pathlib import Path
import sys

CORE_SHA='9de8c5a49bcbf6d5353bdc52a129031df22554a8cefa945e65428df7a6a5b23c'

def load_client():
    path=Path(__file__).with_name('fpm-core.py')
    if path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest()!=CORE_SHA:
        raise RuntimeError('pinned_fastcgi_client_mismatch')
    spec=importlib.util.spec_from_file_location('madar_private_fpm',path)
    client=importlib.util.module_from_spec(spec);spec.loader.exec_module(client)
    client.RELEASE='madar-rewards-20261031'
    client.DIRECTORY=client.DOCROOT/'_madar_rewards_release_20261031'
    client.PRIMARY=client.DIRECTORY/'activate.php'
    client.SCRIPT_NAME='/_madar_rewards_release_20261031/activate.php'
    return client

if __name__=='__main__':
    try:sys.exit(load_client().main())
    except Exception as error:
        import json
        print(json.dumps({'status':'stopped','reason':str(error) if isinstance(error,RuntimeError) else 'private_fastcgi_error'}))
        sys.exit(1)
