"""Durable cold-disk restoration before a separately implemented cutover.

advance() alone performs effects, under worker.lock. collect() only reads local
state. Missing real network/readiness/consumer callbacks keep the source intact.
An attempted source fence forbids generic candidate cleanup and rollback: its
remote generation tombstone is irreversible. Unknown writes only reconcile.

Callbacks receive a scope dictionary, never shell text. They must return fresh
verified, identity/config/incident-bound observations, not success booleans.
The lifecycle factory is (full_identity, BootstrapArtifact|None, authorizer) ->
LifecycleClient. Default source/candidate clients use private local config and
the immutable BootstrapStore host pin. No DNS, CRM or global Iran cutover is
performed here. PRE_CUTOVER_READY is explicitly not migration completion.
"""
from __future__ import annotations

import argparse
import contextlib
import dataclasses
import fcntl
import hashlib
import json
from decimal import Decimal
import os
from pathlib import Path
import re
import sqlite3
import stat
import time
import uuid

from . import core
from .bootstrap import BootstrapStore
from .gateway import private_file
from .provider_adapter import ProviderEffects, _directory
from .snapshot_adapter import SnapshotAdapter


SCHEMA = 'servernet.relay.restore.workflow.v1'
READINESS = ('headless_controller_ready', 'independent_control_verified', 'automation_daemon_verified',
    'source_business_guards_ready', 'external_backup_ready', 'candidate_bootstrap_identity_verified',
    'consumer_driver_ready', 'snapshot_quota_verified', 'live_dependencies_unchanged')
STAGES = {'FENCE_SOURCE':'FENCE', 'SHUTDOWN_SOURCE':'PREPARE_SHUTDOWN',
          'FINAL_SNAPSHOT':'PREPARE_SNAPSHOT', 'REBUILD_CANDIDATE':'PREPARE_REBUILD',
          'ADOPT_CLONE':'ADOPT_CLONE', 'ACTIVATE_CANDIDATE':'ACTIVATE'}


class RestoreError(core.RecoveryError): pass


def _sha(value):
    return isinstance(value,str) and re.fullmatch('[a-f0-9]{64}',value) is not None


def _uuid(value):
    try: return isinstance(value,str) and str(uuid.UUID(value)) == value
    except (ValueError,AttributeError): return False


class WorkflowStore:
    def __init__(self, root):
        self.root = _directory(Path(root)); self.path = self.root/'restore-workflow.sqlite3'
        self.lock = self.root/'restore-workflow.lock'
        with self.transaction() as db:
            db.executescript('''
              CREATE TABLE IF NOT EXISTS workflows(id TEXT PRIMARY KEY,candidate_id TEXT UNIQUE NOT NULL,body TEXT NOT NULL);
              CREATE TABLE IF NOT EXISTS actions(id TEXT PRIMARY KEY,workflow_id TEXT NOT NULL,kind TEXT NOT NULL,
                body TEXT NOT NULL,status TEXT NOT NULL,result TEXT);
              CREATE TABLE IF NOT EXISTS authorizations(id TEXT PRIMARY KEY,action_id TEXT NOT NULL,permit TEXT NOT NULL);
            ''')
        os.chmod(self.path,0o600)

    @contextlib.contextmanager
    def transaction(self):
        if self.lock.exists() or self.lock.is_symlink(): private_file(self.lock)
        fd=os.open(self.lock,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600);db=None
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            if self.path.exists() or self.path.is_symlink(): private_file(self.path)
            mask=os.umask(0o077)
            try:db=sqlite3.connect(self.path,timeout=0,isolation_level=None)
            finally:os.umask(mask)
            db.row_factory=sqlite3.Row;db.execute('PRAGMA synchronous=FULL');db.execute('BEGIN IMMEDIATE')
            yield db
            if db.in_transaction:db.commit()
        except Exception:
            if db is not None and db.in_transaction:db.rollback()
            raise
        finally:
            if db is not None:db.close()
            os.close(fd)

    def get(self, candidate_id=None, workflow_id=None):
        with self.transaction() as db:
            if workflow_id is not None:row=db.execute('SELECT body FROM workflows WHERE id=?',(workflow_id,)).fetchone()
            elif candidate_id is not None:row=db.execute('SELECT body FROM workflows WHERE candidate_id=?',(candidate_id,)).fetchone()
            else:row=db.execute('SELECT body FROM workflows ORDER BY rowid DESC LIMIT 1').fetchone()
        return json.loads(row['body']) if row else None

    def save(self,w):
        with self.transaction() as db:
            row=db.execute('SELECT body FROM workflows WHERE id=?',(w['id'],)).fetchone()
            if row:
                old=json.loads(row['body'])
                for key in ('id','source','candidate','create_intent_id','incident_id'):
                    if old[key]!=w[key]:raise RestoreError('immutable_restore_scope_changed')
                if old.get('fence_attempted') and not w.get('fence_attempted'):
                    raise RestoreError('cannot_erase_source_fence_history')
            db.execute('INSERT INTO workflows VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET body=excluded.body',
                       (w['id'],w['candidate']['provider_id'],core.canonical(w)))

    def action(self, operation_id):
        with self.transaction() as db:row=db.execute('SELECT * FROM actions WHERE id=?',(operation_id,)).fetchone()
        if row is None:raise RestoreError('workflow_action_missing')
        return dict(row)


