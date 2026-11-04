#!/usr/bin/env python3
"""One pinned forced-SSH entry point for backup reads and signed lifecycle ops.

Install the forced command as::
 /usr/bin/python3 /usr/local/lib/servernet-relay-recovery/lifecycle_cli.py \
     --config /etc/servernet/relay-recovery-side/config.json

SSH_ORIGINAL_COMMAND is deliberately ignored. Read stdin once, at most16KiB.
The existing unsigned inspect/status/export/restore-plan protocol keeps working;
unsigned legacy fence is disabled. Lifecycle requests must carry their distinct
schema and pass lifecycle_side's fresh scoped HMAC validation. Both configs use
fixed root-private local paths; no path, command or unit comes from the request.
"""
from __future__ import annotations

import os
import shutil
import sys

try:
    from . import lifecycle_side as lifecycle, relay_side
except ImportError:
    import lifecycle_side as lifecycle
    import relay_side

BASE_CONFIG = '/etc/servernet/relay-recovery-side/config.json'


def dispatch(raw: bytes, output) -> int:
    request = lifecycle.decode(raw)  # Reject duplicate keys before selecting a protocol.
    if 'schema' in request:
        if request['schema'] != lifecycle.REQUEST_SCHEMA:
            raise lifecycle.LifecycleError('request_schema')
        result = lifecycle.Lifecycle(lifecycle.load_config()).run(request)
        output.write(lifecycle.canonical(result)+b'\n')
        output.flush()
        return 0 if result['ok'] else 2
    config = relay_side.load_config(BASE_CONFIG)
    # Snapshot adoption changes the trusted identity, while the source inventory
    # file intentionally remains unchanged. Never publish its old generation.
    identity_path = lifecycle.Path(lifecycle.guard.DEFAULT_CONFIG)
    if identity_path.exists() or identity_path.is_symlink():
        identity = lifecycle.guard.load_identity(identity_path)
        config = {**config, 'generation': identity['generation'],
                  'config_hash': identity['config_hash']}
    request = relay_side.request_from_bytes(raw, config)
    op = request['op']
    if op not in {'inspect', 'status', 'export', 'restore-plan'}:
        raise lifecycle.LifecycleError('unsigned_mutation_disabled')
    side = relay_side.RelaySide(config)
    if op == 'export':
        # relay_side produces a complete private artifact before stdout opens.
        with side.export_artifact(request['request_id']) as (path, _):
            with path.open('rb') as source:
                shutil.copyfileobj(source, output, length=1024*1024)
        output.flush()
        return 0
    result = (side.restore_plan(request['request_id']) if op == 'restore-plan'
              else side.inspect(request['request_id']))
    if op == 'status': result['operation'] = 'status'
    body = relay_side.canonical_json(result)
    if len(body) > 1024*1024: raise lifecycle.LifecycleError('response_size')
    output.write(body+b'\n'); output.flush()
    return 0 if result['ok'] else 2


def main(argv=None) -> int:
    argv = sys.argv[1:] if argv is None else argv
    os.umask(0o077)
    try:
        if argv != ['--config', BASE_CONFIG] or os.geteuid() != 0:
            raise lifecycle.LifecycleError('arguments_or_owner')
        return dispatch(sys.stdin.buffer.read(lifecycle.MAX_BODY+1), sys.stdout.buffer)
    except Exception:
        sys.stderr.write('{"ok":false,"error":"relay_operation_precondition_or_outcome_unknown"}\n')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
