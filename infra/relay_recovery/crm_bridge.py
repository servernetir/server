"""Finite private PHP promotion hooks; no global activation or CLI entry point.

``apply`` writes the existing private bundle immediately before the fixed PHP
request. Its acknowledgement is deliberately not completion. ``reconcile``
requires fresh actual PHP row/upstream readback and the original durable CRM
step, including after the original permit expires or the controller is disabled.
The original private bundle must remain until a new immutable claim has been
reconciled; its historical identity binding is used without renewing freshness.
Claim-absent legacy reconciliation remains supported. Only non-secret, strictly shaped receipts enter driver observations. No caller
selects an executable, path, operation, identity or success flag for PHP.
"""
from __future__ import annotations

import copy
import ipaddress
import re
import time

from . import core
from .agent import _bounded_process
from .production_hooks import CONTEXT_FIELDS, _hash, _identity, _ipv4, _private_bytes, load_registry
from .promotion_bundle import PromotionBundleWriter
from .provider_adapter import CredentialBridge, ProviderEffectError
from .protocol import decode_json


OBSERVATION = 'servernet.relay.driver.observation.v1'
PERMIT_FIELDS = {'schema', 'intent_id', 'parent_intent_id', 'kind', 'context_hash', 'created_at', 'expires_at'}
RECEIPT_FIELDS = {'schema', 'intent_id', 'incident_id', 'old_identity', 'new_identity', 'instance_id',
    'service_id', 'owner_id', 'ipv4', 'ipv6', 'location_code', 'config_hash', 'gate_hash', 'checkpoint',
    'exit_upstream_ids', 'upstream_bindings', 'committed_at'}
CONTINUATION_FIELDS = {'continuation_id', 'continuation_sha256'}
ROUTE_FIELDS = {'id', 'role', 'type', 'host', 'port', 'enabled', 'link_host', 'link_port'}
UUID = re.compile(r'\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\Z')
PARENT = re.compile(r'\A[a-f0-9]{40}\Z')
HOST = re.compile(r'\A(?=.{1,253}\Z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\Z')
LOCATION = re.compile(r'\A[a-z0-9][a-z0-9-]{1,95}\Z')


class CrmBridgeError(ProviderEffectError):
    pass


def require(value):
    if not value:
        raise CrmBridgeError('crm_bridge_scope_or_readback')


