#!/usr/bin/env python3
"""Build the fixed nine-path IR payload offline; never install or activate it."""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

from proof import Stop, apply_hunks, canonical, insist, sha, source_hunks

HERE = Path(__file__).resolve().parent
RELEASE = 'seo-iran-release-20261108'
BASE = 'ca7184db35e2ae5b0ba1504a368b795956b118fc'
CURATED = 'b6e9206c56710587fa9c1d1c004cec5e410ad7dc'
TARGET = 'b687f92a3f1ef300d1a8ae6f4890ec3ea5bdbc88'
TARGET_PARENT = 'ca4ef0829189d29c9f45e04dfa7f6fb8372df5ff'
MAIN_HELPER_SHA256 = 'd74869fc16fc470ba9446b92892d785950f710b9312ae1a622f2e1455e1a405d'
COMPILER_SHA256 = '15c41d553785c0bd26fff98cb62581f87ed4dd9ecca48abc2dde9d62c8f1b910'
ACTIVATION_SHA256 = 'ef5477b40b55ddda97181cb2b1d3d6d9c40dd6dca94674148c5b569fefd30216'
NEW_SOURCE = 'app/Services/Cloud/CountryVpsEligibility.php'
SOURCES = (
    'app/Http/Controllers/CountryVpsController.php',
    'app/Http/Controllers/SiteController.php',
    'app/Services/Cloud/CountryLandingSeo.php',
    NEW_SOURCE,
    'lang/en/country-seo.php',
    'lang/fa/country-seo.php',
    'lang/tr/country-seo.php',
    'resources/views/pages/vps-country.blade.php',
    'resources/views/partials/vps-plan-table.blade.php',
)
CURATED_NON_RUNTIME = (
    'docs/seo/README.md',
    'docs/seo/iran-marketing-2026-11-08.md',
    'website/tests/Feature/IranCountryMarketingTest.php',
)
NON_RUNTIME = ('.github/workflows/seo-commercial-growth.yml',) + CURATED_NON_RUNTIME + (
    'website/tests/Feature/HourlyVpsPageTest.php',
)


class GitRepository:
    """Fixed-object Git reads only; never read mutable checkout source bytes."""
    def __init__(self, path: Path):
        self.path = path

    def read(self, *args) -> bytes:
        environment = dict(os.environ, GIT_NO_LAZY_FETCH='1', GIT_TERMINAL_PROMPT='0')
        result = subprocess.run(['git', '-C', str(self.path), *args], capture_output=True, env=environment)
        insist(result.returncode == 0, 'git_read_failed')
        return result.stdout

    def resolve(self, commit: str) -> str:
        return self.read('rev-parse', '--verify', commit + '^{commit}').decode().strip()

    def parents(self, commit: str) -> list[str]:
        return self.read('show', '-s', '--format=%P', commit).decode().split()

    def changed_paths(self, target: str) -> set[str]:
        return set(self.read('diff', '--no-renames', '--name-only', BASE, target)
                   .decode().splitlines())

    def blob(self, commit: str, path: str) -> bytes | None:
        environment = dict(os.environ, GIT_NO_LAZY_FETCH='1', GIT_TERMINAL_PROMPT='0')
        result = subprocess.run(['git', '-C', str(self.path), 'show', commit + ':' + path],
                                capture_output=True, env=environment)
        return result.stdout if result.returncode == 0 else None


def validate_scope(scope: dict):
    insist(scope.get('version') == 1 and scope.get('runtime_file_count') == 9
           and scope.get('new_file_count') == 1, 'scope_counts_invalid')
    insist(scope.get('base_commit') == BASE and scope.get('target_commit') == TARGET
           and scope.get('curated_commit') == CURATED,
           'scope_commit_invalid')
    insist(scope.get('release') == RELEASE and scope.get('runtime_only') is True,
           'scope_identity_invalid')
    rows = scope.get('files', [])
    insist([row.get('source') for row in rows] == list(SOURCES), 'scope_paths_invalid')
    insist(scope.get('excluded_commit_paths') == list(NON_RUNTIME), 'excluded_paths_invalid')
    for row in rows:
        insist(row.get('destination') == 'servernet_app/' + row['source'], 'scope_destination_invalid')
        insist(row.get('kind') == ('new' if row['source'] == NEW_SOURCE else 'patch'), 'scope_kind_invalid')
        insist(re.fullmatch('[0-9a-f]{64}', row.get('after_sha256', '')), 'scope_after_hash_invalid')
        before = row.get('before_sha256')
        insist((before is None) if row['source'] == NEW_SOURCE
               else isinstance(before, str) and re.fullmatch('[0-9a-f]{64}', before), 'scope_before_hash_invalid')


