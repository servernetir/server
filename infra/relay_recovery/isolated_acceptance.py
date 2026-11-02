"""Verify a typed isolated candidate path; never claim default-path cutover.

This is a verification boundary, not a live Windows/CT implementation. The
installed default-path Windows v1 response cannot satisfy this contract. A
reviewed caller must supply the dedicated raw guest HMAC key and a durable
ReplayStore, plus a pinned local recipe that actually measures the CT stage
and dependency registry before/after the guest probe. No network operations or
resource mutations occur here. Missing proofs are errors, never inferred from
``staged=True`` or a successful historical reconciliation.

The guest candidate protocol deliberately uses a separate body schema. Its
authenticated bytes must bind the isolated path, exact stage profile, restore
epoch, proxy binding and dependency baseline. Neither this proof nor ``ok``
from an isolated accept is a default-path or global migration proof.
"""
from __future__ import annotations

import base64
import hashlib
import ipaddress

try:
    from .protocol import canonical_json, decode_json, ProtocolError, HEX64, IDENTIFIER, SCHEMA as WIRE_SCHEMA
    from . import windows_probe_client as windows
except ImportError:
    from protocol import canonical_json, decode_json, ProtocolError, HEX64, IDENTIFIER, SCHEMA as WIRE_SCHEMA
    import windows_probe_client as windows


PROFILE='isolated_candidate_v1'
PATH_PROFILE='candidate_socks_v1'
SCHEMA='servernet.relay.isolated-acceptance.v1'
GUEST_SCHEMA='servernet.relay.vm-candidate-probe.v1'
CT_SCHEMA='servernet.relay.ct-candidate-observation.v1'
REGISTRY_SCHEMA='servernet.relay.dependency-observation.v1'
SCOPE=('agent_id','incident_id','generation','revision','config_hash','jobs_hash')
TTL=30


def _require(test,code):
    if not test:raise ProtocolError(code)


def _hash(value):
    _require(isinstance(value,str) and bool(HEX64.fullmatch(value)),'isolated_proof_hash')
    return value


def parameters(context):
    """Validate the explicitly authenticated isolated-accept request profile."""
    _require(isinstance(context,dict) and set(context)=={'schema',*SCOPE,'job'}
             and context.get('schema')==WIRE_SCHEMA,'isolated_context')
    _require(all(isinstance(context.get(k),str) and IDENTIFIER.fullmatch(context[k])
                 for k in ('agent_id','incident_id')),'isolated_context_identity')
    _require(all(type(context.get(k)) is int and 0<context[k]<=2**53-1
                 for k in ('generation','revision')),'isolated_context_generation')
    for k in ('config_hash','jobs_hash'):_hash(context.get(k))
    job=context['job']
    _require(isinstance(job,dict) and set(job)=={'id','op','target','params'}
             and isinstance(job.get('id'),str) and IDENTIFIER.fullmatch(job['id'])
             and isinstance(job.get('params'),dict),'isolated_job')
    target=job.get('target')
    _require(isinstance(target,dict) and set(target)=={'host','port','provider_id'},'isolated_target')
    try:address=ipaddress.ip_address(target['host'])
    except (ValueError,TypeError):raise ProtocolError('isolated_target') from None
    ref=target.get('provider_id')
    _require(address.version==4 and address.is_global and str(address)==target['host']
             and type(target.get('port')) is int and target['port'] in (22,443)
             and isinstance(ref,str) and ref.isascii() and ref.isdecimal() and 1<=len(ref)<=20 and int(ref)>0,
             'isolated_target')
    p=context['job'].get('params',{})
    _require(context['job'].get('op')=='accept' and p.get('acceptance_profile')==PROFILE,
             'isolated_accept_profile')
    _require(p.get('stage_profile') in ('trial','restored'),'isolated_stage_profile')
    for key in ('expected_registry_hash','dependency_baseline_sha256','proxy_binding_sha256'):_hash(p.get(key))
    ref=p.get('resource_identity')
    _require(isinstance(ref,dict) and set(ref)=={'provider','project_id','provider_id','generation'}
             and ref.get('provider')=='hetzner' and isinstance(ref.get('project_id'),str)
             and IDENTIFIER.fullmatch(ref['project_id']) and ref.get('provider_id')==context['job']['target']['provider_id']
             and type(ref.get('generation')) is int and 0<ref['generation']<=context['generation'],
             'isolated_resource_scope')
    _hash(p.get('resource_config_hash'))
    if p['stage_profile']=='restored':
        _require(type(p.get('restore_epoch')) is int and p['restore_epoch']>0,'isolated_restore_epoch')
        _hash(p.get('restore_hash'))
    else:
        _require('restore_epoch' not in p and 'restore_hash' not in p,'isolated_trial_epoch')
    return p


