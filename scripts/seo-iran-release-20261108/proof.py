"""Pure, reversible byte proofs for a proposal; no filesystem or installer API."""
from __future__ import annotations

import difflib
import hashlib
import json


class Stop(RuntimeError):
    pass


def insist(condition, reason):
    if not condition:
        raise Stop(reason)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical(data) -> bytes:
    return json.dumps(data, sort_keys=True, separators=(',', ':'), ensure_ascii=True).encode()


def normalized(data: bytes) -> tuple[bytes, bytes]:
    value = data.replace(b'\r\n', b'\n')
    insist(b'\r' not in value and (b'\r\n' not in data or data.count(b'\r\n') == data.count(b'\n')),
           'mixed_line_endings')
    insist(b'\0' not in value, 'binary_source_rejected')
    return value, b'\r\n' if b'\r\n' in data else b'\n'


def apply_hunks(data: bytes, hunks: list[dict]) -> tuple[bytes, list[int], list[int]]:
    """Use the existing release helper's unique-anchor and reverse-byte rules."""
    original, ending = normalized(data)
    value = original
    applied, present = [], []
    for index, hunk in enumerate(hunks):
        insist(set(hunk) == {'old', 'new'} and isinstance(hunk['old'], str)
               and isinstance(hunk['new'], str), 'invalid_hunk')
        old, new = hunk['old'].encode(), hunk['new'].encode()
        insist(old and new and old != new, 'empty_or_noop_hunk')
        old_count, new_count = value.count(old), value.count(new)
        if new_count == 1 and old_count == 0:
            present.append(index)
            continue
        if old_count == 1 and new_count == 1:
            old_at, new_at = value.index(old), value.index(new)
            if old in new and new_at <= old_at and old_at + len(old) <= new_at + len(new):
                present.append(index)
                continue
            insist(new in old and old_at <= new_at and new_at + len(new) <= old_at + len(old),
                   'ambiguous_hunk_state')
        else:
            insist(old_count == 1 and new_count == 0, 'hunk_anchor_missing_or_duplicate')
        value = value.replace(old, new, 1)
        applied.append(index)
    restored = value
    for index in reversed(applied):
        new, old = hunks[index]['new'].encode(), hunks[index]['old'].encode()
        insist(restored.count(new) == 1, 'reverse_hunk_anchor_not_unique')
        restored = restored.replace(new, old, 1)
    insist(restored == original, 'reverse_byte_proof_failed')
    return (value.replace(b'\n', ending) if ending != b'\n' else value), applied, present


def source_hunks(before: bytes, after: bytes) -> list[dict]:
    """Coalesce reviewed source changes and prove exact reconstruction both ways."""
    old, old_ending = normalized(before)
    new, new_ending = normalized(after)
    insist(old_ending == new_ending == b'\n', 'reviewed_source_must_use_lf')
    a, b = old.decode().splitlines(keepends=True), new.decode().splitlines(keepends=True)
    groups = difflib.SequenceMatcher(None, a, b, autojunk=False).get_grouped_opcodes(3)
    result = [{'old': ''.join(a[group[0][1]:group[-1][2]]),
               'new': ''.join(b[group[0][3]:group[-1][4]])} for group in groups]
    insist(result, 'source_delta_empty')
    candidate, applied, present = apply_hunks(before, result)
    insist(candidate == after and len(applied) == len(result) and not present,
           'source_delta_replay_mismatch')
    reverse = [{'old': hunk['new'], 'new': hunk['old']} for hunk in reversed(result)]
    restored, reversed_applied, reversed_present = apply_hunks(after, reverse)
    insist(restored == before and len(reversed_applied) == len(result) and not reversed_present,
           'source_reverse_replay_mismatch')
    return result


def candidate_bytes(current: bytes | None, row: dict) -> bytes:
    """Dry byte calculation only: new files may be absent or exactly identical."""
    if row['kind'] == 'new':
        content = row['content'].encode()
        insist(sha(content) == row['curated_after_sha256'], 'new_source_hash_invalid')
        insist(current is None or current == content, 'new_source_already_divergent')
        return content
    insist(row['kind'] == 'patch' and current is not None, 'patch_source_missing')
    return apply_hunks(current, row['hunks'])[0]