class RestoreWorkflow:
    def __init__(self,config,*,provider=None,bootstrap=None,snapshots=None,lifecycle_factory=None,
                 callbacks=None,now=lambda:int(time.time())):
        self.config,self.now=config,now;self.root=_directory(Path(config['state_dir']))
        self.options=config.get('restore_workflow',{});self.store=WorkflowStore(self.root)
        values=dict(config.get('policy',{}))
        if 'critical_consumers'in values:values['critical_consumers']=tuple(values['critical_consumers'])
        self.policy=core.Config(**values);self.policy.validate()
        self.provider=provider;self.bootstrap=bootstrap;self.snapshots=snapshots
        self.lifecycle_factory=lifecycle_factory;self.callbacks=callbacks or {};self.clients={}
        if snapshots is not None:
            prior=getattr(snapshots,'authorize',None)
            def authorize(p):
                approved=self.authorize_snapshot(p)
                return approved and (prior is None or prior(p) is not False)
            snapshots.authorize=authorize

    def _provider(self):
        if self.provider is None:self.provider=ProviderEffects(self.config,now=self.now)
        return self.provider

    def _bootstrap(self):
        if self.bootstrap is None:self.bootstrap=BootstrapStore(self.config)
        return self.bootstrap

    def _snapshots(self):
        if self.snapshots is None:self.snapshots=SnapshotAdapter(self._provider(),authorize=self.authorize_snapshot,now=self.now)
        return self.snapshots

    def _core(self):
        path=self.root/'controller.sqlite3';private_file(path)
        with sqlite3.connect(path.as_uri()+'?mode=ro',uri=True) as db:
            db.row_factory=sqlite3.Row
            state=db.execute('SELECT body FROM state WHERE id=1').fetchone()
            pending=db.execute('SELECT 1 FROM actions WHERE receipt IS NULL LIMIT 1').fetchone()
        if state is None:raise RestoreError('core_state_missing')
        return json.loads(state['body']),bool(pending)

    def _scope(self,w=None):
        s,pending=self._core();now=self.now()
        if (s.get('clock_unsafe') is not False or now<s['last_now'] or now-s['last_now']>self.policy.receipt_max_age
                or s['phase']!='VALIDATING' or s.get('candidate') is None or s.get('retiring') is not None
                or s.get('pending_create') is not None or s.get('pending_action') is not None or pending):
            raise RestoreError('core_restore_scope_not_quiescent')
        if (s['active']['provider']!='hetzner' or s['active']['project_id']!=self.policy.project_id
                or not _sha(s['active'].get('config_hash')) or not _sha(s['candidate'].get('config_hash'))):
            raise RestoreError('unadopted_restore_scope')
        if w is not None:
            if (core.identity(s['active'])!=core.identity(w['source']) or core.identity(s['candidate'])!=core.identity(w['candidate'])
                    or s['active']['config_hash']!=w['source']['config_hash']
                    or s['candidate']['config_hash']!=w['candidate']['config_hash'] or s['incident_id']!=w['incident_id']):
                raise RestoreError('current_restore_identity_changed')
        return s

    @contextlib.contextmanager
    def _worker_lock(self,fd=None):
        path=self.root/'worker.lock';own=fd is None
        if path.exists() or path.is_symlink():private_file(path)
        if own:fd=os.open(path,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
        try:
            info=os.fstat(fd);actual=path.lstat()
            if (not stat.S_ISREG(info.st_mode) or (info.st_dev,info.st_ino)!=(actual.st_dev,actual.st_ino)
                    or info.st_uid!=os.geteuid() or info.st_mode&0o077):raise RestoreError('worker_lock_required')
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            yield
        finally:
            if own:os.close(fd)

    def _start(self,s):
        candidate=self._provider().store.read_resource(s['candidate']['provider_id'])
        create_id=candidate.labels.get('sn-intent')
        if candidate.has_writes or candidate.promoted_at is not None:raise RestoreError('trial_candidate_already_used')
        with sqlite3.connect((self.root/'controller.sqlite3').as_uri()+'?mode=ro',uri=True) as db:
            row=db.execute('SELECT body FROM actions WHERE id=? AND kind=?',(create_id,'CREATE')).fetchone()
            if row is None:raise RestoreError('candidate_core_create_missing')
            create=json.loads(row[0]);p=create['payload'];option=p['option']
            if (p['next_generation']!=s['candidate']['generation'] or option['config_hash']!=s['candidate']['config_hash']
                    or create['incident_id']!=s['incident_id'] or option['project_id']!=self.policy.project_id
                    or option['owner_id']!=self.policy.owner_id or option['service_id']!=self.policy.service_id):
                raise RestoreError('candidate_create_scope_changed')
            if p.get('purpose')=='installation_restore_drill':
                auth=db.execute('SELECT authorization FROM installation_trials WHERE intent_id=?',(create_id,)).fetchone()
                if auth is None:raise RestoreError('installation_trial_not_authorized')
        source_target=self._provider().probe_target(core.identity(s['active']))
        full_source={**s['active'],'ipv4':source_target['ipv4']}
        w=dict(id=str(uuid.uuid4()),source=full_source,candidate=s['candidate'],create_intent_id=create_id,
            incident_id=s['incident_id'],phase='PREPARE_BOOTSTRAP',created_at=self.now(),last_at=self.now(),
            pending_action=None,proofs={},fence_attempted=False,source_fenced=False,reason='TRIAL_NOT_YET_VERIFIED')
        self.store.save(w)
        self._prepare_bootstrap(w,create);return w

    def _prepare_bootstrap(self,w,create=None):
        if create is None:
            with sqlite3.connect((self.root/'controller.sqlite3').as_uri()+'?mode=ro',uri=True) as db:
                row=db.execute('SELECT body FROM actions WHERE id=?',(w['create_intent_id'],)).fetchone()
            if row is None:raise RestoreError('candidate_core_create_missing')
            create=json.loads(row[0])
        # prepare is immutable and must match the exact pre-CREATE artifact.
        trial=self._bootstrap().prepare(create,w['source'])
        target=self._provider().probe_target(core.identity(w['candidate']))
        bound=self._bootstrap().bind_candidate(w['create_intent_id'],core.identity(w['candidate']),target['ipv4'])
        if trial.public_host_fingerprint!=bound.public_host_fingerprint:raise RestoreError('trial_bootstrap_pin_changed')
        w['candidate_ipv4']=target['ipv4'];w['host_fingerprint']=bound.public_host_fingerprint
        w['phase']='VERIFY_TRIAL_NETWORK';w['bootstrap_prepared']=True
        self.store.save(w)

    def _context(self,w):
        s=self._scope(w)
        return dict(schema=SCHEMA,workflow_id=w['id'],create_intent_id=w['create_intent_id'],
            source_identity=core.identity(w['source']),identity=core.identity(w['candidate']),
            source_config_hash=w['source']['config_hash'],config_hash=w['candidate']['config_hash'],
            incident_id=w['incident_id'],generation=w['candidate']['generation'],candidate_ipv4=w.get('candidate_ipv4'),
            host_fingerprint=w.get('host_fingerprint'),restore_epoch=s['candidate'].get('restore_epoch'),
            restore_hash=s['candidate'].get('restore_hash'),now=self.now())

    def _bound(self,r,w,*,restored=False):
        if not isinstance(r,dict):return False
        c=self._context(w);at=r.get('received_at');observed=r.get('observed_at')
        good=(r.get('verified')is True and type(at)is int and type(observed)is int
            and 0<=self.now()-at<=self.policy.receipt_max_age and at- self.policy.receipt_max_age<=observed<=at+5
            and r.get('workflow_id')==w['id'] and r.get('identity')==c['identity']
            and r.get('source_identity')==c['source_identity'] and r.get('config_hash')==c['config_hash']
            and r.get('incident_id')==w['incident_id'] and r.get('generation')==c['generation'])
        if restored:
            s=self._scope(w)['candidate']
            good=good and core.Engine._restore_bound(r,s)
        return good

    def _callback(self,name,w):
        callback=self.callbacks.get(name)
        return callback(self._context(w)) if callable(callback) else None

    def _client(self,w,source=False):
        target=w['source'] if source else w['candidate'];key=(target['provider_id'],source)
        if key in self.clients:return self.clients[key]
        artifact=None if source else self._bootstrap().profile(w['create_intent_id'],'restored')
        if not source:
            if (not w.get('rebuild_intent_id') or getattr(artifact,'host_key_origin',None)!='source'
                    or artifact.public_host_fingerprint==w['host_fingerprint']):
                raise RestoreError('source_ssh_pin_transition_not_authorized')
        if self.lifecycle_factory:
            client=self.lifecycle_factory(dict(target),artifact,self.authorize_lifecycle)
        else:
            from .lifecycle_client import LifecycleClient,load_config
            settings=self.options.get('lifecycle',{})
            if source:
                path=settings.get('source_configs',{}).get(target['provider_id'])
                if not path:raise RestoreError('source_lifecycle_config_missing')
                cfg=load_config(path)
            else:
                cfg=dict(settings.get('candidate_template',{}))
                cfg.update(schema='servernet.relay.lifecycle-client.v1',service_id=self.policy.service_id,
                    provider_id=target['provider_id'],generation=target['generation'],config_hash=target['config_hash'],
                    source_host=w['candidate_ipv4'],known_hosts_file=artifact.known_hosts_path,
                    expected_host_key_sha256=artifact.public_host_fingerprint,
                    state_dir=str(self.root/('lifecycle-'+target['provider_id'])))
            client=LifecycleClient(cfg,authorize=self.authorize_lifecycle,clock=self.now)
        self.clients[key]=client;return client

    def _lifecycle_proof(self,result,target,*,fenced=None):
        p=result.get('proof',{}) if isinstance(result,dict) else {};at=p.get('observed_at')
        if (not isinstance(p,dict) or p.get('ok')is not True or type(at)is not int
                or not -5<=self.now()-at<=90 or p.get('service_id')!=self.policy.service_id
                or any(p.get(k)!=target[k] for k in ('provider_id','generation','config_hash'))
                or p.get('metadata_provider_id')!=target['provider_id'] or p.get('physical_identity_verified')is not True
                or p.get('guard_installed')is not True or not _sha(p.get('machine_uuid_sha256'))):
            raise RestoreError('lifecycle_identity_or_proof_unverified')
        if fenced is True and not (p.get('persistent_fence')is True and p.get('all_units_inactive')is True
                and p.get('identity_fenced')is True and p.get('docker',{}).get('daemon_inactive')is True
                and p.get('docker',{}).get('exact_inventory')is True and p.get('docker',{}).get('no_shims')is True
                and p.get('docker',{}).get('running_ids')==[]):raise RestoreError('source_or_clone_writers_not_fenced')
        if fenced is False and not (p.get('active_generation_verified')is True and p.get('identity_fenced')is False):
            raise RestoreError('candidate_activation_unverified')
        return p

    def _require_source_off(self,w):
        self._scope(w)
        provider=self._provider();record=provider.store.read_resource(w['source']['provider_id'])
        if provider._identity(record)!=core.identity(w['source']):raise RestoreError('current_restore_identity_changed')
        raw=provider._verified_server(provider._client(),record)
        state=self._scope(w)
        if raw.get('status')!='off' or w.get('source_fenced')is not True:raise RestoreError('old_source_not_fenced_and_off')
        return state

    def _off(self,w):
        self._require_source_off(w)
        proof=dict(source_identity=core.identity(w['source']),provider_off=True,observed_at=self.now(),
                   fence_digest=core.digest(w['proofs']['fence']))
        w['proofs']['source_off']=proof;self.store.save(w);return proof

    def _disk_ready(self,w):
        provider=self._provider();client=provider._client()
        source=provider._verified_server(client,provider.store.read_resource(w['source']['provider_id']))
        target=provider._verified_server(client,provider.store.read_resource(w['candidate']['provider_id']))
        disk=source.get('primary_disk_size');target_disk=target.get('primary_disk_size')
        if (type(disk)is not int or disk<=0 or type(target_disk)is not int or target_disk<disk
                or source.get('volumes') or source.get('private_net') or target.get('volumes') or target.get('private_net')
                or source.get('server_type',{}).get('architecture')!='x86'
                or target.get('server_type',{}).get('architecture')!='x86'):
            raise RestoreError('cold_snapshot_disk_or_resource_incompatible')
        snapshot=self._snapshots();quote=snapshot.quote_storage(disk)
        with snapshot.store.transaction() as db:
            retained=snapshot._storage(db,Decimal(quote['gross_per_gb_month']))['reserved_monthly_micro']
        cap=self.options.get('max_snapshot_monthly_micro',1_000_000)
        if type(cap)is not int or retained+quote['reserved_monthly_micro']>cap:
            raise RestoreError('pre_fence_snapshot_budget_incomplete')
        w['proofs']['disk_preflight']={'source_disk_gb':disk,'candidate_disk_gb':target_disk,'quote':quote}

    def _authorize(self,permit,backend):
        if self.options.get('enabled')is not True or not self.policy.enabled:return False
        operation_id=permit.get('intent_id');row=self.store.action(operation_id)
        body=json.loads(row['body']);w=self.store.get(workflow_id=row['workflow_id']);state=self._scope(w)
        with self.store.transaction() as db:
            authorization=db.execute('SELECT permit FROM authorizations WHERE action_id=? ORDER BY rowid DESC LIMIT 1',
                                     (operation_id,)).fetchone()
        expected=json.loads(authorization['permit']) if authorization else body['permit']
        if (row['status']!='DISPATCHED' or w.get('pending_action')!=operation_id
                or body.get('backend')!=backend or core.canonical(expected)!=core.canonical(permit)
                or not permit['created_at']<=self.now()<permit['expires_at']):return False
        if row['kind']=='ACTIVATE_CANDIDATE':
            if (state['candidate'].get('restore_epoch')!=w.get('restore_epoch')
                    or state['candidate'].get('restore_hash')!=w.get('restore_hash')):return False
            # LifecycleClient authorizes again after its SSH preflight. Re-read
            # the old source there, without renewing the original fence proof
            # or the attestation already bound into this exact permit.
            state=self._require_source_off(w)
            if (state['candidate'].get('restore_epoch')!=w.get('restore_epoch')
                    or state['candidate'].get('restore_hash')!=w.get('restore_hash')):return False
            if not permit['created_at']<=self.now()<permit['expires_at']:return False
        elif state['candidate'].get('restore_pending',{}).get('workflow_id')!=w['id']:return False
        if row['kind']!='FENCE_SOURCE' and w.get('source_fenced')is not True:return False
        return True

    def _local_action(self,w,kind,proof):
        now=self.now();operation_id=str(uuid.uuid4())
        body={'proof':proof,'created_at':now,'expires_at':now+30}
        with self.store.transaction() as db:
            db.execute('INSERT INTO actions VALUES(?,?,?,?,?,NULL)',(operation_id,w['id'],kind,core.canonical(body),'COMMITTED'))
        return operation_id,body

    def _local_complete(self,operation_id,proof):
        with self.store.transaction() as db:
            db.execute("UPDATE actions SET status='COMPLETE',result=? WHERE id=?",(core.canonical(proof),operation_id))

    def _begin_restore(self,w):
        candidate=self._scope(w)['candidate'];pending=candidate.get('restore_pending')
        if pending is not None and pending.get('workflow_id')!=w['id']:raise RestoreError('another_restore_is_pending')
        with self.store.transaction() as db:
            row=db.execute('SELECT id,body FROM actions WHERE workflow_id=? AND kind=? ORDER BY rowid DESC LIMIT 1',
                           (w['id'],'BEGIN_RESTORE')).fetchone()
        saved=json.loads(row['body']) if row else None
        if saved and (pending is not None or self.now()<=saved['expires_at']):
            proof=saved['proof'];operation_id=row['id']
        else:
            now=self.now();proof=dict(verified=True,received_at=now,observed_at=now,workflow_id=w['id'],
                workflow_committed=True,purpose='candidate_restore',expected_restore_epoch=candidate.get('restore_epoch',0)+1,
                identity=core.identity(candidate),source_identity=core.identity(w['source']),incident_id=w['incident_id'],
                generation=candidate['generation'],config_hash=candidate['config_hash'])
            operation_id,saved=self._local_action(w,'BEGIN_RESTORE',proof)
        w['begin_receipt']=proof;w['begin_action_id']=operation_id;w['expected_restore_epoch']=proof['expected_restore_epoch']
        self.store.save(w)
        if pending is None and self.now()>saved['expires_at']:raise RestoreError('local_restore_claim_expired')
        core.Engine(self.policy,core.Journal(self.root/'controller.sqlite3')).begin_restore(proof,self.now())
        self._local_complete(operation_id,proof);w['phase']='FENCE_SOURCE';self.store.save(w)

    def authorize_snapshot(self,permit):return self._authorize(permit,'snapshot')
    def authorize_lifecycle(self,permit):return self._authorize(permit,'lifecycle')

    def _mutation(self,w,kind,params=None):
        now=self.now();operation_id=str(uuid.uuid4());params=params or {}
        if kind in ('FENCE_SOURCE','ADOPT_CLONE','ACTIVATE_CANDIDATE'):
            target=w['source'] if kind=='FENCE_SOURCE' else w['candidate']
            permit=dict(schema='servernet.relay.lifecycle.permit.v1',workflow_id=w['id'],intent_id=operation_id,
                kind=STAGES[kind],service_id=self.policy.service_id,
                target={k:target[k] for k in ('provider_id','generation','config_hash')},params=params,
                created_at=now,expires_at=now+30);backend='lifecycle'
        else:
            permit=dict(intent_id=operation_id,workflow_id=w['id'],kind=STAGES[kind],incident_id=w['incident_id'],
                source_identity=core.identity(w['source']),config_hash=w['source']['config_hash'],created_at=now,expires_at=now+30)
            if kind=='FINAL_SNAPSHOT':permit.update(currency=self.policy.budget_currency,
                max_storage_monthly_micro=self.options.get('max_snapshot_monthly_micro',1_000_000))
            if kind=='REBUILD_CANDIDATE':permit.update(candidate_identity=core.identity(w['candidate']),
                candidate_no_writes=True,candidate_never_promoted=True)
            backend='snapshot'
        body=dict(permit=permit,backend=backend)
        with self.store.transaction() as db:
            db.execute('INSERT INTO actions VALUES(?,?,?,?,?,NULL)',(operation_id,w['id'],kind,core.canonical(body),'COMMITTED'))
            db.execute('INSERT INTO authorizations VALUES(?,?,?)',(str(uuid.uuid4()),operation_id,core.canonical(permit)))
        w['pending_action']=operation_id
        if kind=='FENCE_SOURCE':w['fence_attempted']=True
        if kind=='REBUILD_CANDIDATE':w['candidate_contains_production_state']=True
        self.store.save(w)
        with self.store.transaction() as db:db.execute("UPDATE actions SET status='DISPATCHED' WHERE id=?",(operation_id,))
        try:
            if backend=='lifecycle':
                client=self._client(w,source=kind=='FENCE_SOURCE')
                result=getattr(client,{'FENCE_SOURCE':'fence','ADOPT_CLONE':'adopt_clone','ACTIVATE_CANDIDATE':'activate'}[kind])(permit)
            elif kind=='SHUTDOWN_SOURCE':result=self._snapshots().begin_shutdown(permit)
            elif kind=='FINAL_SNAPSHOT':result=self._snapshots().begin_snapshot(permit,w['shutdown_intent_id'])
            else:
                artifact=self._bootstrap().profile(w['create_intent_id'],'restored')
                if (artifact.profile!='restored' or getattr(artifact,'host_key_origin',None)!='source'):
                    raise RestoreError('restored_source_host_pin_required')
                w['restored_user_data_sha256']=artifact.user_data_sha256;self.store.save(w)
                result=self._snapshots().begin_rebuild(permit,w['snapshot_intent_id'],self._bootstrap().read_user_data(artifact))
        except Exception:result={'state':'unknown','intent_id':operation_id}
        return self._consume(w,self.store.action(operation_id),result)

    def _consume(self,w,row,result):
        kind=row['kind'];state=result.get('state') if isinstance(result,dict) else 'unknown'
        if state=='complete':
            if kind=='FENCE_SOURCE':
                p=self._lifecycle_proof(result,w['source'],fenced=True)
                if p['machine_uuid_sha256']!=w['proofs']['source_inspect']['machine_uuid_sha256']:
                    raise RestoreError('source_machine_changed_before_fence')
                w['proofs']['fence']=p
                w.update(source_fenced=True,phase='SHUTDOWN_SOURCE')
            elif kind=='SHUTDOWN_SOURCE':
                if result.get('server_off')is not True:raise RestoreError('source_off_not_proven')
                w.update(shutdown_intent_id=row['id'],phase='FINAL_SNAPSHOT');self._off(w)
            elif kind=='FINAL_SNAPSHOT':
                snapshot=result.get('snapshot',{})
                if (result.get('image_available')is not True or snapshot.get('source_identity')!=core.identity(w['source'])
                        or snapshot.get('config_hash')!=w['source']['config_hash'] or not str(snapshot.get('id','')).isdigit()):
                    raise RestoreError('final_snapshot_scope_unverified')
                w.update(snapshot_intent_id=row['id'],snapshot_id=snapshot['id'],phase='REBUILD_CANDIDATE')
                w['proofs']['snapshot']=snapshot
            elif kind=='REBUILD_CANDIDATE':
                if (result.get('provider_rebuild_complete')is not True or result.get('public_ips_preserved')is not True
                        or result.get('candidate_identity')!=core.identity(w['candidate'])):raise RestoreError('rebuild_proof_unverified')
                w.update(rebuild_intent_id=row['id'],phase='BOOTSTRAP_QUARANTINE')
                w['proofs']['rebuild']=result
            elif kind=='ADOPT_CLONE':
                proof=self._lifecycle_proof(result,w['candidate'],fenced=True)
                if proof['machine_uuid_sha256']!=w['proofs']['quarantine']['machine_uuid_sha256']:
                    raise RestoreError('adopted_machine_identity_changed')
                w['proofs']['adoption']=proof;w['phase']='VERIFY_ADOPTED_CLONE'
            elif kind=='ACTIVATE_CANDIDATE':
                p=self._lifecycle_proof(result,w['candidate'],fenced=False)
                if p['machine_uuid_sha256']!=w['proofs']['adoption']['machine_uuid_sha256']:
                    raise RestoreError('candidate_machine_changed_before_activation')
                w['proofs']['activation']=p
                w.update(phase='POST_RESTORE_VALIDATION',healthy_since=None,last_health_at=None,last_observed_at=None)
            with self.store.transaction() as db:
                db.execute("UPDATE actions SET status='COMPLETE',result=? WHERE id=?",(core.canonical(result),row['id']))
            w['pending_action']=None;w['reason']='STEP_VERIFIED'
        elif (state=='not_started' and kind=='ADOPT_CLONE' and result.get('proof',{}).get('journal_adoption_absent')is True
              and result.get('proof',{}).get('read_only')is True):
            original=json.loads(row['body'])['permit'];proof=result['proof']
            if (self.now()<original['expires_at'] or proof.get('observed_at',0)<original['expires_at']):
                raise RestoreError('adoption_absence_before_dispatch_expiry')
            self._quarantine(w,result=result)
            with self.store.transaction() as db:
                db.execute("UPDATE actions SET status='RENEWABLE',result=? WHERE id=?",(core.canonical(result),row['id']))
            w['reason']='DEFINITIVE_ABSENCE_SAME_INTENT_RENEWAL_REQUIRED'
        elif state in ('not_submitted','not_started') and result.get('definitive_no_write')is True:
            with self.store.transaction() as db:
                db.execute("UPDATE actions SET status='NOT_STARTED',result=? WHERE id=?",(core.canonical(result),row['id']))
            w['pending_action']=None;w['reason']='DEFINITIVE_NO_WRITE_FRESH_PERMIT_REQUIRED'
        else:
            with self.store.transaction() as db:
                db.execute("UPDATE actions SET status='UNRESOLVED',result=? WHERE id=?",(core.canonical(result or {}),row['id']))
            w['reason']=('SOURCE_POWER_STATE_CONFLICT' if isinstance(result,dict)and result.get('reason')=='source_power_state_conflict'
                         else 'REPAIR_REQUIRED' if state=='failed' else 'OUTCOME_UNKNOWN_READ_ONLY_RECONCILIATION')
        w['last_at']=self.now();self.store.save(w);return self.collect()

    def _reconcile(self,w):
        row=self.store.action(w['pending_action']);body=json.loads(row['body'])
        try:
            if row['status']=='RENEWABLE':
                permit={**body['permit'],'created_at':self.now(),'expires_at':self.now()+30}
                with self.store.transaction() as db:
                    db.execute('INSERT INTO authorizations VALUES(?,?,?)',(str(uuid.uuid4()),row['id'],core.canonical(permit)))
                    db.execute("UPDATE actions SET status='DISPATCHED' WHERE id=?",(row['id'],))
                result=self._client(w).adopt_clone(permit)
                return self._consume(w,self.store.action(row['id']),result)
            result=(self._snapshots().reconcile(row['id']) if body['backend']=='snapshot'
                    else self._client(w,source=row['kind']=='FENCE_SOURCE').reconcile(row['id']))
            return self._consume(w,row,result)
        except Exception:
            w['reason']='OUTCOME_UNKNOWN_READ_ONLY_RECONCILIATION';self.store.save(w);return self.collect()

    def _quarantine(self,w,result=None):
        old={('old_'+k):w['source'][k] for k in ('provider_id','generation','config_hash')}
        old['old_machine_uuid_sha256']=w['proofs']['fence']['machine_uuid_sha256']
        client=self._client(w)
        if not hasattr(client,'quarantine_inspect'):raise RestoreError('authenticated_quarantine_reader_missing')
        result=client.quarantine_inspect(old) if result is None else result;p=result.get('proof',{})
        inherited={k:w['source'][k] for k in ('provider_id','generation','config_hash')}
        inherited.update(service_id=self.policy.service_id,machine_uuid_sha256=w['proofs']['fence']['machine_uuid_sha256'],fenced=True)
        if (p.get('ok')is not True or p.get('quarantine_verified')is not True
                or p.get('read_only')is not True or p.get('inherited_identity')!=inherited
                or not all(p.get(k)is True for k in ('guard_denies_current_machine','docker_socket_inactive','all_units_inactive','guard_installed'))
                or p.get('metadata_provider_id')!=w['candidate']['provider_id'] or not _sha(p.get('machine_uuid_sha256'))
                or p['machine_uuid_sha256']==w['proofs']['fence']['machine_uuid_sha256']):
            raise RestoreError('clone_quarantine_unverified')
        m=p.get('bootstrap',{});now=self.now();observed=p.get('observed_at')
        if (type(observed)is not int or not -5<=now-observed<=90 or m.get('machine_id_ready')is not True
                or m.get('intent_id')!=w['create_intent_id'] or not _sha(m.get('scope'))
                or not _sha(m.get('boot_id_sha256')) or not _sha(m.get('previous_boot_id_sha256'))
                or m['boot_id_sha256']==m['previous_boot_id_sha256']
                or not _sha(m.get('machine_id_sha256')) or not _sha(m.get('previous_machine_id_sha256'))
                or m['machine_id_sha256']==m['previous_machine_id_sha256']):raise RestoreError('fresh_machine_identity_not_complete')
        w['proofs']['quarantine']=p;w['phase']='ADOPT_CLONE';self.store.save(w)

    def _epoch(self,w):
        path=self.root/'controller.sqlite3'
        # The restored disk is now preserved production state. Set the provider
        # deletion safety flag BEFORE releasing core's restore hold; this is a
        # conservative retention barrier, not an assertion of customer writes.
        record=self._provider().store.read_resource(w['candidate']['provider_id'])
        if not record.has_writes:self._provider().store.save_resource(dataclasses.replace(record,has_writes=True))
        with sqlite3.connect(path.as_uri()+'?mode=ro',uri=True) as db:
            prior=db.execute('SELECT receipt FROM restore_epochs WHERE workflow_id=?',(w['id'],)).fetchone()
        if prior:
            proof=json.loads(prior[0])
            with self.store.transaction() as db:
                row=db.execute('SELECT id,body FROM actions WHERE workflow_id=? AND kind=? ORDER BY rowid DESC LIMIT 1',
                               (w['id'],'RECORD_RESTORE_EPOCH')).fetchone()
            if row is None or json.loads(row['body'])['proof']!=proof:raise RestoreError('restore_epoch_receipt_conflict')
            w['epoch_proof']=proof;w['epoch_action_id']=row['id'];self._local_complete(row['id'],proof)
        else:
            candidate=self._scope(w)['candidate'];now=self.now()
            with self.store.transaction() as db:
                row=db.execute('SELECT id,body FROM actions WHERE workflow_id=? AND kind=? ORDER BY rowid DESC LIMIT 1',
                               (w['id'],'RECORD_RESTORE_EPOCH')).fetchone()
            saved=json.loads(row['body']) if row else None
            machine=w['proofs']['quarantine']['bootstrap']
            proof=(saved['proof'] if saved and now<=saved['expires_at'] else dict(verified=True,received_at=now,observed_at=now,
                workflow_id=w['id'],workflow_committed=True,identity=core.identity(candidate),source_identity=core.identity(w['source']),
                incident_id=w['incident_id'],generation=candidate['generation'],config_hash=candidate['config_hash'],
                restore_epoch=w['expected_restore_epoch'],snapshot_id=w['snapshot_id'],snapshot_available=True,source_fenced=True,
                boot_verified=True,quarantine_verified=True,machine_id_sha256=machine['machine_id_sha256'],
                boot_id_sha256=machine['boot_id_sha256'],
                restore_hash=core.digest({'snapshot_id':w['snapshot_id'],'bootstrap':w['restored_user_data_sha256'],
                    'machine':machine,'adoption':core.digest(w['proofs']['adoption'])})))
            if not saved or now>saved['expires_at']:operation_id,saved=self._local_action(w,'RECORD_RESTORE_EPOCH',proof)
            else:operation_id=row['id']
            w['epoch_proof']=proof;w['epoch_action_id']=operation_id;self.store.save(w)
            if self.now()>saved['expires_at']:raise RestoreError('local_restore_epoch_expired')
            core.Engine(self.policy,core.Journal(path)).record_restore_epoch(proof,self.now())
            self._local_complete(operation_id,proof)
        w['phase']='ACTIVATE_CANDIDATE';w['restore_epoch']=proof['restore_epoch'];w['restore_hash']=proof['restore_hash']
        self.store.save(w)

    def _health(self,w):
        self._off(w);r=self._callback('post_restore_health',w)
        good=(self._bound(r,w,restored=True) and r.get('read_only')is True
            and r.get('global_cutover_performed')is False and r.get('all_actual_consumers_checked')is True
            and r.get('ok')is True)
        receipts=r.get('critical_receipts',[]) if isinstance(r,dict) else []
        if good:
            for consumer in self.policy.critical_consumers:
                matches=[v for v in receipts if isinstance(v,dict) and v.get('consumer')==consumer]
                if len(matches)!=1 or not self._bound(matches[0],w,restored=True) or matches[0].get('ok')is not True:good=False;break
        now=self.now()
        if not good:
            w.update(healthy_since=None,last_health_at=None,last_observed_at=None,phase='POST_RESTORE_VALIDATION',reason='ACTUAL_POST_RESTORE_HEALTH_INCOMPLETE')
        elif w.get('last_observed_at') is not None and r['observed_at']<=w['last_observed_at']:
            w['reason']='REPLAYED_HEALTH_CANNOT_EXTEND_ACCEPTANCE'
        else:
            if w.get('last_health_at')is None or now-w['last_health_at']>self.policy.observation_max_gap:
                w['healthy_since']=now
            w.update(last_health_at=r['received_at'],last_observed_at=r['observed_at'],latest_health=r,
                     reason='POST_RESTORE_ACCEPTANCE_IN_PROGRESS')
            window=max(1200,self.options.get('acceptance_seconds',1200))
            if now-w['healthy_since']>=window:w.update(phase='PRE_CUTOVER_READY',reason='ROOT_CONSUMER_TRANSACTION_REQUIRED')
        self.store.save(w)

    def advance(self,*,worker_lock_fd=None):
        if self.options.get('enabled')is not True or not self.policy.enabled:return self.collect(reason='RESTORE_WORKFLOW_DISABLED')
        try:
            with self._worker_lock(worker_lock_fd):return self._advance_locked()
        except BlockingIOError:return self.collect(reason='WORKER_BUSY')
        except Exception:
            w=self.store.get()
            if w:w['reason']='RESTORE_BLOCKED_SAFE';self.store.save(w)
            return self.collect(reason='RESTORE_BLOCKED_SAFE')

    def _advance_locked(self):
        s=self._scope();w=self.store.get(candidate_id=s['candidate']['provider_id'])
        if w is None:w=self._start(s)
        self._scope(w)
        if self.now()<w['last_at']:raise RestoreError('workflow_clock_regressed')
        if w.get('pending_action'):return self._reconcile(w)
        phase=w['phase']
        if phase=='PREPARE_BOOTSTRAP':self._prepare_bootstrap(w)
        elif phase=='VERIFY_TRIAL_NETWORK':
            r=self._callback('trial_network',w)
            if not self._bound(r,w):w['reason']='FRESH_ISOLATED_TRIAL_MISSING'
            elif not all(r.get(k)is True for k in ('network_ok','isolated_egress_ok','staging_isolated','live_dependencies_unchanged')):
                w.update(phase='TRIAL_REJECTED',reason='SOURCE_UNTOUCHED_TRIAL_REJECTED')
            else:w['proofs']['trial']=r;w['phase']='PRE_FENCE_READINESS'
        elif phase=='PRE_FENCE_READINESS':
            r=self._callback('pre_fence_readiness',w)
            remaining=self.policy.candidate_deadline-(self.now()-w['candidate']['created_at'])
            if (not self._bound(r,w) or not all(r.get(k)is True for k in READINESS)
                    or r.get('host_fingerprint')!=w['host_fingerprint'] or remaining<1200+self.options.get('minimum_restore_seconds',600)):
                w['reason']='HEADLESS_CONTROL_GUARD_BACKUP_OR_BUDGET_NOT_READY'
            else:
                inspected=self._client(w,source=True).inspect()
                p=self._lifecycle_proof(inspected,w['source'])
                self._disk_ready(w)
                w['proofs']['readiness']=r;w['proofs']['source_inspect']=p;w['phase']='BEGIN_RESTORE'
        elif phase=='BEGIN_RESTORE':self._begin_restore(w)
        elif phase=='FENCE_SOURCE':
            # Re-read real preflight immediately before the irreversible step.
            r=self._callback('pre_fence_readiness',w)
            trial=self._callback('trial_network',w)
            if (not self._bound(r,w) or not all(r.get(k)is True for k in READINESS) or not self._bound(trial,w)
                    or not all(trial.get(k)is True for k in ('network_ok','isolated_egress_ok','staging_isolated','live_dependencies_unchanged'))):
                w['reason']='FENCE_PREFLIGHT_EXPIRED'
            else:return self._mutation(w,phase)
        elif phase in ('SHUTDOWN_SOURCE','FINAL_SNAPSHOT','REBUILD_CANDIDATE'):return self._mutation(w,phase)
        elif phase=='BOOTSTRAP_QUARANTINE':self._quarantine(w)
        elif phase=='ADOPT_CLONE':
            old={('old_'+k):w['source'][k] for k in ('provider_id','generation','config_hash')}
            old['old_machine_uuid_sha256']=w['proofs']['fence']['machine_uuid_sha256']
            return self._mutation(w,phase,old)
        elif phase=='VERIFY_ADOPTED_CLONE':
            proof=self._lifecycle_proof(self._client(w).inspect(),w['candidate'],fenced=True)
            if proof['machine_uuid_sha256']!=w['proofs']['quarantine']['machine_uuid_sha256']:raise RestoreError('new_boot_machine_changed')
            w['proofs']['adopted_inspect']=proof;w['phase']='RECORD_RESTORE_EPOCH'
        elif phase=='RECORD_RESTORE_EPOCH':self._epoch(w)
        elif phase=='ACTIVATE_CANDIDATE':
            off=self._off(w);attestation={'fence':w['proofs']['fence'],'provider_off':off}
            return self._mutation(w,phase,dict(old_provider_id=w['source']['provider_id'],old_generation=w['source']['generation'],
                old_fence_proof_digest=core.digest(attestation),old_fence_proof_at=self.now()))
        elif phase in ('POST_RESTORE_VALIDATION','PRE_CUTOVER_READY'):self._health(w)
        w['last_at']=self.now();self.store.save(w);return self.collect()

    def collect(self,*,reason=None):
        """Local reads only: never calls provider, SSH, callbacks or keygen."""
        w=self.store.get();result=dict(schema=SCHEMA,enabled=self.options.get('enabled')is True,
            phase=w['phase'] if w else 'NOT_STARTED',reason=reason or (w.get('reason') if w else 'NO_RESTORE_WORKFLOW'),
            migration_complete=False,global_cutover_complete=False,restore_ready=None,
            cleanup_forbidden=bool(w and w.get('fence_attempted')),source_rollback_allowed=False)
        if not w:return result
        result.update(workflow_id=w['id'],source_identity=core.identity(w['source']),identity=core.identity(w['candidate']),
            source_fenced=w.get('source_fenced',False),pending_action=w.get('pending_action'))
        r=w.get('latest_health');at=r.get('received_at') if isinstance(r,dict) else None
        if (w['phase']=='PRE_CUTOVER_READY' and type(at)is int and 0<=self.now()-at<=self.policy.receipt_max_age
                and 0<=self.now()-w['proofs']['source_off']['observed_at']<=self.policy.receipt_max_age):
            try:
                self._scope(w)
                result['restore_ready']={**{k:r[k] for k in ('verified','received_at','observed_at','incident_id','generation','config_hash',
                    'restore_epoch','restore_hash')},'identity':core.identity(w['candidate']),'source_identity':core.identity(w['source']),
                    'workflow_id':w['id'],'snapshot_id':w['snapshot_id'],'pre_cutover_ready':True,
                    'phase':'PRE_CUTOVER_READY','acceptance_last_observed_at':r['observed_at'],
                    'global_cutover_complete':False,'migration_complete':False,'acceptance_started_at':w['healthy_since'],
                    'acceptance_seconds':r['received_at']-w['healthy_since']}
            except Exception:pass
        return result


def main():
    p=argparse.ArgumentParser();p.add_argument('--config',required=True,type=Path);p.add_argument('--advance',action='store_true')
    args=p.parse_args();os.umask(0o077)
    try:
        workflow=RestoreWorkflow(json.loads(private_file(args.config)))
        result=workflow.advance() if args.advance else workflow.collect()
        print(core.canonical(result));return 0
    except Exception:
        print('{"ok":false,"reason":"restore_workflow_unavailable"}');return 1


if __name__=='__main__':raise SystemExit(main())
