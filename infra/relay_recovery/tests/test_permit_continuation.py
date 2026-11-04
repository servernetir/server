"""Offline crash/restart and CAS tests; no transport or provider calls escape fixtures."""
import copy
import importlib.util
import json
from pathlib import Path
import sqlite3
import sys
import unittest

SPEC=importlib.util.spec_from_file_location('permit_driver_fixture',Path(__file__).with_name('test_production_driver.py'))
fixture=importlib.util.module_from_spec(SPEC);sys.modules[SPEC.name]=fixture;SPEC.loader.exec_module(fixture)
core=fixture.core
ProductionDriver=fixture.ProductionDriver


class PermitContinuationTests(unittest.TestCase):
    def setUp(self):
        self.t=fixture.DriverTests();self.t.setUp();self.addCleanup(self.t.doCleanups)
        self.t.trial_complete=False
        self.d=self.t.driver;self.d._catalog()

    def trial(self):
        state=self.t.f.state();parent=self.d._parent(state);context=self.d._context(state,parent)
        row=self.d._prepare_hook_step(parent,'TRIAL_STAGE',context)
        self.assertEqual('COMMITTED',row['phase']);return parent,context,row

    def restart(self):
        self.d=ProductionDriver(self.t.config,collector=self.t.collector,provider=self.t.f.effects,
            bootstrap=self.t.helper.bootstrap,restore=self.t.helper.wf,hooks=self.t.hooks,clock=lambda:self.t.f.now)
        self.d._catalog();self.t.driver=self.d

    def audit(self,table):
        self.assertIn(table,{'hook_dispatches','hook_continuations'})
        with self.d.store.transaction()as db:return[dict(r)for r in db.execute('SELECT * FROM '+table)]

    def save_body(self,row,body):
        with self.d.store.transaction()as db:db.execute('UPDATE steps SET body=? WHERE id=?',(core.canonical(body),row['id']))

    def shorten(self,row):
        body=json.loads(row['body']);body['permit']['expires_at']=body['permit']['created_at']+5
        self.save_body(row,body);return self.d.store.step(row['parent'],row['kind'])

    def test_restart_resumes_unexpired_committed_once_with_same_permit(self):
        parent,context,row=self.trial();self.restart()
        self.d._hook_step(parent,'TRIAL_STAGE',context)
        self.assertEqual([('trial',row['id'])],self.t.applies)
        self.assertEqual(row['body'],self.d.store.step(parent,'TRIAL_STAGE')['body'])
        self.assertEqual([],self.audit('hook_continuations'))
        self.d._hook_step(parent,'TRIAL_STAGE',context)
        self.assertEqual(1,len(self.t.applies));self.assertEqual(1,len(self.audit('hook_dispatches')))

    def test_restart_renews_expired_never_dispatched_without_changing_semantic_intent(self):
        parent,context,row=self.trial();old=json.loads(row['body'])['permit']
        self.t.f.now=old['expires_at'];self.restart()
        self.d._hook_step(parent,'TRIAL_STAGE',context)
        saved=self.d.store.step(parent,'TRIAL_STAGE');body=json.loads(saved['body']);new=body['permit']
        self.assertEqual('DISPATCHED',saved['phase']);self.assertEqual(context,body['context'])
        self.assertEqual({k:v for k,v in old.items()if k not in {'created_at','expires_at'}},
            {k:v for k,v in new.items()if k not in {'created_at','expires_at'}})
        self.assertEqual(self.t.f.now,new['created_at']);self.assertEqual(30,new['expires_at']-new['created_at'])
        self.assertFalse(self.d.authorize_hook(old));self.assertTrue(self.d.authorize_hook(new))
        history=self.audit('hook_continuations');self.assertEqual(1,len(history))
        self.assertEqual(row['body'],history[0]['previous_body']);self.assertEqual(saved['body'],history[0]['body'])
        proof=json.loads(history[0]['proof']);self.assertEqual('committed_without_dispatch',proof['basis'])
        self.assertEqual(core.digest(old),proof['previous_permit_hash'])
        self.restart();self.d._hook_step(parent,'TRIAL_STAGE',context)
        self.assertEqual([('trial',row['id'])],self.t.applies)

    def test_interrupted_dispatch_claim_is_ambiguous_even_when_apply_never_ran(self):
        parent,context,row=self.trial();claimed=self.d._claim_hook_step(row,context)
        self.assertIsNotNone(claimed);self.assertEqual([],self.t.applies)
        self.t.f.now=claimed['permit']['expires_at'];self.restart()
        for _ in range(2):self.d._hook_step(parent,'TRIAL_STAGE',context)
        self.assertEqual([],self.t.applies);self.assertEqual(['trial','trial'],self.t.reads)
        self.assertEqual([],self.audit('hook_continuations'));self.assertEqual(1,len(self.audit('hook_dispatches')))

    def test_expired_legacy_dispatched_without_claim_only_reconciles_after_restart(self):
        parent,context,row=self.trial();permit=json.loads(row['body'])['permit']
        # Older drivers recorded DISPATCHED before apply without a separate
        # immutable dispatch claim. Its absence cannot prove no write occurred.
        with self.d.store.transaction()as db:
            db.execute("UPDATE steps SET phase='DISPATCHED' WHERE id=?",(row['id'],))
        legacy=self.d.store.step(parent,'TRIAL_STAGE')
        self.assertEqual([],self.audit('hook_dispatches'))
        self.t.f.now=permit['expires_at']
        for _ in range(2):
            self.restart()
            self.assertIsNone(self.d._hook_step(parent,'TRIAL_STAGE',context))
            self.assertFalse(self.d.authorize_hook(permit))
            self.assertEqual(legacy,self.d.store.step(parent,'TRIAL_STAGE'))
        self.assertEqual([],self.t.applies);self.assertEqual(['trial','trial'],self.t.reads)
        self.assertEqual([],self.audit('hook_dispatches'));self.assertEqual([],self.audit('hook_continuations'))

    def test_unknown_or_no_write_receipts_cannot_renew_dispatched_work(self):
        parent,context,row=self.trial();self.d._hook_step(parent,'TRIAL_STAGE',context)
        self.t.f.now+=31;self.restart()
        for result in (None,{'state':'unknown'},{'state':'no_write','no_write':True},
                       {'state':'not_started','dispatched':False,'verified':True,'read_only':True}):
            with self.subTest(result=result):
                with self.d.store.transaction()as db:
                    db.execute('UPDATE steps SET result=? WHERE id=?',(None if result is None else core.canonical(result),row['id']))
                self.d._hook_step(parent,'TRIAL_STAGE',context)
                self.assertEqual([('trial',row['id'])],self.t.applies)
                self.assertEqual([],self.audit('hook_continuations'))

    def test_committed_row_with_ambiguous_receipt_is_never_no_write_proof(self):
        parent,context,row=self.trial();self.t.f.now+=31
        with self.d.store.transaction()as db:db.execute('UPDATE steps SET result=? WHERE id=?',
            (core.canonical({'state':'no_write','verified':True}),row['id']))
        self.restart();self.d._hook_step(parent,'TRIAL_STAGE',context)
        self.assertEqual([],self.t.applies);self.assertEqual([],self.audit('hook_dispatches'))
        self.assertEqual([],self.audit('hook_continuations'))

    def test_immutable_dispatch_claim_blocks_accidental_phase_reset(self):
        parent,context,row=self.trial();self.d._claim_hook_step(row,context)
        with self.d.store.transaction()as db:
            db.execute("UPDATE steps SET phase='COMMITTED',result=NULL WHERE id=?",(row['id'],))
        self.t.f.now+=31;self.restart();self.d._hook_step(parent,'TRIAL_STAGE',context)
        self.assertEqual([],self.t.applies);self.assertEqual([],self.audit('hook_continuations'))

    def test_compare_and_swap_rejects_interleaved_receipt(self):
        parent,context,row=self.trial();self.t.f.now+=31
        original=self.d._hook_expiry
        def race(*args):
            expiry=original(*args)
            with self.d.store.transaction()as db:db.execute('UPDATE steps SET result=? WHERE id=?',
                (core.canonical({'state':'unknown'}),row['id']))
            return expiry
        self.d._hook_expiry=race
        self.assertIsNone(self.d._claim_hook_step(row,context))
        self.assertEqual([],self.audit('hook_dispatches'));self.assertEqual([],self.audit('hook_continuations'))

    def test_second_stale_claim_cannot_dispatch_or_renew_again(self):
        parent,context,row=self.trial();self.t.f.now+=31
        self.assertIsNotNone(self.d._claim_hook_step(row,context))
        self.assertIsNone(self.d._claim_hook_step(row,context))
        self.assertEqual(1,len(self.audit('hook_dispatches')));self.assertEqual(1,len(self.audit('hook_continuations')))

    def test_changed_generation_or_scope_blocks_expired_claim(self):
        parent,context,row=self.trial();self.t.f.now+=31
        engine=self.d._engine()
        with engine.journal.transaction()as db:
            state=engine.journal._read(db);state['candidate']['generation']+=1
            engine.journal._write(db,state)
        self.assertIsNone(self.d._claim_hook_step(row,context))
        self.assertEqual([],self.audit('hook_dispatches'));self.assertEqual([],self.audit('hook_continuations'))

    def test_registry_drift_blocks_expired_claim_even_if_config_pin_is_changed(self):
        parent,context,row=self.trial();self.t.f.now+=31
        path=Path(self.t.config['production_driver']['registry']['path'])
        catalog=copy.deepcopy(self.t.catalog);catalog['revision']+=1;path.write_text(core.canonical(catalog))
        self.d.options['registry']['sha256']=fixture.hashlib.sha256(path.read_bytes()).hexdigest()
        self.assertIsNone(self.d._claim_hook_step(row,context))
        self.assertEqual([],self.audit('hook_dispatches'));self.assertEqual([],self.audit('hook_continuations'))

    def test_changed_context_cannot_reuse_the_undispatched_intent(self):
        parent,context,row=self.trial();self.t.f.now+=31
        for field,value in (('owner_id','other'),('registry_hash','0'*64),('restore_epoch',42),('restore_hash','0'*64)):
            with self.subTest(field=field):
                self.assertIsNone(self.d._claim_hook_step(row,{**context,field:value}))
        self.assertEqual([],self.audit('hook_dispatches'));self.assertEqual([],self.audit('hook_continuations'))

    def test_drift_after_claim_blocks_actual_write_and_restart_never_retries(self):
        parent,context,_=self.trial();self.t.f.now+=31
        original=self.d._claim_hook_step
        def drift(*args):
            body=original(*args)
            Path(self.t.config['production_driver']['registry']['path']).write_text('{}')
            return body
        self.d._claim_hook_step=drift
        with self.assertRaisesRegex(Exception,'driver_subpermit_not_authorized'):
            self.d._hook_step(parent,'TRIAL_STAGE',context)
        self.assertEqual([],self.t.applies);self.assertEqual('DISPATCHED',self.d.store.step(parent,'TRIAL_STAGE')['phase'])
        self.t.f.now+=31
        self.d._claim_hook_step=original
        self.d._hook_step(parent,'TRIAL_STAGE',context)
        self.assertEqual([],self.t.applies);self.assertEqual(['trial'],self.t.reads)
        self.assertEqual(1,len(self.audit('hook_dispatches')))

    def test_clock_rollback_cannot_freshen_a_committed_permit(self):
        parent,context,row=self.trial();self.t.f.now-=1;self.restart()
        self.assertIsNone(self.d._claim_hook_step(row,context))
        self.assertEqual([],self.audit('hook_continuations'))

    def external(self):
        self.t.promote_claim(cutover=False);self.d=self.t.driver;state=self.t.f.state();parent=state['pending_action']
        context=self.d._context(state,parent);row=self.d._prepare_hook_step(parent,'EXTERNAL_CUTOVER',context)
        return parent,context,row

    def test_short_expired_external_permit_resumes_only_inside_original_parent(self):
        parent,context,row=self.external();row=self.shorten(row);old=json.loads(row['body'])['permit']
        self.t.f.now+=6;self.restart();self.d._hook_step(parent,'EXTERNAL_CUTOVER',context)
        self.assertEqual([('cutover',row['id'])],self.t.applies)
        permit=json.loads(self.d.store.step(parent,'EXTERNAL_CUTOVER')['body'])['permit']
        with sqlite3.connect(self.t.f.root/'controller.sqlite3')as db:
            action=json.loads(db.execute('SELECT body FROM actions WHERE id=?',(parent,)).fetchone()[0])
        self.assertEqual(action['expires_at'],permit['expires_at']);self.assertFalse(self.d.authorize_hook(old))
        self.assertTrue(self.d.authorize_hook(permit));self.assertEqual(1,len(self.audit('hook_continuations')))

    def test_expired_original_parent_never_renews_even_proven_undispatched_step(self):
        parent,context,row=self.external();self.t.f.now+=31;self.restart()
        self.d._hook_step(parent,'EXTERNAL_CUTOVER',context)
        self.assertEqual([],self.t.applies);self.assertEqual('COMMITTED',self.d.store.step(parent,'EXTERNAL_CUTOVER')['phase'])
        self.assertEqual([],self.audit('hook_continuations'));self.assertEqual([],self.audit('hook_dispatches'))

    def test_crm_continuation_keeps_exact_original_gate_and_checkpoint(self):
        parent,context,_=self.external()
        self.d._hook_step(parent,'EXTERNAL_CUTOVER',context);self.d._hook_step(parent,'EXTERNAL_CUTOVER',context)
        crm_context=self.d._crm_context(self.t.f.state(),parent)
        row=self.shorten(self.d._prepare_hook_step(parent,'CRM_PROMOTION',crm_context));self.t.f.now+=6;self.restart()
        self.d._hook_step(parent,'CRM_PROMOTION',crm_context)
        self.assertEqual(['cutover','crm'],[x[0]for x in self.t.applies])
        self.assertEqual(crm_context,self.t.crm_context);self.assertTrue(self.d.authorize_hook(self.t.crm_permit))
        history=self.audit('hook_continuations');self.assertEqual(1,len(history))
        self.assertEqual(crm_context,json.loads(history[0]['body'])['context'])

    def test_crm_expired_parent_or_changed_original_gate_cannot_be_renewed(self):
        parent,context,_=self.external()
        self.d._hook_step(parent,'EXTERNAL_CUTOVER',context);self.d._hook_step(parent,'EXTERNAL_CUTOVER',context)
        crm_context=self.d._crm_context(self.t.f.state(),parent)
        row=self.shorten(self.d._prepare_hook_step(parent,'CRM_PROMOTION',crm_context));self.t.f.now+=6
        with sqlite3.connect(self.t.f.root/'controller.sqlite3')as db:
            action=json.loads(db.execute('SELECT body FROM actions WHERE id=?',(parent,)).fetchone()[0])
            action['payload']['gate_hash']='0'*64;db.execute('UPDATE actions SET body=? WHERE id=?',(core.canonical(action),parent))
        self.assertIsNone(self.d._claim_hook_step(row,crm_context))
        self.t.f.now+=31;self.restart();self.assertIsNone(self.d._claim_hook_step(row,crm_context))
        self.assertEqual(['cutover'],[x[0]for x in self.t.applies]);self.assertEqual([],self.audit('hook_continuations'))


if __name__=='__main__':unittest.main()
