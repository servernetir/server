"""Fixed private pipe and original-clock artifact tests; no application/network."""
import copy
import hashlib
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest

from infra.relay_recovery import dns_ownership as mod
from infra.relay_recovery.protocol import canonical_json, decode_json
from infra.relay_recovery.provider_adapter import CredentialBridge, ProviderEffectError


NOW = 1_800_000_000
IDENTITY = dict(provider='hetzner', project_id='project-1', provider_id='51', generation=2)


class DnsOwnershipTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name); self.root.chmod(0o700); self.now = NOW
        self.binding = dict(owner_id=1, service_id=7001, project_id='project-1', config_hash='d'*64)
        self.enrollment = dict(schema=mod.ENROLLMENT, agent_id='ir-agent', binding=self.binding, zone_ids=[7])
        self.config = dict(state_dir=str(self.root), policy=dict(owner_id='1', service_id='7001',
            project_id='project-1', agent_id='ir-agent', enabled=False),
            binding=dict(instance_id=5, owner_id=1, service_id=7001))
        self.config['dns_ownership'] = self.pin('enrollment.json', self.enrollment)
        php, script, private = self.root/'php', self.root/'bridge.php', self.root/'php-config.json'
        for path in (php, script, private):
            path.write_bytes(b'fixed offline fixture');path.chmod(0o700 if path==php else 0o600)
        self.config['bridge'] = dict(php=str(php), php_sha256=hashlib.sha256(php.read_bytes()).hexdigest(),
            path=str(script), sha256=hashlib.sha256(script.read_bytes()).hexdigest(), config=str(private))
        self.state = dict(active={**IDENTITY,'config_hash':'d'*64}, clock_unsafe=False, last_now=NOW)
        with sqlite3.connect(self.root/'controller.sqlite3') as db:
            db.execute('CREATE TABLE state(id INTEGER PRIMARY KEY,body TEXT)')
            db.execute('INSERT INTO state VALUES(1,?)',(canonical_json(self.state).decode(),))
        (self.root/'controller.sqlite3').chmod(0o600)
        self.calls=[];self.responses=[];self.after_read=None

    def pin(self,name,value):
        path=self.root/name;raw=canonical_json(value);path.write_bytes(raw);path.chmod(0o600)
        return dict(path=str(path),sha256=hashlib.sha256(raw).hexdigest())

    def source(self, **changes):
        value=dict(schema=mod.SOURCE, ok=True, read_only=True, internal_protected=True,
            agent_id='ir-agent', binding=copy.deepcopy(self.binding),
            enrollment_sha256=self.config['dns_ownership']['sha256'], identity=copy.deepcopy(IDENTITY),
            ipv4='9.9.9.9', observed_at=NOW, received_at=NOW,
            catalog='catalog.example.invalid.', zones=[dict(id=7,name='owned.example.com.')])
        value.update(changes);return value

    def runner(self,argv,data,timeout):
        self.calls.append((argv,decode_json(data),timeout))
        self.assertEqual(decode_json(data),{'op':'dns_ownership'})
        value=self.responses.pop(0) if self.responses else self.source()
        if self.after_read:self.after_read()
        return 0, canonical_json(value) if isinstance(value,dict) else value

    def bridge(self):
        return mod.DnsOwnershipBridge(self.config,runner=self.runner,clock=lambda:self.now,allow_test_paths=True)

    def reject(self, action):
        with self.assertRaisesRegex(mod.DnsOwnershipError,'^dns_ownership_unavailable$'):action()

    def test_actual_fixed_pipe_creates_exact_original_clock_artifact_and_source(self):
        result=self.bridge().build();artifact=result['artifact']
        self.assertEqual(set(artifact),{'schema','agent_id','binding','source_receipt_sha256',
            'observed_at','expires_at','catalog','zones'})
        self.assertEqual(NOW,artifact['observed_at']);self.assertEqual(NOW+900,artifact['expires_at'])
        self.assertEqual(canonical_json(self.source()),Path(result['source_path']).read_bytes())
        self.assertEqual(hashlib.sha256(canonical_json(self.source())).hexdigest(),artifact['source_receipt_sha256'])
        self.assertEqual(canonical_json(artifact),Path(result['path']).read_bytes())
        self.assertEqual(0o600,Path(result['path']).stat().st_mode&0o777)
        self.assertEqual(1,len(self.calls));self.assertTrue(result['read_only'])
        self.assertNotIn('probe_ok',result);self.assertNotIn('registry_complete',result)
        self.assertEqual([self.config['bridge'][k] for k in ('php','path','config')],self.calls[0][0])
        self.assertEqual('catalog.example.invalid.',artifact['catalog'])

    def test_identical_source_is_immutable_and_failure_preserves_existing_artifacts(self):
        bridge=self.bridge();first=bridge.build();before={p.name:p.read_bytes() for p in bridge.artifacts.iterdir()}
        self.assertEqual(first,bridge.build())
        self.responses=[self.source(observed_at=NOW-30)]
        self.reject(bridge.build)
        self.assertEqual(before,{p.name:p.read_bytes() for p in bridge.artifacts.iterdir()})

    def test_historical_reply_or_delay_cannot_renew_its_source_clock(self):
        for source in (self.source(observed_at=NOW-1), self.source(observed_at=NOW+1),
                       self.source(observed_at=True),self.source(received_at=NOW+1)):
            self.responses=[source];self.reject(self.bridge().build)
        self.after_read=lambda:setattr(self,'now',NOW+901)
        self.reject(self.bridge().build)

    def test_real_backend_wait_consumes_lifetime_instead_of_renewing_it(self):
        self.responses=[self.source(received_at=NOW+17)]
        self.after_read=lambda:setattr(self,'now',NOW+17)
        result=self.bridge().build()
        self.assertEqual(NOW,result['observed_at'])
        self.assertEqual(NOW+900,result['expires_at'])
        self.assertEqual(883,result['expires_at']-self.now)

    def test_extra_secret_fields_or_bad_scope_are_not_retained(self):
        for changed in (dict(credential='fixture secret must not persist'),dict(read_only=False),
                        dict(internal_protected=False),dict(agent_id='other'),dict(enrollment_sha256='0'*64),
                        dict(binding={**self.binding,'owner_id':2}),dict(identity={**IDENTITY,'generation':True})):
            self.responses=[self.source(**changed)];self.reject(self.bridge().build)
        self.assertFalse(list((self.root/'dns-ownership').iterdir()))

    def test_zone_set_catalog_and_types_are_exact(self):
        cases=[dict(zones=[]),dict(zones=[dict(id=8,name='owned.example.com.')]),
               dict(zones=[dict(id=True,name='owned.example.com.')]),dict(catalog=''),
               dict(catalog='catalog.example.invalid'),dict(zones=[dict(id=7,name='catalog.example.invalid.')]),
               dict(zones=[dict(id=7,name='owned.example.com.',extra='unselected')])]
        for changed in cases:
            self.responses=[self.source(**changed)];self.reject(self.bridge().build)

    def test_only_private_enrollment_selects_zones_and_catalog_is_not_a_request(self):
        bridge=self.bridge()
        with self.assertRaises(TypeError):bridge.build({'zones':[9]})
        with self.assertRaises(ProviderEffectError):bridge.pipe._exchange({'op':'dns_ownership','zone_id':9})
        with self.assertRaises(ProviderEffectError):CredentialBridge(self.config).request('dns_ownership')
        self.assertFalse(self.calls)

    def test_controller_or_enrollment_drift_during_backend_read_holds(self):
        def changed():
            self.state['active']['generation']=3
            with sqlite3.connect(self.root/'controller.sqlite3') as db:
                db.execute('UPDATE state SET body=?',(canonical_json(self.state).decode(),))
        self.after_read=changed;self.reject(self.bridge().build)
        self.assertFalse(list((self.root/'dns-ownership').iterdir()))

    def test_malformed_missing_catalog_and_failed_php_hold_without_artifact(self):
        for response in (b'not JSON',canonical_json({'ok':False,'error':'fixed'}),
                         self.source(catalog=None),self.source(ipv4='127.0.0.1')):
            self.responses=[response];self.reject(self.bridge().build)
        self.assertFalse(list((self.root/'dns-ownership').iterdir()))

    def test_enrollment_and_script_pins_fail_closed(self):
        bridge=self.bridge();Path(self.config['bridge']['path']).write_bytes(b'changed')
        self.reject(bridge.build);self.assertFalse(self.calls)
        self.config['bridge']['sha256']=hashlib.sha256(b'changed').hexdigest()
        Path(self.config['dns_ownership']['path']).write_bytes(b'{}')
        self.reject(bridge.build)

    def test_output_alias_cannot_replace_an_unrelated_private_file(self):
        bridge=self.bridge();source=self.source();digest=hashlib.sha256(canonical_json(source)).hexdigest()
        target=self.root/'existing.json';target.write_bytes(b'keep');target.chmod(0o600)
        (bridge.artifacts/('source-'+digest+'.json')).symlink_to(target)
        self.reject(bridge.build);self.assertEqual(b'keep',target.read_bytes())

    def test_enrollment_ids_and_binding_do_not_accept_numeric_type_aliases(self):
        for ids in ([True],[7.0],[8,7],[7,7]):
            self.config['dns_ownership']=self.pin('enrollment.json',{**self.enrollment,'zone_ids':ids})
            with self.assertRaises(mod.DnsOwnershipError):self.bridge()
        self.config['dns_ownership']=self.pin('enrollment.json',self.enrollment)
        self.config['binding']['owner_id']=True
        with self.assertRaises(mod.DnsOwnershipError):self.bridge()
        self.assertFalse(self.calls)


if __name__=='__main__':unittest.main()
