#!/usr/bin/env python3
"""Offline review of the pinned international-market release. Never deploys.

The manifest is an evidence record, not an executable release manifest.
Only review snapshots supplied via an independently approved handoff may be
used for three-way comparisons. No production paths or credentials are read.
"""

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tempfile


HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
REVIEW = HERE / 'manifest.review.json'
OVERLAYS = HERE / 'reconciliation-overlays.json'
PLAN = HERE / 'candidate-install-plan.json'
EXPECTED_RECONCILIATION = {
    'website/app/Console/Commands/ReleaseGate.php',
    'website/app/Http/Controllers/SiteController.php',
    'website/resources/views/partials/header.blade.php',
    'website/routes/web.php',
}
NON_RUNTIME = {
    'website/.env.example',
    'website/tests/Feature/ForeignLocalePriceGuardTest.php',
    'website/tests/Feature/InternationalMarketPilotTest.php',
}
PATHS = ('', '/cloud', '/vps/hourly', '/cloud/n8n', '/gpu', '/ai')


def sha(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def git(*args):
    return subprocess.check_output(['git', '-C', str(REPO), *args])


def blob(commit, path):
    return git('show', f'{commit}:{path}')


def destination(source):
    assert source.startswith('website/')
    rel = source.removeprefix('website/')
    if rel.startswith('public/'):
        return 'public_html/' + rel.removeprefix('public/')
    return 'servernet_app/' + rel


def verify(manifest):
    assert manifest['publishable'] is False
    assert manifest['status'] == 'review_only_pending_runtime_validation'
    base, candidate = manifest['git_base'], manifest['git_candidate']
    changes = git('diff', '--name-status', base, candidate, '--', 'website/').decode().splitlines()
    runtime = {path: op for op, path in (line.split('\t') for line in changes) if path not in NON_RUNTIME}
    records = manifest['files']
    assert len(records) == len(runtime) == 14
    assert len({r['source'] for r in records}) == len(records)
    assert len({r['destination'] for r in records}) == len(records)
    assert set(runtime) == {r['source'] for r in records}
    pending = []
    for row in records:
        source = row['source']
        op = runtime[source]
        assert op in ('A', 'M')
        assert row['operation'] == ('add' if op == 'A' else 'modify')
        assert row['destination'] == destination(source)
        assert row['candidate_sha256'] == sha(blob(candidate, source))
        assert row['base_sha256'] == (None if op == 'A' else sha(blob(base, source)))
        if row['observed_live_sha256'] == row['base_sha256']:
            assert row['review_status'] == 'candidate_matches_preflight'
            assert row['reviewed_install_sha256'] == row['candidate_sha256']
        else:
            assert row['review_status'] == 'requires_three_way_review'
            assert row['reviewed_install_sha256'] is None
            pending.append(source)
    assert set(pending) == EXPECTED_RECONCILIATION
    evidence = manifest['reconciliation_evidence']
    assert evidence['state'] == 'four_local_candidates_verified'
    assert evidence['php_and_runtime_validation_pending'] is True
    proposals = evidence['offline_candidates']
    assert set(proposals) == EXPECTED_RECONCILIATION
    assert evidence['source_handoff_pending'] == []
    for row in records:
        if row['source'] in proposals:
            proposal = proposals[row['source']]
            assert proposal['source_sha256'] == row['observed_live_sha256']
            assert re.fullmatch('[0-9a-f]{64}', proposal['proposed_sha256'])
            assert proposal['proposed_sha256'] != row['candidate_sha256']
            assert row['reviewed_install_sha256'] is None
    assert records[-1]['source'] == 'website/routes/web.php'
    assert records[0]['source'] == 'website/app/Support/InternationalMarket.php'
    urls = {f'https://servernet.cloud/{l}{p}' for l in ('ru', 'az') for p in PATHS}
    assert len(manifest['acceptance']['new_urls']) == len(urls) == 12
    assert set(manifest['acceptance']['new_urls']) == urls
    assert manifest['activation'] == {
        'setting': 'INTERNATIONAL_MARKET_LOCALES', 'expected_before': '',
        'requested_after': 'ru,az', 'requires_separate_verified_activation': True,
    }
    assert b"env('INTERNATIONAL_MARKET_LOCALES', '')" in blob(candidate, 'website/config/international-market.php')
    assert manifest['acceptance']['submit_only_after_acceptance'] is True
    verify_plan(manifest, json.loads(PLAN.read_text()), json.loads(OVERLAYS.read_text()))
    return pending


def verify_plan(manifest, plan, overlays):
    assert plan['deployment_authorized'] is False
    assert plan['status'] == 'complete_source_candidate_pending_runtime_review'
    assert plan['git_source_commit'] == manifest['git_candidate']
    assert set(overlays) == EXPECTED_RECONCILIATION
    assert len(plan['files']) == len(manifest['files'])
    assert all(plan['constraints'].values())
    for row, candidate in zip(manifest['files'], plan['files']):
        assert row['source'] == candidate['source']
        assert row['destination'] == candidate['destination']
        assert row['observed_live_sha256'] == candidate['before']
        if row['source'] in overlays:
            overlay = overlays[row['source']]
            expected = manifest['reconciliation_evidence']['offline_candidates'][row['source']]
            assert overlay['source_sha256'] == expected['source_sha256'] == candidate['before']
            assert overlay['proposed_sha256'] == expected['proposed_sha256'] == candidate['after']
            assert candidate['operation'] == 'overlay'
            assert overlay['edits'] and all(set(e) == {'old', 'new', 'count'} and e['old'] and e['new']
                                            and e['old'] != e['new'] and e['count'] == 1 for e in overlay['edits'])
        else:
            assert candidate['after'] == row['candidate_sha256']
            assert candidate['operation'] == ('add' if row['operation'] == 'add' else 'replace')


def reproduce_overlay(data, overlay):
    """Offline byte proof using exact reviewed hunks, never a deployment operation."""
    assert sha(data) == overlay['source_sha256']
    original = data
    for edit in overlay['edits']:
        old, new = edit['old'].encode(), edit['new'].encode()
        assert data.count(old) == edit['count'] == 1
        data = data.replace(old, new, 1)
    assert sha(data) == overlay['proposed_sha256']
    restored = data
    for edit in reversed(overlay['edits']):
        new, old = edit['new'].encode(), edit['old'].encode()
        assert restored.count(new) == 1
        restored = restored.replace(new, old, 1)
    assert restored == original
    return data


def three_way(live, base, candidate):
    """Check a merge offline. Return bytes for a clean merge, never conflict text."""
    with tempfile.TemporaryDirectory(prefix='servernet-intl-review-') as directory:
        root = Path(directory)
        inputs = [root / name for name in ('live', 'base', 'candidate')]
        for path, data in zip(inputs, (live, base, candidate)):
            path.write_bytes(data)
        result = subprocess.run(['git', 'merge-file', '-p', *map(str, inputs)], capture_output=True)
        if result.returncode == 0:
            return result.stdout
        if 0 < result.returncode < 128:
            return None
        raise RuntimeError('Offline three-way comparison failed')


def check_snapshots(manifest, folder):
    """Check only the four allowlisted source files in an offline handoff folder."""
    folder = folder.resolve(strict=True)
    assert folder.is_dir() and folder != Path('/')
    # Explicit refusal of the production application root. The input must be a
    # stand-alone export under the operator's local working directory.
    assert not folder.is_relative_to(Path('/home/servernetcloud'))
    results = []
    overlays = json.loads(OVERLAYS.read_text())
    for row in manifest['files']:
        if row['source'] not in EXPECTED_RECONCILIATION:
            continue
        target = folder / row['destination']
        relative_parents = [target, *target.parents]
        assert not any(p.is_symlink() for p in relative_parents if p.is_relative_to(folder))
        assert target.is_file() and target.stat().st_size <= 2 * 1024 * 1024
        data = target.read_bytes()
        if sha(data) != row['observed_live_sha256']:
            results.append({'source': row['source'], 'status': 'snapshot_digest_mismatch'})
            continue
        merged = reproduce_overlay(data, overlays[row['source']])
        results.append({'source': row['source'], 'status': 'forward_reverse_proof_ok_pending_runtime_review',
                        'proposed_sha256': sha(merged)})
    return results


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--snapshot-dir', type=Path, help='Independently authorized local source-only export (optional).')
    parser.add_argument('--require-publishable', action='store_true', help='Exit nonzero while reviewed installation hashes are missing.')
    args = parser.parse_args()
    manifest = json.loads(REVIEW.read_text())
    pending = verify(manifest)
    output = {'candidate_integrity': 'verified', 'runtime_files': len(manifest['files']),
              'direct_candidates': len(manifest['files']) - len(pending),
              'reconciliation_pending': pending,
              'locally_reconciled': list(manifest['reconciliation_evidence']['offline_candidates']),
              'source_handoff_pending': manifest['reconciliation_evidence']['source_handoff_pending'],
              'public_urls': len(manifest['acceptance']['new_urls']),
              'publishable': False}
    if args.snapshot_dir:
        output['snapshot_review'] = check_snapshots(manifest, args.snapshot_dir)
    print(json.dumps(output, indent=2))
    return 2 if args.require_publishable else 0


if __name__ == '__main__':
    raise SystemExit(main())
