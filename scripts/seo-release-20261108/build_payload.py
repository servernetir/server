#!/usr/bin/env python3
"""Build an offline payload from only five reviewed runtime commit deltas."""
from __future__ import annotations

import argparse
import difflib
import importlib.util
import json
from pathlib import Path
import subprocess

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('seo_release', HERE / 'release.py')
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)
COMMITS = (
    '9b31f79a516970773d4ed9b3c6136c792317e8ab',
    '00d9c94bf205cd7d0fb16fa9eeff05c2494399e7',
    'c5b42391ec74490b9d1a3d9cb1fc4e17fce340cc',
    '2fd0c2168626e63b083a76bb04093841be91960a',
    'f1ef751cd00a57adda8fd028929dcdfca70fd479',
)
TARGET = 'ca7184db35e2ae5b0ba1504a368b795956b118fc'
INVENTORY = 'docs/seo/evidence/release-inventory-2026-11-08.json'


def git(repo, *arguments):
    return subprocess.check_output(['git', '-C', str(repo), *arguments], stderr=subprocess.DEVNULL)


def blob(repo, commit, path):
    result = subprocess.run(['git', '-C', str(repo), 'show', commit + ':' + path], capture_output=True)
    return result.stdout if result.returncode == 0 else None


def hunks(before: bytes, after: bytes, context=3):
    a = before.decode().splitlines(keepends=True)
    b = after.decode().splitlines(keepends=True)
    groups = difflib.SequenceMatcher(None, a, b, autojunk=False).get_grouped_opcodes(context)
    result = []
    for group in groups:
        old = ''.join(a[group[0][1]:group[-1][2]])
        new = ''.join(b[group[0][3]:group[-1][4]])
        core.insist(old and new and old != new, 'coalesced_anchor_empty_or_noop')
        result.append({'old': old, 'new': new})
    core.insist(result, 'source_delta_empty')
    merged, _, _ = core.apply_hunks(before, result)
    core.insist(merged == after, 'source_delta_replay_mismatch')
    return result


def build(repo: Path):
    inventory_bytes = git(repo, 'show', TARGET + ':' + INVENTORY)
    inventory = json.loads(inventory_bytes)
    expected = {'website/' + path for path in core.INVENTORY_SOURCES}
    core.insist({row['source'] for row in inventory['files']} == expected
                and inventory['file_count'] == 33, 'reviewed_inventory_scope_invalid')
    inventory_hashes = {row['source'][8:]: row['sha256'] for row in inventory['files']}
    baselines, candidates, changes = {}, {}, {}
    for commit in COMMITS:
        core.insist(git(repo, 'rev-parse', commit).decode().strip() == commit, 'reviewed_commit_missing')
        changed = set(git(repo, 'diff-tree', '--no-commit-id', '--name-only', '-r', commit).decode().splitlines())
        for source in core.SOURCES:
            path = 'website/' + source
            if path not in changed:
                continue
            before, after = blob(repo, commit + '^', path), blob(repo, commit, path)
            core.insist(after is not None, 'curated_source_deleted')
            if source not in baselines:
                baselines[source], candidates[source], changes[source] = before, before, []
            current = candidates[source]
            if before is None:
                core.insist(current is None, 'curated_new_source_already_exists')
                candidates[source] = after
            else:
                core.insist(current is not None, 'curated_base_missing')
                replay_error = None
                for context in (3, 2, 1):
                    try:
                        delta = hunks(before, after, context)
                        candidate, _, _ = core.apply_hunks(current, delta)
                        candidates[source] = candidate
                        replay_error = None
                        break
                    except core.Stop as error:
                        replay_error = error
                if replay_error:
                    raise core.Stop('curated_replay_requires_review:' + source) from replay_error
            changes[source].append(commit)
    core.insist(set(candidates) == set(core.SOURCES), 'curated_inventory_incomplete')
    rows = []
    for source in core.SOURCES:
        before, after = baselines[source], candidates[source]
        current_tree = blob(repo, TARGET, 'website/' + source)
        core.insist(current_tree is not None, 'reviewed_target_source_missing')
        if source in inventory_hashes:
            core.insist(core.sha(current_tree) == inventory_hashes[source], 'target_inventory_hash_mismatch')
        row = {'source': source, 'destination': core.destination(source),
               'curated_commits': changes[source],
               'curated_before_sha256': core.sha(before) if before is not None else None,
               'curated_after_sha256': core.sha(after),
               'reviewed_tree_sha256': core.sha(current_tree)}
        if before is None:
            row.update(kind='new', content=after.decode())
        else:
            row.update(kind='patch', hunks=hunks(before, after))
        # The live preflight explicitly found this pre-existing Git dependency absent.
        # Only this full reviewed file is allowed as an absent-source fallback.
        if source == 'app/Services/PublicPageStyles.php':
            core.insist(after == current_tree, 'absent_dependency_not_exact_reviewed_tree')
            row['absent_dependency_content'] = current_tree.decode()
        if source == 'routes/web.php':
            core.insist(changes[source] == [COMMITS[-1]] and len(row['hunks']) == 1,
                        'route_scope_not_single_reviewed_alias')
            core.insist("'/ساخت-اپلیکیشن-موبایل-در-ارومیه' => '/urmia/app-development'," in row['hunks'][0]['new'],
                        'route_alias_missing')
        rows.append(row)
    payload = {'version': 1, 'release': core.RELEASE, 'home': '/home/servernetcloud',
               'account_uid': 1020, 'target_commit': TARGET, 'curated_commits': list(COMMITS),
               'inventory_sha256': core.sha(inventory_bytes), 'files': rows,
               'helpers': {name: core.sha((HERE / name).read_bytes())
                           for name in ('release.py', 'compile-blade.php')},
               'scope_note': 'Only curated hunks or exact new/explicitly absent dependency bytes; preserve other live differences.'}
    core.validate_payload(payload)
    return payload


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=HERE.parents[1])
    parser.add_argument('--output', type=Path, default=HERE / 'payload.json')
    args = parser.parse_args()
    payload = build(args.repo.resolve())
    data = core.canonical(payload) + b'\n'
    args.output.write_bytes(data)
    print(json.dumps({'release': core.RELEASE, 'files': len(payload['files']),
                      'new_files': sum(row['kind'] == 'new' for row in payload['files']),
                      'coalesced_hunks': sum(len(row.get('hunks', [])) for row in payload['files']),
                      'payload_sha256': core.sha(data), 'target_commit': TARGET}, sort_keys=True))


if __name__ == '__main__':
    main()
