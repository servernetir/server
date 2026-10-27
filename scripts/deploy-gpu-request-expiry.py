#!/usr/bin/env python3
"""Unfunded GPU request expiry release, using the existing guarded web runner."""
import difflib
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import urllib.request

helper = Path(__file__).with_name('deploy-pending-request-ui.py')
if hashlib.sha256(helper.read_bytes()).hexdigest() != '2b7844c9bb91d28170965ba76ca277c3d5b48e4aabf7617393c09a0ff3dfbc92':
    raise SystemExit('Shared runner differs; review before release')
spec = importlib.util.spec_from_file_location('guarded_pending_ui', helper)
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
POLICY = 'app/Services/Billing/OrderPaymentWindow.php'
INVOICE = 'app/Console/Commands/ExpireOrderInvoices.php'
GPU = 'app/Services/Cloud/GpuCapacityOrders.php'
JS = 'public/assets/js/gpu-request-expiry.js'


def fetch(url):
    request = urllib.request.Request(url, headers={'User-Agent': 'ServerNet-Guarded-Release'})
    with urllib.request.urlopen(request, timeout=30) as response:
        return response.read()


def patched(live, before, after):
    old_lines = before.decode().splitlines(keepends=True)
    new_lines = after.decode().splitlines(keepends=True)
    text = live.decode()
    for group in difflib.SequenceMatcher(None, old_lines, new_lines, autojunk=False).get_grouped_opcodes(n=3):
        old = ''.join(old_lines[group[0][1]:group[-1][2]])
        new = ''.join(new_lines[group[0][3]:group[-1][4]])
        text = runner.once(text, old.replace('\r\n', '\n'), new.replace('\r\n', '\n'))
    return text.encode()


def candidates(source, unused_base_view, home):
    args = sys.argv
    sha = args[args.index('--source-sha') + 1]
    commit = json.loads(fetch(f'https://api.github.com/repos/servernetir/server/git/commits/{sha}'))
    if len(commit['parents']) != 1:
        raise RuntimeError('A single reviewed source parent is required')
    parent = commit['parents'][0]['sha']
    source_url = f'https://raw.githubusercontent.com/servernetir/server/{sha}/website/'
    before_url = f'https://raw.githubusercontent.com/servernetir/server/{parent}/website/'
    for path in (POLICY, INVOICE, GPU, JS):
        source[path] = fetch(source_url + path)
    output = {}
    for relative, path in [('servernet_app/' + POLICY, POLICY), ('servernet_app/' + JS, JS),
                           ('public_html/assets/js/gpu-request-expiry.js', JS)]:
        if (home / relative).exists():
            raise RuntimeError('New target exists; inspect previous release: ' + relative)
        output[relative] = source[path]
    for path in (runner.CSS,):
        before = fetch(before_url + path)
        for relative in ('servernet_app/' + path, 'public_html/assets/css/cloud-request.css'):
            output[relative] = patched((home / relative).read_bytes(), before, source[path])
    for path in runner.LANGS:
        live = (home / 'servernet_app' / path).read_bytes().decode()
        if 'gpu_request_deadline_heading' in live:
            raise RuntimeError('Expiry translations already exist; inspect receipt')
        text = source[path].decode()
        block = text[text.index('    // Funding expiry for unpaid hourly GPU requests.'):text.rfind('];')]
        output['servernet_app/' + path] = runner.once(live, '\n];', '\n\n' + block + '];').encode()
    for path in (INVOICE, GPU, runner.VIEW, runner.PARTIAL):
        output['servernet_app/' + path] = patched((home / 'servernet_app' / path).read_bytes(), fetch(before_url + path), source[path])
    return output


original_run_php = runner.run_php


def run_php(home, stage, compile_live=False):
    root = home / 'servernet_app' if compile_live else stage / 'staged/servernet_app'
    for path in (POLICY, INVOICE, GPU):
        subprocess.run([runner.PHP, '-l', str(root / path)], check=True, stdout=subprocess.DEVNULL)
    original_run_php(home, stage, compile_live)


if __name__ == '__main__':
    runner.candidates = candidates
    runner.run_php = run_php
    runner.main()
