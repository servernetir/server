#!/usr/bin/python3
"""Read only connector registration evidence from a short-lived VM116 snapshot."""
import datetime
import json
import pathlib
import re
import subprocess
import time

def run(args, check=True):
    return subprocess.run(args, text=True, capture_output=True, check=check, timeout=60)

volume = 'vmstore/vm-116-disk-0'
tag = 'peeta-cf-logcheck-20261017T1017'
snapshot = volume + '@' + tag
mount = pathlib.Path('/root/servernet-vm116-cloudflare-20261017/logcheck')
old = run(['zfs','get','-H','-o','value,source','snapdev',volume]).stdout.strip().split('\t')
assert old == ['hidden', 'default'], old
assert run(['zfs','list','-H',snapshot],check=False).returncode != 0
loop = None
made = False
mounted = False
results = []
connectors = {}

def inspect(message, origin, timestamp):
    # Emit no raw messages, names, account details or tunnel identifiers.
    if 'Registered tunnel connection' not in message:
        return
    index = re.search(r'connIndex[=": ]+(\d+)', message)
    edge = re.search(r'ip[=": ]+(198\.41\.(?:192|200)\.\d+)', message)
    location = re.search(r'location[=": ]+([a-z]{3}\d+)', message)
    results.append({'source':origin,'time':timestamp,
                    'connIndex':index.group(1) if index else None,
                    'edge':edge.group(1) if edge else None,
                    'location':location.group(1) if location else None})

try:
    run(['zfs','snapshot',snapshot]); made = True
    run(['zfs','set','snapdev=visible',volume])
    device = '/dev/zvol/' + snapshot
    for _ in range(30):
        if pathlib.Path(device).exists(): break
        time.sleep(0.2)
    assert pathlib.Path(device).exists()
    loop = run(['losetup','--find','--show','--read-only','--offset',str(2099200*512),
                '--sizelimit',str(319813599*512),device]).stdout.strip()
    assert re.fullmatch(r'/dev/loop\d+',loop)
    mount.mkdir(mode=0o700)
    run(['mount','-t','ext4','-o','ro,noload,noexec,nosuid,nodev',loop,str(mount)]); mounted=True
    journal = mount / 'var/log/journal'
    if journal.is_dir():
        output = run(['journalctl','--directory',str(journal),'--since','2026-10-17 10:04:00 UTC',
                      '--no-pager','--output=json','--grep','Registered tunnel connection'],check=False)
        for line in output.stdout.splitlines():
            try: row=json.loads(line)
            except ValueError: continue
            message=row.get('MESSAGE','')
            if not isinstance(message,str): continue
            stamp=datetime.datetime.fromtimestamp(int(row.get('__REALTIME_TIMESTAMP',0))/1e6,datetime.timezone.utc).isoformat()
            unit=row.get('_SYSTEMD_UNIT','unknown')
            # Identify distinct services without emitting names or UUIDs.
            identity=unit if unit != 'unknown' else row.get('_PID','unknown')
            if identity not in connectors: connectors[identity]='connector-'+str(len(connectors)+1)
            origin=connectors[identity]
            inspect(message,origin,stamp)
    docker=mount/'var/lib/docker/containers'
    docker_files=list(docker.glob('*/*-json.log')) if docker.is_dir() and not results else []
    for number, path in enumerate(docker_files):
        # Read at most the latest 8 MiB of each runtime log; never config/env.
        with path.open('rb') as stream:
            stream.seek(max(0,path.stat().st_size-8*1024*1024))
            for line in stream:
                try: row=json.loads(line)
                except ValueError: continue
                if row.get('time','') < '2026-10-17T10:04:00': continue
                inspect(row.get('log',''),'container-'+str(number+1),row.get('time'))
    print(json.dumps({'registrations':results,'docker_log_files':len(docker_files),'snapshot_read_only':True}),flush=True)
finally:
    if mounted: run(['umount',str(mount)])
    if loop: run(['losetup','-d',loop])
    if made:
        run(['zfs','inherit','snapdev',volume])
        run(['zfs','destroy',snapshot])
    if mount.is_dir(): mount.rmdir()
    print(json.dumps({'cleanup':'complete','snapdev':run(['zfs','get','-H','-o','value,source','snapdev',volume]).stdout.strip()}),flush=True)
