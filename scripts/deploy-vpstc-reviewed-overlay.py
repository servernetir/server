#!/usr/bin/env python3
"""Use the ordinary scoped VPS.TC release with two exact, reviewed live overlays.

Only CloudStoreController and its view's known pricing/UX drift are reconciled. Every other
file keeps the ordinary git-apply conflict guard. The source base/target and
normalized live bytes must match the reviewed hashes; unknown bytes stop.
The changes below add only VPS.TC monthly checkout rules while preserving
all prior production changes. Invocation is identical to deploy-vpstc.py.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path

_spec = importlib.util.spec_from_file_location('vpstc_scoped_release', Path(__file__).with_name('deploy-vpstc.py'))
core = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(core)
ordinary_merge_candidate = core.merge_candidate

RELATIVE = 'app/Http/Controllers/Account/CloudStoreController.php'
BASE_SHA256 = 'ed68f90569aefe7d7cd677ff2d810d5baf2b91e54b5eada15621f015f63d9f2d'
TARGET_SHA256 = 'a0f8aaef43f95c4fa317cf0c3baa75853d4573265b3fe8994627a66cd5c5e2e4'
LIVE_SHA256 = '9dcef025c7aea41aacee5aa3eba7b1e873e6f81099074654686d620692db106a'
CANDIDATE_SHA256 = '208916e844025815150eb88bba8d081cce20b63c8cb034d71c560299583b286f'

REPLACEMENTS = (
    (
        "    public static function priceForCycle(CloudPlan $plan, string $cycle, array $addons = []): int\n"
        "    {\n"
        "        $months = Service::monthsIn($cycle);",
        "    public static function priceForCycle(CloudPlan $plan, string $cycle, array $addons = []): int\n"
        "    {\n"
        "        if ($plan->provider === 'vpstc' && $cycle !== 'monthly') {\n"
        "            return 0;\n"
        "        }\n"
        "        $months = Service::monthsIn($cycle);",
    ),
    (
        "        $discount = in_array($plan->provider, ['salad', 'lightnode'], true) ? 0",
        "        $discount = in_array($plan->provider, ['salad', 'lightnode', 'vpstc'], true) ? 0",
    ),
    (
        "                $price = self::priceForCycle($offer, $cy);\n"
        "                $undiscounted =",
        "                $price = self::priceForCycle($offer, $cy);\n"
        "                if ($price <= 0) {\n"
        "                    continue;\n"
        "                }\n"
        "                $undiscounted =",
    ),
    (
        "                $saving = $undiscounted > $price && ! in_array($offer->provider, ['salad', 'lightnode'], true)",
        "                $saving = $undiscounted > $price && ! in_array($offer->provider, ['salad', 'lightnode', 'vpstc'], true)",
    ),
    (
        "            if ($row->provider === 'arvan' && $row->kind === 'app') {",
        "            if ($offer->provider === 'vpstc'\n"
        "                && ! str_starts_with((string) $row->provider_ref, $offer->provider_ref.'|')) {\n"
        "                continue;\n"
        "            }\n"
        "\n"
        "            if ($row->provider === 'arvan' && $row->kind === 'app') {",
    ),
)

VIEW_RELATIVE = 'resources/views/account/cloud-store.blade.php'
VIEW_BASE_SHA256 = '349ce97d782706ab0def5343dac8d9dc97ae8c26aa7ec5ea3a236721f8a8fa06'
VIEW_TARGET_SHA256 = 'be25ddca675f4a1d0036ac00c82999c4f72577f4b22fa759b584fed417d8feba'
VIEW_LIVE_SHA256 = 'e53f4faeb24ec557953222c0804801425f82f52d9b83ba5f0b1124ef3caa8ce4'
VIEW_CANDIDATE_SHA256 = '2f92a7f1b070541eef63b77c363679c442f236c7f7e79590a33221d36bda2332'
VIEW_REPLACEMENTS = (
    (
        "  if (! in_array($curCycle, $cycles, true)) { $curCycle = $defCycle; }",
        "  if (! in_array($curCycle, $cycles, true)) { $curCycle = $defCycle; }\n"
        "  if (!isset($priceMap[$curSlug][$curCycle]) && !empty($priceMap[$curSlug])) { $curCycle = array_key_first($priceMap[$curSlug]); }",
    ),
    (
        '                <label class="cvb-seg cvb-seg-c @if($cy === $curCycle) on @endif" data-cyc="{{ $cy }}">\n'
        '                  <input type="radio" name="cycle" value="{{ $cy }}" @checked($cy === $curCycle)>',
        '                <label class="cvb-seg cvb-seg-c @if($cy === $curCycle) on @endif" data-cyc="{{ $cy }}" @if(!isset($priceMap[$curSlug][$cy])) hidden @endif>\n'
        '                  <input type="radio" name="cycle" value="{{ $cy }}" @checked($cy === $curCycle) @disabled(!isset($priceMap[$curSlug][$cy]))>',
    ),
    (
        '    var bucket = D.prices[slug] || {};\n'
        '    var h =',
        '    var bucket = D.prices[slug] || {};\n'
        "    // Billing choices follow the selected offer's actual supplier contract.\n"
        '    var availableCycles = Object.keys(bucket);\n'
        '    if (!bucket[cyc] && availableCycles.length) {\n'
        '      cyc = availableCycles[0];\n'
        '      var selectedCycle = form.querySelector(\'input[name="cycle"][value="\' + cyc + \'"]\');\n'
        '      if (selectedCycle) selectedCycle.checked = true;\n'
        '    }\n'
        "    form.querySelectorAll('.cvb-seg-c[data-cyc]').forEach(function(segment){\n"
        '      var cycleInput = segment.querySelector(\'input[name="cycle"]\');\n'
        "      var supported = !!bucket[segment.getAttribute('data-cyc')];\n"
        '      segment.hidden = !supported;\n'
        '      if (cycleInput) cycleInput.disabled = !supported;\n'
        '    });\n'
        '    var h =',
    ),
)


def exact_overlay(before: bytes, base: bytes, desired: bytes, relative: str,
                  base_hash: str, target_hash: str, live_hash: str, candidate_hash: str,
                  replacements: tuple) -> bytes:
    original, ending = core.normalized(before, relative)
    source_base, _ = core.normalized(base, relative)
    source_target, _ = core.normalized(desired, relative)
    if core.digest(source_base) != base_hash or core.digest(source_target) != target_hash:
        raise core.ReleaseError('reviewed_overlay_git_source_changed:' + relative)
    if core.digest(original) == candidate_hash:
        return before  # Exact previously installed overlay: no further writes.
    if core.digest(original) != live_hash:
        raise core.ReleaseError('reviewed_overlay_live_hash_changed:' + relative)
    candidate = original
    for old, new in replacements:
        old_bytes, new_bytes = old.encode(), new.encode()
        if candidate.count(old_bytes) != 1 or new_bytes in candidate:
            raise core.ReleaseError('reviewed_overlay_anchor_not_unique:' + relative)
        candidate = candidate.replace(old_bytes, new_bytes, 1)
    if core.digest(candidate) != candidate_hash:
        raise core.ReleaseError('reviewed_overlay_candidate_hash_changed:' + relative)
    # Prove every byte outside the approved replacements survived.
    restored = candidate
    for old, new in reversed(replacements):
        if restored.count(new.encode()) != 1:
            raise core.ReleaseError('reviewed_overlay_reverse_anchor_not_unique:' + relative)
        restored = restored.replace(new.encode(), old.encode(), 1)
    if restored != original:
        raise core.ReleaseError('reviewed_overlay_unapproved_drift:' + relative)
    return candidate.replace(b'\n', b'\r\n') if ending == b'\r\n' else candidate


def cloud_store_overlay(before: bytes, base: bytes, desired: bytes) -> bytes:
    return exact_overlay(before, base, desired, RELATIVE, BASE_SHA256, TARGET_SHA256,
                         LIVE_SHA256, CANDIDATE_SHA256, REPLACEMENTS)


def cloud_store_view_overlay(before: bytes, base: bytes, desired: bytes) -> bytes:
    return exact_overlay(before, base, desired, VIEW_RELATIVE, VIEW_BASE_SHA256, VIEW_TARGET_SHA256,
                         VIEW_LIVE_SHA256, VIEW_CANDIDATE_SHA256, VIEW_REPLACEMENTS)


def reviewed_merge_candidate(release: Path, relative: str, before: bytes | None,
                             base: bytes | None, desired: bytes) -> bytes:
    if relative not in (RELATIVE, VIEW_RELATIVE):
        return ordinary_merge_candidate(release, relative, before, base, desired)
    if before is None or base is None:
        raise core.ReleaseError('reviewed_overlay_requires_existing_file:' + relative)
    return (cloud_store_overlay if relative == RELATIVE else cloud_store_view_overlay)(before, base, desired)


core.merge_candidate = reviewed_merge_candidate

if __name__ == '__main__':
    raise SystemExit(core.main())