class PromotionBridge:
    def __init__(self, config, *, provider, runner=_bounded_process,
                 clock=lambda: int(time.time()), allow_test_paths=False):
        self.config = copy.deepcopy(config)
        self.clock, self.last_now = clock, None
        self.writer = PromotionBundleWriter(self.config, provider=provider, clock=self._now,
                                            allow_test_paths=allow_test_paths)
        self.pipe = CredentialBridge(self.config, runner=runner)
        self.allow_test_paths = allow_test_paths

    def _now(self):
        now = self.clock()
        require(type(now) is int and now >= 0 and (self.last_now is None or now >= self.last_now))
        self.last_now = now
        return now

    def _scope(self, permit, context, *, mutation=False):
        require(isinstance(context, dict) and set(context) == CONTEXT_FIELDS | {'gate_hash', 'checkpoint'}
            and context.get('schema') == 'servernet.relay.production-driver.v1'
            and isinstance(context.get('parent_intent_id'), str) and PARENT.fullmatch(context['parent_intent_id'])
            and isinstance(context.get('incident_id'), str) and 1 <= len(context['incident_id']) <= 160
            and isinstance(context.get('checkpoint'), str) and 1 <= len(context['checkpoint']) <= 256
            and type(context.get('restore_epoch')) is int and context['restore_epoch'] > 0)
        for field in ('config_hash', 'registry_hash', 'gate_hash', 'restore_hash'):
            _hash(context[field])
        old, new = _identity(context['source_identity']), _identity(context['identity'])
        require(old['project_id'] == new['project_id'] == context['project_id']
            and old['provider_id'] != new['provider_id'] and new['generation'] == old['generation'] + 1)
        require(isinstance(permit, dict) and set(permit) == PERMIT_FIELDS
            and permit['schema'] == 'servernet.relay.driver.permit.v1' and permit['kind'] == 'CRM_PROMOTION'
            and isinstance(permit['intent_id'], str) and UUID.fullmatch(permit['intent_id'])
            and permit['parent_intent_id'] == context['parent_intent_id']
            and permit['context_hash'] == core.digest(context)
            and type(permit['created_at']) is int and type(permit['expires_at']) is int
            and 0 < permit['expires_at'] - permit['created_at'] <= 30)
        now = self._now()
        require(permit['created_at'] <= now)
        if mutation:
            require(self.config.get('policy', {}).get('enabled') is True
                and self.config.get('production_driver', {}).get('enabled') is True
                and now < permit['expires_at'])
        catalog = load_registry(self.config, allow_test_paths=self.allow_test_paths)
        require(context['registry_hash'] == self.config['production_driver']['registry']['sha256']
            and all(context[k] == catalog[k] for k in ('owner_id', 'service_id', 'project_id')))
        panels = [r for r in catalog['resources'] if r['kind'] == 'panel_binding']
        require(len(panels) == 1 and set(panels[0]) == {'id', 'kind', 'instance_id', 'exit_upstream_ids'})
        panel = panels[0]
        require(type(panel['instance_id']) is int and panel['instance_id'] > 0
            and isinstance(panel['exit_upstream_ids'], list) and len(panel['exit_upstream_ids']) <= 32
            and all(type(i) is int and i > 0 for i in panel['exit_upstream_ids'])
            and len(set(panel['exit_upstream_ids'])) == len(panel['exit_upstream_ids'])
            and self.config.get('binding', {}).get('instance_id') == panel['instance_id']
            and self.config.get('exit_upstream_ids') == panel['exit_upstream_ids']
            and all(str(self.config['binding'].get(k)) == context[k] for k in ('owner_id', 'service_id')))
        with self.writer._db('production-driver.sqlite3') as db:
            row = db.execute('SELECT * FROM steps WHERE id=?', (permit['intent_id'],)).fetchone()
            require(row is not None and row['kind'] == 'CRM_PROMOTION' and row['parent'] == context['parent_intent_id']
                and row['phase'] in {'DISPATCHED', 'VERIFIED'}
                and core.canonical(decode_json(row['body'].encode())) == core.canonical({'permit': permit, 'context': context}))
            has_claim_table = db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='hook_dispatches'").fetchone()
            claim = db.execute('SELECT body FROM hook_dispatches WHERE id=?', (permit['intent_id'],)).fetchone() if has_claim_table else None
            require(claim is None or claim['body'] == row['body'])
            if mutation:
                require(row['phase'] == 'DISPATCHED' and claim is not None)
        return panel, claim is not None

    def _fresh_result(self, result, *, read_only, started):
        require(isinstance(result, dict) and set(result) == {'ok', 'outcome', 'receipt', 'changed',
            'read_only', 'observed_at', 'received_at', 'actual_upstreams_verified'}
            and result['ok'] is True and result['outcome'] == 'promoted'
            and type(result['changed']) is bool and result['read_only'] is read_only
            and (not read_only or result['changed'] is False)
            and result['actual_upstreams_verified'] is True
            and type(result['observed_at']) is int and type(result['received_at']) is int)
        now = self._now()
        require(0 <= now - result['observed_at'] <= 30 and 0 <= now - result['received_at'] <= 30
            and started <= result['observed_at'] <= result['received_at'] <= now
            and result['received_at'] - result['observed_at'] <= 30)
        return result['receipt']

    @staticmethod
    def _route_host(value, ipv4, *, empty=False):
        require(isinstance(value, str))
        if empty and value == '':
            return
        try:
            address = ipaddress.ip_address(value)
        except ValueError:
            require(HOST.fullmatch(value))
        else:
            require(str(address) == value == ipv4)

    def _receipt(self, receipt, permit, context, panel):
        require(isinstance(receipt, dict) and set(receipt) in (RECEIPT_FIELDS, RECEIPT_FIELDS | CONTINUATION_FIELDS)
            and receipt.get('schema') == 'servernet.relay.crm-promotion.v1'
            and receipt.get('intent_id') == context['parent_intent_id']
            and _identity(receipt.get('old_identity')) == context['source_identity']
            and _identity(receipt.get('new_identity')) == context['identity']
            and all(receipt.get(k) == context[k] for k in ('incident_id', 'config_hash', 'gate_hash', 'checkpoint'))
            and type(receipt.get('committed_at')) is int and receipt['committed_at'] <= self._now()
            and type(receipt.get('instance_id')) is int and receipt['instance_id'] == panel['instance_id']
            and isinstance(receipt.get('exit_upstream_ids'), list)
            and all(type(i) is int for i in receipt['exit_upstream_ids'])
            and receipt['exit_upstream_ids'] == panel['exit_upstream_ids'])
        with self.writer._db('controller.sqlite3') as db:
            row = db.execute("SELECT body FROM actions WHERE id=? AND kind='PROMOTE'",
                             (context['parent_intent_id'],)).fetchone()
        require(row is not None)
        action = decode_json(row['body'].encode())
        require(action.get('id') == context['parent_intent_id'] and action.get('kind') == 'PROMOTE'
            and action.get('incident_id') == context['incident_id']
            and type(action.get('generation')) is int and action['generation'] == context['identity']['generation']
            and isinstance(action.get('payload'), dict)
            and _identity(action['payload'].get('identity')) == context['identity']
            and _identity(action['payload'].get('old_identity')) == context['source_identity']
            and all(action['payload'].get(k) == context[k] for k in ('gate_hash', 'checkpoint'))
            and type(action.get('created_at')) is int and type(action.get('expires_at')) is int
            and action['created_at'] <= receipt['committed_at'] < action['expires_at']
            and permit['created_at'] >= action['created_at'] and permit['expires_at'] <= action['expires_at'])
        for key in ('owner_id', 'service_id'):
            require(type(receipt.get(key)) is int and str(receipt[key]) == context[key])
        _ipv4(receipt['ipv4'])
        if receipt['ipv6'] is not None:
            address = ipaddress.IPv6Address(receipt['ipv6'])
            require(address.is_global and str(address) == receipt['ipv6'])
        require(isinstance(receipt['location_code'], str) and LOCATION.fullmatch(receipt['location_code']))
        if CONTINUATION_FIELDS <= receipt.keys():
            require(receipt['continuation_id'] == permit['intent_id']
                and receipt['continuation_sha256'] == core.digest(permit)
                and permit['created_at'] <= receipt['committed_at'] < permit['expires_at'])
        routes = receipt.get('upstream_bindings')
        require(isinstance(routes, list) and len(routes) == len(panel['exit_upstream_ids']))
        for route, expected in zip(routes, panel['exit_upstream_ids']):
            require(isinstance(route, dict) and set(route) == ROUTE_FIELDS and type(route.get('id')) is int
                and route['id'] == expected and route.get('role') in {'relay', 'exit'}
                and route.get('type') in {'ssh', 'socks', 'wireguard', 'vless', 'trojan'}
                and type(route.get('enabled')) is bool)
            link = route['type'] in {'vless', 'trojan'}
            self._route_host(route['host'], receipt['ipv4'], empty=link)
            if link:
                self._route_host(route['link_host'], receipt['ipv4'])
                require(type(route['link_port']) is int and 1 <= route['link_port'] <= 65535
                    and (route['port'] is None or type(route['port']) is int))
            else:
                require(type(route['port']) is int and 1 <= route['port'] <= 65535
                    and route['link_host'] is None and route['link_port'] is None)
        return copy.deepcopy(receipt)

    @staticmethod
    def _bind_bundle(receipt, bundle, context):
        require(isinstance(bundle, dict) and bundle.get('all_checks_passed') is True
            and bundle.get('intent_id') == context['parent_intent_id']
            and _identity(bundle.get('old_identity')) == context['source_identity']
            and _identity(bundle.get('new_identity')) == context['identity']
            and all(bundle.get(k) == context[k] for k in ('incident_id', 'config_hash', 'gate_hash', 'checkpoint'))
            and all(receipt.get(k) == bundle.get(k) for k in ('ipv4', 'ipv6', 'location_code')))

    def _historical_bundle(self, receipt, context, *, claimed):
        path = self.writer.root/('promotion-'+context['parent_intent_id']+'.json')
        if not path.exists() and not path.is_symlink() and not claimed:
            return  # The legacy PHP protocol did not promise retained bundles.
        raw = _private_bytes(path, limit=65536, allow_test_paths=self.allow_test_paths)
        bundle = decode_json(raw)
        self._bind_bundle(receipt, bundle, context)
        # No freshness/deadline is granted by this historical address binding.

    def apply(self, permit, context, authorize):
        try:
            permit, context = copy.deepcopy(permit), copy.deepcopy(context)
            panel, _ = self._scope(permit, context, mutation=True)
            bundle = self.writer(permit, context, authorize)
            def guard():
                self._scope(permit, context, mutation=True)
                now = self._now()
                require(callable(authorize) and authorize(permit) is True
                    and now < bundle['proof_expires_at'])
                return min(bundle['proof_expires_at'], permit['expires_at']) - self._now()
            started = self._now()
            result = self.pipe._exchange(dict(op='promote', intent_id=context['parent_intent_id'], continuation=permit),
                                         guard=guard)
            receipt = self._receipt(self._fresh_result(result, read_only=False, started=started), permit, context, panel)
            self._bind_bundle(receipt, bundle, context)
            guard()  # A late acknowledgement remains unknown, even after commit.
            return {'state': 'dispatched', 'intent_id': permit['intent_id']}
        except Exception:
            raise CrmBridgeError('crm_bridge_failed') from None

    def reconcile(self, permit, context):
        try:
            permit, context = copy.deepcopy(permit), copy.deepcopy(context)
            panel, claimed = self._scope(permit, context)
            started = self._now()
            result = self.pipe._exchange(dict(op='reconcile_promotion', intent_id=context['parent_intent_id']))
            self._scope(permit, context)
            if (isinstance(result, dict) and set(result) == {'ok', 'outcome', 'changed'}
                    and result == {'ok': True, 'outcome': 'not_promoted', 'changed': False}):
                return {'state': 'pending', 'intent_id': permit['intent_id']}
            receipt = self._receipt(self._fresh_result(result, read_only=True, started=started), permit, context, panel)
            self._historical_bundle(receipt, context, claimed=claimed)
            proof = dict(schema=OBSERVATION, operation='crm_promotion', verified=True, read_only=True,
                context_hash=core.digest(context), intent_id=permit['intent_id'], state='complete', panel_committed=True,
                observed_at=result['observed_at'], received_at=result['received_at'],
                resource_proofs=[dict(id=panel['id'], verified=True, verifier='private-php-actual-rows-v1',
                    proof_sha256=core.digest(receipt), proof=receipt)])
            return proof
        except Exception:
            raise CrmBridgeError('crm_bridge_failed') from None
