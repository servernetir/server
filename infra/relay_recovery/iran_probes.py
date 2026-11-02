#!/usr/bin/env python3
"""Fixed read-only production probes for PVE IR, CT113 and Windows VM109.

Install this file with agent.py/protocol.py/iran_adapter.py in a root-owned
directory. Invoke it only through an Iran-adapter pinned local SPEC, e.g.::

  /usr/bin/python3.13 /usr/local/lib/servernet-relay-recovery/iran_probes.py \
    --kind ct113 --agent-id pve-ir

The interpreter, script and imported bundle must be locally reviewed and pinned
by the adapter deployment. No executable, argv, URL, IP, unit or path comes from
the JSON request. The requested public relay IP is used only for comparison.
All network probes target fixed reviewed endpoints. All PVE commands are reads;
this helper never starts a service, enables QGA or changes a guest/route.

CT113 acceptance requires the two actual tunnel units, an UP tun119, both the
working default and blackhole fallback in table119, a loopback10819 SSH socket
whose process targets the exact requested relay IPv4, and an HTTPS request
through that SOCKS socket whose real public egress equals the requested relay.
The VM109 source route is diagnostic ONLY. It never proves VM109 guest health.

VM109 prefers the installed authenticated read-only Windows HTTP probe when its
private local config exists. The default path is fixed below. --windows-config
is a local install-time override; its absolute argv operand must be an Iran
adapter input hash pin. Include windows_probe_client.py, protocol.py and all
imported bundle modules in the adapter's input pins. The private default config
and its key can rotate under root ownership without changing the target bundle.
A configured client failure never falls back to a different guest transport.
Only an absent default config permits the QGA fallback. No config path, endpoint,
executable or key is selected from the network context.

Without an installed HTTP config, VM109 is false if QGA is absent. When QGA is
available, a fixed PowerShell read checks
the source address and binds curl.exe to 10.10.10.50 inside the actual Windows
guest. Its real public egress must equal the requested relay. The subprocess
budget is below the Iran adapter's eight-second read-only deadline. No raw
command output, process command line or local route details leave this helper.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
import ipaddress
import json
import math
import os
from pathlib import Path
import re
import shlex
import sys
from typing import Callable

try:
    from . import agent, iran_adapter
    from .protocol import MAX_BODY, ProtocolError, canonical_json, decode_json, private_file
except ImportError:
    import agent
    import iran_adapter
    from protocol import MAX_BODY, ProtocolError, canonical_json, decode_json, private_file


PCT = '/usr/sbin/pct'
QM = '/usr/sbin/qm'
WHM_IP = '65.109.176.14'
WHM_PORT = 443
HOST_INTERFACE = 'vmbr0'
SOURCE_IP = '10.10.10.50'
CT_UNITS = ('servernet-country@119.service', 'vm119-fixed-relay.service')
SOCKS_ADDRESS = '127.0.0.1:10819'
EGRESS_URL = 'https://api.ipify.org'
WINDOWS_CONFIG = '/etc/servernet/relay-recovery/windows-probe.json'
SCOPE = iran_adapter.SCOPE
PID = re.compile(r'\bpid=([1-9][0-9]{0,9})\b')

# Literal reviewed code, never built from JSON. Binding curl inside the guest
# establishes a real source/egress observation; a host route-get cannot do so.
GUEST_SCRIPT = (
    "$ErrorActionPreference='Stop';"
    "$a=Get-NetIPAddress -IPAddress '10.10.10.50' -AddressFamily IPv4 -ErrorAction Stop;"
    "if (@($a).Count -ne 1) {exit 2};"
    "$v=(& 'C:\\Windows\\System32\\curl.exe' -q --noproxy '*' --interface '10.10.10.50' -fsS --max-time 4 'https://api.ipify.org');"
    "if ($LASTEXITCODE -ne 0) {exit 3};"
    "@{source_ip=$a.IPAddress;egress_ip=([string]$v).Trim()} | ConvertTo-Json -Compress"
)


def _text(raw: bytes, limit: int = 8192) -> str:
    if not isinstance(raw, bytes) or len(raw) > limit:
        raise ProtocolError('probe_output_size')
    try:
        return raw.decode('utf-8', 'strict').strip()
    except UnicodeError:
        raise ProtocolError('probe_output_encoding') from None


def _json_list(raw: bytes) -> list:
    try:
        value = json.loads(_text(raw))
    except (ValueError, RecursionError):
        raise ProtocolError('probe_output_json') from None
    if not isinstance(value, list) or len(value) > 32 or any(not isinstance(v, dict) for v in value):
        raise ProtocolError('probe_output_json')
    return value


def _ip(raw: bytes) -> str | None:
    try:
        value = _text(raw, 128)
        address = ipaddress.ip_address(value)
        return value if address.version == 4 and address.is_global and str(address) == value else None
    except (ValueError, ProtocolError):
        return None


def _ssh_listener(raw: bytes) -> int | None:
    """Select only one actual IPv4 loopback SSH listener, never another proxy."""
    found = []
    for line in _text(raw).splitlines():
        parts = line.split()
        if (len(parts) >= 6 and parts[0] == 'LISTEN'
                and parts[3] == SOCKS_ADDRESS and '("ssh",' in line):
            match = PID.search(line)
            if match:
                found.append(int(match[1]))
    return found[0] if len(found) == 1 else None


def _ssh_target(raw: bytes, target: str) -> bool:
    """ps output is parsed as data. Its tokens are NEVER executed."""
    try:
        args = shlex.split(_text(raw))
    except ValueError:
        return False
    if not args or Path(args[0]).name != 'ssh':
        return False
    target_token = any(token == target or (token.count('@') == 1 and token.rsplit('@', 1)[1] == target)
                       for token in args[1:])
    dynamic = any((token == '-D' and n + 1 < len(args) and args[n + 1] == SOCKS_ADDRESS)
                  or token == '-D' + SOCKS_ADDRESS for n, token in enumerate(args))
    return target_token and dynamic


class Probes:
    def __init__(self, *, runner: Callable = agent._bounded_process,
                 tcp: Callable = agent.tcp_probe, read: Callable | None = None,
                 windows_config: str | Path | None = None, windows_explicit: bool = False,
                 windows_probe: Callable | None = None, root_owned: bool = True):
        self.runner, self.tcp = runner, tcp
        self.read = read or (lambda path: Path(path).read_bytes())
        self.windows_config, self.windows_explicit = windows_config, windows_explicit
        self.windows_probe, self.root_owned = windows_probe, root_owned

    def command(self, argv: list[str], timeout: float) -> tuple[bool, bytes]:
        try:
            status, output = self.runner(argv, b'', timeout)
            if type(status) is not int or not isinstance(output, bytes) or len(output) > 8192:
                return False, b''
            return status == 0, output
        except (ProtocolError, OSError, TimeoutError):
            return False, b''

    def ct(self, argv: list[str], timeout: float = 2) -> tuple[bool, bytes]:
        return self.command([PCT, 'exec', '113', '--', *argv], timeout)

    def host(self, context: dict) -> dict:
        try:
            uptime = float(_text(self.read('/proc/uptime'), 128).split()[0])
            interface = _text(self.read('/sys/class/net/' + HOST_INTERFACE + '/operstate'), 64) == 'up'
            flags = int(_text(self.read('/sys/class/net/' + HOST_INTERFACE + '/flags'), 64), 16)
            healthy = math.isfinite(uptime) and uptime > 0 and interface and bool(flags & 1)
        except (OSError, ProtocolError, ValueError, IndexError):
            healthy = False
        control = self.tcp(WHM_IP, WHM_PORT, timeout=3) is True
        return dict(host_healthy=healthy, direct_control=control,
                    internet_healthy=control, interface_up=healthy)

    def ct113(self, context: dict) -> dict:
        work = {
            'units': (['/usr/bin/systemctl', 'is-active', *CT_UNITS], 2),
            'link': (['/usr/sbin/ip', '-j', 'link', 'show', 'dev', 'tun119'], 2),
            'table': (['/usr/sbin/ip', '-j', 'route', 'show', 'table', '119'], 2),
            'source_route': (['/usr/sbin/ip', '-j', 'route', 'get', '1.1.1.1', 'from', SOURCE_IP], 2),
            'socket': (['/usr/bin/ss', '-H', '-ltnp', '( sport = :10819 )'], 2),
            'egress': (['/usr/bin/curl', '-q', '--socks5-hostname', SOCKS_ADDRESS,
                        '-fsS', '--max-time', '5', EGRESS_URL], 6),
        }
        with ThreadPoolExecutor(max_workers=len(work)) as pool:
            pending = {name: pool.submit(self.ct, argv, timeout) for name, (argv, timeout) in work.items()}
            observed = {name: future.result() for name, future in pending.items()}
        checks = dict(execution_origin='ct113', units_active=False, tun119_up=False,
                      table119_default=False, table119_blackhole=False, ssh_socks_listener=False,
                      ssh_target_matches=False, source_route_tun119=False, tunnel_ok=False, probe_ok=False)
        try:
            checks['units_active'] = observed['units'][0] and _text(observed['units'][1]).splitlines() == ['active', 'active']
            links = _json_list(observed['link'][1]) if observed['link'][0] else []
            checks['tun119_up'] = (len(links) == 1 and links[0].get('ifname') == 'tun119'
                                   and isinstance(links[0].get('flags'), list) and 'UP' in links[0]['flags'])
            routes = _json_list(observed['table'][1]) if observed['table'][0] else []
            checks['table119_default'] = any(r.get('dst') == 'default' and r.get('dev') == 'tun119'
                                             and r.get('type', 'unicast') == 'unicast' for r in routes)
            checks['table119_blackhole'] = any(r.get('dst') == 'default' and r.get('type') == 'blackhole' for r in routes)
            route = _json_list(observed['source_route'][1]) if observed['source_route'][0] else []
            checks['source_route_tun119'] = bool(route and route[0].get('dev') == 'tun119'
                                                and route[0].get('table') in (119, '119'))
            pid = _ssh_listener(observed['socket'][1]) if observed['socket'][0] else None
            checks['ssh_socks_listener'] = pid is not None
            if pid is not None:
                ok, raw = self.ct(['/usr/bin/ps', '-p', str(pid), '-o', 'args='], 1)
                checks['ssh_target_matches'] = ok and _ssh_target(raw, context['job']['target']['host'])
            egress = _ip(observed['egress'][1]) if observed['egress'][0] else None
            checks['egress_ip'] = egress
            gate = ('units_active', 'tun119_up', 'table119_default', 'table119_blackhole',
                    'ssh_socks_listener', 'ssh_target_matches')
            checks['tunnel_ok'] = all(checks[name] is True for name in gate)
            checks['probe_ok'] = checks['tunnel_ok'] and egress == context['job']['target']['host']
        except ProtocolError:
            checks['tunnel_ok'] = checks['probe_ok'] = False
        return checks

    def _windows(self, context: dict) -> dict | None:
        """None means unconfigured. Configured failure is explicit, never QGA."""
        if self.windows_config is None:
            return None
        checks = dict(execution_origin='guest', windows_channel_verified=False,
                      source_ip=None, egress_ip=None, tunnel_ok=False, probe_ok=False)
        try:
            path = Path(self.windows_config)
            try:
                path.lstat()
            except FileNotFoundError:
                return checks if self.windows_explicit else None
            path = private_file(path, root_owned=self.root_owned)
            if path.stat().st_size > MAX_BODY:
                return checks
            client = self.windows_probe
            if client is None:
                # The outer pinned Iran SPEC verifies this dependency's exact
                # bytes before starting us. Also refuse unsafe local code here.
                private_file(Path(__file__).parent / 'windows_probe_client.py', root_owned=self.root_owned)
                try:
                    from .windows_probe_client import probe as client
                except ImportError:
                    from windows_probe_client import probe as client
            result = client(context, config_path=str(path))
            if (not isinstance(result, dict) or result.get('schema') != iran_adapter.PROBE_SCHEMA
                    or result.get('probe_kind') != 'vm109' or result.get('read_only') is not True
                    or type(result.get('ok')) is not bool or not iran_adapter._bound(result, context)
                    or not isinstance(result.get('checks'), dict)
                    or len(canonical_json(result['checks'])) > 2048):
                return checks
            proof = result['checks']
            source = proof.get('source_ip')
            egress = _ip(proof.get('egress_ip', '').encode('ascii')) if isinstance(proof.get('egress_ip'), str) else None
            good = (result['ok'] and proof.get('execution_origin') == 'guest' and source == SOURCE_IP
                    and egress == context['job']['target']['host']
                    and proof.get('tunnel_ok') is True and proof.get('probe_ok') is True)
            checks.update(windows_channel_verified=bool(good), source_ip=source if source == SOURCE_IP else None,
                          egress_ip=egress, tunnel_ok=bool(good), probe_ok=bool(good))
            return checks
        except Exception:
            # Fail closed without reflecting local files, transport errors,
            # raw responses, HMAC material or private configuration.
            return checks

    def vm109(self, context: dict) -> dict:
        windows = self._windows(context)
        if windows is not None:
            return windows
        checks = dict(execution_origin='guest', qga_available=False, source_ip=None,
                      egress_ip=None, tunnel_ok=False, probe_ok=False)
        available, _ = self.command([QM, 'guest', 'cmd', '109', 'ping'], 1.25)
        checks['qga_available'] = available
        if not available:
            return checks
        ok, raw = self.command([QM, 'guest', 'exec', '109', '--timeout', '5', '--',
                                'powershell.exe', '-NoProfile', '-NonInteractive', '-Command', GUEST_SCRIPT], 5.5)
        if not ok:
            return checks
        try:
            result = decode_json(raw)
            if type(result.get('exitcode')) is not int or result['exitcode'] != 0 or result.get('exited') not in (True, 1):
                return checks
            data = result.get('out-data')
            if not isinstance(data, str) or len(data) > 512:
                return checks
            guest = decode_json(data.encode('utf-8'))
            source = guest.get('source_ip')
            egress = _ip(guest.get('egress_ip', '').encode('ascii')) if isinstance(guest.get('egress_ip'), str) else None
            checks['source_ip'], checks['egress_ip'] = source if source == SOURCE_IP else None, egress
            checks['tunnel_ok'] = source == SOURCE_IP and egress == context['job']['target']['host']
            checks['probe_ok'] = checks['tunnel_ok']
        except (ProtocolError, UnicodeError):
            pass
        return checks

    def run(self, kind: str, context: dict, agent_id: str = 'pve-ir') -> dict:
        if kind not in ('host', 'ct113', 'vm109'):
            raise ProtocolError('probe_kind')
        context = iran_adapter.validate_context(context, {'agent_id': agent_id, 'candidate_ports': [22, 443]})
        checks = getattr(self, kind)(context)
        keys = ('host_healthy', 'direct_control', 'internet_healthy') if kind == 'host' else ('tunnel_ok', 'probe_ok')
        return dict(schema=iran_adapter.PROBE_SCHEMA, probe_kind=kind, read_only=True,
                    ok=all(checks.get(k) is True for k in keys), checks=checks,
                    target=context['job']['target'], **{k: context[k] for k in SCOPE})


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--kind', choices=('host', 'ct113', 'vm109'), required=True)
    parser.add_argument('--agent-id', default='pve-ir')
    parser.add_argument('--windows-config', help='Private local Windows probe config; never a network-supplied path')
    args = parser.parse_args(argv)
    context = {}
    try:
        if os.geteuid() != 0:
            raise ProtocolError('probe_root_required')
        raw = sys.stdin.buffer.read(16385)
        if len(raw) > 16384:
            raise ProtocolError('probe_input_size')
        context = decode_json(raw)
        result = Probes(windows_config=args.windows_config or WINDOWS_CONFIG,
                        windows_explicit=args.windows_config is not None).run(args.kind, context, args.agent_id)
        sys.stdout.buffer.write(canonical_json(result))
        return 0 if result['ok'] else 1
    except (ProtocolError, OSError):
        # Never reflect raw commands, keys, route contents or malformed input.
        result = dict(schema=iran_adapter.PROBE_SCHEMA, probe_kind=args.kind,
                      read_only=True, ok=False, checks={'probe_ok': False})
        for key in SCOPE:
            if key in context:
                result[key] = context[key]
        if isinstance(context.get('job'), dict):
            result['target'] = context['job'].get('target')
        sys.stdout.buffer.write(canonical_json(result))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
