#!/usr/bin/env python3
"""Build one exact-preimage hosting overlay without changing other payload rows."""
from __future__ import annotations

import argparse
import copy
import difflib
import importlib.util
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('seo_release_hosting_reconciliation', HERE / 'release.py')
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)
SOURCE = 'resources/views/pages/hosting.blade.php'
BASE_PAYLOAD_SHA256 = '3545d847dd414151ce173d413edb22f1194efc93f2b58e855890ff548861fbe5'
LIVE_SHA256 = 'dd2ab08e4d305016c28ea3e367964a3aa171cb3647bc880f41794d1de19d6f1d'
PREFIX = "@extends('layouts.site')\n\n@php\n"


def reconcile(base: dict, live: bytes):
    core.insist(core.sha(core.canonical(base) + b'\n') == BASE_PAYLOAD_SHA256,
                'hosting_base_payload_not_reviewed')
    core.insist(core.sha(live) == LIVE_SHA256, 'hosting_live_preimage_not_reviewed')
    payload = copy.deepcopy(base)
    row = next(item for item in payload['files'] if item['source'] == SOURCE)
    original = copy.deepcopy(row)
    core.insist(row['kind'] == 'patch' and len(row['hunks']) == 8, 'hosting_original_scope_changed')
    first = row['hunks'][0]
    before, after = first['old'].splitlines(keepends=True), first['new'].splitlines(keepends=True)
    edits = [opcode for opcode in difflib.SequenceMatcher(None, before, after, autojunk=False).get_opcodes()
             if opcode[0] != 'equal']
    core.insist(len(edits) == 1 and edits[0][0] == 'insert', 'hosting_first_delta_is_not_one_insertion')
    _, start, end, new_start, new_end = edits[0]
    core.insist(start == end == 3 and ''.join(before[:start]) == PREFIX,
                'hosting_insertion_anchor_changed')
    addition = ''.join(after[new_start:new_end])
    replacement = PREFIX + addition
    core.insist(first['new'] == first['old'].replace(PREFIX, replacement, 1),
                'hosting_delta_contains_other_edits')
    row['hunks'][0] = {'old': PREFIX, 'new': replacement}
    candidate, applied, present = core.apply_hunks(live, row['hunks'])
    core.insist(applied == list(range(8)) and present == [], 'hosting_reviewed_state_unexpected')
    restored = candidate
    for hunk in reversed(row['hunks']):
        old, new = hunk['old'].encode(), hunk['new'].encode()
        core.insist(restored.count(new) == 1, 'hosting_reverse_anchor_changed')
        restored = restored.replace(new, old, 1)
    core.insist(restored == live, 'hosting_unrelated_live_bytes_changed')
    row['reviewed_before_sha256'] = LIVE_SHA256
    row['reviewed_after_sha256'] = core.sha(candidate)
    payload['helpers'] = {name: core.sha((HERE / name).read_bytes())
                          for name in ('release.py', 'compile-blade.php')}
    core.validate_payload(payload)
    for old_row, new_row in zip(base['files'], payload['files']):
        if old_row['source'] != SOURCE:
            core.insist(core.canonical(old_row) == core.canonical(new_row), 'other_payload_row_changed')
    core.insist(row['hunks'][1:] == original['hunks'][1:], 'other_hosting_hunks_changed')
    restriction = b'$iranPurchaseRestricted = in_array('
    core.insist(live.count(restriction) == candidate.count(restriction) == 1,
                'iran_restriction_not_preserved')
    diff = list(difflib.unified_diff(live.decode().splitlines(), candidate.decode().splitlines(),
                                   fromfile='reviewed-live-hosting', tofile='guarded-hosting-candidate'))
    report = {'release': core.RELEASE, 'source': SOURCE,
              'reviewed_before_sha256': LIVE_SHA256, 'reviewed_after_sha256': core.sha(candidate),
              'before_bytes': len(live), 'after_bytes': len(candidate),
              'hosting_hunks': 8, 'hosting_hunks_reanchored': [0],
              'other_hosting_hunks_unchanged': 7, 'other_payload_rows_unchanged': len(payload['files']) - 1,
              'reverse_byte_proof': True, 'iran_purchase_restriction_preserved': True,
              'added_lines': sum(line.startswith('+') and not line.startswith('+++') for line in diff),
              'removed_lines': sum(line.startswith('-') and not line.startswith('---') for line in diff),
              'application_writes': 0,
              'scope': 'The same approved hostingSeo insertion, reanchored before the unchanged live Iran purchase restriction; all seven other approved hosting hunks unchanged.'}
    return payload, report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--live-source', type=Path, required=True)
    parser.add_argument('--base-payload', type=Path, default=HERE / 'payload.json')
    parser.add_argument('--output', type=Path, default=HERE / 'payload-live-v2.json')
    parser.add_argument('--report', type=Path, default=HERE / 'hosting-reconciliation-v2.json')
    args = parser.parse_args()
    base_bytes = args.base_payload.read_bytes()
    core.insist(core.sha(base_bytes) == BASE_PAYLOAD_SHA256, 'hosting_base_payload_bytes_changed')
    payload, report = reconcile(json.loads(base_bytes), args.live_source.read_bytes())
    data = core.canonical(payload) + b'\n'
    args.output.write_bytes(data)
    report.update(base_payload_sha256=core.sha(base_bytes), payload_sha256=core.sha(data), helpers=payload['helpers'])
    args.report.write_text(json.dumps(report, indent=2, sort_keys=True) + '\n')
    print(json.dumps(report, sort_keys=True))


if __name__ == '__main__':
    main()
