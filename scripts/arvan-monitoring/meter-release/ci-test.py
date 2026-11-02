#!/usr/bin/env python3
"""Run the frozen release flows with temporary CI-only path substitutions.

No Composer, application bootstrap, database, provider, credential or network use.
The original four package artifacts are checksum-verified and never modified.
"""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

HERE = Path(__file__).resolve().parent
FROZEN = {'deploy.php': '0550824d42f54eb31c739cfd32a13bfcc04127cd0e29a8ffe073b79e283cd60e', 'candidate.php': '56c3972ba0896e6bc14c4d443246c1eadaef6bccb63245e0cd3b274e292d2ca8', 'test_release.py': 'd96fd2fba790b24ee6c7c698fec94f53b2a8639539b60f464e6743e7a1ddc3c2', 'README.md': '1cfa3e05f48dc7ce5352256854ff0f2a54332383cb861f7d268ce2044c425b21'}
BEFORE_SHA = '464b2db7f0bda854d6473b06e46da630cdc325c82bb22894088af8bcfaba4f3f'
REVERSE_HUNKS = [("    private function hasUnreviewedCharge(int $cid, bool $currentRead = false): bool\n    {\n        $knownQuery = Payment::where('customer_id', $cid)->where('status', 'paid')->where('gateway', 'credit')\n            ->where(fn ($q) => $q->where('external_ref', 'like', 'traffic-owner:%')->orWhere('external_ref', 'like', 'traffic-meter:%'));\n        // Under InnoDB REPEATABLE READ, another plain SELECT would retain the\n        // old planning snapshot even after Customer's locking read completes.\n        // Locking/current reads are required to see a just-committed debit.\n        $known = ($currentRead ? $knownQuery->lockForUpdate() : $knownQuery)->pluck('invoice_id');\n        $invoiceQuery = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')\n            ->where('invoices.customer_id', $cid)->whereNotIn('invoices.id', $known)\n            ->where(fn ($q) => $q->where('title', 'like', '%traffic%')->orWhere('title', 'like', '%ترافیک%'));\n        $invoice = $currentRead ? $invoiceQuery->lockForUpdate()->first() !== null : $invoiceQuery->exists();\n        $debitQuery = CreditEntry::where('customer_id', $cid)->where('amount', '<', 0)\n            ->where(fn ($q) => $q->where('reason', 'like', '%traffic%')->orWhere('note', 'like', '%traffic%')->orWhere('note', 'like', '%ترافیک%'));\n        $debits = ($currentRead ? $debitQuery->lockForUpdate() : $debitQuery)->get();\n\n        return $invoice || $debits->contains(fn ($entry) => $entry->source_type !== Invoice::class || ! $known->contains($entry->source_id));\n    }\n\n", "    private function hasUnreviewedCharge(int $cid): bool\n    {\n        $known = Payment::where('customer_id', $cid)->where('status', 'paid')->where('gateway', 'credit')\n            ->where(fn ($q) => $q->where('external_ref', 'like', 'traffic-owner:%')->orWhere('external_ref', 'like', 'traffic-meter:%'))\n            ->pluck('invoice_id');\n        $invoice = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')\n            ->where('invoices.customer_id', $cid)->whereNotIn('invoices.id', $known)\n            ->where(fn ($q) => $q->where('title', 'like', '%traffic%')->orWhere('title', 'like', '%ترافیک%'))->exists();\n        $debits = CreditEntry::where('customer_id', $cid)->where('amount', '<', 0)\n            ->where(fn ($q) => $q->where('reason', 'like', '%traffic%')->orWhere('note', 'like', '%traffic%')->orWhere('note', 'like', '%ترافیک%'))->get();\n\n        return $invoice || $debits->contains(fn ($entry) => $entry->source_type !== Invoice::class || ! $known->contains($entry->source_id));\n    }\n\n"), ("            // A manual traffic settlement may commit after the initial review\n            // above but before we acquire the same customer lock used by Wallet.\n            // Recheck under that lock before writing ANY invoice/checkpoint.\n            foreach (collect($lines)->pluck('next.customer_id')->unique()->sort() as $cid) {\n                $this->check(! $this->hasUnreviewedCharge((int) $cid, true),\n                    'Manual traffic charge changed during lock acquisition; reconciliation required');\n            }\n", '')]

def main():
    for name, expected in FROZEN.items():
        assert hashlib.sha256((HERE/name).read_bytes()).hexdigest() == expected, 'frozen artifact changed: '+name
    candidate = (HERE/'candidate.php').read_text()
    before = candidate
    for present, previous in REVERSE_HUNKS:
        assert before.count(present) == 1, 'reviewed reverse hunk is not unique'
        before = before.replace(present, previous)
    assert hashlib.sha256(before.encode()).hexdigest() == BEFORE_SHA, 'reconstructed before hash differs'
    php = shutil.which('php')
    assert php, 'native PHP required'
    php_binary = subprocess.check_output([php, '-r', 'echo PHP_BINARY;'], text=True).strip()
    version = subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True).strip()
    assert tuple(map(int, version.split('.')[:2])) >= (8,4), 'CI requires PHP 8.4 or later'
    with tempfile.TemporaryDirectory(prefix='arvan-meter-release-ci-') as d:
        temp = Path(d)
        for name in FROZEN:
            shutil.copyfile(HERE/name, temp/name)
        baseline = temp/'before-source.php'
        baseline.write_text(before)
        harness = (temp/'test_release.py').read_text()
        substitutions = {
            "PHP = Path('/workspace/scratch/669618ddf503/php-runtime/php')": 'PHP = Path('+repr(php)+')',
            "PHP_BINARY = Path('/workspace/scratch/669618ddf503/php-runtime/usr/bin/php8.3')": 'PHP_BINARY = Path('+repr(php_binary)+')',
            "BEFORE = Path('/workspace/scratch/669618ddf503/arvan-runtime-private/ArvanTrafficMeter.php').read_bytes()": 'BEFORE = Path('+repr(str(baseline))+').read_bytes()',
        }
        for original, replacement in substitutions.items():
            assert harness.count(original) == 1, 'test-only substitution drift'
            harness = harness.replace(original, replacement)
        adapted = temp/'test_release.py'
        adapted.write_text(harness)
        subprocess.run([php, '-l', str(temp/'deploy.php')], check=True)
        subprocess.run(['python3', str(adapted)], check=True)
    print('Frozen package verified; 20 release flows completed on PHP '+version)

if __name__ == '__main__':
    main()
