#!/usr/bin/python3
"""Scoped PEETA TCP/7844 routing; run on Proxmox, CT half via stdin.

No guest/account credentials are used. The existing host connector helper and
country-agent state are deliberately outside this program's ownership.
"""
import datetime
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import subprocess
import sys

SOURCE = '10.10.10.61'
CT = ['pct', 'exec', '113', '--']
BASE = Path('/root/servernet-vm116-cloudflare-20261017')
HOST_TABLE = '17844'
RETURN_TABLE = '17845'
COMMENT = 'servernet-vm116-cloudflare'
# Official global-region addresses, checked 2026-10-17 in Cloudflare firewall docs.
EDGES = ['198.41.192.' + n for n in ['167','67','57','107','27','7','227','47','37','77']]
EDGES += ['198.41.200.' + n for n in ['13','193','33','233','53','63','113','73','43','23']]
IN_CT = '--ct' in sys.argv
ACTION = sys.argv[-1]


def run(args, check=True, input=None):
    r = subprocess.run(args, input=input, capture_output=True, text=True, timeout=30)
    if check and r.returncode:
        raise RuntimeError(str(args[:5]) + ': ' + r.stderr.strip())
    return r


def ct(action):
    r = run(CT + ['python3', '-', '--ct', action], input=Path(__file__).read_text())
    return json.loads(r.stdout)


def snapshot():
    result = {}
    for name, args in {
        'rules': ['ip','-4','rule','show'],
        'rules_json': ['ip','-j','-4','rule','show'],
        'routes': ['ip','-4','route','show','table','all'],
        'iptables': ['iptables-save'],
        'default': ['ip','-4','route','show','default'],
    }.items():
        result[name] = run(args).stdout
    if not IN_CT:
        result['guests'] = run(['qm','list']).stdout
        result['containers'] = run(['pct','list']).stdout
        result['host_helper_sha256'] = hashlib.sha256(Path('/usr/local/sbin/servernet-cloudflared-egress').read_bytes()).hexdigest()
    return result


def route_get(edge, proto='tcp', port='7844', src=SOURCE):
    return run(['ip','-4','route','get',edge,'from',src,'iif','eth0' if IN_CT else 'vmbr1',
                'ipproto',proto,'dport',port]).stdout


def rule(edge, reverse=False):
    if reverse:
        return ['priority','902','from',edge+'/32','to',SOURCE+'/32',
                'ipproto','tcp','sport','7844','lookup',RETURN_TABLE]
    return ['priority','901','from',SOURCE+'/32','to',edge+'/32',
            'ipproto','tcp','dport','7844','lookup','119' if IN_CT else HOST_TABLE]


def cidr(row, key):
    value = row.get(key,'all')
    if value == 'all': return value
    return str(ipaddress.ip_network(value if '/' in value else value+'/'+str(row.get(key+'len',32)),strict=False))


def matching(rows, edge, reverse=False):
    return [r for r in rows if str(r.get('priority')) == ('902' if reverse else '901')
            and cidr(r,'src') == (edge+'/32' if reverse else SOURCE+'/32')
            and cidr(r,'dst') == (SOURCE+'/32' if reverse else edge+'/32')
            and r.get('ipproto') == 'tcp'
            and str(r.get('sport' if reverse else 'dport')) == '7844'
            and str(r.get('table')) == (RETURN_TABLE if reverse else ('119' if IN_CT else HOST_TABLE))]


def preflight():
    rows=json.loads(run(['ip','-j','-4','rule','show']).stdout)
    for row in rows:
        if str(row.get('priority')) in (['901','902'] if IN_CT else ['901']):
            assert any(row in matching(rows,edge,rev) for edge in EDGES
                       for rev in ([False,True] if IN_CT else [False])), 'Foreign policy priority conflict'
    if IN_CT:
        assert 'default dev tun119' in run(['ip','route','show','table','119']).stdout
        run(['ip','link','show','tun119'])
        existing=run(['ip','route','show','table',RETURN_TABLE],check=False).stdout
        assert all(l.startswith((SOURCE+' via 10.10.10.1 dev eth0',
                                 SOURCE+'/32 via 10.10.10.1 dev eth0','blackhole default')) for l in existing.splitlines()), 'Return table conflict'
    else:
        existing=run(['ip','route','show','table',HOST_TABLE],check=False).stdout
        assert all(l.startswith(('default via 10.10.10.20 dev vmbr1','blackhole default')) for l in existing.splitlines()), 'Host table conflict'
        assert run(['sysctl','-n','net.ipv4.conf.all.send_redirects']).stdout.strip() == '0'
        assert run(['sysctl','-n','net.ipv4.conf.vmbr1.send_redirects']).stdout.strip() == '0'


def policy(edge, reverse=False, remove=False):
    rows=json.loads(run(['ip','-j','-4','rule','show']).stdout)
    found=matching(rows,edge,reverse)
    if remove:
        for _ in found: run(['ip','rule','del']+rule(edge,reverse))
    elif not found:
        run(['ip','rule','add']+rule(edge,reverse))


