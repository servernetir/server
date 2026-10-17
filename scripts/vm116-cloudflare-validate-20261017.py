#!/usr/bin/python3
"""Final regression receipt; prints only counts and health flags."""
import datetime
import hashlib
import json
from pathlib import Path
import re
import subprocess
import urllib.request

base=Path('/root/servernet-vm116-cloudflare-20261017')
helper=Path('/usr/local/sbin/servernet-vm116-cloudflare-egress')
def run(args,input=None):
    return subprocess.run(args,input=input,text=True,capture_output=True,check=True,timeout=45).stdout
def snap(ct=False):
    if ct:
        return json.loads(run(['pct','exec','113','--','python3','-','--ct','snapshot'],helper.read_text()))
    return json.loads(run([str(helper),'snapshot']))
def normalized(value):
    return [line for line in re.sub(r'\[\d+:\d+\]','[counter]',value).splitlines() if not line.startswith('#')]
before=json.loads((base/'before-host.json').read_text());host=snap()
oldct=json.loads((base/'before-ct113.json').read_text());ct=snap(True)
checks={key:before[key]==host[key] for key in ['default','guests','containers','host_helper_sha256']}
checks['host_firewall_unchanged']=normalized(before['iptables'])==normalized(host['iptables'])
prior=set(normalized(oldct['iptables']));now=set(normalized(ct['iptables']))
checks['ct_no_removed_firewall_rules']=not prior-now
extra=now-prior
checks['ct_only_scoped_nat_added']=len(extra)==20 and all('servernet-vm116-cloudflare' in row and '10.10.10.61/32' in row and '--dport 7844' in row for row in extra)
checks['owner_ready']=[urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/ready',timeout=5).status for port in [20241,20243,20244]]
checks['relay_verify']=json.loads(run([str(helper),'verify']))
checks['persistent_enabled']=run(['systemctl','is-enabled','servernet-vm116-cloudflare-egress.service','servernet-vm116-cloudflare-egress.timer']).splitlines()==['enabled','enabled']
checks['persistent_result']=run(['systemctl','show','servernet-vm116-cloudflare-egress.service','-p','Result','--value']).strip()
checks['timer_active']=run(['systemctl','is-active','servernet-vm116-cloudflare-egress.timer']).strip()
checks['ssh_dnat_active']=run(['systemctl','is-active','vm116-ssh-dnat.service']).strip()
checks['temporary_rollback_inactive']=subprocess.run(['systemctl','is-active','servernet-vm116-cf-rollback-20261017.timer'],capture_output=True,text=True).stdout.strip()=='inactive'
flows=run(['conntrack','-L','-p','tcp','--orig-src','10.10.10.61','--dport','7844'])
checks['vm116_established_assured']=sum('ESTABLISHED' in line and '[ASSURED]' in line for line in flows.splitlines())
pid=run(['lxc-info','-n','113','-pH']).strip();assert pid.isdigit()
flowsct=run(['nsenter','--target',pid,'--net','conntrack','-L','-p','tcp','--orig-src','10.10.10.61','--dport','7844'])
checks['relay_masqueraded_established']=sum('ESTABLISHED' in line and '[ASSURED]' in line and 'dst=10.119.0.1 ' in line for line in flowsct.splitlines())
checks['utc']=datetime.datetime.now(datetime.timezone.utc).isoformat()
(base/'persistent-host.json').write_text(json.dumps(host))
(base/'persistent-ct113.json').write_text(json.dumps(ct))
(base/'validation.json').write_text(json.dumps(checks,indent=2))
print(json.dumps(checks),flush=True)
assert all(checks[key] is True for key in ['default','guests','containers','host_helper_sha256','host_firewall_unchanged','ct_no_removed_firewall_rules','ct_only_scoped_nat_added','persistent_enabled','temporary_rollback_inactive'])
assert checks['owner_ready']==[200,200,200]
assert checks['persistent_result']=='success' and checks['timer_active']=='active' and checks['ssh_dnat_active']=='active'
assert checks['vm116_established_assured']>=12 and checks['relay_masqueraded_established']>=12
