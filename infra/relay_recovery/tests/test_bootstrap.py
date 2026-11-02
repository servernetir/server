"""Offline bootstrap tests: real local ssh-keygen, no provider/VM operations."""
import base64
import copy
import hashlib
import json
import os
from pathlib import Path
import stat
import struct
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from infra.relay_recovery import bootstrap as b


def key(fill):
    blob=struct.pack('!I',11)+b'ssh-ed25519'+struct.pack('!I',32)+bytes([fill])*32
    return 'ssh-ed25519 '+base64.b64encode(blob).decode()


class BootstrapTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.root.chmod(0o700)
        self.controller=self.root/'controller.pub';self.controller.write_text(key(1)+' controller\n');self.controller.chmod(0o644)
        self.iran=self.root/'iran.pub';self.iran.write_text(key(2)+'\n');self.iran.chmod(0o600)
        self.source_public=self.root/'source.pub';self.source_public.write_text(key(3)+'\n');self.source_public.chmod(0o600)
        self.source_known=self.root/'source.known_hosts';self.source_known.write_text('8.8.4.4 '+key(3)+'\n');self.source_known.chmod(0o600)
        self.config={'state_dir':str(self.root),'policy':{'owner_id':'test-owner',
            'service_id':'test-service','project_id':'test-project'},
            'binding':{'provider_id':'12345','ipv4':'8.8.4.4'},'bootstrap':{
            'controller_public_key_file':str(self.controller),'controller_source_ips':['8.8.8.8'],
            'iran_probe_public_key_file':str(self.iran),'iran_probe_source_ips':['1.1.1.1'],
            'source_public_host_key_file':str(self.source_public),'source_known_hosts_file':str(self.source_known)}}
        self.source={'provider':'hetzner','project_id':'test-project','provider_id':'12345',
                     'generation':4,'config_hash':'a'*64}
        self.candidate={**self.source,'provider_id':'67890','generation':5}
        self.intent={'id':'1'*40,'kind':'CREATE','payload':{'next_generation':5,'option':{
            **self.config['policy'],'provider':'hetzner','architecture':'x86','image':'1234',
            'location':'fsn1','config_hash':'b'*64}}}
        self.calls=[]

    def runner(self,argv):
        self.calls.append(argv)
        return b._run(argv)

    def store(self):return b.BootstrapStore(self.config,runner=self.runner)

    def document(self,artifact):
        raw=self.store().read_user_data(artifact)
        self.assertTrue(raw.startswith('#cloud-config\n'))
        self.assertLessEqual(len(raw.encode()),32768)
        return json.loads(raw.split('\n',1)[1])

    def test_generate_once_private_durable_artifact_and_source_bound_trial(self):
        store=self.store();artifact=store.prepare(self.intent,self.source)
        self.assertEqual(store.prepare(self.intent,self.source),artifact)
        generated=[argv for argv in self.calls if '-t' in argv]
        self.assertEqual(len(generated),1)
        self.assertEqual(generated[0][:10],[b.KEYGEN,'-q','-t','ed25519','-N','','-C','servernet-relay-host','-f',str(self.root/'bootstrap'/self.intent['id']/'host_ed25519')])
        directory=self.root/'bootstrap'/self.intent['id']
        self.assertEqual(stat.S_IMODE(directory.stat().st_mode),0o700)
        for path in directory.iterdir():self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o600,path.name)
        doc=self.document(artifact)
        self.assertFalse(doc['ssh_pwauth']);self.assertFalse(doc['allow_public_ssh_keys'])
        self.assertTrue(doc['ssh_deletekeys']);self.assertFalse(doc['disable_root'])
        self.assertEqual(doc['ssh_keys']['ed25519_public'],artifact.public_host_key)
        self.assertIn('PRIVATE KEY',doc['ssh_keys']['ed25519_private'])
        self.assertNotIn('PRIVATE KEY',repr(artifact))
        self.assertNotIn('PRIVATE KEY',(directory/'trial.json').read_text())
        metadata=json.loads((directory/'trial.json').read_text())
        self.assertEqual(metadata['owner_id'],'test-owner')
        self.assertEqual(metadata['service_id'],'test-service')
        self.assertEqual(metadata['source_identity'],self.source)
        self.assertEqual(metadata['image'],'1234');self.assertEqual(metadata['location'],'fsn1')
        self.assertEqual(metadata['generation'],5);self.assertEqual(metadata['config_hash'],'b'*64)
        auth=doc['users'][0]['ssh_authorized_keys']
        self.assertEqual(len(auth),2)
        self.assertTrue(auth[0].startswith('from="8.8.8.8",no-agent-forwarding,no-port-forwarding,no-X11-forwarding,no-pty ssh-ed25519 '))
        self.assertTrue(auth[1].startswith('from="1.1.1.1",'))
        self.assertNotIn('runcmd',doc)
        scope=json.loads((directory/'scope.json').read_text())
        self.assertEqual(scope['source_identity'],self.source)
        self.assertEqual(scope['owner_id'],'test-owner')

    def test_distinct_intents_have_distinct_host_identity(self):
        store=self.store();first=store.prepare(self.intent,self.source)
        other=copy.deepcopy(self.intent);other['id']='2'*40
        second=store.prepare(other,self.source)
        self.assertNotEqual(first.public_host_key,second.public_host_key)
        self.assertNotEqual(first.user_data_sha256,second.user_data_sha256)

    def test_rebuild_preserves_source_sftp_identity_without_private_metadata(self):
        store=self.store();trial=store.prepare(self.intent,self.source)
        with self.assertRaises(FileNotFoundError):store.profile(self.intent['id'],'restored')
        bound=store.bind_candidate(self.intent['id'],self.candidate,'9.9.9.9')
        self.assertEqual(Path(bound.known_hosts_path).read_text(),'9.9.9.9 '+trial.public_host_key+'\n')
        restored=store.profile(self.intent['id'],'restored')
        self.assertNotEqual(restored.public_host_key,trial.public_host_key)
        self.assertEqual(restored.public_host_key,key(3))
        self.assertEqual(restored.host_key_origin,'source');self.assertEqual(trial.host_key_origin,'trial')
        self.assertNotEqual(restored.known_hosts_path,bound.known_hosts_path)
        self.assertEqual(Path(restored.known_hosts_path).read_text(),'9.9.9.9 '+key(3)+'\n')
        self.assertEqual(Path(bound.known_hosts_path).read_text(),'9.9.9.9 '+trial.public_host_key+'\n')
        self.assertTrue(restored.machine_id_receipt_required)
        doc=self.document(restored)
        self.assertNotIn('ssh_keys',doc);self.assertFalse(doc['ssh_deletekeys'])
        self.assertNotIn('ssh_pwauth',doc);self.assertNotIn('users',doc);self.assertNotIn('bootcmd',doc)
        self.assertEqual(doc['cloud_init_modules'],['write_files'])
        self.assertEqual(doc['cloud_config_modules'],['runcmd'])
        self.assertEqual(doc['cloud_final_modules'],['scripts_user'])
        self.assertEqual({entry['path'] for entry in doc['write_files']},{
            '/etc/servernet/relay-bootstrap.json',
            '/usr/local/lib/servernet-relay-recovery/machine_id_recovery.py',
            '/etc/systemd/system/'+b.MACHINE_ID_UNIT})
        self.assertNotIn('BEGIN OPENSSH PRIVATE KEY',self.store().read_user_data(restored))
        self.assertNotIn(self.document(bound)['ssh_keys']['ed25519_private'],self.store().read_user_data(restored))
        self.assertEqual(doc['runcmd'],[['/usr/bin/systemctl','daemon-reload'],
            ['/usr/bin/systemctl','enable',b.MACHINE_ID_UNIT],
            ['/usr/bin/systemctl','--no-block','start',b.MACHINE_ID_UNIT]])
        unit=next(f['content'] for f in doc['write_files'] if f['path']=='/etc/systemd/system/'+b.MACHINE_ID_UNIT)
        self.assertIn('After=cloud-init.target\n',unit)
        self.assertIn('Wants=cloud-init.target\n',unit)
        self.assertIn('WantedBy=cloud-final.service\n',unit)
        self.assertNotIn('multi-user.target',unit)
        guest=json.loads(next(f['content'] for f in doc['write_files'] if f['path']=='/etc/servernet/relay-bootstrap.json'))
        self.assertEqual(guest['candidate_provider_id'],self.candidate['provider_id'])
        self.assertEqual(guest['service_id'],'test-service')
        self.assertFalse(json.loads((self.root/'bootstrap'/self.intent['id']/'restored.json').read_text())['machine_id_ready'])
        self.assertEqual(store.profile(self.intent['id'],'restored'),restored)

    def test_source_pin_requires_private_matching_source_and_provider_ip(self):
        self.source_public.chmod(0o644)
        with self.assertRaisesRegex(b.BootstrapError,'private_bootstrap_path'):self.store()
        self.source_public.chmod(0o600)
        self.source_known.write_text('8.8.4.4 '+key(4)+'\n')
        with self.assertRaisesRegex(b.BootstrapError,'pin_mismatch'):self.store()
        self.source_known.write_text('8.8.4.4 '+key(3)+'\n'+'9.9.9.9 '+key(3)+'\n')
        with self.assertRaisesRegex(b.BootstrapError,'pin_mismatch'):self.store()
        self.source_known.write_text('8.8.4.4 '+key(3)+'\n')
        wrong={**self.source,'ipv4':'9.9.9.9'}
        with self.assertRaisesRegex(b.BootstrapError,'resource_ip'):self.store().prepare(self.intent,wrong)
        self.config['binding']['ipv4']='9.9.9.9'
        self.store().prepare(self.intent,{**self.source,'ipv4':'8.8.4.4'})
        self.config['binding']['provider_id']='other'
        with self.assertRaisesRegex(b.BootstrapError,'resource_ip'):self.store().prepare(self.intent,self.source)

    def test_immutable_scope_binding_and_profile_block_reuse(self):
        store=self.store();store.prepare(self.intent,self.source)
        changed=copy.deepcopy(self.intent);changed['payload']['option']['location']='hel1'
        with self.assertRaisesRegex(b.BootstrapError,'immutable_bootstrap_conflict'):store.prepare(changed,self.source)
        store.bind_candidate(self.intent['id'],self.candidate,'9.9.9.9')
        with self.assertRaisesRegex(b.BootstrapError,'immutable_bootstrap_conflict'):store.bind_candidate(self.intent['id'],self.candidate,'8.8.4.4')
        with self.assertRaises(b.BootstrapError):store.bind_candidate(self.intent['id'],self.source,'9.9.9.9')
        with self.assertRaises(b.BootstrapError):store.profile(self.intent['id'],'initial-provisioner')
        self.config['bootstrap']['controller_source_ips']=['8.8.4.4']
        with self.assertRaisesRegex(b.BootstrapError,'authorizations_changed'):self.store().profile(self.intent['id'])

    def test_keygen_completed_before_process_failure_is_not_regenerated(self):
        fail=[True]
        def crashing(argv):
            result=self.runner(argv)
            if '-t' in argv and fail[0]:fail[0]=False;raise b.BootstrapError('injected_after_keygen')
            return result
        store=b.BootstrapStore(self.config,runner=crashing)
        with self.assertRaisesRegex(b.BootstrapError,'injected'):store.prepare(self.intent,self.source)
        private=self.root/'bootstrap'/self.intent['id']/'host_ed25519'
        original=private.read_bytes()
        artifact=store.prepare(self.intent,self.source)
        self.assertEqual(private.read_bytes(),original)
        self.assertEqual(len([x for x in self.calls if '-t' in x]),1)
        self.assertTrue(artifact.public_host_key.startswith('ssh-ed25519 '))

    def test_invalid_keys_sources_scope_permissions_and_symlink_fail_closed(self):
        for supplied in (key(1)+'\n'+key(2),'command="id" '+key(1),'ssh-rsa AAAA',key(1)+' a b'):
            with self.subTest(key=supplied):
                with self.assertRaises(b.BootstrapError):b.public_key(supplied)
        for source in (['*'],['8.8.8.0/24'],['127.0.0.1'],['10.0.0.1'],['8.8.8.8','8.8.8.8']):
            with self.subTest(source=source):
                with self.assertRaises(b.BootstrapError):b._sources(source)
        self.controller.chmod(0o666)
        with self.assertRaises(b.BootstrapError):self.store()
        self.controller.chmod(0o644)
        for image in ('0',True,'1;id',None):
            invalid=copy.deepcopy(self.intent);invalid['payload']['option']['image']=image
            with self.assertRaises(b.BootstrapError):self.store().prepare(invalid,self.source)
        store=self.store();store.prepare(self.intent,self.source)
        private=self.root/'bootstrap'/self.intent['id']/'host_ed25519'
        private.unlink();private.symlink_to(self.controller)
        with self.assertRaises(b.BootstrapError):store.prepare(self.intent,self.source)

    def test_hash_binding_and_oversize_restored_payload_rejected(self):
        store=self.store();artifact=store.prepare(self.intent,self.source)
        data=Path(artifact.user_data_path)
        data.write_bytes(data.read_bytes()+b' ')
        with self.assertRaisesRegex(b.BootstrapError,'hash_mismatch'):store.read_user_data(artifact)
        data.write_bytes(data.read_bytes()[:-1])
        store.bind_candidate(self.intent['id'],self.candidate,'9.9.9.9')
        with patch.object(b,'MACHINE_ID_HELPER','x'*32768):
            with self.assertRaisesRegex(b.BootstrapError,'too_large'):store.profile(self.intent['id'],'restored')
        binding=self.root/'bootstrap'/self.intent['id']/'candidate.json'
        value=json.loads(binding.read_text());value['identity']['generation']=99;binding.write_text(json.dumps(value))
        with self.assertRaisesRegex(b.BootstrapError,'binding_scope'):store.profile(self.intent['id'],'restored')


