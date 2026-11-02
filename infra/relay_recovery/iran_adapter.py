#!/usr/bin/env python3
"""Bounded, root-owned Iran adapter for agent.py. No cloud lifecycle calls.

The fixed executable wrapper installed as agent.config.adapter invokes::
  /usr/bin/python3.12 /usr/local/lib/servernet-relay-recovery/iran_adapter.py \
    --config /etc/servernet/relay-recovery-adapter.json ACTION

JSON context arrives on stdin from the root-owned agent AFTER HMAC verification.
The adapter must not be exposed as a network endpoint or arbitrary sudo command.
The caller's context contract is agent.py's context, unchanged. Results use
servernet.relay.adapter.v1 and bind incident_id/generation/revision/config_hash.

Private local config schema servernet.relay.iran-config.v1::
  {"schema":"servernet.relay.iran-config.v1", "agent_id":"pve-ir",
   "manifest_dir":"/etc/servernet/relay-recovery/bundles",
   "candidate_ports":[22,443], "vm109_source_ip":"10.10.10.50",
   "probes":{"host":SPEC,"ct113":SPEC,"vm109":SPEC},
   "consumer_probes":{"dns":SPEC,"storage":SPEC},
   "recipes":{"stage-v1":SPEC,"apply-v1":SPEC,"reconcile-v1":SPEC}}

SPEC has action, argv (fixed strings, argv[0] absolute), executable_sha256,
inputs:[{path:absolute,sha256:HEX64}], timeout (1..8 for probes,1..20 recipes).
Every absolute argv operand other than argv[0] MUST be an input hash pin. Python
interpreter calls therefore pin both the interpreter and the script. Shells and
interpreter -c/-m execution are refused. The network never supplies argv, paths,
executable names, source text, shell expressions or environment variables.
Optional consumer_probes are actual read-only acceptance probes, keyed by an
installed consumer name. They use action=consumer name and the same probe JSON
contract with checks.consumer=name and checks.probe_ok=true. An absent probe
produces false for that requested consumer, never a route-based DNS/storage
assumption. proxmox/vm109/vpn are fixed derived checks and cannot be overridden.

An installed root-owned 0600 BUNDLE file is named <context.config_hash>.json and
its bytes must hash to config_hash. Bundle schema servernet.relay.iran-bundle.v1:
  {schema,agent_id,candidate_ports:[22,443],
   recipes:{stage:"stage-v1",apply:"apply-v1",rollback:"rollback-v1",
            accept:"accept-v1",reconcile:"reconcile-v1"}}
The bundle authorizes reviewed local recipes and a target PROFILE. It must not
pin a not-yet-known future cloud IP. The exact public literal IP, allowed port,
positive provider ID, incident and generation instead come from the authenticated
agent context. Every child result must bind that exact target and scope.

Local probe stdout is bounded JSON servernet.relay.iran-probe.v1, probe_kind
host/ct113/vm109, read_only:true, ok:boolean, target and scope, checks. Host checks:
host_healthy/direct_control/internet_healthy booleans. CT113: execution_origin=
ct113, tunnel_ok/probe_ok booleans, egress_ip. VM109: execution_origin=guest,
tunnel_ok/probe_ok, egress_ip, source_ip. Egress must equal the requested target;
the guest source must equal locally configured vm109_source_ip. Route-get or a
CT113 curl must NOT be implemented as the VM109 guest probe. Missing real guest
execution means unknown/failed, never a synthetic success.
The relay path and individual guest acceptance are separate: CT113 proves
tunnel_ok/path_probe_ok, while an unavailable VM109 keeps its critical consumer
false. An offline guest must not trigger replacement of a working relay path.
The accept action still requires every requested critical consumer to pass.

Recipe stdout schema servernet.relay.iran-recipe.v1 includes action, read_only,
ok, checks, target, scope. stage/apply/rollback need staged/applied/rolled_back=True.
accept is read-only and needs accepted=True plus real probes. reconcile ALWAYS
uses only the reviewed reconcile recipe and requires read_only:true. It may
report reconciled=True only after read-only observation proves the original
job's outcome; it never resends that job. AgentJournal owns intent/outbox writes.

No default probe claims real tunnel/VM109 health. Production recipes and probe
argv must be installed, pinned and tested against actual routing before enable.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
import hashlib
import hmac
import ipaddress
import os
from pathlib import Path
import stat
import sys
import time
from typing import Callable

try:
    from . import agent
    from .protocol import (HEX64, IDENTIFIER, MAX_BODY, ProtocolError,
                           canonical_json, decode_json, private_file)
except ImportError:
    import agent
    from protocol import (HEX64, IDENTIFIER, MAX_BODY, ProtocolError,
                          canonical_json, decode_json, private_file)


CONFIG_SCHEMA = 'servernet.relay.iran-config.v1'
BUNDLE_SCHEMA = 'servernet.relay.iran-bundle.v1'
PROBE_SCHEMA = 'servernet.relay.iran-probe.v1'
RECIPE_SCHEMA = 'servernet.relay.iran-recipe.v1'
SCOPE = ('incident_id', 'generation', 'revision', 'config_hash')
ACTIONS = frozenset({'probe', 'stage', 'apply', 'accept', 'rollback', 'reconcile'})
MUTATIONS = frozenset({'stage', 'apply', 'rollback'})
SHELLS = frozenset({'sh', 'bash', 'dash', 'zsh', 'csh', 'tcsh', 'fish', 'ksh'})
INT_PARAMS = frozenset({'expected_current_generation', 'expected_generation', 'expected_revision',
                        'fencing_token', 'expires_at', 'restore_epoch'})
HASH_PARAMS = frozenset({'expected_config_hash', 'bundle_hash', 'restore_hash'})
ID_PARAMS = frozenset({'checkpoint', 'backup_checkpoint', 'consumer',
                      'expected_provider_id', 'expected_active_provider_id'})
RESOURCE_PARAMS = frozenset({'resource_identity', 'resource_config_hash', 'critical_consumers'})
DERIVED_CONSUMERS = frozenset({'proxmox', 'ct113', 'vm109', 'vpn'})


def _hash(value: object, code: str) -> str:
    if not isinstance(value, str) or not HEX64.fullmatch(value):
        raise ProtocolError(code)
    return value


def _integer(value: object, low: int, high: int, code: str) -> int:
    if type(value) is not int or not low <= value <= high:
        raise ProtocolError(code)
    return value


def _target(value: object, ports: list[int]) -> dict:
    if not isinstance(value, dict) or set(value) != {'host', 'port', 'provider_id'}:
        raise ProtocolError('adapter_target')
    try:
        addr = ipaddress.ip_address(value['host'])
    except (TypeError, ValueError):
        raise ProtocolError('adapter_target_ip') from None
    if not addr.is_global or str(addr) != value['host']:
        raise ProtocolError('adapter_target_ip')
    if _integer(value['port'], 1, 65535, 'adapter_target_port') not in ports:
        raise ProtocolError('adapter_target_port')
    ref = value['provider_id']
    if not isinstance(ref, str) or not ref.isascii() or not ref.isdigit() or not 1 <= len(ref) <= 20 or int(ref) < 1:
        raise ProtocolError('adapter_target_provider')
    return dict(value)


def _secure_dir(path: object, root_owned: bool) -> Path:
    if not isinstance(path, str) or not Path(path).is_absolute():
        raise ProtocolError('adapter_manifest_dir')
    p = Path(path)
    for item in (p, *p.parents):
        try:
            st = item.lstat()
        except OSError:
            raise ProtocolError('adapter_manifest_dir') from None
        if not stat.S_ISDIR(st.st_mode) or item.is_symlink():
            raise ProtocolError('adapter_manifest_dir')
        if root_owned and (st.st_uid != 0 or stat.S_IMODE(st.st_mode) & 0o022):
            raise ProtocolError('adapter_manifest_permissions')
    return p


def _read_file(path: str | Path, root_owned: bool, *, executable=False) -> tuple[Path, bytes]:
    p = private_file(path, root_owned=root_owned, executable=executable)
    if p.stat().st_size > 16 * 1024 * 1024:
        raise ProtocolError('adapter_file_size')
    # O_NOFOLLOW also protects the final component at the actual read. Trusted,
    # non-writable parents prevent replacing pinned code between check and exec.
    fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as source:
        data = source.read(16 * 1024 * 1024 + 1)
    if len(data) > 16 * 1024 * 1024:
        raise ProtocolError('adapter_file_size')
    return p, data


def _spec(value: object, action: str, root_owned: bool, *, probe=False) -> dict:
    if not isinstance(value, dict) or set(value) - {'action', 'argv', 'executable_sha256', 'inputs', 'timeout'}:
        raise ProtocolError('adapter_spec')
    if value.get('action') != action:
        raise ProtocolError('adapter_spec_action')
    argv = value.get('argv')
    if (not isinstance(argv, list) or not 1 <= len(argv) <= 24
            or any(not isinstance(v, str) or not v or len(v) > 1024 or '\x00' in v for v in argv)
            or not Path(argv[0]).is_absolute()):
        raise ProtocolError('adapter_argv')
    if Path(argv[0]).name in SHELLS or any(v in ('-c', '-m', '--command') for v in argv[1:]):
        raise ProtocolError('adapter_shell_forbidden')
    read_only = probe or action in {'accept', 'reconcile'}
    timeout = _integer(value.get('timeout', 6 if read_only else 20), 1, 8 if read_only else 20, 'adapter_timeout_policy')
    pins = value.get('inputs', [])
    if not isinstance(pins, list) or len(pins) > 16:
        raise ProtocolError('adapter_inputs')
    paths = set()
    for pin in pins:
        if not isinstance(pin, dict) or set(pin) != {'path', 'sha256'}:
            raise ProtocolError('adapter_input_pin')
        expected = _hash(pin['sha256'], 'adapter_input_hash')
        p, content = _read_file(pin['path'], root_owned)
        if str(p) in paths or not hmac.compare_digest(hashlib.sha256(content).hexdigest(), expected):
            raise ProtocolError('adapter_input_hash')
        paths.add(str(p))
    for operand in argv[1:]:
        if operand.startswith('/') and operand not in paths:
            raise ProtocolError('adapter_unpinned_operand')
    p, content = _read_file(argv[0], root_owned, executable=True)
    if not hmac.compare_digest(hashlib.sha256(content).hexdigest(),
                               _hash(value.get('executable_sha256'), 'adapter_executable_hash')):
        raise ProtocolError('adapter_executable_hash')
    return dict(value, argv=list(argv), timeout=timeout)


def load_config(path: str | Path, *, root_owned: bool = True) -> dict:
    _, data = _read_file(path, root_owned)
    if len(data) > MAX_BODY:
        raise ProtocolError('adapter_config_size')
    cfg = decode_json(data)
    if cfg.get('schema') != CONFIG_SCHEMA or not isinstance(cfg.get('agent_id'), str) or not IDENTIFIER.fullmatch(cfg['agent_id']):
        raise ProtocolError('adapter_config')
    ports = cfg.get('candidate_ports', [22, 443])
    if not isinstance(ports, list) or not 1 <= len(ports) <= 8 or len(set(ports)) != len(ports):
        raise ProtocolError('adapter_ports')
    for p in ports:
        _integer(p, 1, 65535, 'adapter_port')
    try:
        source = ipaddress.ip_address(cfg.get('vm109_source_ip', ''))
    except (TypeError, ValueError):
        raise ProtocolError('adapter_guest_source') from None
    if source.is_global or source.version != 4:
        raise ProtocolError('adapter_guest_source')
    cfg['manifest_dir'] = str(_secure_dir(cfg.get('manifest_dir'), root_owned))
    probes = cfg.get('probes')
    if not isinstance(probes, dict) or set(probes) != {'host', 'ct113', 'vm109'}:
        raise ProtocolError('adapter_probe_config')
    cfg['probes'] = {name: _spec(probes[name], name, root_owned, probe=True) for name in probes}
    consumer_probes = cfg.get('consumer_probes', {})
    if not isinstance(consumer_probes, dict) or len(consumer_probes) > 5:
        raise ProtocolError('adapter_consumer_config')
    for name, spec in consumer_probes.items():
        if (not isinstance(name, str) or not IDENTIFIER.fullmatch(name)
                or name in DERIVED_CONSUMERS or name in probes):
            raise ProtocolError('adapter_consumer_name')
        _spec(spec, name, root_owned, probe=True)
    cfg['consumer_probes'] = consumer_probes
    recipes = cfg.get('recipes', {})
    if not isinstance(recipes, dict) or len(recipes) > 24:
        raise ProtocolError('adapter_recipe_config')
    for name, recipe in recipes.items():
        if not isinstance(name, str) or not IDENTIFIER.fullmatch(name):
            raise ProtocolError('adapter_recipe_id')
        action = recipe.get('action') if isinstance(recipe, dict) else None
        if action not in MUTATIONS | {'accept', 'reconcile'}:
            raise ProtocolError('adapter_recipe_action')
        _spec(recipe, action, root_owned)
    cfg['candidate_ports'] = ports
    cfg['root_owned'] = root_owned
    return cfg


def validate_context(context: object, config: dict) -> dict:
    if not isinstance(context, dict) or len(canonical_json(context)) > 16384:
        raise ProtocolError('adapter_context')
    if set(context) - {'schema', 'agent_id', 'incident_id', 'generation', 'revision', 'config_hash', 'jobs_hash', 'job'}:
        raise ProtocolError('adapter_context_fields')
    if context.get('schema') != agent.SCHEMA or context.get('agent_id') != config['agent_id']:
        raise ProtocolError('adapter_context_identity')
    if not isinstance(context.get('incident_id'), str) or not IDENTIFIER.fullmatch(context['incident_id']):
        raise ProtocolError('adapter_context_incident')
    for k in ('generation', 'revision'):
        _integer(context.get(k), 1, 2**53 - 1, 'adapter_context_' + k)
    _hash(context.get('config_hash'), 'adapter_context_hash')
    _hash(context.get('jobs_hash'), 'adapter_jobs_hash')
    agent.validate_job(context.get('job'), config)
    _target(context['job'].get('target'), config['candidate_ports'])
    params = context['job'].get('params', {})
    if set(params) - (INT_PARAMS | HASH_PARAMS | ID_PARAMS | RESOURCE_PARAMS):
        raise ProtocolError('adapter_params')
    for key, value in params.items():
        if key in INT_PARAMS:
            _integer(value, 1, 2**53 - 1, 'adapter_param_integer')
        elif key in HASH_PARAMS:
            _hash(value, 'adapter_param_hash')
        elif key in ID_PARAMS and (not isinstance(value, str) or not IDENTIFIER.fullmatch(value)):
            raise ProtocolError('adapter_param_identifier')
    if ('restore_epoch' in params) != ('restore_hash' in params):
        raise ProtocolError('adapter_restore_scope')
    if set(params) & {'resource_identity', 'resource_config_hash'}:
        ref = params.get('resource_identity')
        if (not isinstance(ref, dict) or set(ref) != {'provider', 'project_id', 'provider_id', 'generation'}
                or ref.get('provider') != 'hetzner'
                or not isinstance(ref.get('project_id'), str) or not IDENTIFIER.fullmatch(ref['project_id'])
                or ref.get('provider_id') != context['job']['target']['provider_id']):
            raise ProtocolError('adapter_resource_identity')
        _integer(ref.get('generation'), 1, context['generation'], 'adapter_resource_generation')
        _hash(params.get('resource_config_hash'), 'adapter_resource_hash')
    if 'critical_consumers' in params:
        names = params['critical_consumers']
        if (not isinstance(names, list) or not 1 <= len(names) <= 16
                or any(not isinstance(v, str) or not IDENTIFIER.fullmatch(v) for v in names)
                or len(set(names)) != len(names)):
            raise ProtocolError('adapter_critical_consumers')
    return context


def _bound(result: dict, context: dict) -> bool:
    return (all(type(result.get(k)) is type(context[k]) and result.get(k) == context[k] for k in SCOPE)
            and result.get('target') == context['job']['target'])


class IranAdapter:
    def __init__(self, config: dict, *, runner: Callable = agent._bounded_process,
                 tcp: Callable = agent.tcp_probe, icmp: Callable = agent.icmp_probe,
                 now: Callable = time.time):
        self.config = config
        self.root_owned = config.get('root_owned', True)
        self.runner, self.tcp, self.icmp, self.now = runner, tcp, icmp, now

    def _bundle(self, context: dict) -> dict:
        _, content = _read_file(Path(self.config['manifest_dir']) / (context['config_hash'] + '.json'), self.root_owned)
        if len(content) > MAX_BODY or not hmac.compare_digest(hashlib.sha256(content).hexdigest(), context['config_hash']):
            raise ProtocolError('adapter_bundle_hash')
        bundle = decode_json(content)
        if bundle.get('schema') != BUNDLE_SCHEMA or bundle.get('agent_id') != self.config['agent_id']:
            raise ProtocolError('adapter_bundle_identity')
        ports = bundle.get('candidate_ports')
        if not isinstance(ports, list) or not ports or any(type(v) is not int or v not in self.config['candidate_ports'] for v in ports):
            raise ProtocolError('adapter_bundle_profile')
        if context['job']['target']['port'] not in ports:
            raise ProtocolError('adapter_bundle_profile')
        recipes = bundle.get('recipes')
        if not isinstance(recipes, dict) or set(recipes) - (MUTATIONS | {'accept', 'reconcile'}):
            raise ProtocolError('adapter_bundle_recipes')
        return bundle

    def _run(self, spec: dict, action: str, context: dict, *, probe=False) -> dict:
        checked = _spec(spec, action, self.root_owned, probe=probe)
        try:
            status, output = self.runner(checked['argv'], canonical_json(context), checked['timeout'])
        except (OSError, TimeoutError):
            raise ProtocolError('adapter_child_unavailable') from None
        if not isinstance(output, bytes) or len(output) > 8192:
            raise ProtocolError('adapter_child_output')
        result = decode_json(output)
        schema = PROBE_SCHEMA if probe else RECIPE_SCHEMA
        kind = 'probe_kind' if probe else 'action'
        if (result.get('schema') != schema or result.get(kind) != action
                or type(result.get('ok')) is not bool or not isinstance(result.get('checks'), dict)
                or not _bound(result, context) or len(canonical_json(result['checks'])) > 2048):
            raise ProtocolError('adapter_child_receipt')
        if (probe or action in {'accept', 'reconcile'}) and result.get('read_only') is not True:
            raise ProtocolError('adapter_read_only_required')
        if action in MUTATIONS and result.get('read_only') is not False:
            raise ProtocolError('adapter_mutation_receipt')
        if type(status) is not int or (status != 0 and result['ok']):
            raise ProtocolError('adapter_child_exit')
        return result

    @staticmethod
    def _bool_checks(result: dict) -> dict:
        allowed = ('host_healthy', 'direct_control', 'internet_healthy', 'tunnel_ok', 'probe_ok',
                   'staged', 'applied', 'rolled_back', 'accepted', 'generation_verified')
        return {k: result['checks'][k] for k in allowed if type(result['checks'].get(k)) is bool}

    def _probe(self, context: dict) -> tuple[bool, dict]:
        target = context['job']['target']
        checks = dict(candidate_tcp=bool(self.tcp(target['host'], target['port'])),
                      candidate_icmp=self.icmp(target['host']))
        if checks['candidate_icmp'] is not None and type(checks['candidate_icmp']) is not bool:
            checks['candidate_icmp'] = None
        probes = {**self.config['probes'], **self.config.get('consumer_probes', {})}
        def probe(name):
            try:
                return name, self._run(probes[name], name, context, probe=True)
            except ProtocolError:
                return name, None
        with ThreadPoolExecutor(max_workers=len(probes)) as pool:
            results = dict(pool.map(probe, probes))
        host = results['host']
        host_ok = bool(host and host['ok'] and all(host['checks'].get(k) is True
                              for k in ('host_healthy', 'direct_control', 'internet_healthy')))
        for k in ('host_healthy', 'direct_control', 'internet_healthy'):
            checks[k] = bool(host and host['ok'] and host['checks'].get(k) is True)
        consumers = {'proxmox': host_ok}
        for name, origin in (('ct113', 'ct113'), ('vm109', 'guest')):
            result = results[name]
            c = result['checks'] if result else {}
            good = bool(result and result['ok'] and c.get('execution_origin') == origin
                        and c.get('tunnel_ok') is True and c.get('probe_ok') is True
                        and c.get('egress_ip') == target['host'])
            if name == 'vm109':
                good = good and c.get('source_ip') == self.config['vm109_source_ip']
            consumers[name] = good
        checks['consumers'] = consumers
        # A guest fault or absent QGA is not evidence of a relay-path outage.
        # Keep it in critical acceptance so it blocks cutover/retirement while
        # the real host/CT path remains independently healthy.
        checks['tunnel_ok'] = consumers['ct113']
        checks['path_probe_ok'] = host_ok and checks['tunnel_ok']
        checks['probe_ok'] = checks['path_probe_ok']
        acceptance = {**consumers, 'vpn': checks['tunnel_ok']}
        for name in self.config.get('consumer_probes', {}):
            observed = results.get(name)
            c = observed['checks'] if observed else {}
            acceptance[name] = bool(observed and observed['ok']
                                    and c.get('consumer') == name and c.get('probe_ok') is True)
        requested = context['job'].get('params', {}).get('critical_consumers', list(acceptance))
        checks['critical_consumers'] = {name: acceptance.get(name) is True for name in requested}
        return bool(checks['candidate_tcp'] and checks['probe_ok']), checks

    def run(self, action: str, context: dict) -> dict:
        if action not in ACTIONS:
            raise ProtocolError('adapter_action')
        context = validate_context(context, self.config)
        if action != 'reconcile' and action != context['job']['op']:
            raise ProtocolError('adapter_action_mismatch')
        if action == 'reconcile' and context['job']['op'] not in MUTATIONS:
            raise ProtocolError('adapter_reconcile_original')
        bundle = self._bundle(context)
        result = dict(schema=agent.ADAPTER_SCHEMA, ok=False, checks={},
                      **{k: context[k] for k in SCOPE})
        if action == 'probe':
            result['ok'], result['checks'] = self._probe(context)
            return result
        recipe_id = bundle['recipes'].get(action)
        if not isinstance(recipe_id, str) or recipe_id not in self.config['recipes']:
            raise ProtocolError('adapter_recipe_not_installed')
        child = self._run(self.config['recipes'][recipe_id], action, context)
        checks = self._bool_checks(child)
        if action == 'reconcile':
            # Never dispatch context.job.op here. Only a read-only recipe is
            # allowed to establish that original mutation's observed outcome.
            result.update(ok=child['ok'] and child.get('reconciled') is True,
                          reconciled=child.get('reconciled') is True,
                          checks={**checks, 'read_only': True})
            return result
        flag = {'stage': 'staged', 'apply': 'applied', 'rollback': 'rolled_back', 'accept': 'accepted'}[action]
        result['ok'] = child['ok'] and checks.get(flag) is True and checks.get('generation_verified') is True
        result['checks'] = checks
        if action == 'accept':
            probe_ok, probe_checks = self._probe(context)
            critical = probe_checks['critical_consumers']
            result['ok'] = (result['ok'] and probe_ok and bool(critical)
                            and all(value is True for value in critical.values()))
            result['checks'] = {**checks, **probe_checks}
        return result


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('action', choices=sorted(ACTIONS))
    args = parser.parse_args(argv)
    context = {}
    try:
        if os.geteuid() != 0:
            raise ProtocolError('adapter_root_required')
        body = sys.stdin.buffer.read(16385)
        if len(body) > 16384:
            raise ProtocolError('adapter_context_size')
        context = decode_json(body)
        config = load_config(args.config)
        result = IranAdapter(config).run(args.action, context)
        sys.stdout.buffer.write(canonical_json(result))
        return 0 if result['ok'] else 1
    except (ProtocolError, OSError) as error:
        code = str(error) if isinstance(error, ProtocolError) else 'adapter_local_failure'
        result = dict(schema=agent.ADAPTER_SCHEMA, ok=False, checks={'adapter_safe': False}, code=code)
        for k in SCOPE:
            if k in context:
                result[k] = context[k]
        sys.stdout.buffer.write(canonical_json(result))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
