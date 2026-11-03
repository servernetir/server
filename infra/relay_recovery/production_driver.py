"""Default-off, journaled production orchestration; no bundled success hooks.

The sole enabled scheduler holds worker.lock and calls this driver. Effects
are impossible without reviewed in-process ProductionHooks and a pinned private
dependency catalogue. CLI optionally imports the fixed production_hooks module;
it never imports a path or command supplied by a report/private config string.
The module must supply actual integrations from its separately pinned enrollment.

Hooks must implement actual discovery, isolated trial staging, consumer/DNS/CRM
cutover and read-only verification. An apply ACK never proves completion. Hooks
must call authorize_hook() immediately before writes, and must support fresh
durable continuation permits for every namespace (including PHP CRM metadata).
Raw expired PROMOTE permission and mutating RECONCILE_PROMOTION are forbidden.
"""
from __future__ import annotations

import argparse
import dataclasses
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import time
import uuid
from typing import Callable

from . import core
from .bootstrap import BootstrapStore
from .controller_adapter import Adapter
from .gateway import private_file
from .protocol import decode_json
from .provider_adapter import ProviderEffects, _directory
from .restore_workflow import RestoreWorkflow
from .snapshot_adapter import SnapshotStore


SCHEMA='servernet.relay.production-driver.v1'
OBSERVATION='servernet.relay.driver.observation.v1'
REQUIRED_CONTINUATIONS={'trial_stage','external_cutover','crm_promotion'}
HOOK_KINDS={'TRIAL_STAGE','EXTERNAL_CUTOVER','CRM_PROMOTION'}


class DriverError(core.RecoveryError):pass


@dataclasses.dataclass(frozen=True)
class ProductionHooks:
    """Trusted local functions only. No defaults that pretend to inspect/apply.

    registry(context): live complete catalogue observation, capabilities and
    actual resource proof digests. apply(permit,context,authorizer): a write ACK.
    reconcile(permit,context): READ-ONLY observation; never repeats apply.
    cutover_verify(context): fresh actual resource/critical-consumer/CRM proofs.
    retirement_verify(context): separate fresh old-reference/backup/TTL evidence.
    crm_apply/crm_reconcile: separately journaled panel transaction and actual
    readback. They are optional only for construction compatibility; live
    integration requires both, before staging or source fencing can begin.
    """
    registry:Callable
    trial_apply:Callable
    trial_reconcile:Callable
    cutover_apply:Callable
    cutover_reconcile:Callable
    cutover_verify:Callable
    retirement_verify:Callable
    crm_apply:Callable|None=None
    crm_reconcile:Callable|None=None


class DriverStore(SnapshotStore):
    def __init__(self,root):
        self.root=_directory(Path(root));self.path=self.root/'production-driver.sqlite3';self.lock=self.root/'production-driver.lock'
        with self.transaction() as db:
            db.executescript('''CREATE TABLE IF NOT EXISTS steps(id TEXT PRIMARY KEY,parent TEXT NOT NULL,kind TEXT NOT NULL,
                body TEXT NOT NULL,phase TEXT NOT NULL,result TEXT,UNIQUE(parent,kind));
                CREATE TABLE IF NOT EXISTS promotion_gates(parent TEXT PRIMARY KEY,body TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS cutover_acceptance(parent TEXT PRIMARY KEY,body TEXT NOT NULL,proof_hash TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS status(id INTEGER PRIMARY KEY CHECK(id=1),body TEXT NOT NULL);''')
        os.chmod(self.path,0o600)

    def step(self,parent,kind):
        with self.transaction() as db:row=db.execute('SELECT * FROM steps WHERE parent=? AND kind=?',(parent,kind)).fetchone()
        return dict(row)if row else None