@unittest.skipUnless(os.geteuid()==0,'guest helper enforces root ownership')
class MachineIdHelperTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.root.chmod(0o700)
        self.ns={'__name__':'offline_fixture'}
        exec(compile(b.MACHINE_ID_HELPER,'machine_id_recovery.py','exec'),self.ns)
        for name in ('CONFIG','GUARD','MACHINE','BOOT','DMI'):self.ns[name]=self.root/name.lower()
        self.ns['MARKERS']=self.root/'markers'
        self.ns['CLEAN_HOOKS']=self.root/'clean.d'
        self.c={'schema':'servernet.relay.bootstrap.guest.v1','profile':'restored','service_id':'test-service',
            'candidate_provider_id':'67890','intent_id':'1'*40,'generation':5,'config_hash':'b'*64,
            'source_identity':{'provider_id':'12345','generation':4,'config_hash':'a'*64}}
        source_dmi='12345678-1234-1234-1234-123456789abc'
        self.g={'schema':'servernet.relay.generation.v1','service_id':'test-service','provider_id':'12345',
            'generation':4,'config_hash':'a'*64,'fenced':True,'units':['relay-app.service'],
            'machine_uuid_sha256':hashlib.sha256(source_dmi.encode()).hexdigest()}
        self.write_json('CONFIG',self.c);self.write_json('GUARD',self.g)
        self.ns['DMI'].write_text('87654321-4321-4321-4321-cba987654321\n')
        self.ns['BOOT'].write_text('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa\n')
        self.ns['MACHINE'].write_text('1'*32+'\n')
        self.ns['metadata_id']=lambda:'67890'
        self.calls=[];self.writer_active=False;self.clean_ok=True
        def command(argv,timeout=10):
            self.calls.append((argv,timeout))
            if argv[1]=='is-active':return SimpleNamespace(returncode=0 if self.writer_active else 3,stdout=b'active\n' if self.writer_active else b'inactive\n')
            if argv[0]=='/usr/bin/cloud-init':
                if self.clean_ok:self.ns['MACHINE'].write_text('uninitialized\n')
                return SimpleNamespace(returncode=0 if self.clean_ok else 1,stdout=b'')
            if argv==['/usr/bin/systemctl','reboot']:return SimpleNamespace(returncode=0,stdout=b'')
            raise AssertionError('unexpected command')
        self.ns['command']=command

    def write_json(self,name,value):
        self.ns[name].write_text(json.dumps(value));self.ns[name].chmod(0o600)

    def mutations(self):return [argv for argv,_ in self.calls if 'is-active' not in argv]

    def test_one_guarded_reboot_not_ready_until_new_boot_and_machine_identity(self):
        self.assertEqual(self.ns['step'](),{'ready':False,'phase':'awaiting_reboot'})
        self.assertEqual(self.ns['step'](),{'ready':False,'phase':'awaiting_reboot'})
        self.assertEqual(self.mutations(),[['/usr/bin/cloud-init','clean','--machine-id'],['/usr/bin/systemctl','reboot']])
        self.ns['BOOT'].write_text('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb\n')
        self.ns['MACHINE'].write_text('2'*32+'\n')
        result=self.ns['step']()
        self.assertTrue(result['ready']);self.assertNotEqual(result['boot_id'],result['previous_boot_id'])
        self.assertNotEqual(result['machine_id_sha256'],result['previous_machine_id_sha256'])
        self.assertEqual(result['candidate_provider_id'],'67890')
        self.assertEqual(self.ns['step'](),result)
        self.assertEqual(len(self.mutations()),2)
        self.assertTrue(json.loads(self.ns['GUARD'].read_text())['fenced'])
        marker=self.ns['MARKERS']/self.c['intent_id']/'machine-id.json'
        self.assertEqual(stat.S_IMODE(marker.stat().st_mode),0o600)
        self.assertEqual(stat.S_IMODE(marker.parent.stat().st_mode),0o700)
        self.ns['BOOT'].write_text('cccccccc-cccc-cccc-cccc-cccccccccccc\n')
        later=self.ns['step']()
        self.assertTrue(later['ready']);self.assertNotEqual(later['boot_id'],result['boot_id'])
        self.assertEqual(json.loads(marker.read_text())['new_boot_id'],later['boot_id'])
        self.assertEqual(len(self.mutations()),2)

    def test_source_metadata_or_same_dmi_or_running_writer_never_mutates(self):
        self.ns['metadata_id']=lambda:'12345'
        with self.assertRaisesRegex(ValueError,'not_candidate'):self.ns['step']()
        self.assertFalse(self.mutations())
        self.ns['metadata_id']=lambda:'67890'
        self.ns['DMI'].write_text('12345678-1234-1234-1234-123456789abc\n')
        with self.assertRaisesRegex(ValueError,'inherited_fence'):self.ns['step']()
        self.assertFalse(self.mutations())
        self.ns['DMI'].write_text('87654321-4321-4321-4321-cba987654321\n');self.writer_active=True
        with self.assertRaisesRegex(ValueError,'business_not_blocked'):self.ns['step']()
        self.assertFalse(self.mutations())

    def test_unfenced_wrong_service_and_failed_cleanup_do_not_retry(self):
        self.g['fenced']=False;self.write_json('GUARD',self.g)
        with self.assertRaisesRegex(ValueError,'inherited_fence'):self.ns['step']()
        self.g['fenced']=True;self.g['service_id']='other';self.write_json('GUARD',self.g)
        with self.assertRaisesRegex(ValueError,'inherited_fence'):self.ns['step']()
        self.g['service_id']='test-service';self.write_json('GUARD',self.g);self.clean_ok=False
        with self.assertRaisesRegex(ValueError,'machine_clean_failed'):self.ns['step']()
        with self.assertRaisesRegex(ValueError,'requires_reconciliation'):self.ns['step']()
        self.assertEqual(self.mutations(),[['/usr/bin/cloud-init','clean','--machine-id']])

    def test_changed_boot_without_regenerated_id_and_marker_scope_remain_blocked(self):
        self.ns['step']()
        self.ns['BOOT'].write_text('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb\n')
        self.ns['MACHINE'].write_text('1'*32+'\n')
        with self.assertRaisesRegex(ValueError,'machine_not_regenerated'):self.ns['step']()
        self.ns['MACHINE'].write_text('0'*32+'\n')
        with self.assertRaisesRegex(ValueError,'machine_not_regenerated'):self.ns['step']()
        self.c['config_hash']='c'*64;self.write_json('CONFIG',self.c)
        with self.assertRaisesRegex(ValueError,'machine_scope_conflict'):self.ns['step']()
        self.assertEqual(len(self.mutations()),2)

    def test_prior_generation_markers_are_preserved_and_path_traversal_rejected(self):
        prior=self.ns['MARKERS']/('f'*40)
        self.ns['MARKERS'].mkdir(mode=0o700);prior.mkdir(mode=0o700)
        history=prior/'machine-id.json';history.write_text('{"prior":"immutable"}');history.chmod(0o600)
        self.ns['step']()
        self.assertEqual(history.read_text(),'{"prior":"immutable"}')
        self.assertTrue((self.ns['MARKERS']/self.c['intent_id']/'machine-id.json').exists())
        self.c['intent_id']='../other';self.write_json('CONFIG',self.c)
        with self.assertRaisesRegex(ValueError,'restore_intent'):self.ns['step']()
        self.assertEqual(len(self.mutations()),2)

    def test_unknown_clean_hook_blocks_before_clean_and_requires_exact_pinned_inventory(self):
        hooks=self.ns['CLEAN_HOOKS'];hooks.mkdir(mode=0o755)
        script=hooks/'reviewed-hook';script.write_text('#!/bin/sh\nexit 0\n');script.chmod(0o700)
        with self.assertRaisesRegex(ValueError,'clean_hook_inventory'):self.ns['step']()
        self.assertFalse(self.mutations())
        self.assertFalse((self.ns['MARKERS']/self.c['intent_id']/'machine-id.json').exists())
        self.c['clean_hook_sha256']={'reviewed-hook':hashlib.sha256(script.read_bytes()).hexdigest()}
        self.write_json('CONFIG',self.c)
        self.assertFalse(self.ns['step']()['ready'])
        self.assertEqual(len(self.mutations()),2)

    def test_clean_hook_symlink_is_never_followed(self):
        hooks=self.ns['CLEAN_HOOKS'];hooks.mkdir(mode=0o755)
        (hooks/'unexpected').symlink_to(self.ns['CONFIG'])
        with self.assertRaisesRegex(ValueError,'clean_hook_symlink'):self.ns['step']()
        self.assertFalse(self.mutations())


if __name__=='__main__':unittest.main()
