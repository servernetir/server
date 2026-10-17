#!/usr/bin/python3
"""Only host-bound Cloudflare TCP/7844 uses the existing CT113/tun119 relay.

Independent of country-agent sources: no fwmark, no customer assignment, no
managed chain. Reconciliation and rollback touch only these exact rules.
"""
import json
import subprocess
import sys

CT = ['pct', 'exec', '113', '--']
SOURCE = '10.10.10.1'
NETWORKS = ['198.41.192.0/24', '198.41.200.0/24']
PREF = '900'
TABLE = '7844'
COMMENT = 'servernet-cloudflared-egress'
REMOVE = sys.argv[1:] == ['--remove']
assert not sys.argv[1:] or REMOVE


def run(args, prefix=(), check=True):
    r = subprocess.run(list(prefix) + args, capture_output=True, text=True, timeout=25)
    if check and r.returncode:
        raise RuntimeError(str(args) + ': ' + r.stderr.strip())
    return r


def policy(prefix, network, table):
    args = ['priority', PREF, 'from', SOURCE + '/32', 'to', network,
            'ipproto', 'tcp', 'dport', '7844', 'lookup', table]
    rules = run(['ip', '-j', 'rule', 'show'], prefix).stdout
    rows = json.loads(rules)
    def destination(row):
        dst = row.get('dst', '')
        return dst if '/' in dst else dst + '/' + str(row.get('dstlen', 32))
    matching = [r for r in rows if str(r.get('priority')) == PREF
                and r.get('src', '').split('/')[0] == SOURCE
                and destination(r) == network
                and str(r.get('table')) == table
                and r.get('ipproto') == 'tcp'
                and str(r.get('dport')) == '7844']
    if REMOVE:
        for _ in matching:
            run(['ip', 'rule', 'del'] + args, prefix)
    elif not matching:
        # Refuse to reuse this priority if another owner's rule occupies it.
        assert all(str(r.get('priority')) != PREF or (
            r.get('src', '').split('/')[0] == SOURCE
            and destination(r) in NETWORKS
            and str(r.get('table')) == table
            and r.get('ipproto') == 'tcp'
            and str(r.get('dport')) == '7844') for r in rows), 'Policy priority conflict'
        run(['ip', 'rule', 'add'] + args, prefix)


if not REMOVE:
    run(['ip', 'link', 'show', 'vmbr1'])
    run(['ip', 'link', 'show', 'tun119'], CT)
    assert 'default dev tun119' in run(['ip', 'route', 'show', 'table', '119'], CT).stdout
    existing = run(['ip', 'route', 'show', 'table', TABLE], check=False).stdout
    assert all(line.startswith(('default via 10.10.10.20 dev vmbr1',
                                'blackhole default')) for line in existing.splitlines()), 'Routing table conflict'
    run(['ip', 'route', 'replace', 'blackhole', 'default', 'table', TABLE, 'metric', '1000'])
    run(['ip', 'route', 'replace', 'default', 'via', '10.10.10.20', 'dev', 'vmbr1',
         'table', TABLE, 'metric', '10'])

for network in NETWORKS:
    # Configure the relay first, so newly directed traffic has a complete path.
    policy(CT, network, '119')
    nat = ['POSTROUTING', '-s', SOURCE + '/32', '-d', network, '-p', 'tcp',
           '--dport', '7844', '-o', 'tun119', '-m', 'comment', '--comment', COMMENT,
           '-j', 'MASQUERADE']
    exists = run(['iptables', '-w', '10', '-t', 'nat', '-C'] + nat, CT, check=False).returncode == 0
    if REMOVE and exists:
        run(['iptables', '-w', '10', '-t', 'nat', '-D'] + nat, CT)
    elif not REMOVE and not exists:
        run(['iptables', '-w', '10', '-t', 'nat', '-A'] + nat, CT)
    policy((), network, TABLE)

if REMOVE:
    run(['ip', 'route', 'del', 'default', 'via', '10.10.10.20', 'dev', 'vmbr1',
         'table', TABLE, 'metric', '10'], check=False)
    run(['ip', 'route', 'del', 'blackhole', 'default', 'table', TABLE, 'metric', '1000'], check=False)
    print('Removed only Cloudflare host egress rules.')
else:
    for network in NETWORKS:
        target = network.split('/')[0].rsplit('.', 1)[0] + '.7'
        assert 'via 10.10.10.20' in run(['ip', 'route', 'get', target, 'from', SOURCE,
                                      'ipproto', 'tcp', 'dport', '7844']).stdout
        assert 'dev tun119' in run(['ip', 'route', 'get', target, 'from', SOURCE,
                                  'iif', 'eth0', 'ipproto', 'tcp', 'dport', '7844'], CT).stdout
    print('Cloudflare TCP/7844 relay policy verified.')