class IsolatedAcceptanceVerifier:
    """A caller-supplied verifier; no key/config discovery or success defaults.

    ``replay`` must be the dedicated durable guest response ReplayStore. A
    verified response nonce is consumed only after semantic validation succeeds.
    The raw key is intentionally never retained in returned proof data.
    """
    def __init__(self,key,replay,*,now):
        _require(isinstance(key,bytes) and len(key)==32,'isolated_guest_key')
        _require(callable(getattr(replay,'claim',None)) and callable(now),'isolated_guest_verifier')
        self._key,self.replay,self.now=key,replay,now

    def verify(self,context,recipe):
        p=parameters(context);now=self.now()
        _require(type(now) is int and now>=0,'isolated_clock')
        _require(recipe.get('read_only') is True and recipe.get('ok') is True
                 and recipe.get('action')=='accept','isolated_recipe')
        c=recipe.get('checks',{})
        _require(c.get('accepted') is True and c.get('generation_verified') is True
                 and c.get('historical_outcome') is not True,'isolated_fresh_accept_required')
        evidence=recipe.get('isolated_evidence')
        _require(isinstance(evidence,dict) and set(evidence)=={'schema','ct','registry','guest'}
                 and evidence.get('schema')==SCHEMA,'isolated_evidence_required')
        scope={k:context[k] for k in SCOPE};target=context['job']['target']
        bound={'scope':scope,'target':target,'stage_profile':p['stage_profile'],
            'resource_identity':p['resource_identity'],'resource_config_hash':p['resource_config_hash'],
            'restore_epoch':p.get('restore_epoch'),'restore_hash':p.get('restore_hash'),
            'proxy_binding_sha256':p['proxy_binding_sha256']}
        ct=evidence['ct'];registry=evidence['registry'];guest=evidence['guest']
        _require(isinstance(ct,dict) and ct.get('schema')==CT_SCHEMA and
                 canonical_json({k:ct.get(k) for k in bound})==canonical_json(bound)
                 and ct.get('read_only') is True and ct.get('fresh_probe') is True
                 and ct.get('historical_outcome') is False and ct.get('accepted') is True
                 and ct.get('generation_verified') is True and ct.get('socks')=='127.0.0.1:10829',
                 'isolated_ct_fresh_proof_required')
        ct_at=ct.get('observed_at')
        _require(type(ct_at) is int and 0<=now-ct_at<=TTL,'isolated_ct_expired')
        _require(isinstance(registry,dict) and registry.get('schema')==REGISTRY_SCHEMA
                 and canonical_json(registry.get('scope'))==canonical_json(scope)
                 and canonical_json(registry.get('target'))==canonical_json(target)
                 and registry.get('read_only') is True and registry.get('coverage_complete') is True
                 and registry.get('registry_sha256')==p['expected_registry_hash']
                 and registry.get('before_state_sha256')==p['dependency_baseline_sha256']
                 and registry.get('after_state_sha256')==p['dependency_baseline_sha256']
                 and type(registry.get('resource_count')) is int and registry['resource_count']>0,
                 'isolated_dependency_digests_required')
        before,after=registry.get('before_observed_at'),registry.get('after_observed_at')
        _require(type(before) is int and type(after) is int and 0<=now-after<=TTL
                 and 0<=now-before<=TTL and before<=ct_at<=after,'isolated_registry_clock')
        _require(isinstance(guest,dict) and set(guest)=={'body_base64','headers','request_nonce'},
                 'isolated_signed_guest_required')
        encoded=guest['body_base64']
        _require(isinstance(encoded,str) and len(encoded)<=windows.MAX_BODY*2,'isolated_guest_body')
        try:raw=base64.b64decode(encoded,validate=True)
        except (ValueError,base64.binascii.Error):raise ProtocolError('isolated_guest_body') from None
        _require(len(raw)<=windows.MAX_BODY,'isolated_guest_body')
        body=decode_json(raw)
        _require(canonical_json(body)==raw,'isolated_guest_canonical')
        _require(body.get('schema')==GUEST_SCHEMA and body.get('path_profile')==PATH_PROFILE
                 and canonical_json({k:body.get(k) for k in bound})==canonical_json(bound)
                 and body.get('registry_sha256')==p['expected_registry_hash']
                 and body.get('dependency_baseline_sha256')==p['dependency_baseline_sha256']
                 and isinstance(guest['request_nonce'],str) and windows.NONCE.fullmatch(guest['request_nonce'])
                 and body.get('request_nonce')==guest['request_nonce']
                 and body.get('execution_origin')=='guest' and body.get('source_ip')==windows.SOURCE_IP
                 and body.get('egress_ip')==target['host']
                 and all(body.get(k) is True for k in ('ok','tunnel_ok','probe_ok')),
                 'isolated_guest_candidate_scope')
        guest_at=body.get('observed_at')
        _require(type(guest_at) is int and before<=guest_at<=after and 0<=now-guest_at<=TTL,
                 'isolated_guest_expired')
        headers=guest['headers']
        _require(isinstance(headers,dict) and set(headers)=={'X-VM-Timestamp','X-VM-Nonce','X-VM-Signature'}
                 and all(isinstance(v,str) for v in headers.values()),'isolated_guest_headers')
        # Reuse the exact installed guest HMAC framing. The distinct body schema
        # and signed path profile prevent a default-path response being relabeled.
        windows.verify(self._key,'RESPONSE',raw,headers,now,self.replay)
        observed=min(ct_at,guest_at,after)
        return dict(schema=SCHEMA,verified=True,acceptance_profile=PROFILE,path_profile=PATH_PROFILE,
            default_path_verified=False,global_cutover_performed=False,**bound,
            registry_sha256=p['expected_registry_hash'],dependency_baseline_sha256=p['dependency_baseline_sha256'],
            observed_at=observed,ct_observed_at=ct_at,guest_observed_at=guest_at,
            registry_observed_at=after,guest_body_sha256=hashlib.sha256(raw).hexdigest(),
            ct_proof_sha256=hashlib.sha256(canonical_json(ct)).hexdigest(),
            registry_proof_sha256=hashlib.sha256(canonical_json(registry)).hexdigest())
