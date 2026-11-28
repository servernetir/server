"""Read-only local admission diagnostics for an existing replacement resource.

This is not an adoption operation. In particular, it must never synthesize a
CREATE intent or a provider receipt for a resource purchased outside the
controller. The live provider and guest acceptance checks remain independent.
"""
from __future__ import annotations

import argparse
from contextlib import closing
import json
import os
from pathlib import Path
import re
import sqlite3
import stat

from .gateway import private_file, private_state_dir


SCHEMA = 'servernet.relay.candidate-admission.v1'


def _db(path: Path):
    info = path.lstat()
    if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid()
            or info.st_mode & 0o077):
        raise ValueError('private_journal_required')
    return sqlite3.connect(path.as_uri() + '?mode=ro', uri=True, timeout=0)


def inspect_candidate(config: dict, expected_provider_id: str) -> dict:
    """Inspect only existing local journal records; never authorize effects."""
    if not isinstance(expected_provider_id, str) or not re.fullmatch(r'[1-9][0-9]{0,19}', expected_provider_id):
        raise ValueError('invalid_candidate_provider_id')
    root = private_state_dir(Path(config['state_dir']))
    with closing(_db(root / 'controller.sqlite3')) as controller:
        controller.execute('BEGIN')
        row = controller.execute('SELECT body FROM state WHERE id=1').fetchone()
        if row is None:
            raise ValueError('controller_state_missing')
        state = json.loads(row[0])
        active = state['active']
        candidate = state.get('candidate')
        pending = controller.execute('SELECT 1 FROM actions WHERE receipt IS NULL LIMIT 1').fetchone() is not None
        blockers = []
        if config.get('policy', {}).get('enabled') is not True:
            blockers.append('POLICY_DISABLED')
        if config.get('production_driver', {}).get('enabled') is not True:
            blockers.append('PRODUCTION_DRIVER_DISABLED')
        if config.get('restore_workflow', {}).get('enabled') is not True:
            blockers.append('RESTORE_WORKFLOW_DISABLED')
        if state.get('phase') != 'VALIDATING' or pending or state.get('pending_action') is not None:
            blockers.append('CONTROLLER_NOT_QUIESCENT_FOR_RESTORE')
        if str(active['provider_id']) == expected_provider_id:
            blockers.append('EXPECTED_CANDIDATE_IS_ACTIVE_SOURCE')
        candidate_matches = (isinstance(candidate, dict)
                             and str(candidate.get('provider_id')) == expected_provider_id)
        if candidate is None:
            blockers.append('CANDIDATE_NOT_REGISTERED')
        elif not candidate_matches:
            blockers.append('CANDIDATE_ID_MISMATCH')

        # Reading the existing provider ledger in ro mode avoids constructing
        # ProviderEffects/ProviderStore (which can initialize a database).
        record = None
        path = root / 'provider-effects.sqlite3'
        if path.exists() or path.is_symlink():
            with closing(_db(path)) as provider:
                item = provider.execute('SELECT body FROM resources WHERE id=?', (expected_provider_id,)).fetchone()
                if item is not None:
                    record = json.loads(item[0])
        if record is None:
            blockers.append('PROVIDER_RECORD_MISSING')
        record_matches = (isinstance(record, dict) and str(record.get('provider_id')) == expected_provider_id
                          and candidate_matches and record.get('generation') == candidate.get('generation'))
        if record is not None and not record_matches:
            blockers.append('PROVIDER_RECORD_SCOPE_UNVERIFIED')
        intent = (record.get('labels') or {}).get('sn-intent') if isinstance(record, dict) else None
        create = None
        if isinstance(intent, str) and re.fullmatch('[a-f0-9]{40}', intent):
            create = controller.execute('SELECT body,receipt FROM actions WHERE id=? AND kind=?', (intent, 'CREATE')).fetchone()
        has_create = create is not None and create[1] is not None
        if not has_create:
            blockers.append('COMMITTED_CREATE_PROVENANCE_MISSING')
        # Even a complete set of local records is not evidence of live provider
        # labels, isolation, durable fencing, backup, or application readiness.
        return {'schema': SCHEMA, 'read_only': True, 'authorizes_effects': False,
                'live_acceptance_verified': False,
                'candidate_provider_id': expected_provider_id, 'candidate_matches_core': candidate_matches,
                'provider_record_exists': record is not None, 'provider_record_scope_matches': record_matches,
                'local_create_receipt_exists': has_create, 'blockers': blockers}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True, type=Path)
    parser.add_argument('--candidate-provider-id', required=True)
    args = parser.parse_args()
    try:
        result = inspect_candidate(json.loads(private_file(args.config)), args.candidate_provider_id)
        print(json.dumps(result, sort_keys=True))
        return 0
    except Exception:
        print(json.dumps({'schema': SCHEMA, 'read_only': True, 'authorizes_effects': False,
                          'error': 'candidate_admission_unavailable'}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
