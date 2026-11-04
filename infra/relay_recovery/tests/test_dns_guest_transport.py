"""Actual native verifier/adapter contracts; only SSH and DNS I/O are fixtures."""
import base64
import copy
import hashlib
from pathlib import Path
import shlex
import struct
import tempfile
import unittest

from infra.relay_recovery import dns_consumer_probe as dns, dns_guest_transport as mod, iran_adapter, protocol
from infra.relay_recovery.tests import test_dns_consumer_probe as wire

NOW=1_800_000_000


class GuestFixture:
    def __init__(self,root,*,config_hash='a'*64):
        self.root=Path(root);self.root.chmod(0o700);self.now=NOW
        self.identity=dict(provider='hetzner',project_id='project-7',provider_id='27',generation=1)
        self.binding=dict(owner_id=7,service_id=17,project_id='project-7',config_hash=config_hash)
        self.source=dict(schema='servernet.relay.dns-ownership-source.v1',ok=True,read_only=True,
            internal_protected=True,agent_id='ir-agent',binding=self.binding,enrollment_sha256='f'*64,
            identity=self.identity,ipv4='1.1.1.1',observed_at=NOW,received_at=NOW,
            catalog=wire.CATALOG,zones=[dict(id=7,name=wire.ZONE)])
        source_hash=self.hash(protocol.canonical_json(self.source))
        artifact=dict(schema=dns.OWNERSHIP_SCHEMA,agent_id='ir-agent',binding=self.binding,
            source_receipt_sha256=source_hash,observed_at=NOW,expires_at=NOW+900,
            catalog=wire.CATALOG,zones=self.source['zones'])
        self.handoff=dict(schema=dns.HANDOFF_SCHEMA,source=self.source,source_receipt_sha256=source_hash,
                          artifact=artifact,artifact_sha256=self.hash(protocol.canonical_json(artifact)))
        self.context=dict(schema=protocol.SCHEMA,agent_id='ir-agent',incident_id='incident-7',generation=3,
            revision=5,config_hash='b'*64,jobs_hash='c'*64,job=dict(id='probe-27-5',op='probe',
            target=dict(host='1.1.1.1',port=22,provider_id='27'),params=dict(resource_identity=self.identity,
            resource_config_hash=config_hash,critical_consumers=['dns'],dns_ownership=self.handoff)))
        self.enrollment=dict(schema=dns.HANDOFF_CONFIG_SCHEMA,agent_id='ir-agent',binding=self.binding,
            source_identity=self.identity,primary=dict(ipv4='1.1.1.1',port=5300,provider_id='27',generation=1),
            secondary=dict(ipv4='10.0.0.5',port=53),ownership_enrollment_sha256='f'*64)
        self.native_pin=self.pin('native.json',protocol.canonical_json(self.enrollment))
        blob=struct.pack('!I',11)+b'ssh-ed25519'+struct.pack('!I',32)+b'x'*32
        host='10.0.0.5 ssh-ed25519 '+base64.b64encode(blob).decode()+'\n'
        known=self.pin('known-hosts',host.encode());identity=self.root/'identity';identity.write_bytes(b'PRIVATE_FIXTURE');identity.chmod(0o600)
        binary=self.pin('ssh',b'fixed-fixture-executable');Path(binary['path']).chmod(0o700)
        guest=self.root/'guest';guest.mkdir(mode=0o700)
        source_root=Path(dns.__file__).parent
        script=self.pin('guest/dns_consumer_probe.py',(source_root/'dns_consumer_probe.py').read_bytes())
        proto=self.pin('guest/protocol.py',(source_root/'protocol.py').read_bytes())
        python=self.pin('guest/python',b'fixed-offline-python');Path(python['path']).chmod(0o700)
        self.transport_cfg=dict(schema=mod.SCHEMA,agent_id='ir-agent',native_enrollment=self.native_pin,
            ssh=dict(executable=binary,port=22,identity_file=str(identity),known_hosts=known,
                     host_key_sha256='SHA256:'+base64.b64encode(hashlib.sha256(blob).digest()).decode().rstrip('=')),
            guest=dict(python=python,script=script,protocol=proto,config=self.native_pin))
        self.transport_pin=self.pin('transport.json',protocol.canonical_json(self.transport_cfg))
        self.ssh_calls=[];self.dns_calls=[];self.reply_mutation=None;self.fail_query=False

    @staticmethod
    def hash(raw):return hashlib.sha256(raw).hexdigest()

    def pin(self,name,raw):
        path=self.root/name;path.write_bytes(raw);path.chmod(0o600)
        return dict(path=str(path),sha256=self.hash(raw))

    def query(self,endpoint,name,transport,source,deadline):
        self.dns_calls.append((endpoint,name,transport,source,deadline))
        if self.fail_query and endpoint['port']==5300:raise TimeoutError()
        # Exercise the real strict packet parser, never a health boolean.
        return dns.parse_soa(wire.response(zone=name),query_id=7,zone=name)

    def native(self,context):
        return dns.DnsHandoffProbe(self.native_pin['path'],config_sha256=self.native_pin['sha256'],
            agent_id='ir-agent',root_owned=False,query=self.query,now=lambda:self.now).run(context)

    def runner(self,argv,body,timeout):
        self.ssh_calls.append((argv,body,timeout))
        result=self.native(protocol.decode_json(body))
        if self.reply_mutation:self.reply_mutation(result)
        return (0 if result['ok'] else 1),protocol.canonical_json(result)

    def transport(self):
        return mod.DnsGuestTransport(self.transport_pin['path'],self.transport_pin['sha256'],
            agent_id='ir-agent',root_owned=False,runner=self.runner,now=lambda:self.now)


class TransportTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.f=GuestFixture(self.tmp.name)

    def test_wire_maxscope_kept_separate_from_exact_per_resource_source_and_native_observation(self):
        result=self.f.transport().run(self.f.context)
        self.assertTrue(result['ok']);self.assertEqual(result['generation'],3)
        self.assertEqual(result['config_hash'],'b'*64)
        self.assertEqual(len(self.f.ssh_calls),1);self.assertEqual(len(self.f.dns_calls),8)
        self.assertEqual(result['checks']['ownership_observed_at'],NOW)
        self.assertEqual(result['checks']['ownership_expires_at'],NOW+900)
        self.assertEqual(result['checks']['source_receipt_sha256'],self.f.handoff['source_receipt_sha256'])
        self.assertEqual(result['checks']['ownership_sha256'],self.f.handoff['artifact_sha256'])
        argv,body,timeout=self.f.ssh_calls[0]
        self.assertEqual(protocol.decode_json(body),self.f.context);self.assertEqual(timeout,8)
        self.assertIn('-oStrictHostKeyChecking=yes',argv);self.assertIn('-oHostKeyAlgorithms=ssh-ed25519',argv)
        self.assertIn('root@10.0.0.5',argv)
        command=shlex.split(argv[-1]);self.assertEqual(command[1:5],['-E','-S','-B','-c'])
        self.assertIn(self.f.transport_cfg['guest']['script']['sha256'],command[5])
        self.assertNotIn(wire.ZONE,command[5])

    def test_native_primary_timeout_never_becomes_metadata_success(self):
        self.f.fail_query=True
        result=self.f.transport().run(self.f.context)
        self.assertFalse(result['ok']);self.assertFalse(result['checks']['probe_ok'])
        self.assertTrue(self.f.dns_calls)

    def test_missing_handoff_cannot_fall_back_to_static_artifact(self):
        context=copy.deepcopy(self.f.context);context['job']['params'].pop('dns_ownership')
        self.assertFalse(self.f.transport().run(context)['ok']);self.assertFalse(self.f.ssh_calls)
        # Static mode explicitly rejects signed handoff data rather than reading
        # a coincidentally matching local artifact.
        static=dns.DnsConsumerProbe('/not/enrolled',agent_id='ir-agent',root_owned=False,now=lambda:NOW)
        self.assertFalse(static.run(self.f.context)['ok'])

    def test_wrong_candidate_source_owner_enrollment_or_source_hash_has_no_ssh(self):
        changes=[lambda c:c['job']['params']['resource_identity'].update(provider_id='28'),
                 lambda c:c['job']['params']['dns_ownership']['source']['binding'].update(owner_id=8),
                 lambda c:c['job']['params']['dns_ownership']['source'].update(enrollment_sha256='e'*64),
                 lambda c:c['job']['params']['dns_ownership'].update(source_receipt_sha256='0'*64),
                 lambda c:c['job']['params']['dns_ownership']['source'].update(observed_at=NOW-1),
                 lambda c:c['job']['params']['dns_ownership']['artifact'].update(expires_at=NOW+901)]
        for index,change in enumerate(changes):
            context=copy.deepcopy(self.f.context);change(context)
            with self.subTest(index=index):self.assertFalse(self.f.transport().run(context)['ok'])
        self.assertFalse(self.f.ssh_calls);self.assertFalse(self.f.dns_calls)

    def test_original_source_expiry_future_or_delayed_source_no_ssh(self):
        self.f.now=NOW+900
        self.assertFalse(self.f.transport().run(self.f.context)['ok']);self.assertFalse(self.f.ssh_calls)
        self.f.now=NOW-1
        self.assertFalse(self.f.transport().run(self.f.context)['ok']);self.assertFalse(self.f.ssh_calls)

    def test_guest_pin_or_known_host_drift_no_ssh(self):
        Path(self.f.transport_cfg['ssh']['known_hosts']['path']).write_bytes(b'changed')
        self.assertFalse(self.f.transport().run(self.f.context)['ok']);self.assertFalse(self.f.ssh_calls)

    def test_pinned_host_file_cannot_authorize_wildcard_hash_alias_or_second_key(self):
        host_path=Path(self.f.transport_cfg['ssh']['known_hosts']['path']);original=host_path.read_bytes()
        fields=original.split();key=fields[1]+b' '+fields[2]+b'\n'
        cases=[original+b'* '+key,original+b'10.0.0.5 '+key,
               b'10.0.0.5,other.invalid '+key,b'|1|hashed|entry '+key,
               b'@cert-authority 10.0.0.5 '+key,b'* '+key,original+b'other.invalid '+key]
        for index,raw in enumerate(cases):
            # Re-pin bytes to test semantic trust, not merely a changed hash.
            self.f.transport_cfg['ssh']['known_hosts']=self.f.pin('known-hosts',raw)
            self.f.transport_pin=self.f.pin('transport.json',protocol.canonical_json(self.f.transport_cfg))
            with self.subTest(index=index):self.assertFalse(self.f.transport().run(self.f.context)['ok'])
        self.assertFalse(self.f.ssh_calls);self.assertFalse(self.f.dns_calls)

    def test_wrong_native_scope_or_clock_cannot_be_accepted(self):
        self.f.reply_mutation=lambda r:r.update(revision=6)
        self.assertFalse(self.f.transport().run(self.f.context)['ok'])
        self.f.reply_mutation=lambda r:r['checks'].update(ownership_expires_at=NOW+901)
        self.assertFalse(self.f.transport().run(self.f.context)['ok'])
        self.f.reply_mutation=lambda r:r['checks'].update(source_bound=False)
        self.assertFalse(self.f.transport().run(self.f.context)['ok'])

    def test_adapter_accepts_finite_signed_data_but_rejects_wire_commands(self):
        cfg=dict(agent_id='ir-agent',candidate_ports=[22,443])
        self.assertEqual(iran_adapter.validate_context(self.f.context,cfg),self.f.context)
        context=copy.deepcopy(self.f.context);context['job']['params']['dns_ownership']['source']['path']='/bad'
        with self.assertRaises(protocol.ProtocolError):iran_adapter.validate_context(context,cfg)


if __name__=='__main__':unittest.main()
