"""Scoped DNS mutation/reconciliation tests with fake APIs and DNS sockets."""
import copy
import json
from pathlib import Path
import socket
import sqlite3
import ssl
import struct
import tempfile
import unittest
from unittest.mock import patch

from infra.relay_recovery import cloudflare_adapter as cf, core

Z, R, EXTRA = 'a'*32, 'b'*32, 'c'*32
NOW = 1_800_000_000
OLD, NEW = '8.8.8.8', '8.8.4.4'


def record(rid=R, content=OLD, **values):
    return {'id': rid, 'type': 'A', 'name': 'relay.example.com', 'content': content,
            'proxied': False, 'ttl': 1, 'comment': 'owner approved relay',
            'tags': ['owner:example'], 'settings': {'ipv4_only': True},
            'meta': {'source': 'primary'}, 'created_on': 'unchanged',
            'comment_modified_on': 'unchanged', 'modified_on': 'before', **values}


class API:
    def __init__(self):
        self.records = {R: record()}
        self.calls, self.pages = [], None
        self.timeout_before = self.timeout_after = False
        self.post_drift = False
        self.on_patch = None

    def __call__(self, method, path, payload):
        self.calls.append((method,path,copy.deepcopy(payload)))
        if '?' in path:
            page = int(path.split('page=')[1].split('&')[0])
            pages = self.pages or [list(self.records.values())]
            return 200, {'success': True, 'errors': [], 'result': copy.deepcopy(pages[page-1]),
                         'result_info': {'page': page, 'total_pages': len(pages)}}
        rid = path.rsplit('/',1)[1]
        if method == 'PATCH':
            if self.on_patch: self.on_patch(method,path,payload)
            if self.timeout_before: raise cf.UnknownWrite('simulated_timeout')
            self.records[rid]['content'] = payload['content']
            self.records[rid]['modified_on'] = 'after'
            if self.post_drift: self.records[rid]['comment'] = 'concurrent external comment'
            if self.timeout_after: raise cf.UnknownWrite('simulated_timeout')
        return 200, {'success': True, 'errors': [], 'result': copy.deepcopy(self.records[rid])}


class CloudflareTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); self.root.chmod(0o700)
        self.config = {'state_dir': str(self.root), 'policy': {'enabled': False},
            'cloudflare': {'old_ipv4': OLD, 'zones': [{'zone_id': Z, 'record_ids': [R],
                'authoritative_ips': ['9.9.9.9']}]}}
        self.old = {'provider':'hetzner','project_id':'example','provider_id':'123','generation':1}
        self.new = {**self.old,'provider_id':'456','generation':2}
        self.api, self.clock, self.permits = API(), [NOW], []
        self.allow = True
        self.adapter = self.make()

    def authorize(self, plan, permit, direction):
        self.permits.append((permit,direction))
        return self.allow

    def dns(self, server,name,typ):
        return {'authoritative': True,'name':name,'type':typ,'addresses':[NEW],'ttl':300}

    def make(self, **kw):
        return cf.CloudflareEffects(self.config, transport=self.api, now=lambda:self.clock[0],
            authorizer=kw.pop('authorizer',self.authorize), dns=kw.pop('dns',self.dns), **kw)

    def plan(self): return self.adapter.plan(self.old,self.new,NEW)
    def patches(self): return [c for c in self.api.calls if c[0] == 'PATCH']

    def test_plan_is_read_only_scoped_and_preserves_full_snapshot(self):
        plan = self.plan()
        self.assertTrue(plan['ready']); self.assertEqual(self.patches(), [])
        self.assertEqual(plan['entries'][0]['before'],self.api.records[R])
        self.assertTrue(all('/zones/'+Z+'/' in p for _,p,_ in self.api.calls))
        self.assertFalse(plan['cas_atomic'])

    def test_content_only_patch_is_durable_before_send_and_metadata_preserved(self):
        plan = self.plan(); before = copy.deepcopy(self.api.records[R])
        def durable(*_):
            with sqlite3.connect(self.root/'cloudflare-effects.sqlite3') as db:
                row = db.execute('SELECT phase FROM changes').fetchone()
            self.assertEqual(row[0],'pending')
        self.api.on_patch = durable
        result = self.adapter.apply(plan['plan_id'],'fresh-permit')
        self.assertEqual(self.patches()[0][2],{'content':NEW})
        self.assertEqual(cf._stable(self.api.records[R]),cf._changed(before,NEW))
        self.assertTrue(result['api_verified']); self.assertTrue(result['authoritative_verified'])
        self.assertEqual(result['expires_at'],NOW+300)
        self.assertFalse(result['records'][0]['receipt']['client_cache_expiry_guaranteed'])

    def test_concurrent_metadata_edit_before_patch_is_never_overwritten(self):
        plan = self.plan(); self.api.records[R]['tags'].append('other:writer')
        with self.assertRaisesRegex(cf.CloudflareError,'compare_conflict'):
            self.adapter.apply(plan['plan_id'],'permit')
        self.assertEqual(self.patches(),[])

    def test_concurrent_metadata_change_during_patch_is_conflict_not_success(self):
        plan = self.plan(); self.api.post_drift = True
        result = self.adapter.apply(plan['plan_id'],'permit')
        self.assertFalse(result['api_verified'])
        self.assertEqual(result['records'][0]['outcome'],'conflict')
        self.assertEqual(self.api.records[R]['comment'],'concurrent external comment')
        with self.assertRaisesRegex(cf.CloudflareError,'rollback_not_owned'):
            self.adapter.rollback(plan['plan_id'],'rollback-permit')

    def test_timeout_after_write_reconciles_success_without_second_patch(self):
        plan = self.plan(); self.api.timeout_after = True
        self.assertTrue(self.adapter.apply(plan['plan_id'],'permit')['api_verified'])
        self.assertTrue(self.adapter.reconcile(plan['plan_id'])['api_verified'])
        self.assertTrue(self.adapter.apply(plan['plan_id'],'permit')['api_verified'])
        self.assertEqual(len(self.patches()),1)

    def test_timeout_before_write_does_not_authorize_blind_retry(self):
        plan = self.plan(); self.api.timeout_before = True
        self.assertFalse(self.adapter.apply(plan['plan_id'],'permit')['api_verified'])
        self.api.timeout_before = False
        again = self.adapter.apply(plan['plan_id'],'new-permit')
        self.assertEqual(again['records'][0]['outcome'],'unchanged')
        self.assertFalse(again['api_verified']); self.assertEqual(len(self.patches()),1)

    def test_rollback_only_owned_receipt_new_to_old_and_fresh_permit(self):
        plan = self.plan(); self.adapter.apply(plan['plan_id'],'forward-permit')
        result = self.adapter.rollback(plan['plan_id'],'rollback-permit')
        self.assertTrue(result['api_verified']); self.assertEqual(self.api.records[R]['content'],OLD)
        self.assertEqual([c[2] for c in self.patches()],[{'content':NEW},{'content':OLD}])
        self.assertIn(('rollback-permit','rollback'),self.permits)

    def test_rollback_refuses_unowned_or_concurrently_changed_record(self):
        plan = self.plan()
        with self.assertRaisesRegex(cf.CloudflareError,'rollback_not_owned'):
            self.adapter.rollback(plan['plan_id'],'permit')
        self.adapter.apply(plan['plan_id'],'forward')
        self.api.records[R]['content'] = '1.1.1.1'
        with self.assertRaisesRegex(cf.CloudflareError,'compare_conflict'):
            self.adapter.rollback(plan['plan_id'],'rollback')
        self.assertEqual(len(self.patches()),1)

    def test_rollback_cannot_reuse_forward_permit(self):
        plan = self.plan(); self.adapter.apply(plan['plan_id'],'same-permit')
        with self.assertRaisesRegex(cf.CloudflareError,'fresh_permit_required'):
            self.adapter.rollback(plan['plan_id'],'same-permit')
        self.assertEqual(len(self.patches()),1)

    def test_discovery_finds_unregistered_reference_on_later_page_and_blocks_apply(self):
        self.api.records[EXTRA] = record(EXTRA)
        self.api.pages = [[record()], [record(EXTRA)]]
        plan = self.plan()
        self.assertFalse(plan['ready'])
        self.assertEqual(plan['blockers'][0]['code'],'unregistered_old_reference')
        with self.assertRaisesRegex(cf.CloudflareError,'blocked_or_expired'):
            self.adapter.apply(plan['plan_id'],'permit')
        self.assertFalse(self.patches())

    def test_unrelated_foreign_record_is_never_modified(self):
        self.api.records[EXTRA] = record(EXTRA,content='1.1.1.1')
        plan = self.plan(); self.adapter.apply(plan['plan_id'],'permit')
        self.assertEqual(self.api.records[EXTRA]['content'],'1.1.1.1')
        self.assertTrue(all(path.endswith('/'+R) for _,path,_ in self.patches()))

    def test_missing_ipv6_replacement_blocks_without_removing_aaaa(self):
        self.config['cloudflare']['old_ipv6'] = '2606:4700:4700::1111'
        self.api.records[R] = record(content='2606:4700:4700::1111',type='AAAA')
        self.adapter = self.make()
        plan = self.plan()
        self.assertFalse(plan['ready']); self.assertEqual(plan['blockers'][0]['code'],'ipv6_replacement_missing')
        self.assertFalse(self.patches())

    def test_proxied_public_dns_never_proves_origin_migration(self):
        self.api.records[R]['proxied'] = True
        plan = self.plan(); result = self.adapter.apply(plan['plan_id'],'permit')
        self.assertTrue(result['api_verified']); self.assertFalse(result['authoritative_verified'])
        self.assertTrue(result['records'][0]['receipt']['origin_verification_required'])
        self.assertEqual(self.api.records[R]['proxied'],True)

    def test_absent_authoritative_probe_keeps_dns_acceptance_false(self):
        self.config['cloudflare']['zones'][0]['authoritative_ips'] = []
        self.adapter = self.make(); plan = self.plan()
        result = self.adapter.apply(plan['plan_id'],'permit')
        self.assertTrue(result['api_verified']); self.assertFalse(result['authoritative_verified'])

    def test_reconciliation_is_get_only_after_permit_expiry(self):
        plan = self.plan(); self.adapter.apply(plan['plan_id'],'permit')
        self.clock[0] += 500; self.allow = False
        original = len(self.patches())
        self.assertTrue(self.adapter.reconcile(plan['plan_id'])['api_verified'])
        self.assertEqual(len(self.patches()),original)

    def test_denied_permit_or_tampered_plan_never_writes(self):
        plan = self.plan(); self.allow = False
        with self.assertRaisesRegex(cf.CloudflareError,'permit_rejected'):
            self.adapter.apply(plan['plan_id'],'permit')
        with self.adapter.store.transaction() as db:
            saved = json.loads(db.execute('SELECT body FROM plans').fetchone()[0])
            saved['new_addresses']['A'] = '1.1.1.1'
            db.execute('UPDATE plans SET body=?',(json.dumps(saved),))
        with self.assertRaisesRegex(cf.CloudflareError,'plan_changed'):
            self.adapter.apply(plan['plan_id'],'permit')
        self.assertFalse(self.patches())

    def test_private_bridge_supplies_token_without_environment(self):
        class Bridge:
            def request(self, operation):
                self.operation = operation
                return {'cloudflare_token':'private-test-token'}
        bridge = Bridge()
        adapter = cf.CloudflareEffects(self.config,bridge=bridge,now=lambda:NOW)
        with patch.object(cf,'HttpTransport',return_value=self.api) as constructor:
            adapter.plan(self.old,self.new,NEW)
            constructor.assert_called_once_with('private-test-token')
        self.assertEqual(bridge.operation,'credentials')

    def install_core_permit(self, expires=NOW+30):
        active = {**self.old,'config_hash':'a'*64}
        candidate = {**self.new,'config_hash':'a'*64}
        journal = core.Journal(self.root/'controller.sqlite3'); journal.initialize(active,NOW)
        intent = {'id':'permit','kind':'PROMOTE','created_at':NOW,'expires_at':expires,
                  'payload':{'old_identity':self.old,'identity':self.new}}
        with journal.transaction() as db:
            state = journal._read(db)
            state.update(candidate=candidate,phase='CUTOVER',pending_action='permit')
            journal._write(db,state)
            db.execute('INSERT INTO actions(id,kind,incident,created_at,body) VALUES(?,?,?,?,?)',
                       ('permit','PROMOTE','incident',NOW,core.canonical(intent)))

    def test_default_authorizer_requires_core_commit_and_provider_address_proof(self):
        self.config['policy']['enabled'] = True; self.install_core_permit()
        class Provider:
            def probe_target(inner,ident): return {'identity':ident,'ipv4':OLD if ident==self.old else NEW}
        adapter = self.make(authorizer=None,provider=Provider())
        plan = adapter.plan(self.old,self.new,NEW)
        self.assertTrue(adapter.apply(plan['plan_id'],'permit')['api_verified'])

    def test_live_provider_reads_cannot_outlive_short_core_permit(self):
        self.config['policy']['enabled'] = True; self.install_core_permit()
        class Provider:
            def probe_target(inner,ident):
                if ident==self.new: self.clock[0] += 31
                return {'identity':ident,'ipv4':OLD if ident==self.old else NEW}
        adapter = self.make(authorizer=None,provider=Provider())
        plan = adapter.plan(self.old,self.new,NEW)
        with self.assertRaisesRegex(cf.CloudflareError,'scope_or_deadline'):
            adapter.apply(plan['plan_id'],'permit')
        self.assertFalse(self.patches())


