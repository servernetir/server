#!/usr/bin/env python3
"""Hourly wallet copy follow-up; reuse the existing guarded release runner."""
import difflib
import hashlib
import importlib.util
from pathlib import Path
import urllib.request

helper = Path(__file__).with_name('deploy-pending-request-ui.py')
if hashlib.sha256(helper.read_bytes()).hexdigest() != '2b7844c9bb91d28170965ba76ca277c3d5b48e4aabf7617393c09a0ff3dfbc92':
    raise SystemExit('Shared runner differs; review before release')
spec = importlib.util.spec_from_file_location('guarded_pending_ui', helper)
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
BASE = '5b262470123e7edf5e2250eae892fee79a5b85c6'


def candidates(source, unused_base_view, home):
    output = {}
    for path in runner.LANGS:
        live = (home / 'servernet_app' / path).read_bytes().decode()
        if 'gpu_request_wallet_heading' in live:
            raise RuntimeError('Hourly policy already present; inspect receipt')
        new = source[path].decode()
        block = new[new.index('    // Hourly wallet policy; hours comes from CloudPlan.'):new.rfind('];')]
        output['servernet_app/' + path] = runner.once(live, '\n];', '\n\n' + block + '];').encode()
    for path in (runner.CSS, runner.PARTIAL):
        url = f'https://raw.githubusercontent.com/servernetir/server/{BASE}/website/{path}'
        with urllib.request.urlopen(url, timeout=30) as response:
            before = response.read().decode().splitlines(keepends=True)
        after = source[path].decode().splitlines(keepends=True)
        targets = ['servernet_app/' + path]
        if path == runner.CSS:
            targets.append('public_html/assets/css/cloud-request.css')
        for relative in targets:
            text = (home / relative).read_bytes().decode()
            for group in difflib.SequenceMatcher(None, before, after, autojunk=False).get_grouped_opcodes(n=3):
                old = ''.join(before[group[0][1]:group[-1][2]])
                new = ''.join(after[group[0][3]:group[-1][4]])
                text = runner.once(text, old, new)
            output[relative] = text.encode()
    return output


original_run_php = runner.run_php


def run_php(home, stage, compile_live=False):
    if not compile_live:
        # The unchanged parent view is checked privately, preserving its live differences.
        target = stage / 'staged/servernet_app' / runner.VIEW
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes((home / 'servernet_app' / runner.VIEW).read_bytes())
    original_run_php(home, stage, compile_live)


if __name__ == '__main__':
    runner.candidates = candidates
    runner.run_php = run_php
    runner.main()