class ProductionDriver:
    def __init__(self,config,*,collector=None,provider=None,bootstrap=None,restore=None,hooks=None,
                 restore_callbacks=None,lifecycle_factory=None,clock=lambda:int(time.time())):
        self.config=config;self.root=_directory(Path(config['state_dir']));self.options=config.get('production_driver',{})
        self.store=DriverStore(self.root);self.clock=clock;self.last_now=None
        values=dict(config.get('policy',{}))
        if 'critical_consumers'in values:values['critical_consumers']=tuple(values['critical_consumers'])
        self.policy=core.Config(**values);self.policy.validate()
        self.collector=collector;self.provider=provider;self.bootstrap=bootstrap;self.restore=restore;self.hooks=hooks
        self.restore_callbacks=restore_callbacks or {};self.lifecycle_factory=lifecycle_factory
        self.catalog=None;self.catalog_hash=None;self.integration_ready=False

    def now(self):
        value=self.clock()
        if type(value)is not int or value<0 or(self.last_now is not None and value<self.last_now):raise DriverError('driver_clock_unsafe')
        self.last_now=value;return value

    def _provider(self):
        if self.provider is None:self.provider=ProviderEffects(self.config,now=self.now)
        return self.provider

    def _bootstrap(self):
        if self.bootstrap is None:self.bootstrap=BootstrapStore(self.config)
        return self.bootstrap

    def _restore(self):
        if self.restore is None:self.restore=RestoreWorkflow(self.config,provider=self._provider(),bootstrap=self._bootstrap(),
            callbacks=self.restore_callbacks,lifecycle_factory=self.lifecycle_factory,now=self.now)
        return self.restore

    def _engine(self):
        path=self.root/'controller.sqlite3';private_file(path)
        engine=core.Engine(self.policy,core.Journal(path));state=engine.snapshot()
        if (state['active']['provider']!='hetzner' or state['active']['project_id']!=self.policy.project_id
                or str(self.config.get('binding',{}).get('owner_id'))!=self.policy.owner_id
                or str(self.config.get('binding',{}).get('service_id'))!=self.policy.service_id):raise DriverError('driver_scope_mismatch')
        return engine

    def _catalog(self):
        entry=self.options.get('registry',{});path=Path(entry.get('path',''))
        raw=private_file(path)
        if len(raw)>65536 or hashlib.sha256(raw).hexdigest()!=entry.get('sha256'):raise DriverError('registry_pin_required')
        catalog=decode_json(raw)
        if (catalog.get('schema')!='servernet.relay.dependency.registry.v1'
                or any(str(catalog.get(k))!=str(getattr(self.policy,k))for k in ('owner_id','service_id','project_id'))
                or type(catalog.get('revision'))is not int or catalog['revision']<1
                or set(catalog.get('consumers',[]))!=set(self.policy.critical_consumers)):
            raise DriverError('registry_scope_incomplete')
        resources=catalog.get('resources')
        if (not isinstance(resources,list)or not 1<=len(resources)<=256
                or any(not isinstance(r,dict)or not isinstance(r.get('id'),str)or not r['id']or not isinstance(r.get('kind'),str)for r in resources)
                or len({r['id']for r in resources})!=len(resources)):raise DriverError('registry_resources_incomplete')
        self.catalog,self.catalog_hash=catalog,hashlib.sha256(raw).hexdigest()

    def _context(self,state,parent=None):
        resource=state.get('candidate')or state['active']
        return dict(schema=SCHEMA,owner_id=self.policy.owner_id,service_id=self.policy.service_id,project_id=self.policy.project_id,
            source_identity=core.identity(state.get('retiring')or state['active']),identity=core.identity(resource),
            config_hash=resource['config_hash'],registry_hash=self.catalog_hash,parent_intent_id=parent,
            incident_id=state.get('incident_id'),restore_epoch=resource.get('restore_epoch'),restore_hash=resource.get('restore_hash'))

    def _observed(self,value,operation,context):
        return (isinstance(value,dict)and value.get('schema')==OBSERVATION and value.get('operation')==operation
            and value.get('verified')is True and value.get('read_only')is True
            and value.get('context_hash')==core.digest(context)
            and type(value.get('received_at'))is int and type(value.get('observed_at'))is int
            and 0<=self.now()-value['received_at']<=self.policy.receipt_max_age
            and 0<=self.now()-value['observed_at']<=self.policy.receipt_max_age
            and value['observed_at']<=value['received_at']+5)

    def _resources(self,proof):
        resources=proof.get('resource_proofs',[])
        return (isinstance(resources,list)and {r.get('id')for r in resources if isinstance(r,dict)}=={r['id']for r in self.catalog['resources']}
            and len(resources)==len(self.catalog['resources'])
            and all(isinstance(r,dict)and r.get('verified')is True and isinstance(r.get('verifier'),str)and r['verifier']
                    and isinstance(r.get('proof_sha256'),str)and re.fullmatch('[a-f0-9]{64}',r['proof_sha256'])for r in resources))

    def _integration_ready(self,state):
        if not isinstance(self.hooks,ProductionHooks)or any(not callable(getattr(self.hooks,f.name))for f in dataclasses.fields(ProductionHooks)):
            raise DriverError('concrete_production_hooks_missing')
        callbacks=getattr(self.restore,'callbacks',self.restore_callbacks)
        if not all(callable(callbacks.get(k))for k in ('trial_network','pre_fence_readiness','post_restore_health')):
            raise DriverError('concrete_restore_callbacks_missing')
        self._catalog();context=self._context(state)
        proof=self.hooks.registry(context)
        if (not self._observed(proof,'registry',context)or not self._resources(proof)
                or proof.get('discovery_complete')is not True or proof.get('headless_control_verified')is not True
                or set(proof.get('continuation_kinds',[]))!=REQUIRED_CONTINUATIONS):
            raise DriverError('live_registry_or_continuation_integration_incomplete')
        self.integration_ready=True

    def _collect(self,state):
        if self.collector is None:self.collector=Adapter(self.config,provider=self._provider())
        result=self.collector.collect({'now':self.now(),'state':state,'binding':self.config.get('binding',{})})
        if result.get('schema')!='servernet.relay.evidence.v1'or not isinstance(result.get('evidence'),dict):raise DriverError('invalid_collector_result')
        evidence=json.loads(core.canonical(result['evidence']))
        workflow=self._restore().collect()
        candidate=state.get('candidate')
        if candidate:
            record=self._provider().store.read_resource(candidate['provider_id'])
            if record.has_writes or (workflow.get('identity')==core.identity(candidate)and workflow.get('cleanup_forbidden')):
                evidence.pop('cleanup',None);evidence.pop('candidate_rejected',None)
        ready=workflow.get('restore_ready')
        if (ready is None and state['phase']=='CUTOVER'and workflow.get('phase')=='PRE_CUTOVER_READY'
                and workflow.get('identity')==core.identity(state['candidate'])
                and workflow.get('source_identity')==core.identity(state['active'])):
            with self.store.transaction()as db:row=db.execute('SELECT body FROM promotion_gates WHERE parent=?',(state['pending_action'],)).fetchone()
            if row:ready=json.loads(row['body']).get('restore_ready')
        if ready is not None:
            # Exact original clocks; bridge schema fields without freshening.
            evidence['restore_ready']={**ready,'phase':'PRE_CUTOVER_READY',
                                       'acceptance_last_observed_at':ready['observed_at']}
        else:evidence.pop('restore_ready',None)
        return evidence,workflow

    def _crm_context(self,state,parent):
        with sqlite3.connect((self.root/'controller.sqlite3').as_uri()+'?mode=ro',uri=True)as db:
            row=db.execute("SELECT body FROM actions WHERE id=? AND kind='PROMOTE'",(parent,)).fetchone()
        if row is None:raise DriverError('crm_parent_promotion_missing')
        action=json.loads(row[0]);payload=action.get('payload',{})
        if (action.get('id')!=parent or action.get('kind')!='PROMOTE'
                or not isinstance(payload.get('gate_hash'),str)or not re.fullmatch('[a-f0-9]{64}',payload['gate_hash'])
                or not isinstance(payload.get('checkpoint'),str)or not payload['checkpoint']):
            raise DriverError('crm_parent_promotion_scope')
        return {**self._context(state,parent),'gate_hash':payload['gate_hash'],'checkpoint':payload['checkpoint']}

    def _scope_action(self,context,kind):
        if kind not in HOOK_KINDS:return False
        # An earlier registry read is not authority to write after local drift.
        self._catalog()
        engine=self._engine();state=engine.snapshot()
        if context.get('registry_hash')!=self.catalog_hash:return False
        current=(self._crm_context(state,context['parent_intent_id'])if kind=='CRM_PROMOTION'
                 else self._context(state,context['parent_intent_id']))
        return core.canonical(current)==core.canonical(context)

    def _external_cutover_verified(self,parent,state):
        external=self.store.step(parent,'EXTERNAL_CUTOVER')
        if external is None or external['phase']!='VERIFIED':return False
        saved=json.loads(external['body']);proof=json.loads(external['result'])
        context=self._context(state,parent)
        return (core.canonical(saved['context'])==core.canonical(context)
                and self._observed(proof,'cutover_dispatch',context)
                and proof.get('intent_id')==saved['permit']['intent_id']and proof.get('state')=='complete')

    def authorize_hook(self,permit):
        try:
            if (self.options.get('enabled')is not True or not self.policy.enabled or not isinstance(permit,dict)
                    or set(permit)!={'schema','intent_id','parent_intent_id','kind','context_hash','created_at','expires_at'}
                    or permit.get('schema')!='servernet.relay.driver.permit.v1'or permit.get('kind')not in HOOK_KINDS
                    or type(permit.get('created_at'))is not int or type(permit.get('expires_at'))is not int
                    or not 0<permit['expires_at']-permit['created_at']<=30):return False
            with self.store.transaction()as db:
                row=db.execute('SELECT body,phase,parent,kind FROM steps WHERE id=?',(permit.get('intent_id'),)).fetchone()
            if (row is None or row['phase']!='DISPATCHED'or row['parent']!=permit['parent_intent_id']
                    or row['kind']!=permit['kind']):return False
            body=json.loads(row['body']);context=body['context']
            if (core.canonical(body['permit'])!=core.canonical(permit)or permit['context_hash']!=core.digest(context)
                    or not permit['created_at']<=self.now()<permit['expires_at']
                    or not self._scope_action(context,permit['kind'])):return False
            state=self._engine().snapshot()
            if permit['kind']in {'EXTERNAL_CUTOVER','CRM_PROMOTION'}:
                parent=permit['parent_intent_id']
                if (state['phase']!='CUTOVER'or state['pending_action']!=parent
                        or not self._fresh_promotion_gate(parent,state)):return False
                with sqlite3.connect((self.root/'controller.sqlite3').as_uri()+'?mode=ro',uri=True)as db:
                    row=db.execute("SELECT body FROM actions WHERE id=? AND kind='PROMOTE'",(parent,)).fetchone()
                action=json.loads(row[0])if row else {}
                if (type(action.get('created_at'))is not int or type(action.get('expires_at'))is not int
                        or permit['created_at']<action['created_at']or permit['expires_at']>action['expires_at']):return False
                if permit['kind']=='CRM_PROMOTION'and not self._external_cutover_verified(parent,state):return False
                return True
            return state['phase']=='VALIDATING'and state.get('candidate')is not None and state.get('pending_action')is None
        except Exception:
            # This is a boolean write guard; incomplete reads never grant access.
            return False

    def _fresh_promotion_gate(self,parent,state):
        with sqlite3.connect((self.root/'controller.sqlite3').as_uri()+'?mode=ro',uri=True)as db:
            row=db.execute('SELECT body FROM actions WHERE id=? AND kind=?',(parent,'PROMOTE')).fetchone()
        if row is None:return False
        action=json.loads(row[0])
        if not action['created_at']<=self.now()<action['expires_at']:return False
        with self.store.transaction()as db:saved=db.execute('SELECT body FROM promotion_gates WHERE parent=?',(parent,)).fetchone()
        if saved is None:return False
        evidence,_=self._collect(state)
        virtual=json.loads(core.canonical(state));virtual.update(phase='VALIDATING',pending_action=None)
        engine=self._engine()
        with sqlite3.connect(self.root/'controller.sqlite3')as db:
            db.row_factory=sqlite3.Row;planned=engine._plan(virtual,evidence,self.now(),db)
        if (planned.kind!='PROMOTE'or any(planned.payload.get(k)!=action['payload'].get(k)
                for k in ('identity','old_identity','checkpoint'))):return False
        provider=self._provider();record=provider.store.read_resource(state['active']['provider_id'])
        raw=provider._verified_server(provider._client(),record)
        return raw.get('status')=='off'and self.now()<action['expires_at']

    def _hook_step(self,parent,kind,context):
        if kind not in HOOK_KINDS:raise DriverError('unknown_hook_kind')
        row=self.store.step(parent,kind)
        if row is None:
            expiry=self.now()+30
            if kind in {'EXTERNAL_CUTOVER','CRM_PROMOTION'}:
                state=self._engine().snapshot()
                if (state['phase']!='CUTOVER'or state['pending_action']!=parent
                        or context.get('parent_intent_id')!=parent or not self._scope_action(context,kind)
                        or not self._fresh_promotion_gate(parent,state)):return None
                if kind=='CRM_PROMOTION'and not self._external_cutover_verified(parent,state):return None
                with sqlite3.connect(self.root/'controller.sqlite3')as db:
                    action=json.loads(db.execute('SELECT body FROM actions WHERE id=?',(parent,)).fetchone()[0])
                expiry=min(expiry,action['expires_at'])
            permit=dict(schema='servernet.relay.driver.permit.v1',intent_id=str(uuid.uuid4()),parent_intent_id=parent,
                kind=kind,context_hash=core.digest(context),created_at=self.now(),expires_at=expiry)
            body={'permit':permit,'context':context}
            with self.store.transaction()as db:
                db.execute('INSERT INTO steps VALUES(?,?,?,?,?,NULL)',(permit['intent_id'],parent,kind,core.canonical(body),'COMMITTED'))
            with self.store.transaction()as db:db.execute("UPDATE steps SET phase='DISPATCHED' WHERE id=?",(permit['intent_id'],))
            if not self.authorize_hook(permit):raise DriverError('driver_subpermit_not_authorized')
            apply={'TRIAL_STAGE':self.hooks.trial_apply,'EXTERNAL_CUTOVER':self.hooks.cutover_apply,
                   'CRM_PROMOTION':self.hooks.crm_apply}[kind]
            try:result=apply(permit,context,self.authorize_hook)
            except Exception:result={'state':'unknown'}
            with self.store.transaction()as db:db.execute('UPDATE steps SET result=? WHERE id=?',(core.canonical(result),permit['intent_id']))
            return None
        body=json.loads(row['body']);permit=body['permit'];original=body['context']
        if core.canonical(original)!=core.canonical(context):raise DriverError('immutable_hook_scope_changed')
        if row['phase']=='VERIFIED':return json.loads(row['result'])
        reconcile={'TRIAL_STAGE':self.hooks.trial_reconcile,'EXTERNAL_CUTOVER':self.hooks.cutover_reconcile,
                   'CRM_PROMOTION':self.hooks.crm_reconcile}[kind]
        result=reconcile(permit,original)
        operation={'TRIAL_STAGE':'stage_trial','EXTERNAL_CUTOVER':'cutover_dispatch','CRM_PROMOTION':'crm_promotion'}[kind]
        if not self._observed(result,operation,original)or result.get('intent_id')!=permit['intent_id']:return None
        if result.get('state')!='complete':return None
        if kind=='TRIAL_STAGE'and not all(result.get(k)is True for k in ('isolation_verified','network_verified','ct_egress_verified','live_dependencies_unchanged')):
            return None
        if kind=='CRM_PROMOTION'and result.get('panel_committed')is not True:return None
        with self.store.transaction()as db:db.execute("UPDATE steps SET phase='VERIFIED',result=? WHERE id=?",(core.canonical(result),row['id']))
        return result

    def _parent(self,state):
        record=self._provider().store.read_resource(state['candidate']['provider_id']);action_id=record.labels.get('sn-intent')
        with sqlite3.connect((self.root/'controller.sqlite3').as_uri()+'?mode=ro',uri=True)as db:
            row=db.execute('SELECT body FROM actions WHERE id=? AND kind=?',(action_id,'CREATE')).fetchone()
        if row is None:raise DriverError('candidate_create_not_committed')
        action=json.loads(row[0])
        if action['payload']['next_generation']!=state['candidate']['generation']:raise DriverError('candidate_create_generation_changed')
        return action_id

    def _provider_effect(self,engine,intent,evidence):
        provider=self._provider()
        if intent.kind=='CREATE':
            state=engine.snapshot();source=dict(state['active'])
            source['ipv4']=provider.probe_target(core.identity(source))['ipv4']
            artifact=self._bootstrap().prepare(intent.to_dict(),source)
            if artifact.profile!='trial'or artifact.host_key_origin!='trial':raise DriverError('explicit_trial_bootstrap_required')
            # Per-effect config copy; do not mutate enabled flags or the shared
            # runtime configuration. Durable CREATE already owns this artifact.
            config=json.loads(core.canonical(self.config));config.setdefault('provider',{})['bootstrap']={
                'path':artifact.user_data_path,'sha256':artifact.user_data_sha256}
            if isinstance(provider,ProviderEffects):
                provider=ProviderEffects(config,now=self.now,client_factory=provider.client_factory,bridge=provider.bridge)
        result=provider.execute(intent.kind,{'intent':intent.to_dict(),'evidence':evidence})
        engine.record_effect(intent.id,result['receipt'],self.now())

    def _cutover(self,engine,state,evidence):
        parent=state['pending_action'];context=self._context(state,parent)
        def complete_step(kind,scope):
            existing=self.store.step(parent,kind)
            result=self._hook_step(parent,kind,scope)
            # One read-only readback after a new dispatch can finish within the
            # original thirty-second claim. Never redispatch an unknown effect.
            if result is None and existing is None and self.store.step(parent,kind)is not None:
                result=self._hook_step(parent,kind,scope)
            return result
        if complete_step('EXTERNAL_CUTOVER',context)is None:return self._status('CUTOVER_RECONCILING',state)
        if complete_step('CRM_PROMOTION',self._crm_context(state,parent))is None:
            return self._status('CRM_PROMOTION_RECONCILING',state)
        proof=self.hooks.cutover_verify(context)
        if (not self._observed(proof,'cutover',context)or not self._resources(proof)
                or proof.get('parent_intent_id')!=parent or not all(proof.get(k)is True for k in (
                    'all_registered_resources_verified','no_old_references','panel_committed','global_cutover_complete'))
                or not engine._all_critical(state,state['candidate'],{'critical_receipts':proof.get('critical_receipts',[])},self.now())):
            return self._status('EXTERNAL_CUTOVER_NOT_VERIFIED',state)
        acceptance={'parent_intent_id':parent,'context':context,'proof':proof,'catalog':self.catalog}
        with self.store.transaction()as db:
            saved=db.execute('SELECT body,proof_hash FROM cutover_acceptance WHERE parent=?',(parent,)).fetchone()
            if saved:
                prior=json.loads(saved['body'])
                if prior['context']!=context or core.digest(prior['proof'])!=saved['proof_hash']:
                    raise DriverError('immutable_cutover_acceptance_conflict')
                accepted_hash=saved['proof_hash']
            else:
                accepted_hash=core.digest(proof)
                db.execute('INSERT INTO cutover_acceptance VALUES(?,?,?)',(parent,core.canonical(acceptance),accepted_hash))
        with sqlite3.connect((self.root/'controller.sqlite3').as_uri()+'?mode=ro',uri=True)as db:
            row=db.execute('SELECT body,receipt FROM actions WHERE id=?',(parent,)).fetchone()
        action=json.loads(row[0]);intent_id=parent;kind='PROMOTE'
        if row[1]is not None:
            reconcile=engine.plan(engine.snapshot(),evidence,self.now())
            if reconcile.kind!='RECONCILE_PROMOTION':raise DriverError('promotion_reconcile_not_planned')
            engine.commit_intent(reconcile,evidence,self.now());intent_id=reconcile.id;kind=reconcile.kind
        receipt=dict(intent_id=intent_id,kind=kind,outcome='promoted',verified=True,received_at=self.now(),
            identity=core.identity(state['candidate']),all_checks_passed=True,
            driver_verification_hash=accepted_hash,parent_intent_id=action['id'])
        engine.record_effect(intent_id,receipt,self.now())
        return self._status('PROMOTION_VERIFIED',engine.snapshot(),verification=accepted_hash)

    def _retirement_acceptance(self,state):
        with self.store.transaction()as db:rows=db.execute('SELECT body,proof_hash FROM cutover_acceptance').fetchall()
        for row in rows:
            accepted=json.loads(row['body']);context=accepted['context']
            if (context['source_identity']==core.identity(state['retiring'])and context['identity']==core.identity(state['active'])
                    and context['registry_hash']==self.catalog_hash and core.digest(accepted['proof'])==row['proof_hash']
                    and self._resources(accepted['proof'])):
                with sqlite3.connect(self.root/'controller.sqlite3')as db:
                    promotion=db.execute('SELECT 1 FROM promotions WHERE id=?',(accepted['parent_intent_id'],)).fetchone()
                    receipts=db.execute("SELECT receipt FROM actions WHERE kind IN ('PROMOTE','RECONCILE_PROMOTION') AND receipt IS NOT NULL").fetchall()
                linked=any((r:=json.loads(v[0])).get('outcome')=='promoted'and r.get('all_checks_passed')is True
                    and r.get('driver_verification_hash')==row['proof_hash']
                    and r.get('parent_intent_id')==accepted['parent_intent_id']
                    and r.get('identity')==core.identity(state['active'])for v in receipts)
                if promotion and linked:return True
        return False

    def _status(self,reason,state=None,**extra):
        result=dict(schema=SCHEMA,enabled=self.options.get('enabled')is True,at=self.now(),reason=reason,
            phase=state.get('phase')if state else None,integration_ready=self.integration_ready,
            effects_enabled=bool(self.options.get('enabled')and self.policy.enabled),
            effect_armed=bool(self.integration_ready and self.policy.enabled),**extra)
        with self.store.transaction()as db:db.execute('INSERT INTO status VALUES(1,?) ON CONFLICT(id) DO UPDATE SET body=excluded.body',(core.canonical(result),))
        return result

    def status(self):
        with self.store.transaction()as db:row=db.execute('SELECT body FROM status WHERE id=1').fetchone()
        return json.loads(row[0])if row else dict(schema=SCHEMA,enabled=False,reason='DRIVER_NOT_STARTED',effect_armed=False)

    def tick(self):
        self.integration_ready=False
        if self.options.get('enabled')is not True or not self.policy.enabled:return self._status('DRIVER_DISABLED')
        path=self.root/'worker.lock'
        if path.exists()or path.is_symlink():private_file(path)
        fd=os.open(path,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            engine=self._engine();state=engine.snapshot();self._integration_ready(state)
            evidence,workflow=self._collect(state);state=engine.observe(evidence,self.now())
            if state['phase']=='CUTOVER':return self._cutover(engine,state,evidence)
            if state.get('candidate')is not None:
                cleanup=engine.plan(state,evidence,self.now())
                if cleanup.kind in ('DELETE_CANDIDATE','RECONCILE_DELETE_CANDIDATE'):
                    record=self._provider().store.read_resource(state['candidate']['provider_id'])
                    blocked=record.has_writes or(record.promoted_at is not None)or(
                        workflow.get('identity')==core.identity(state['candidate'])and workflow.get('cleanup_forbidden'))
                    if blocked:return self._status('RESTORED_DATA_CLEANUP_FORBIDDEN',state)
                    engine.commit_intent(cleanup,evidence,self.now());self._provider_effect(engine,cleanup,evidence)
                    return self._status(cleanup.kind+'_RECORDED',engine.snapshot())
                parent=self._parent(state)
                if workflow.get('identity')==core.identity(state['candidate'])and workflow.get('phase')=='TRIAL_REJECTED':pass
                else:
                    if not workflow.get('cleanup_forbidden'):
                        if self._hook_step(parent,'TRIAL_STAGE',self._context(state,parent))is None:
                            return self._status('ISOLATED_TRIAL_NOT_VERIFIED',state)
                    was_ready=workflow.get('phase')=='PRE_CUTOVER_READY'
                    result=self._restore().advance(worker_lock_fd=fd)
                    if not was_ready or result['phase']!='PRE_CUTOVER_READY':return self._status('RESTORE_'+result['phase'],engine.snapshot())
                    evidence,workflow=self._collect(engine.snapshot());state=engine.observe(evidence,self.now())
            intent=engine.plan(state,evidence,self.now())
            if intent.kind=='HOLD':return self._status(intent.reason,state)
            if intent.kind in ('RETIRE','RECONCILE_RETIRE'):
                if not self._retirement_acceptance(state):return self._status('DURABLE_CUTOVER_ACCEPTANCE_MISSING',state)
                proof=self.hooks.retirement_verify(self._context(state))
                if (not self._observed(proof,'retirement',self._context(state))or not self._resources(proof)
                        or proof.get('no_old_references')is not True or proof.get('durable_backup_coverage')is not True):
                    return self._status('RETIREMENT_EXTERNAL_GATES_MISSING',state)
            if intent.kind=='DELETE_CANDIDATE'and workflow.get('identity')==core.identity(state['candidate'])and workflow.get('cleanup_forbidden'):
                return self._status('RESTORED_DATA_CLEANUP_FORBIDDEN',state)
            if intent.kind=='CREATE'and self.options.get('mode','authorized_candidate')!='automatic':
                return self._status('AUTHORIZED_CANDIDATE_REQUIRED_NO_NEW_PURCHASE',state)
            if intent.kind=='PROMOTE':
                with self.store.transaction()as db:
                    db.execute('INSERT INTO promotion_gates VALUES(?,?)',(intent.id,core.canonical(evidence)))
            engine.commit_intent(intent,evidence,self.now())
            if intent.kind=='PROMOTE':return self._cutover(engine,engine.snapshot(),evidence)
            if intent.kind not in ProviderEffects.ACTIONS:raise DriverError('unimplemented_driver_effect')
            self._provider_effect(engine,intent,evidence)
            return self._status(intent.kind+'_RECORDED',engine.snapshot())
        except BlockingIOError:return self._status('WORKER_BUSY')
        except Exception:return self._status('DRIVER_HOLD_INTEGRATION_OR_PROOF_MISSING')
        finally:os.close(fd)


def main():
    p=argparse.ArgumentParser();p.add_argument('--config',required=True,type=Path);p.add_argument('--status',action='store_true')
    args=p.parse_args();os.umask(0o077)
    try:
        raw=private_file(args.config)
        if len(raw)>65536:raise DriverError('driver_config_size')
        config=decode_json(raw);integrations={}
        # The fixed repository module supplies explicitly enrolled integrations.
        # Missing module remains a hold, never an all-true recipe.
        if not args.status:
            try:
                from .production_hooks import build_integrations
                integrations=build_integrations(config)
            except ImportError:pass
        driver=ProductionDriver(config,**integrations)
        print(core.canonical(driver.status()if args.status else driver.tick()));return 0
    except Exception:
        print('{"schema":"servernet.relay.production-driver.v1","reason":"DRIVER_UNAVAILABLE","effect_armed":false}');return 1


if __name__=='__main__':raise SystemExit(main())