def verified_helpers() -> dict:
    """Prove the copied core differs only in release, static scope and stage prefix."""
    origin = json.loads((HERE / 'installer-origin.json').read_bytes())
    insist(origin.get('original_sha256') == MAIN_HELPER_SHA256, 'installer_origin_invalid')
    expected_new = ["RELEASE = '" + RELEASE + "'",
                    'SOURCES = (\n' + ''.join('    ' + repr(s) + ',\n' for s in SOURCES) + ')\n',
                    r'\.seo-iran-release-20261108-[a-z0-9-]+']
    changes = origin.get('changes', [])
    insist(len(changes) == 3 and [row.get('new') for row in changes] == expected_new,
           'installer_derivation_scope_invalid')
    insist(changes[0].get('old') == "RELEASE = 'seo-release-20261108'"
           and changes[1].get('old', '').startswith('INVENTORY_SOURCES = (\n')
           and changes[1]['old'].endswith("SOURCES = INVENTORY_SOURCES + ('routes/web.php',)\n")
           and changes[2].get('old') == r'\.seo-release-20261108-[a-z0-9-]+',
           'installer_derivation_anchors_invalid')
    helper = (HERE / 'release.py').read_bytes()
    reversed_changes = [{'old': row['new'], 'new': row['old']} for row in reversed(changes)]
    restored, applied, present = apply_hunks(helper, reversed_changes)
    insist(len(applied) == 3 and not present and sha(restored) == MAIN_HELPER_SHA256,
           'installer_core_drift')
    compiler = (HERE / 'compile-blade.php').read_bytes()
    insist(sha(compiler) == COMPILER_SHA256, 'compiler_copy_drift')
    activation = (HERE / 'activate.php').read_bytes()
    insist(sha(activation) == ACTIVATION_SHA256, 'activation_helper_drift')
    return {'release.py': sha(helper), 'compile-blade.php': sha(compiler), 'activate.php': sha(activation)}


def build(repo, scope: dict | None = None) -> dict:
    scope = json.loads((HERE / 'scope.json').read_bytes()) if scope is None else scope
    validate_scope(scope)
    insist(repo.resolve(BASE) == BASE and repo.resolve(CURATED) == CURATED
           and repo.resolve(TARGET) == TARGET, 'source_commit_invalid')
    insist(repo.parents(CURATED) == [BASE] and repo.parents(TARGET) == [TARGET_PARENT], 'source_parent_invalid')
    insist(repo.changed_paths(TARGET) == {'website/' + source for source in SOURCES} | set(NON_RUNTIME)
           and repo.changed_paths(CURATED) == {'website/' + source for source in SOURCES} | set(CURATED_NON_RUNTIME),
           'source_changed_paths_invalid')
    files = []
    for scoped in scope['files']:
        source = scoped['source']
        before, after = repo.blob(BASE, 'website/' + source), repo.blob(TARGET, 'website/' + source)
        insist(after is not None and sha(after) == scoped['after_sha256'], 'source_after_hash_invalid')
        insist(after == repo.blob(CURATED, 'website/' + source), 'published_runtime_differs_from_curated')
        insist((before is None and scoped['before_sha256'] is None)
               or (before is not None and sha(before) == scoped['before_sha256']), 'source_before_hash_invalid')
        row = {'source': source, 'destination': scoped['destination'], 'kind': scoped['kind'],
               'curated_commits': [CURATED], 'curated_before_sha256': scoped['before_sha256'],
               'curated_after_sha256': sha(after), 'reviewed_tree_sha256': sha(after)}
        if scoped['kind'] == 'new':
            insist(before is None, 'new_source_preexists')
            row['content'] = after.decode()
        else:
            insist(before is not None, 'patch_source_missing')
            row['hunks'] = source_hunks(before, after)
        files.append(row)
    return {'version': 1, 'release': RELEASE,
            'base_commit': BASE, 'target_commit': TARGET, 'curated_commits': [CURATED],
            'home': '/home/servernetcloud', 'account_uid': 1020,
            'scope_sha256': sha(canonical(scope) + b'\n'), 'files': files,
            'helpers': verified_helpers(),
            'builder_helpers': {name: sha((HERE / name).read_bytes()) for name in ('build_payload.py', 'proof.py')},
            'scope_note': 'Nine reviewed runtime paths only; unique reversible hunks preserve unrelated live bytes. '
                          'New Eligibility content must be absent or byte-identical. No absent patch fallbacks.',
            'installer_base_sha256': MAIN_HELPER_SHA256,
            'scope_integration': 'Separate static nine-path helper; unchanged main helper rejects this release. '
                                 'Installation and activation require independent live preflight and acceptance.'}


def write_payload(payload: dict, output: Path) -> bytes:
    path = output.absolute()
    insist(path.is_relative_to(HERE), 'output_outside_proposal_directory')
    cursor = path
    while cursor != HERE:
        insist(not cursor.is_symlink(), 'output_symlink_rejected')
        cursor = cursor.parent
    insist(path.parent.is_dir() and path.parent.resolve() == path.parent
           and path.resolve().is_relative_to(HERE), 'output_parent_invalid')
    insist(not path.exists() or path.is_file(), 'output_not_regular')
    data = canonical(payload) + b'\n'
    fd, temporary = tempfile.mkstemp(prefix='.proposal-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    return data


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=HERE.parents[1])
    parser.add_argument('--output', type=Path, default=HERE / 'payload.json')
    args = parser.parse_args()
    try:
        payload = build(GitRepository(args.repo.resolve()))
        data = write_payload(payload, args.output)
        print(json.dumps({'files': len(payload['files']),
                          'new_files': sum(row['kind'] == 'new' for row in payload['files']),
                          'hunks': sum(len(row.get('hunks', [])) for row in payload['files']),
                          'payload_sha256': sha(data), 'scope_sha256': payload['scope_sha256'],
                          'target_commit': TARGET}, sort_keys=True))
    except Stop as error:
        parser.exit(1, str(error) + '\n')


if __name__ == '__main__':
    main()
