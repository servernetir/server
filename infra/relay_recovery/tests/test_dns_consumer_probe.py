import copy
import hashlib
import os
from pathlib import Path
import socket
import struct
import tempfile
import threading
import time
import unittest

from infra.relay_recovery import dns_consumer_probe as mod, iran_adapter, protocol


ZONE = 'owned.example.invalid.'
CATALOG = 'catalog.example.invalid.'
SOA = ((b'ns', b'example', b'invalid'), (b'hostmaster', b'example', b'invalid'),
       123, 3600, 600, 2419200, 300)


def response(query_id=7, zone=ZONE, soa=SOA, *, flags=0x8400, owner=b'\xc0\x0c'):
    question = mod._wire_name(zone) + struct.pack('!HH', 6, 1)
    names = [b''.join(bytes([len(part)]) + part for part in name) + b'\0' for name in soa[:2]]
    data = b''.join(names) + struct.pack('!5I', *soa[2:])
    return (struct.pack('!6H', query_id, flags, 1, 1, 0, 0) + question + owner
            + struct.pack('!HHIH', 6, 1, 60, len(data)) + data)


class WireTests(unittest.TestCase):
    def test_authoritative_compressed_owner(self):
        self.assertEqual(mod.parse_soa(response(), query_id=7, zone=ZONE), SOA)

    def test_wrong_id_and_question(self):
        for kwargs in ({'query_id':8}, {'zone':'other.example.invalid.'}):
            with self.subTest(kwargs=kwargs), self.assertRaises(protocol.ProtocolError):
                mod.parse_soa(response(**kwargs), query_id=7, zone=ZONE)

    def test_non_authoritative_recursive_truncated_error_and_opcode(self):
        for flags in (0x8000, 0x8500, 0x8600, 0x8403, 0x8c00, 0x8440, 0x0400):
            with self.subTest(flags=flags), self.assertRaises(protocol.ProtocolError):
                mod.parse_soa(response(flags=flags), query_id=7, zone=ZONE)

    def test_wrong_answer_owner(self):
        with self.assertRaises(protocol.ProtocolError):
            mod.parse_soa(response(owner=mod._wire_name('other.example.invalid.')), query_id=7, zone=ZONE)

    def test_truncated_oversized_or_trailing_packet(self):
        packet = response()
        for bad in (packet[:11], packet[:-1], packet+b'\0', packet+b'X'*4096):
            with self.subTest(size=len(bad)), self.assertRaises(protocol.ProtocolError):
                mod.parse_soa(bad, query_id=7, zone=ZONE)

    def test_no_alias_or_wrong_answer_type(self):
        packet = bytearray(response())
        offset = 12+len(mod._wire_name(ZONE))+4+2
        packet[offset:offset+2] = struct.pack('!H',5)
        with self.assertRaises(protocol.ProtocolError):
            mod.parse_soa(bytes(packet), query_id=7, zone=ZONE)

    def test_name_compression_cycle_and_forward_pointer(self):
        for pointer in (12,14):
            packet=b'\0'*12+struct.pack('!H',0xc000|pointer)+b'\0'
            with self.subTest(pointer=pointer), self.assertRaises(protocol.ProtocolError):mod._read_name(packet,12)

    def test_soa_names_can_compress_to_question(self):
        question=mod._wire_name(ZONE)+struct.pack('!HH',6,1)
        data=b'\xc0\x0c\xc0\x0c'+struct.pack('!5I',*SOA[2:])
        packet=struct.pack('!6H',7,0x8400,1,1,0,0)+question+b'\xc0\x0c'+struct.pack('!HHIH',6,1,60,len(data))+data
        actual=mod.parse_soa(packet,query_id=7,zone=ZONE)
        self.assertEqual(actual[:2], ((b'owned',b'example',b'invalid'),)*2)

    def test_real_udp_and_tcp_exchange_bound_to_literal_source(self):
        # Actual localhost protocol exchange; production enrollment rejects
        # loopback addresses, so this never reaches any infrastructure endpoint.
        errors=[]
        with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as tcp, socket.socket(socket.AF_INET,socket.SOCK_DGRAM) as udp:
            tcp.bind(('127.0.0.1',0));tcp.listen(1);tcp.settimeout(2)
            port=tcp.getsockname()[1];udp.bind(('127.0.0.1',port));udp.settimeout(2)
            def server():
                try:
                    data,peer=udp.recvfrom(4096)
                    self.assertEqual(peer[0],'127.0.0.1')
                    self.assertEqual(data[2:4],b'\0\0')
                    udp.sendto(response(struct.unpack('!H',data[:2])[0]),peer)
                    connection,peer=tcp.accept()
                    with connection:
                        connection.settimeout(2)
                        size=struct.unpack('!H',connection.recv(2))[0]
                        request=bytearray()
                        while len(request)<size:request.extend(connection.recv(size-len(request)))
                        answer=response(struct.unpack('!H',request[:2])[0])
                        framed=struct.pack('!H',len(answer))+answer
                        connection.sendall(framed[:1]);connection.sendall(framed[1:])
                except Exception as error:errors.append(error)
            thread=threading.Thread(target=server,daemon=True);thread.start()
            endpoint={'ipv4':'127.0.0.1','port':port}
            for transport in ('udp','tcp'):
                self.assertEqual(mod.exchange(endpoint,ZONE,transport,'127.0.0.1',time.monotonic()+2),SOA)
            thread.join(3)
            self.assertFalse(thread.is_alive())
            self.assertEqual(errors,[])


class ConsumerTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.evidence=self.root/'ownership.json';self.config=self.root/'dns.json'
        self.binding={'owner_id':7,'service_id':17,'project_id':'example-project','config_hash':'a'*64}
        self.owned={'schema':mod.OWNERSHIP_SCHEMA,'agent_id':'test-agent','binding':dict(self.binding),
                    'source_receipt_sha256':'d'*64,'observed_at':1000,'expires_at':1100,
                    'catalog':CATALOG,'zones':[{'id':27,'name':ZONE}]}
        self.cfg={'schema':mod.CONFIG_SCHEMA,'agent_id':'test-agent','binding':dict(self.binding),
                  'primary':{'ipv4':'1.1.1.1','port':5300,'provider_id':'27','generation':3},
                  'secondary':{'ipv4':'10.0.0.5','port':53},
                  'ownership_evidence':{'path':str(self.evidence),'sha256':''}}
        self.context={'schema':protocol.SCHEMA,'agent_id':'test-agent','incident_id':'incident-7',
                      'generation':3,'revision':9,'config_hash':'a'*64,'jobs_hash':'b'*64,
                      'job':{'id':'probe-9','op':'probe','target':{'host':'1.1.1.1','port':22,'provider_id':'27'},
                             'params':{}}}
        self.calls=[]
        self.write()

    def write(self):
        raw=protocol.canonical_json(self.owned)
        self.evidence.write_bytes(raw);self.evidence.chmod(0o600)
        self.cfg['ownership_evidence']['sha256']=hashlib.sha256(raw).hexdigest()
        self.config.write_bytes(protocol.canonical_json(self.cfg));self.config.chmod(0o600)

    def query(self,*args):
        self.calls.append(args)
        return SOA

    def probe(self,**kwargs):
        return mod.DnsConsumerProbe(self.config,agent_id='test-agent',root_owned=False,
                                    now=kwargs.pop('now',lambda:1001),query=kwargs.pop('query',self.query),**kwargs)

    def test_actual_all_four_answers_required_for_each_enrolled_zone(self):
        result=self.probe().run(self.context)
        self.assertTrue(result['ok'])
        self.assertEqual(len(self.calls),8)
        self.assertEqual({(call[0]['port'],call[2]) for call in self.calls},
                         {(53,'udp'),(53,'tcp'),(5300,'udp'),(5300,'tcp')})
        self.assertTrue(all(call[3]=='10.0.0.5' for call in self.calls))
        self.assertEqual(result['target'],self.context['job']['target'])
        self.assertEqual({key:result[key] for key in mod.SCOPE},{key:self.context[key] for key in mod.SCOPE})
        self.assertTrue(result['checks']['network_observation_fresh'])
        self.assertNotIn(ZONE,protocol.canonical_json(result).decode())
        self.assertNotIn('10.0.0.5',protocol.canonical_json(result).decode())

    def test_real_component_receipt_passes_existing_adapter_consumer_contract(self):
        wrapper=self.root/'native-dns';wrapper.write_bytes(b'fixed-test-transport');wrapper.chmod(0o700)
        spec={'action':'dns','argv':[str(wrapper)],'executable_sha256':hashlib.sha256(wrapper.read_bytes()).hexdigest(),
              'inputs':[{'path':str(path),'sha256':hashlib.sha256(path.read_bytes()).hexdigest()}
                        for path in (self.config,self.evidence)],'timeout':8}
        def runner(argv,body,timeout):
            self.assertEqual(argv,[str(wrapper)]);self.assertEqual(timeout,8)
            result=self.probe().run(protocol.decode_json(body))
            return (0 if result['ok'] else 1),protocol.canonical_json(result)
        adapter=iran_adapter.IranAdapter({'root_owned':False},runner=runner)
        receipt=adapter._run(spec,'dns',self.context,probe=True)
        self.assertTrue(receipt['ok'])
        self.assertEqual(receipt['checks']['consumer'],'dns')
        self.assertTrue(receipt['checks']['probe_ok'])

    def test_old_primary_host_provider_generation_or_config_has_no_socket(self):
        for key,value in (('host','8.8.8.8'),('provider_id','28'),('generation',4),('config_hash','e'*64)):
            context=copy.deepcopy(self.context)
            if key in ('host','provider_id'):context['job']['target'][key]=value
            else:context[key]=value
            self.calls.clear()
            result=self.probe().run(context)
            self.assertFalse(result['ok'])
            self.assertEqual(self.calls,[])

    def test_boolean_scope_provider_port_and_dns_port_are_rejected(self):
        cases=[('generation',True),('revision',True)]
        for key,value in cases:
            context=copy.deepcopy(self.context);context[key]=value
            with self.assertRaises(protocol.ProtocolError):self.probe().run(context)
        for key,value in (('provider_id',True),('port',True)):
            context=copy.deepcopy(self.context);context['job']['target'][key]=value
            with self.assertRaises(protocol.ProtocolError):self.probe().run(context)
        self.cfg['primary']['port']=True;self.write()
        self.assertFalse(self.probe().run(self.context)['ok'])
        self.assertEqual(self.calls,[])

    def test_missing_enrollment_has_no_fallback(self):
        self.config.unlink()
        self.assertFalse(self.probe().run(self.context)['ok'])
        self.assertEqual(self.calls,[])

    def test_expired_future_or_long_lived_metadata_has_no_socket(self):
        for observed,expiry in ((900,1001),(1002,1100),(1000,1901)):
            self.owned.update(observed_at=observed,expires_at=expiry);self.write();self.calls.clear()
            self.assertFalse(self.probe().run(self.context)['ok'])
            self.assertEqual(self.calls,[])

    def test_mismatching_evidence_hash_or_owner_service_project_has_no_socket(self):
        for key,value in (('owner_id',8),('service_id',18),('project_id','other-project')):
            self.owned['binding']=dict(self.binding,**{key:value});self.write();self.calls.clear()
            self.assertFalse(self.probe().run(self.context)['ok'])
            self.assertEqual(self.calls,[])
        self.owned['binding']=dict(self.binding);self.write()
        self.evidence.write_bytes(self.evidence.read_bytes()+b'\n')
        self.assertFalse(self.probe().run(self.context)['ok']);self.assertEqual(self.calls,[])

    def test_invalid_or_unowned_zone_enrollment_has_no_socket(self):
        for zones in ([],[{'id':27,'name':CATALOG}],[{'id':27,'name':ZONE},{'id':27,'name':'other.example.invalid.'}],
                      [{'id':True,'name':ZONE}],[{'id':27,'name':'https://secret.example.invalid/'}]):
            self.owned['zones']=zones;self.write();self.calls.clear()
            self.assertFalse(self.probe().run(self.context)['ok']);self.assertEqual(self.calls,[])

    def test_single_missing_or_different_answer_is_false(self):
        for change in ('error','serial','rname','timer','bool'):
            def query(endpoint,zone,transport,*rest):
                if endpoint['port']==53 and zone==ZONE and transport=='tcp':
                    if change=='error':raise TimeoutError()
                    if change=='bool':return True
                    value=list(SOA)
                    value[{'serial':2,'rname':1,'timer':4}[change]]=456 if change!='rname' else (b'other',b'invalid')
                    return tuple(value)
                return SOA
            with self.subTest(change=change):self.assertFalse(self.probe(query=query).run(self.context)['ok'])

    def test_original_deadline_and_ownership_clocks_never_refreshed(self):
        self.context['job']['params']['expires_at']=1002
        clock=iter((1001,1002))
        result=self.probe(now=lambda:next(clock)).run(self.context)
        self.assertFalse(result['ok'])
        self.assertEqual(result['checks']['expires_at'],1002)
        self.assertEqual(result['checks']['ownership_observed_at'],1000)
        self.assertEqual(result['checks']['ownership_expires_at'],1100)

    def test_already_expired_original_deadline_has_no_socket(self):
        self.context['job']['params']['expires_at']=1001
        self.assertFalse(self.probe().run(self.context)['ok']);self.assertEqual(self.calls,[])

    def test_clock_rollback_or_ownership_expiry_during_queries_is_false(self):
        for times in ((1001,1000),(1001,1100)):
            clock=iter(times)
            self.assertFalse(self.probe(now=lambda:next(clock)).run(self.context)['ok'])

    def test_local_reenrollment_during_queries_is_false(self):
        lock=threading.Lock();changed=False
        def query(*args):
            nonlocal changed
            with lock:
                if not changed:
                    self.owned['source_receipt_sha256']='f'*64;self.write();changed=True
            return SOA
        self.assertFalse(self.probe(query=query).run(self.context)['ok'])

    def test_isolated_candidate_observation_cannot_count_as_default(self):
        for params in ({'acceptance_profile':'isolated_candidate_v1'}, {'path_profile':'candidate_socks_v1'}):
            context=copy.deepcopy(self.context);context['job']['params']=params
            with self.assertRaises(protocol.ProtocolError):self.probe().run(context)
        self.assertEqual(self.calls,[])

    def test_private_permissions_symlink_and_hardlink_rejected(self):
        self.evidence.chmod(0o644)
        self.assertFalse(self.probe().run(self.context)['ok'])
        self.write();target=self.root/'linked';os.link(self.evidence,target)
        self.assertFalse(self.probe().run(self.context)['ok'])
        target.unlink();raw=self.evidence.read_bytes();self.evidence.unlink()
        target.write_bytes(raw);target.chmod(0o600);self.evidence.symlink_to(target)
        self.assertFalse(self.probe().run(self.context)['ok']);self.assertEqual(self.calls,[])

    def test_public_secondary_loopback_primary_and_command_fields_rejected(self):
        self.cfg['secondary']['ipv4']='1.1.1.1';self.write()
        self.assertFalse(self.probe().run(self.context)['ok'])
        self.cfg['secondary']['ipv4']='10.0.0.5';self.cfg['primary']['ipv4']='127.0.0.1';self.write()
        self.assertFalse(self.probe().run(self.context)['ok'])
        self.cfg['primary']['ipv4']='1.1.1.1';self.cfg['command']='do-something';self.write()
        self.assertFalse(self.probe().run(self.context)['ok']);self.assertEqual(self.calls,[])

    def test_reserved_or_broadcast_source_is_not_a_private_guest(self):
        for value in ('255.255.255.255','240.0.0.1','192.0.2.1','0.0.0.1'):
            self.cfg['secondary']['ipv4']=value;self.write()
            with self.subTest(value=value):
                self.assertFalse(self.probe().run(self.context)['ok'])
                self.assertEqual(self.calls,[])


if __name__=='__main__':unittest.main()
