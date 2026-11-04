"""Private read-only isolated candidate evidence producer; no scheduler wiring.

The caller supplies two already authenticated accept contexts. The CT projection
contains the public SSH pin required by NodeStage; the guest context contains the
strict isolated profile. Neither is an ordinary publisher accept or driver proof.
Only fixed local/CT113 dependency reads, NodeStage.accept and the existing native
Windows candidate client run. No stage/apply, proxy setup or default-path fallback
is provided. Missing private candidate enrollment is a HOLD, never implicit setup.

Dependencies are an explicitly pinned finite catalogue of files and loaded units,
not inferred whole-host inventory. Its digest must be the authenticated request's
expected_registry_hash; its measured state must equal dependency_baseline_sha256
before and after both probes. Global coverage and factory integration are separate.
"""
from __future__ import annotations

import copy
import hashlib
import hmac
import os
from pathlib import Path
import re
import threading
import time

from . import agent, ct_stage, iran_stage, isolated_acceptance as isolated
from . import isolated_verifier_enrollment as verifier_enrollment
from . import windows_candidate_probe_client as candidate
from . import windows_probe_client as windows
from .protocol import HEX64, IDENTIFIER, ProtocolError, canonical_json, decode_json, private_file


SCHEMA = 'servernet.relay.isolated-trial.config.v1'
REGISTRY = 'servernet.relay.isolated-trial.dependencies.v1'
OBSERVATION = 'servernet.relay.isolated-trial.observation.v1'
REFS = {'node_stage', 'windows', 'candidate_proxy', 'verifier', 'dependencies'}
UNIT = re.compile(r'\A[A-Za-z0-9_][A-Za-z0-9_.@:-]{0,118}\.(?:service|timer)\Z')
PATH = re.compile(r'\A/[A-Za-z0-9_./@:-]{1,220}\Z')
DEADLINE = 24

# The same fixed reader runs locally or in CT113. stdin is private catalogue
# data, never code. Its output contains only hashes, bounded unit properties and
# clocks, never file contents/argv/environment. No mutation commands are present.
DEPENDENCY_READER = r'''import hashlib,json,os,pathlib,stat,subprocess,sys,time
def require(value):
 if not value: raise ValueError('dependency_hold')
def identity(s): return (s.st_dev,s.st_ino,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
def collect(resources):
 result=[];total=0
 for resource in resources:
  if resource['kind']=='file':
   path=pathlib.Path(resource['path'])
   for p in path.parents:
    s=p.lstat();require(stat.S_ISDIR(s.st_mode) and not p.is_symlink() and s.st_uid==0 and not s.st_mode&0o022)
   fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
   try:
    s=os.fstat(fd);require(stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_nlink==1 and not s.st_mode&0o022 and s.st_size<=1048576)
    total+=s.st_size;require(total<=4194304)
    digest=hashlib.sha256();count=0
    while True:
     part=os.read(fd,min(65536,1048577-count))
     if not part:break
     count+=len(part);require(count<=s.st_size);digest.update(part)
    require(count==s.st_size and identity(s)==identity(os.fstat(fd)) and identity(s)==identity(path.lstat()))
    value={'sha256':digest.hexdigest(),'bytes':count}
   finally:os.close(fd)
  else:
   fields=('Id','LoadState','ActiveState','SubState','MainPID','NeedDaemonReload','ActiveEnterTimestampMonotonic','ExecMainStartTimestampMonotonic')
   command=['/usr/bin/systemctl','show',resource['unit'],'--no-pager','--property='+','.join(fields)]
   output=subprocess.run(command,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=1,env={'PATH':'/usr/bin:/bin','LANG':'C','LC_ALL':'C'})
   require(output.returncode==0 and len(output.stdout)<=4096)
   pairs=[line.split('=',1) for line in output.stdout.decode('ascii').splitlines()]
   require(all(len(p)==2 for p in pairs));value=dict(pairs)
   require(len(value)==len(pairs) and set(value)==set(fields) and value['Id']==resource['unit'] and value['LoadState']=='loaded' and value['NeedDaemonReload']=='no')
   require(all(len(v)<=160 and '\n' not in v for v in value.values()))
  result.append({'id':resource['id'],'kind':resource['kind'],'state':value})
 return result
try:
 resources=json.loads(sys.argv[1]);require(type(resources) is list and 1<=len(resources)<=32)
 result={'observed_at':int(time.time()),'resources':collect(resources)}
 print(json.dumps(result,sort_keys=True,separators=(',',':')),flush=True)
except Exception:
 print('{"error":"dependency_hold"}',flush=True);sys.exit(1)
'''


