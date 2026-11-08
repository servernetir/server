"""Synthetic byte/scope guards. Temporary writes stay inside this proposal directory."""
from __future__ import annotations

import json
from pathlib import Path
import sys
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import build_payload as builder
from proof import Stop, apply_hunks, candidate_bytes, canonical, sha, source_hunks


class SyntheticRepository:
    def __init__(self):
        self.resolved = {commit: commit for commit in (builder.BASE, builder.CURATED, builder.TARGET)}
        self.parent_list = [builder.BASE]
        self.paths = {'website/' + source for source in builder.SOURCES} | set(builder.NON_RUNTIME)
        self.curated_paths = {'website/' + source for source in builder.SOURCES} | set(builder.CURATED_NON_RUNTIME)
        self.blobs = {}
        self.scope = {'base_commit': builder.BASE, 'target_commit': builder.TARGET,
                      'curated_commit': builder.CURATED, 'release': builder.RELEASE, 'runtime_only': True,
                      'version': 1, 'runtime_file_count': 9, 'new_file_count': 1,
                      'excluded_commit_paths': list(builder.NON_RUNTIME), 'files': []}
        for index, source in enumerate(builder.SOURCES):
            before = None if source == builder.NEW_SOURCE else (
                f'<?php\n// Synthetic {source}\n'.encode() + b"$state = 'old';\n" + b'// retained\n')
            after = (f'<?php\n// Synthetic {source}\n'.encode() + b"$state = 'new';\n" + b'// retained\n')
            self.blobs[(builder.BASE, 'website/' + source)] = before
            self.blobs[(builder.TARGET, 'website/' + source)] = after
            self.blobs[(builder.CURATED, 'website/' + source)] = after
            self.scope['files'].append({'source': source, 'destination': 'servernet_app/' + source,
                                       'kind': 'new' if before is None else 'patch',
                                       'before_sha256': sha(before) if before is not None else None,
                                       'after_sha256': sha(after)})

    def resolve(self, commit):
        return self.resolved[commit]

    def parents(self, commit):
        return self.parent_list if commit == builder.CURATED else [builder.TARGET_PARENT]

    def changed_paths(self, target):
        return self.paths if target == builder.TARGET else self.curated_paths

    def blob(self, commit, path):
        return self.blobs.get((commit, path))


class HunkProofTests(unittest.TestCase):
    def test_preserves_interleaved_live_overlay_and_partial_installation(self):
        before = b'head\nold-a\n' + b'unchanged\n' * 16 + b'old-b\ntail\n'
        after = before.replace(b'old-a', b'new-a').replace(b'old-b', b'new-b')
        hunks = source_hunks(before, after)
        self.assertEqual(len(hunks), 2)
        live = before.replace(b'old-a', b'new-a').replace(b'unchanged\n' * 8,
                                                       b'unchanged\n' * 8 + b'LIVE KYC OVERLAY\n', 1)
        result, applied, present = apply_hunks(live, hunks)
        self.assertEqual(result, live.replace(b'old-b', b'new-b'))
        self.assertEqual((applied, present), ([1], [0]))
        self.assertEqual(apply_hunks(result, hunks), (result, [], [0, 1]))

    def test_insert_and_delete_containment_are_idempotent(self):
        for old, new in [('anchor\n', 'anchor\ninsert\n'), ('anchor\nremove\n', 'anchor\n')]:
            with self.subTest(old=old):
                hunk = {'old': old, 'new': new}
                result, applied, present = apply_hunks(('head\n' + old).encode(), [hunk])
                self.assertEqual(result, ('head\n' + new).encode())
                self.assertEqual((applied, present), ([0], []))
                self.assertEqual(apply_hunks(result, [hunk]), (result, [], [0]))

    def test_ambiguous_or_missing_anchors_stop(self):
        for current in (b'old\nold\n', b'new\nnew\n', b'old\nnew\n', b'unknown\n'):
            with self.subTest(current=current), self.assertRaises(Stop):
                apply_hunks(current, [{'old': 'old\n', 'new': 'new\n'}])

    def test_reverse_proof_rejects_duplicate_new_anchors_created_across_boundaries(self):
        hunks = [{'old': 'X', 'new': 'aa'}]
        with self.assertRaisesRegex(Stop, 'reverse_hunk_anchor_not_unique'):
            apply_hunks(b'aXa', hunks)

    def test_preserves_uniform_crlf_but_rejects_mixed_and_binary(self):
        self.assertEqual(apply_hunks(b'head\r\nold\r\n', [{'old': 'old\n', 'new': 'new\n'}])[0],
                         b'head\r\nnew\r\n')
        for current in (b'old\r\nother\n', b'old\r', b'old\0'):
            with self.subTest(current=current), self.assertRaises(Stop):
                apply_hunks(current, [{'old': 'old', 'new': 'new'}])

    def test_new_content_allows_only_absence_or_exact_existing_bytes(self):
        content = b'<?php\n// exact new service\n'
        row = {'kind': 'new', 'content': content.decode(), 'curated_after_sha256': sha(content)}
        self.assertEqual(candidate_bytes(None, row), content)
        self.assertEqual(candidate_bytes(content, row), content)
        with self.assertRaisesRegex(Stop, 'new_source_already_divergent'):
            candidate_bytes(content + b'// existing overlay\n', row)


