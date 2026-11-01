#!/usr/bin/env python3
"""Retire only the reviewed denied primary into private storage; verify HTTP 404 separately."""
import hashlib
import json
import os
from pathlib import Path

HOME=Path('/home/servernetcloud')
DIRECTORY=HOME/'public_html/_seo_blog_authors_20261101'
PRIMARY=DIRECTORY/'activate.php'
PRIVATE=HOME/'.seo-blog-authors-20261101'
RETIRED=PRIVATE/'retired-primary'
EXPECTED='f624f46d7e66d64e2366d38ef05f207d3f8e7179e79d197bc6f4cbe3a23d7794'
try:
    if os.getuid()==0 or Path.home()!=HOME or DIRECTORY.resolve()!=DIRECTORY:
        raise RuntimeError('unexpected_account_or_path')
    if PRIVATE.resolve()!=PRIVATE or PRIVATE.is_symlink() or PRIVATE.stat().st_uid!=os.getuid() or RETIRED.exists() or RETIRED.is_symlink():
        raise RuntimeError('private_destination_invalid_or_present')
    if {p.name for p in DIRECTORY.iterdir()}!={'activate.php','.htaccess'}:
        raise RuntimeError('unrelated_files_prevent_cleanup')
    for path in [DIRECTORY,PRIMARY,DIRECTORY/'.htaccess']:
        if path.is_symlink() or path.lstat().st_uid!=os.getuid():
            raise RuntimeError('unexpected_owner_or_symlink')
    if hashlib.sha256(PRIMARY.read_bytes()).hexdigest()!=EXPECTED or (DIRECTORY/'.htaccess').read_bytes()!=b'Require all denied\n':
        raise RuntimeError('temporary_file_drift')
    os.rename(DIRECTORY,RETIRED)
    print(json.dumps({'status':'retired_to_private_stage','public_http_404_verified':False,'private_backups_retained':True}))
except Exception as error:
    print(json.dumps({'status':'stopped','reason':str(error) if isinstance(error,RuntimeError) else 'inspect_private_cleanup_status'}))
    raise SystemExit(1)
