#!/usr/bin/env python3
"""Exercise n8n export/restore using two new, isolated SQLite homes and fake data."""
import argparse
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
import uuid


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--n8n', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    binary = args.n8n.resolve(strict=True)
    with tempfile.TemporaryDirectory(prefix='servernet-n8n-qa-') as temp:
        root = Path(temp)
        os.chmod(root, 0o700)
        key, password = secrets.token_hex(32), secrets.token_hex(20)
        marker, workflow_id = 'servernet-backup-restore-fixture', 'SeoBackupTest001'
        base = os.environ.copy()
        for name in list(base):
            if name.startswith(('N8N_', 'DB_', 'QUEUE_', 'EXECUTIONS_')) or name == 'WEBHOOK_URL':
                del base[name]
        base.update(N8N_DIAGNOSTICS_ENABLED='false', N8N_VERSION_NOTIFICATIONS_ENABLED='false',
                    N8N_TEMPLATES_ENABLED='false', N8N_ENCRYPTION_KEY=key,
                    N8N_LISTEN_ADDRESS='127.0.0.1', N8N_RUNNERS_BROKER_LISTEN_ADDRESS='127.0.0.1',
                    N8N_LOG_LEVEL='info', DB_TYPE='sqlite', EXECUTIONS_MODE='regular')
        commands = []

        def run(home, *command, encryption_key=None, expect_success=True):
            env = dict(base, N8N_USER_FOLDER=str(root / home))
            if encryption_key is not None:
                env['N8N_ENCRYPTION_KEY'] = encryption_key
            result = subprocess.run([str(binary), *command], cwd=root, env=env,
                                    capture_output=True, text=True, timeout=120)
            commands.append({'home': home, 'command': command[0], 'exit': result.returncode})
            (root / ('command-' + str(len(commands)) + '.log')).write_text(result.stdout + result.stderr)
            if expect_success and result.returncode != 0:
                raise RuntimeError(command[0] + ' failed: ' + (result.stdout + result.stderr)[-1800:])
            return result

        workflow = {'id': workflow_id, 'name': 'ServerNet isolated backup fixture', 'active': False,
                    'versionId': str(uuid.uuid4()), 'settings': {'executionOrder': 'v1'},
                    'nodes': [
                        {'id': str(uuid.uuid4()), 'name': 'Manual', 'type': 'n8n-nodes-base.manualTrigger',
                         'typeVersion': 1, 'position': [0, 0], 'parameters': {}},
                        {'id': str(uuid.uuid4()), 'name': 'Fixture', 'type': 'n8n-nodes-base.set',
                         'typeVersion': 3.4, 'position': [240, 0], 'parameters': {
                             'assignments': {'assignments': [{'id': str(uuid.uuid4()), 'name': 'marker',
                                                             'value': marker, 'type': 'string'}]},
                             'options': {}}}],
                    'connections': {'Manual': {'main': [[{'node': 'Fixture', 'type': 'main', 'index': 0}]]}}}
        seed = root / 'workflow.json'
        seed.write_text(json.dumps([workflow]))
        credentials = root / 'fake-credentials.json'
        credentials.write_text(json.dumps([{'id': 'SeoFakeCred001', 'name': 'Fake test credential only',
            'type': 'httpBasicAuth', 'data': {'user': 'fixture-only', 'password': password}}]))
        os.chmod(credentials, 0o600)
        run('source', 'import:workflow', '--input=' + str(seed))
        run('source', 'import:credentials', '--input=' + str(credentials))
        source_result = run('source', 'execute', '--id=' + workflow_id, '--rawOutput')
        assert marker in source_result.stdout, 'Source workflow did not produce its fixture output'
        workflow_backup, credential_backup = root / 'workflows.json', root / 'credentials.json'
        run('source', 'export:workflow', '--all', '--output=' + str(workflow_backup))
        run('source', 'export:credentials', '--all', '--output=' + str(credential_backup))
        assert password not in credential_backup.read_text(), 'Backup contains a plaintext password'
        assert isinstance(json.loads(credential_backup.read_text())[0]['data'], str)
        run('restore', 'import:workflow', '--input=' + str(workflow_backup))
        run('restore', 'import:credentials', '--input=' + str(credential_backup))
        restored_result = run('restore', 'execute', '--id=' + workflow_id, '--rawOutput')
        assert marker in restored_result.stdout, 'Restored workflow did not produce its fixture output'
        restored_credentials = root / 'restored-fake-credentials.json'
        run('restore', 'export:credentials', '--all', '--decrypted', '--output=' + str(restored_credentials))
        actual = json.loads(restored_credentials.read_text())[0]['data']
        assert actual == {'user': 'fixture-only', 'password': password}
        wrong = run('restore', 'export:credentials', '--all', '--decrypted',
                    '--output=' + str(root / 'wrong-key.json'), encryption_key=secrets.token_hex(32),
                    expect_success=False)
        assert wrong.returncode != 0, 'Wrong encryption key unexpectedly accepted'
        version = run('source', '--version').stdout.strip().splitlines()[-1]
        summary = {'n8n_version': version, 'fixture_only': True, 'external_requests_in_workflow': False,
                   'source_execution': True, 'restored_execution': True, 'encrypted_credential_export': True,
                   'credential_decryption_after_restore': True, 'wrong_key_rejected': True,
                   'production_customer_migration': False, 'commands': commands}
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(summary, indent=2) + '\n')
        print(json.dumps({k: v for k, v in summary.items() if k != 'commands'}))


if __name__ == '__main__':
    main()