class BuilderScopeTests(unittest.TestCase):
    def setUp(self):
        self.repo = SyntheticRepository()

    def build(self):
        return builder.build(self.repo, self.repo.scope)

    def test_build_contains_exact_nine_runtime_paths_and_one_new_file(self):
        payload = self.build()
        self.assertEqual([row['source'] for row in payload['files']], list(builder.SOURCES))
        self.assertNotEqual(payload['release'], 'seo-release-20261108')
        self.assertEqual(sum(row['kind'] == 'new' for row in payload['files']), 1)
        self.assertEqual(payload['helpers'], builder.verified_helpers())
        self.assertEqual(payload['builder_helpers'], {name: sha((HERE / name).read_bytes())
                                                     for name in ('build_payload.py', 'proof.py')})
        for row in payload['files']:
            before = self.repo.blob(builder.BASE, 'website/' + row['source'])
            self.assertEqual(candidate_bytes(before, row),
                             self.repo.blob(builder.TARGET, 'website/' + row['source']))
            self.assertEqual(row['reviewed_tree_sha256'], row['curated_after_sha256'])
        self.assertFalse(any(row['source'].startswith(('tests/', 'docs/')) for row in payload['files']))

    def test_rejects_unexpected_source_commit_and_parent(self):
        self.repo.resolved[builder.TARGET] = 'a' * 40
        with self.assertRaisesRegex(Stop, 'source_commit_invalid'):
            self.build()
        self.repo.resolved[builder.TARGET] = builder.TARGET
        self.repo.parent_list = ['b' * 40]
        with self.assertRaisesRegex(Stop, 'source_parent_invalid'):
            self.build()

    def test_rejects_unexpected_scope_commit_or_counts(self):
        self.repo.scope['target_commit'] = 'a' * 40
        with self.assertRaisesRegex(Stop, 'scope_commit_invalid'):
            self.build()
        self.repo = SyntheticRepository()
        self.repo.scope['runtime_file_count'] = 34
        with self.assertRaisesRegex(Stop, 'scope_counts_invalid'):
            self.build()

    def test_rejects_added_or_missing_changed_paths(self):
        for changed in [self.repo.paths | {'website/routes/web.php'}, self.repo.paths - {builder.NON_RUNTIME[0]}]:
            self.repo.paths = changed
            with self.assertRaisesRegex(Stop, 'source_changed_paths_invalid'):
                self.build()

    def test_rejects_unexpected_payload_path_or_destination(self):
        self.repo.scope['files'][0]['source'] = '../routes/web.php'
        with self.assertRaisesRegex(Stop, 'scope_paths_invalid'):
            self.build()
        self.repo = SyntheticRepository()
        self.repo.scope['files'][0]['destination'] = 'public_html/other.php'
        with self.assertRaisesRegex(Stop, 'scope_destination_invalid'):
            self.build()

    def test_rejects_altered_baseline_or_reviewed_target_bytes(self):
        key = (builder.TARGET, 'website/' + builder.SOURCES[0])
        self.repo.blobs[key] += b'// unreviewed\n'
        with self.assertRaisesRegex(Stop, 'source_after_hash_invalid'):
            self.build()
        self.repo = SyntheticRepository()
        self.repo.blobs[(builder.BASE, 'website/' + builder.SOURCES[0])] += b'// different base\n'
        with self.assertRaisesRegex(Stop, 'source_before_hash_invalid'):
            self.build()

    def test_rejects_deleted_target_and_fake_new_file(self):
        self.repo.blobs[(builder.TARGET, 'website/' + builder.SOURCES[0])] = None
        with self.assertRaisesRegex(Stop, 'source_after_hash_invalid'):
            self.build()
        self.repo = SyntheticRepository()
        self.repo.blobs[(builder.BASE, 'website/' + builder.NEW_SOURCE)] = b'pre-existing\n'
        with self.assertRaisesRegex(Stop, 'source_before_hash_invalid'):
            self.build()

    def test_published_runtime_must_match_original_curated_provenance(self):
        self.repo.blobs[(builder.CURATED, 'website/' + builder.SOURCES[0])] += b'// changed provenance\n'
        with self.assertRaisesRegex(Stop, 'published_runtime_differs_from_curated'):
            self.build()

    def test_proposal_writes_only_within_its_own_directory_and_rejects_symlinks(self):
        with tempfile.TemporaryDirectory(prefix='test-output-', dir=HERE) as name:
            directory = Path(name)
            output = directory / 'proposal.json'
            data = builder.write_payload(self.build(), output)
            self.assertEqual(output.read_bytes(), data)
            self.assertEqual(json.loads(data)['release'], builder.RELEASE)
            with self.assertRaisesRegex(Stop, 'output_outside_proposal_directory'):
                builder.write_payload({}, HERE.parent / 'must-not-be-written.json')
            target = directory / 'target.json'
            target.write_bytes(b'unchanged')
            link = directory / 'link.json'
            link.symlink_to(target)
            with self.assertRaisesRegex(Stop, 'output_symlink_rejected'):
                builder.write_payload({}, link)
            self.assertEqual(target.read_bytes(), b'unchanged')
            with self.assertRaisesRegex(Stop, 'output_parent_invalid'):
                builder.write_payload({}, directory / '..' / '..' / 'must-not-be-written.json')


class ReviewedSourceTests(unittest.TestCase):
    def test_actual_pinned_source_hunks_preserve_external_overlay_bytes(self):
        repo = builder.GitRepository(HERE.parents[1])
        payload = builder.build(repo)
        for row in payload['files']:
            before = repo.blob(builder.BASE, 'website/' + row['source'])
            after = repo.blob(builder.TARGET, 'website/' + row['source'])
            if row['kind'] == 'new':
                self.assertEqual(candidate_bytes(None, row), after)
                continue
            with self.subTest(source=row['source']):
                prefix, suffix = b'// UNRELATED LIVE PREFIX\n', b'\n// UNRELATED LIVE SUFFIX\n'
                self.assertEqual(candidate_bytes(prefix + before + suffix, row), prefix + after + suffix)


if __name__ == '__main__':
    unittest.main()