def require(value, code='isolated_trial_hold'):
    if not value:
        raise ProtocolError(code)


def _pin(reference, *, root_owned=True, limit=32768):
    require(isinstance(reference, dict) and set(reference) == {'path', 'sha256'}
            and isinstance(reference['sha256'], str) and HEX64.fullmatch(reference['sha256']),
            'isolated_trial_pin')
    path = private_file(reference['path'], root_owned=root_owned)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as source:
        before = os.fstat(source.fileno())
        require(before.st_nlink == 1 and before.st_size <= limit, 'isolated_trial_pin')
        raw = source.read(limit + 1)
        after = os.fstat(source.fileno())
    require(len(raw) == before.st_size and len(raw) <= limit
            and (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
            == (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
            and hmac.compare_digest(hashlib.sha256(raw).hexdigest(), reference['sha256']), 'isolated_trial_pin')
    value = decode_json(raw)
    require(raw == canonical_json(value), 'isolated_trial_canonical')
    return value


def _registry(value, agent_id):
    require(isinstance(value, dict) and set(value) == {'schema', 'agent_id', 'resources'}
            and value['schema'] == REGISTRY and value['agent_id'] == agent_id,
            'isolated_trial_dependency_registry')
    resources = value['resources']
    require(isinstance(resources, list) and 2 <= len(resources) <= 32,
            'isolated_trial_dependency_registry')
    ids, bindings = set(), set()
    for resource in resources:
        require(isinstance(resource, dict) and resource.get('kind') in {'file', 'unit'}
                and resource.get('location') in {'pve', 'ct113'}
                and isinstance(resource.get('id'), str) and IDENTIFIER.fullmatch(resource['id']),
                'isolated_trial_dependency_resource')
        field = 'path' if resource['kind'] == 'file' else 'unit'
        require(set(resource) == {'id', 'kind', 'location', field}, 'isolated_trial_dependency_resource')
        target = resource[field]
        require(isinstance(target, str), 'isolated_trial_dependency_resource')
        if field == 'path':
            require(PATH.fullmatch(target) and '..' not in Path(target).parts
                    and str(Path(target)) == target and not target.startswith('//')
                    and not target.startswith(('/proc/', '/sys/', '/dev/')), 'isolated_trial_dependency_path')
        else:
            require(UNIT.fullmatch(target), 'isolated_trial_dependency_unit')
        binding = (resource['location'], field, target)
        require(resource['id'] not in ids and binding not in bindings, 'isolated_trial_dependency_duplicate')
        ids.add(resource['id']); bindings.add(binding)
    require({'file', 'unit'} <= {r['kind'] for r in resources}, 'isolated_trial_dependency_coverage')
    return copy.deepcopy(value)


class IsolatedTrialProducer:
    """Finite private API; no injectable observer, guest envelope or success flag.

    ``root_owned=False`` is for disposable non-root offline fixtures only. The
    production default enforces existing root private-file/parent checks. The
    only process/network implementations are the fixed readers, NodeStage and
    WindowsCandidateProbeClient; their OS transports can be patched in tests.
    """
    def __init__(self, reference, *, clock=lambda: int(time.time()), monotonic=time.monotonic,
                 root_owned=True):
        self.reference = copy.deepcopy(reference)
        self.root_owned, self.clock, self.monotonic = root_owned, clock, monotonic
        self.last_now = None
        self._busy = threading.Lock()
        self.config = _pin(reference, root_owned=root_owned)
        require(set(self.config) == {'schema', 'agent_id', 'enabled', *REFS}
                and self.config['schema'] == SCHEMA and self.config['enabled'] is True
                and isinstance(self.config['agent_id'], str) and IDENTIFIER.fullmatch(self.config['agent_id']),
                'isolated_trial_not_enrolled')
        # Validate the explicit candidate path before any guest key/state access.
        require(self.config['candidate_proxy'] is not None, 'isolated_trial_candidate_enrollment_missing')
        self.proxy = _pin(self.config['candidate_proxy'], root_owned=root_owned, limit=4096)
        candidate.validate_enrollment(self.proxy, self.config['agent_id'])
        self.refs = {key: copy.deepcopy(self.config[key]) for key in REFS}
        self.pinned = {key: _pin(ref, root_owned=root_owned) for key, ref in self.refs.items()}
        self.dependencies = _registry(self.pinned['dependencies'], self.config['agent_id'])
        guest_config = self.pinned['windows']
        require(guest_config.get('agent_id') == self.config['agent_id'], 'isolated_trial_agent_scope')
        self.node = self.guest = self.verifier = None

    def _node_client(self):
        if self.node is None:
            node_config = iran_stage.load_config(self.refs['node_stage']['path'], root_owned=self.root_owned)
            expected = copy.deepcopy(self.pinned['node_stage'])
            expected.update(ssh_port=expected.get('ssh_port', 22), root_owned=self.root_owned)
            require(node_config == expected, 'isolated_trial_node_pin_changed')
            require(node_config['agent_id'] == self.config['agent_id'], 'isolated_trial_agent_scope')
            self.node = iran_stage.NodeStage(node_config, now=self._now, runner=self._node_process)

    def _node_process(self, argv, data, timeout):
        # NodeStage retains its own smaller limits; every fixed subprocess also
        # shares this composite's deadline, including pins and job delivery.
        remaining = self.deadline - self.monotonic()
        require(remaining > 0, 'isolated_trial_deadline')
        return agent._bounded_process(argv, data, min(timeout, remaining))

    def _clients(self):
        self._node_client()
        guest_config = self.pinned['windows']
        self.guest = candidate.WindowsCandidateProbeClient(guest_config, self.proxy, clock=self._now,
            monotonic=self.monotonic, root_owned=self.root_owned)
        self.verifier = verifier_enrollment.load_verifier(self.refs['verifier'],
            agent_id=self.config['agent_id'], root_owned=self.root_owned, now=self._now)
        require(self.verifier is not None, 'isolated_trial_verifier_not_enrolled')
        require(Path(self.verifier.enrollment['state_dir']).resolve()
                != Path(guest_config['state_dir']).resolve(), 'isolated_trial_replay_separation')
        require(self.verifier.enrollment['guest_key_file'] == guest_config['key_file']
                and self.verifier.enrollment['access_policy'] == guest_config.get('access_policy', windows.DEFAULT_ACCESS_POLICY),
                'isolated_trial_guest_key_policy')

    def _now(self):
        now = self.clock()
        require(type(now) is int and now >= 0 and (self.last_now is None or now >= self.last_now),
                'isolated_trial_clock')
        self.last_now = now
        return now

    def _guard(self):
        require(_pin(self.reference, root_owned=self.root_owned) == self.config, 'isolated_trial_pin')
        for key, ref in self.refs.items():
            require(_pin(ref, root_owned=self.root_owned) == self.pinned[key], 'isolated_trial_pin')
        if self.verifier is not None:
            self.verifier.guard()
        require(self.monotonic() < self.deadline, 'isolated_trial_deadline')

    def _snapshot(self):
        self._guard(); self.node._pins()
        rows = []
        started = self._now()
        for location in ('pve', 'ct113'):
            selected = [r for r in self.dependencies['resources'] if r['location'] == location]
            if not selected:
                continue
            argv = ['/usr/bin/python3', '-I', '-B', '-c', DEPENDENCY_READER, canonical_json(selected).decode()]
            if location == 'ct113':
                argv = [iran_stage.PCT, 'exec', '113', '--keep-env', '0', '--', *argv]
            timeout = min(3, self.deadline - self.monotonic())
            require(timeout > 0, 'isolated_trial_deadline')
            status, raw = agent._bounded_process(argv, b'', timeout)
            require(type(status) is int and status == 0 and isinstance(raw, bytes) and len(raw) <= 16384,
                    'isolated_trial_dependency_read')
            result = decode_json(raw)
            require(isinstance(result, dict) and set(result) == {'observed_at', 'resources'}
                    and type(result['observed_at']) is int and started <= result['observed_at'] <= self._now()
                    and isinstance(result['resources'], list) and len(result['resources']) == len(selected),
                    'isolated_trial_dependency_read')
            for entry, expected in zip(result['resources'], selected):
                require(isinstance(entry, dict) and set(entry) == {'id', 'kind', 'state'}
                        and entry['id'] == expected['id'] and entry['kind'] == expected['kind']
                        and isinstance(entry['state'], dict) and entry['state'], 'isolated_trial_dependency_read')
                rows.append({'location': location, **entry})
        self._guard()
        return hashlib.sha256(canonical_json(rows)).hexdigest(), started

    def inspect_dependencies(self):
        """Read the enrolled finite baseline; never a readiness observation."""
        require(self._busy.acquire(blocking=False), 'isolated_trial_busy')
        try:
            self.deadline = self.monotonic() + DEADLINE
            self._guard(); self._node_client()
            digest, observed = self._snapshot()
            return dict(read_only=True, registry_sha256=self.refs['dependencies']['sha256'],
                state_sha256=digest, resource_count=len(self.dependencies['resources']), observed_at=observed)
        finally:
            self._busy.release()

    def _contexts(self, context, ct_context):
        p = isolated.parameters(context)
        candidate.request_body(context, self.proxy, self.config['agent_id'])  # exact guest grammar
        ct_stage.validate_context(ct_context, self.config['agent_id'])
        require(ct_context['job']['op'] == 'accept'
                and canonical_json({k: ct_context[k] for k in isolated.SCOPE})
                == canonical_json({k: context[k] for k in isolated.SCOPE})
                and ct_context['job']['id'] == context['job']['id']
                and ct_context['job']['target'] == context['job']['target'], 'isolated_trial_context_binding')
        q = ct_context['job']['params']
        fields = {'stage_profile', 'resource_identity', 'resource_config_hash', 'expected_registry_hash'}
        if p['stage_profile'] == 'restored':
            fields |= {'restore_epoch', 'restore_hash'}
        require(set(q) == fields | {'ssh_host_public_key', 'ssh_host_key_sha256'}
                and canonical_json({k: q.get(k) for k in fields}) == canonical_json({k: p[k] for k in fields})
                and p['expected_registry_hash'] == self.refs['dependencies']['sha256'], 'isolated_trial_context_binding')
        return p

    def produce(self, context, ct_context):
        """Produce and verify one fresh isolated proof, never global readiness."""
        require(self._busy.acquire(blocking=False), 'isolated_trial_busy')
        try:
            return self._produce(context, ct_context)
        finally:
            self._busy.release()

    def _produce(self, context, ct_context):
        context, ct_context = copy.deepcopy(context), copy.deepcopy(ct_context)
        self.deadline = self.monotonic() + DEADLINE
        p = self._contexts(context, ct_context); self._guard(); self._clients()
        before, before_at = self._snapshot()
        require(before == p['dependency_baseline_sha256'], 'isolated_trial_dependency_baseline')
        receipt = self.node.run('accept', ct_context, fresh_accept=True)
        ct_at = self._now()
        require(isinstance(receipt, dict) and receipt.get('schema') == iran_stage.RECIPE_SCHEMA
                and receipt.get('action') == 'accept' and receipt.get('read_only') is True and receipt.get('ok') is True
                and receipt.get('target') == context['job']['target']
                and canonical_json({k: receipt.get(k) for k in iran_stage.SCOPE})
                == canonical_json({k: context[k] for k in iran_stage.SCOPE})
                and receipt.get('checks') == {'accepted': True, 'generation_verified': True},
                'isolated_trial_ct_accept')
        self._guard()
        require(self.deadline - self.monotonic() >= windows.DEADLINE, 'isolated_trial_deadline')
        guest = self.guest.probe(context)
        after, _ = self._snapshot(); after_at = self._now()
        require(before == after, 'isolated_trial_dependencies_changed')
        bound = dict(scope={k: context[k] for k in isolated.SCOPE}, target=context['job']['target'],
            stage_profile=p['stage_profile'], resource_identity=p['resource_identity'],
            resource_config_hash=p['resource_config_hash'], restore_epoch=p.get('restore_epoch'),
            restore_hash=p.get('restore_hash'), proxy_binding_sha256=p['proxy_binding_sha256'])
        ct = dict(schema=isolated.CT_SCHEMA, **bound, read_only=True, fresh_probe=True,
            historical_outcome=False, accepted=True, generation_verified=True,
            socks=ct_stage.STAGE_SOCKS, observed_at=ct_at)
        registry = dict(schema=isolated.REGISTRY_SCHEMA, scope=bound['scope'], target=bound['target'],
            read_only=True, coverage_complete=True, resource_count=len(self.dependencies['resources']),
            registry_sha256=p['expected_registry_hash'], before_state_sha256=before,
            after_state_sha256=after, before_observed_at=before_at, after_observed_at=after_at)
        recipe = dict(action='accept', read_only=True, ok=True,
            checks={'accepted': True, 'generation_verified': True},
            isolated_evidence=dict(schema=isolated.SCHEMA, ct=ct, registry=registry, guest=guest))
        self._guard()
        proof = self.verifier.verify(context, recipe)
        return dict(schema=OBSERVATION, verified=True, read_only=True,
            context_hash=hashlib.sha256(canonical_json(context)).hexdigest(), observed_at=proof['observed_at'],
            received_at=self._now(), isolated_candidate=proof,
            default_path_verified=False, global_cutover_performed=False)