def nat(edge, remove=False):
    args=['POSTROUTING','-s',SOURCE+'/32','-d',edge+'/32','-p','tcp','--dport','7844',
          '-o','tun119','-m','comment','--comment',COMMENT,'-j','MASQUERADE']
    exists=run(['iptables','-w','10','-t','nat','-C']+args,check=False).returncode==0
    if remove and exists:run(['iptables','-w','10','-t','nat','-D']+args)
    elif not remove and not exists:run(['iptables','-w','10','-t','nat','-A']+args)


def apply():
    preflight()
    if IN_CT:
        run(['ip','route','replace','blackhole','default','table',RETURN_TABLE,'metric','1000'])
        run(['ip','route','replace',SOURCE+'/32','via','10.10.10.1','dev','eth0','table',RETURN_TABLE])
        for edge in EDGES:
            policy(edge,reverse=True)
            nat(edge)
            policy(edge)
    else:
        ct('preflight')
        ct('apply')
        run(['ip','route','replace','blackhole','default','table',HOST_TABLE,'metric','1000'])
        run(['ip','route','replace','default','via','10.10.10.20','dev','vmbr1','table',HOST_TABLE,'metric','10'])
        for edge in EDGES:policy(edge)


def clear_stale(prefix=()):
    # Only this VM's Cloudflare TCP/7844 tuples; SSH and all other state survive.
    removed=0
    for edge in EDGES:
        r=run(list(prefix)+['conntrack','-D','-p','tcp','--orig-src',SOURCE,'--orig-dst',edge,'--dport','7844'],check=False)
        if r.returncode==0:removed+=1
    return removed


def clear_ct_stale():
    # Use the host tool in CT113's network namespace; install nothing in the CT.
    pid=run(['lxc-info','-n','113','-pH']).stdout.strip()
    assert pid.isdigit()
    return clear_stale(['nsenter','--target',pid,'--net'])


def rollback():
    for edge in EDGES:policy(edge,remove=True)
    if IN_CT:
        for edge in EDGES:
            policy(edge,reverse=True,remove=True)
            nat(edge,remove=True)
        run(['ip','route','del',SOURCE+'/32','via','10.10.10.1','dev','eth0','table',RETURN_TABLE],check=False)
        run(['ip','route','del','blackhole','default','table',RETURN_TABLE,'metric','1000'],check=False)
    else:
        run(['ip','route','del','default','via','10.10.10.20','dev','vmbr1','table',HOST_TABLE,'metric','10'],check=False)
        run(['ip','route','del','blackhole','default','table',HOST_TABLE,'metric','1000'],check=False)
        ct('rollback')
        clear_stale()
        clear_ct_stale()


def verify():
    preflight()
    if IN_CT:
        for edge in EDGES:
            assert 'dev tun119' in route_get(edge)
            output=run(['ip','-4','route','get',SOURCE,'from',edge,'iif','tun119',
                        'ipproto','tcp','sport','7844','dport','40000']).stdout
            assert 'via 10.10.10.1' in output, output
        return {'outbound':'tun119','return':'via host 10.10.10.1','edges':len(EDGES)}
    for edge in EDGES:assert 'via 10.10.10.20' in route_get(edge)
    assert 'via 85.9.108.113' in route_get('1.1.1.1',port='443')
    assert 'via 85.9.108.113' in route_get(EDGES[0],proto='udp')
    assert 'via 85.9.108.113' in route_get('1.1.1.1',port='7844')
    assert 'via 85.9.108.113' in route_get(EDGES[0],src='10.10.10.62')
    return {'vm116_tcp7844':'CT113','ct':ct('verify'),
            'ordinary_udp_nonedge_other_vm':'unchanged'}


if not IN_CT:
    assert run(['hostname']).stdout.strip()=='ir.servernet.cloud'
    os.umask(0o077)
    if os.name == 'posix':
        import fcntl
        LOCK = open('/run/servernet-vm116-cloudflare.lock','a')
        fcntl.flock(LOCK,fcntl.LOCK_EX)
if ACTION=='snapshot':result=snapshot()
elif ACTION=='preflight':preflight();result={'preflight':'ok'}
elif ACTION=='prepare':
    assert not IN_CT
    BASE.mkdir(mode=0o700)
    (BASE/'before-host.json').write_text(json.dumps(snapshot()))
    (BASE/'before-ct113.json').write_text(json.dumps(ct('snapshot')))
    result={'backup':str(BASE),'utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
elif ACTION=='apply':apply();result={'applied':True}
elif ACTION=='verify':result=verify()
elif ACTION=='clear-stale':result={'edge_tuples_cleared':clear_stale()}
elif ACTION=='refresh':
    assert not IN_CT
    result={'host':clear_stale(),'ct':clear_ct_stale()}
elif ACTION=='rollback':rollback();result={'rolled_back':True}
else:raise ValueError(ACTION)
print(json.dumps(result),flush=True)