class DNSTests(unittest.TestCase):
    def test_dns_tcp_requires_authoritative_answer_and_matches_question(self):
        class Socket:
            authoritative = True
            def __enter__(self): return self
            def __exit__(self,*_): pass
            def settimeout(self,*_): pass
            def sendall(self,data):
                request = data[2:]; ident = struct.unpack('!H',request[:2])[0]
                response = struct.pack('!6H',ident,0x8400 if self.authoritative else 0x8000,1,1,0,0)+request[12:]
                response += b'\xc0\x0c'+struct.pack('!2HIH',1,1,300,4)+socket.inet_aton(NEW)
                self.response = struct.pack('!H',len(response))+response
            def recv(self,count):
                result,self.response = self.response[:count],self.response[count:]
                return result
        sock = Socket()
        with patch.object(cf.socket,'create_connection',return_value=sock):
            good = cf.authoritative_query('9.9.9.9','relay.example.com','A')
            self.assertTrue(good['authoritative']); self.assertEqual(good['addresses'],[NEW])
            sock.authoritative = False
            self.assertFalse(cf.authoritative_query('9.9.9.9','relay.example.com','A')['authoritative'])

    def test_http_transport_verifies_tls_and_refuses_other_origins(self):
        with patch.object(cf.urllib.request,'build_opener') as opener:
            transport = cf.HttpTransport('private-test-token')
            handler = opener.call_args.args[0]
            self.assertEqual(handler._context.verify_mode,ssl.CERT_REQUIRED)
            self.assertTrue(handler._context.check_hostname)
        with self.assertRaises(cf.CloudflareError):
            transport('GET','https://untrusted.example/zones/'+Z,None)


if __name__ == '__main__': unittest.main()
