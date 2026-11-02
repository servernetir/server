"""Narrow Hetzner adapter for the relay-recovery controller (stdlib only).

No global environment tokens, retry-on-write, implicit adoption or deletion by
IP/name.  The caller owns the durable intent/generation journal, credentials,
budget reservations and application/data fencing.  This adapter never treats a
DELETE acknowledgement as proof that the resource no longer exists.

Transport is injected in tests.  Importing this module performs no I/O.
Prices are integer millionths of the *project's* currency, rounded upwards.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from decimal import Decimal, InvalidOperation, ROUND_CEILING
import hashlib
import ipaddress
import json
from pathlib import Path
import re
import time
from typing import Callable, Mapping
import urllib.error
import urllib.parse
import urllib.request

BASE_URL = "https://api.hetzner.cloud/v1"
MANAGER = "relay-recovery-v1"
MAX_BODY = 4 * 1024 * 1024
LABEL = re.compile(r"^[A-Za-z0-9](?:[A-Za-z0-9_.-]{0,61}[A-Za-z0-9])?$")
NAME = re.compile(r"^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$")


class AdapterError(RuntimeError):
    """Intentionally excludes response bodies, URLs with credentials and tokens."""

    def __init__(self, reason: str, status: int = 0):
        self.reason, self.status = reason, status
        super().__init__(f"{reason} (status={status})")


class TransportUnknown(AdapterError):
    pass


@dataclass(frozen=True)
class Response:
    status: int
    body: dict


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise AdapterError("redirect_refused", code)


class HttpTransport:
    """TLS-verified, origin-pinned HTTP transport; write retries are forbidden."""

    def __init__(self, token: str, timeout: float = 20):
        if not isinstance(token, str) or not token or any(c.isspace() for c in token):
            raise AdapterError("invalid_token")
        self._token, self.timeout = token, timeout
        self._opener = urllib.request.build_opener(_NoRedirect())

    @classmethod
    def from_private_file(cls, path: str | Path, **kwargs):
        p = Path(path)
        if p.is_symlink() or not p.is_file() or p.stat().st_mode & 0o077:
            raise AdapterError("token_file_not_private")
        return cls(p.read_text().strip(), **kwargs)

    def __call__(self, method: str, url: str, payload: dict | None) -> Response:
        parsed = urllib.parse.urlsplit(url)
        if (parsed.scheme, parsed.netloc) != ("https", "api.hetzner.cloud") or not parsed.path.startswith("/v1/"):
            raise AdapterError("untrusted_origin")
        raw = None if payload is None else json.dumps(payload, separators=(",", ":")).encode()
        req = urllib.request.Request(url, data=raw, method=method, headers={
            "Authorization": "Bearer " + self._token, "Accept": "application/json",
            "Content-Type": "application/json", "User-Agent": "ServerNet-relay-recovery/1",
        })
        try:
            with self._opener.open(req, timeout=self.timeout) as reply:
                status, body = reply.status, reply.read(MAX_BODY + 1)
        except urllib.error.HTTPError as exc:
            status, body = exc.code, exc.read(MAX_BODY + 1)
        except AdapterError:
            raise
        except (OSError, TimeoutError, urllib.error.URLError):
            raise TransportUnknown("transport_unknown") from None
        if len(body) > MAX_BODY:
            raise AdapterError("response_too_large", status)
        try:
            decoded = json.loads(body) if body else {}
        except (ValueError, UnicodeError):
            raise AdapterError("invalid_json", status) from None
        if not isinstance(decoded, dict):
            raise AdapterError("invalid_response_shape", status)
        return Response(status, decoded)


def _identifier(value: object) -> str:
    if not isinstance(value, (str, int)) or isinstance(value, bool):
        raise AdapterError("invalid_id")
    result = str(value)
    if not result.isascii() or not result.isdecimal() or int(result) < 1:
        raise AdapterError("invalid_id")
    return result


def _label(value: object) -> str:
    text = str(value)
    if not LABEL.fullmatch(text):
        raise AdapterError("invalid_label")
    return text


def _money(value: object) -> Decimal:
    # JSON floats are refused: the official API returns monetary strings.
    if not isinstance(value, (str, int)) or isinstance(value, bool):
        raise AdapterError("invalid_price")
    try:
        number = Decimal(value)
    except InvalidOperation:
        raise AdapterError("invalid_price") from None
    if not number.is_finite() or number < 0:
        raise AdapterError("invalid_price")
    return number


def _micro(value: Decimal) -> int:
    return int((value * 1_000_000).to_integral_value(rounding=ROUND_CEILING))


def _unit(price: Mapping, vat: Decimal) -> Decimal:
    net, gross = _money(price.get("net")), _money(price.get("gross"))
    # Reject inconsistent taxes instead of guessing a project's tax treatment.
    if abs(gross - net * (1 + vat / 100)) > Decimal("0.0001"):
        raise AdapterError("inconsistent_tax")
    return gross


@dataclass(frozen=True)
class Quote:
    server_type: str
    location: str
    country: str
    currency: str
    monthly_micro: int
    hourly_micro: int
    predicted_traffic_micro: int
    included_traffic_bytes: int
    traffic_per_tb_micro: int
    observed_at: int
    cores: int
    memory_gib: str
    disk_gb: int
    architecture: str = "x86"

    @property
    def predicted_monthly_micro(self):
        return self.monthly_micro + self.predicted_traffic_micro


@dataclass(frozen=True)
class CandidateIntent:
    """Must already have been atomically committed to the caller's journal."""
    intent_id: str
    incident_id: str
    generation: int
    owner_id: str
    service_id: str
    name: str
    server_type: str
    location: str
    image: str
    ssh_key_ids: tuple[str, ...]
    created_at: int
    user_data: str = field(default="", repr=False)
    user_data_hash: str = ""

    def validate(self, now: int):
        for value in (self.intent_id, self.incident_id, self.owner_id, self.service_id):
            _label(value)
        if not isinstance(self.generation, int) or isinstance(self.generation, bool) or self.generation < 1:
            raise AdapterError("invalid_generation")
        if not NAME.fullmatch(self.name) or not NAME.fullmatch(self.server_type) or not NAME.fullmatch(self.location):
            raise AdapterError("invalid_name")
        if not isinstance(self.created_at, int) or self.created_at <= 0 or self.created_at > now:
            raise AdapterError("intent_not_committed")
        _identifier(self.image)
        if not self.ssh_key_ids:
            raise AdapterError("ssh_key_required")
        for key in self.ssh_key_ids:
            _identifier(key)
        if hashlib.sha256(self.user_data.encode()).hexdigest() != self.user_data_hash:
            raise AdapterError("bootstrap_hash_mismatch")

    def labels(self):
        return {"sn-managed": MANAGER, "sn-owner": str(self.owner_id),
                "sn-service": str(self.service_id), "sn-generation": str(self.generation),
                "sn-incident": self.incident_id, "sn-intent": self.intent_id}


@dataclass(frozen=True)
class ManagedServer:
    """Capture this object in the durable journal before promotion/deletion."""
    provider_id: str
    name: str
    labels: dict
    generation: int
    primary_ip_ids: tuple[str, ...]
    ipv4: str | None
    captured_at: int
    promoted_at: int | None = None
    has_writes: bool = False


@dataclass(frozen=True)
class DeletePermit:
    """Fresh authoritative core decision, not a flag supplied by an HTTP client.

    The core must verify signed application receipts, backup/fencing and DNS
    deadlines before issuing a retirement permit. Adapter enforces scope/freshness.
    """
    kind: str  # rejected_candidate | retired_generation
    provider_id: str
    generation: int
    owner_id: str
    service_id: str
    registry_revision: str
    checked_at: int
    active_provider_ids: tuple[str, ...]
    gate_digest: str
    no_writes: bool = False


@dataclass(frozen=True)
class OperationResult:
    state: str
    reason: str = ""
    server: ManagedServer | None = None
    remaining_ip_ids: tuple[str, ...] = ()


class Hetzner:
    def __init__(self, transport: Callable, *, now: Callable[[], int] = lambda: int(time.time()),
                 base_url: str = BASE_URL):
        if base_url != BASE_URL:
            raise AdapterError("untrusted_origin")
        self.transport, self.now, self.base_url = transport, now, base_url

    def _request(self, method: str, path: str, payload: dict | None = None,
                 query: Mapping | None = None) -> Response:
        if not path.startswith("/") or any(c in path for c in ("?", "#", "..", "\\")):
            raise AdapterError("invalid_path")
        url = self.base_url + path
        if query:
            url += "?" + urllib.parse.urlencode(query)
        result = self.transport(method, url, payload)
        if not isinstance(result, Response):
            raise AdapterError("invalid_transport_response")
        return result

    @staticmethod
    def _ok(response: Response, allowed=(200,)):
        if response.status not in allowed:
            # Never reflect a provider body containing caller input or credentials.
            raise AdapterError("provider_request_failed", response.status)
        return response.body

    def _pages(self, path: str, key: str, query: Mapping | None = None) -> list[dict]:
        page, result, seen = 1, [], set()
        while True:
            if page in seen or len(seen) >= 1000:
                raise AdapterError("invalid_pagination")
            seen.add(page)
            body = self._ok(self._request("GET", path, query={**(query or {}), "page": page, "per_page": 50}))
            rows = body.get(key)
            if not isinstance(rows, list) or any(not isinstance(r, dict) for r in rows):
                raise AdapterError("invalid_collection")
            result.extend(rows)
            pagination = body.get("meta", {}).get("pagination")
            if not isinstance(pagination, dict) or "next_page" not in pagination:
                raise AdapterError("missing_pagination")
            next_page = pagination["next_page"]
            if next_page is None:
                return result
            if not isinstance(next_page, int) or isinstance(next_page, bool) or next_page <= page:
                raise AdapterError("invalid_pagination")
            page = next_page

    def quotes(self, *, expected_currency: str, predicted_outgoing_bytes: int = 0,
               min_memory_gib: str = "2", min_disk_gb: int = 20,
               monthly_caps_micro: Mapping[str, int] | None = None) -> list[Quote]:
        """Rank compatible available offers, preserving DE→FI→US priority.

        No API default currency or foreign-exchange rate is inferred. Optional
        caps and returned values use integer millionths of expected_currency.
        """
        now = self.now()
        if expected_currency not in ("EUR", "USD"):
            raise AdapterError("unsupported_currency")
        if not isinstance(predicted_outgoing_bytes, int) or predicted_outgoing_bytes < 0:
            raise AdapterError("invalid_traffic_forecast")
        locations = {r["name"]: r for r in self._pages("/locations", "locations") if isinstance(r.get("name"), str)}
        types = self._pages("/server_types", "server_types")
        price_root = self._ok(self._request("GET", "/pricing")).get("pricing")
        if not isinstance(price_root, dict) or price_root.get("currency") != expected_currency:
            raise AdapterError("currency_mismatch")
        vat = _money(price_root.get("vat_rate"))
        ip_prices = {p["location"]: p for ip in price_root.get("primary_ips", []) if ip.get("type") == "ipv4"
                     for p in ip.get("prices", []) if isinstance(p.get("location"), str)}
        price_types = {str(p.get("id")): p for p in price_root.get("server_types", [])}
        offers = []
        for kind in types:
            memory = kind.get("memory")
            if isinstance(memory, bool) or not isinstance(memory, (str, int, float)):
                raise AdapterError("invalid_resource_shape")
            memory = _money(str(memory))
            if kind.get("architecture") != "x86" or memory < _money(min_memory_gib):
                continue
            if not isinstance(kind.get("disk"), int) or kind["disk"] < min_disk_gb or not isinstance(kind.get("cores"), int) or kind["cores"] < 1:
                continue
            prices = {p.get("location"): p for p in price_types.get(str(kind.get("id")), {}).get("prices", [])}
            for support in kind.get("locations", []):
                if not isinstance(support, dict) or support.get("available") is not True:
                    continue
                # Conservative: no new deployments on a deprecated location/type.
                if support.get("deprecation") is not None:
                    continue
                loc_name = support.get("name")
                location = locations.get(loc_name, {})
                country = location.get("country")
                if country not in ("DE", "FI", "US"):
                    continue
                price, ip_price = prices.get(loc_name), ip_prices.get(loc_name)
                if not isinstance(price, dict) or not isinstance(ip_price, dict):
                    continue
                monthly = _unit(price.get("price_monthly", {}), vat) + _unit(ip_price.get("price_monthly", {}), vat)
                hourly = _unit(price.get("price_hourly", {}), vat) + _unit(ip_price.get("price_hourly", {}), vat)
                included = price.get("included_traffic")
                if not isinstance(included, int) or isinstance(included, bool) or included < 0 or monthly <= 0 or hourly <= 0:
                    raise AdapterError("invalid_tariff")
                traffic = _unit(price.get("price_per_tb_traffic", {}), vat)
                # Billing is in 100 MB blocks; round the forecast conservatively.
                excess = max(0, predicted_outgoing_bytes - included)
                billable = ((excess + 100_000_000 - 1) // 100_000_000) * 100_000_000
                predicted = traffic * Decimal(billable) / Decimal(1_000_000_000_000)
                quote = Quote(kind["name"], loc_name, country, expected_currency, _micro(monthly),
                              _micro(hourly), _micro(predicted), included, _micro(traffic), now,
                              kind["cores"], str(kind["memory"]), kind["disk"])
                if monthly_caps_micro and quote.predicted_monthly_micro > monthly_caps_micro.get(country, -1):
                    continue
                offers.append(quote)
        return sorted(offers, key=lambda q: (("DE", "FI", "US").index(q.country),
                                             q.predicted_monthly_micro, q.hourly_micro, q.location, q.server_type))

    def _capture(self, raw: Mapping, intent: CandidateIntent) -> ManagedServer:
        if raw.get("name") != intent.name or raw.get("labels") != intent.labels():
            raise AdapterError("identity_conflict")
        if not isinstance(raw.get("server_type"), dict) or not isinstance(raw.get("location"), dict) or not isinstance(raw.get("public_net"), dict):
            raise AdapterError("incomplete_server_identity")
        if str(raw["server_type"].get("name")) != intent.server_type or raw["location"].get("name") != intent.location:
            raise AdapterError("spec_conflict")
        ipv4 = raw["public_net"].get("ipv4")
        if not isinstance(ipv4, dict) or ipv4.get("id") is None:
            raise AdapterError("primary_ipv4_not_captured")
        try:
            ipv4_address = str(ipaddress.IPv4Address(ipv4.get("ip")))
        except (ValueError, TypeError):
            raise AdapterError("primary_ipv4_not_captured") from None
        ids = []
        for family in ("ipv4", "ipv6"):
            ip = raw.get("public_net", {}).get(family) or {}
            if ip.get("id") is not None:
                ids.append(_identifier(ip["id"]))
        return ManagedServer(_identifier(raw.get("id")), raw["name"], dict(raw["labels"]), intent.generation,
                             tuple(ids), ipv4_address, self.now())

    def reconcile_candidate(self, intent: CandidateIntent) -> OperationResult:
        intent.validate(self.now())
        # Inspect all project pages, including conflicting names with wrong labels.
        rows = self._pages("/servers", "servers")
        matches = [s for s in rows if s.get("name") == intent.name or s.get("labels", {}).get("sn-intent") == intent.intent_id]
        if not matches:
            return OperationResult("absent")
        if len(matches) != 1:
            return OperationResult("blocked", "multiple_identity_matches")
        try:
            return OperationResult("found", server=self._capture(matches[0], intent))
        except AdapterError as exc:
            state = "create_unknown" if exc.reason in ("incomplete_server_identity", "primary_ipv4_not_captured") else "blocked"
            return OperationResult(state, exc.reason)

    def create_candidate(self, intent: CandidateIntent, quote: Quote | None = None,
                         *, reconciliation_only: bool = False, authorize=None) -> OperationResult:
        intent.validate(self.now())
        try:
            prior = self.reconcile_candidate(intent)
        except AdapterError as exc:
            return OperationResult("create_unknown", exc.reason)
        if prior.state != "absent":
            return prior
        if reconciliation_only:
            # Eventual consistency or a timed-out POST is not permission to retry.
            return OperationResult("create_unknown", "absence_after_ambiguous_create")
        if quote is None or self.now() - quote.observed_at not in range(0, 301):
            return OperationResult("blocked", "stale_or_missing_quote")
        if (quote.server_type, quote.location, quote.architecture) != (intent.server_type, intent.location, "x86"):
            return OperationResult("blocked", "quote_spec_mismatch")
        try:
            image = self._ok(self._request("GET", "/images/" + _identifier(intent.image))).get("image", {})
            if image.get("architecture") != "x86" or image.get("status") != "available" or image.get("deprecation") is not None:
                return OperationResult("blocked", "incompatible_image")
            if self.now() - quote.observed_at not in range(0, 301):
                return OperationResult("blocked", "stale_or_missing_quote")
            if authorize is not None:
                authorize()
            response = self._request("POST", "/servers", {
                "name": intent.name, "server_type": intent.server_type, "location": intent.location,
                "image": int(intent.image), "ssh_keys": [int(i) for i in intent.ssh_key_ids],
                "labels": intent.labels(), "user_data": intent.user_data, "start_after_create": True,
                "public_net": {"enable_ipv4": True, "enable_ipv6": True},
            })
            if response.status != 201:
                code = response.body.get("error", {}).get("code")
                if response.status >= 500 or response.status in (408, 409, 429) or code == "uniqueness_error":
                    return OperationResult("create_unknown", "create_requires_reconciliation")
                return OperationResult("blocked", "create_rejected_" + str(response.status))
            raw = response.body.get("server")
            if not isinstance(raw, dict):
                return OperationResult("create_unknown", "malformed_create_response")
            return OperationResult("created", server=self._capture(raw, intent))
        except AdapterError as exc:
            return OperationResult("create_unknown", exc.reason)

    def _permit(self, server: ManagedServer, permit: DeletePermit):
        if permit.provider_id != server.provider_id or permit.generation != server.generation:
            raise AdapterError("delete_scope_mismatch")
        if str(permit.owner_id) != server.labels.get("sn-owner") or str(permit.service_id) != server.labels.get("sn-service"):
            raise AdapterError("delete_owner_mismatch")
        if server.provider_id in permit.active_provider_ids:
            raise AdapterError("delete_active_server")
        if not permit.active_provider_ids:
            raise AdapterError("missing_project_scope_anchor")
        for active_id in permit.active_provider_ids:
            _identifier(active_id)
        if self.now() - permit.checked_at not in range(0, 31) or not permit.registry_revision or not re.fullmatch(r"[a-f0-9]{64}", permit.gate_digest):
            raise AdapterError("stale_delete_permit")
        if permit.kind == "rejected_candidate":
            if server.promoted_at is not None or server.has_writes or not permit.no_writes:
                raise AdapterError("candidate_has_production_data")
        elif permit.kind != "retired_generation":
            raise AdapterError("invalid_delete_permit")

    def delete_server(self, server: ManagedServer, permit: DeletePermit, *, authorize=None) -> OperationResult:
        """One deletion step. Persist returned remaining_ip_ids across invocations.

        No removal of snapshots, volumes or unrelated resources is implemented.
        Those require their own ownership/retention protocol.
        """
        self._permit(server, permit)
        server_id = _identifier(server.provider_id)
        try:
            # A wrong/replaced project token can return 404 for an existing ID.
            # Verify a separate active generation in this same credential scope
            # before accepting *any* absence proof or executing a DELETE.
            for active_id in permit.active_provider_ids:
                anchor = self._ok(self._request("GET", "/servers/" + active_id)).get("server", {})
                labels = anchor.get("labels", {})
                if (str(anchor.get("id")) != active_id or labels.get("sn-managed") != MANAGER
                        or labels.get("sn-owner") != str(permit.owner_id)
                        or labels.get("sn-service") != str(permit.service_id)):
                    return OperationResult("blocked", "project_scope_unconfirmed", server, server.primary_ip_ids)
            response = self._request("GET", "/servers/" + server_id)
            if response.status == 404:
                return self._cleanup_ips(server, permit, authorize)
            raw = self._ok(response).get("server", {})
            if str(raw.get("id")) != server_id or raw.get("name") != server.name or raw.get("labels") != server.labels or raw.get("labels", {}).get("sn-managed") != MANAGER:
                return OperationResult("blocked", "delete_identity_conflict", server)
            actual_ids = tuple(_identifier(v["id"]) for family in ("ipv4", "ipv6")
                               if (v := raw.get("public_net", {}).get(family) or {}).get("id") is not None)
            if set(actual_ids) != set(server.primary_ip_ids):
                return OperationResult("blocked", "primary_ip_identity_changed", server)
            # Resources attached later may contain unique data or another service.
            if raw.get("volumes") or raw.get("private_net"):
                return OperationResult("blocked", "attached_resources_need_separate_plan", server)
            self._permit(server, permit)
            if authorize is not None:
                authorize()
            deleted = self._request("DELETE", "/servers/" + server_id)
            if deleted.status not in (200, 201, 204, 404):
                return OperationResult("delete_pending", "delete_not_confirmed", server, server.primary_ip_ids)
            action = deleted.body.get("action")
            if action:
                if action.get("command") != "delete_server" or not any(str(r.get("id")) == server_id and r.get("type") == "server" for r in action.get("resources", [])):
                    return OperationResult("delete_pending", "action_scope_unconfirmed", server, server.primary_ip_ids)
                if action.get("status") != "success":
                    return OperationResult("delete_pending", "delete_action_pending", server, server.primary_ip_ids)
            after = self._request("GET", "/servers/" + server_id)
            if after.status != 404:
                return OperationResult("delete_pending", "server_absence_unconfirmed", server, server.primary_ip_ids)
            return self._cleanup_ips(server, permit, authorize)
        except AdapterError as exc:
            return OperationResult("delete_pending", exc.reason, server, server.primary_ip_ids)

    def _cleanup_ips(self, server: ManagedServer, permit: DeletePermit, authorize=None) -> OperationResult:
        remaining = []
        for ip_id in server.primary_ip_ids:
            try:
                response = self._request("GET", "/primary_ips/" + _identifier(ip_id))
                if response.status == 404:
                    continue
                raw = self._ok(response).get("primary_ip", {})
                # Captured at server creation and still exclusively managed by us.
                # A missing ownership label is a pending manual/adoption issue,
                # never a reason to delete every currently unassigned IP.
                labels = raw.get("labels", {})
                if str(raw.get("id")) != ip_id or labels != server.labels:
                    remaining.append(ip_id)
                    continue
                if raw.get("assignee_id") is not None or raw.get("assignee_type") not in (None, "unassigned"):
                    remaining.append(ip_id)
                    continue
                self._permit(server, permit)
                if authorize is not None:
                    authorize()
                response = self._request("DELETE", "/primary_ips/" + ip_id)
                if response.status not in (200, 201, 204, 404):
                    remaining.append(ip_id)
                    continue
                if self._request("GET", "/primary_ips/" + ip_id).status != 404:
                    remaining.append(ip_id)
            except AdapterError:
                remaining.append(ip_id)
        return OperationResult("delete_pending" if remaining else "deleted",
                               "remaining_primary_ips" if remaining else "", server, tuple(remaining))

    def tag_captured_ips(self, server: ManagedServer) -> OperationResult:
        """After journalling a newly created server, tag its captured IP IDs.

        Called before deletion/promotion. Never adopts by address or labels alone.
        """
        try:
            live = self._ok(self._request("GET", "/servers/" + _identifier(server.provider_id))).get("server", {})
            if str(live.get("id")) != server.provider_id or live.get("name") != server.name or live.get("labels") != server.labels:
                return OperationResult("blocked", "server_identity_conflict", server)
            linked = {str(v.get("id")) for f in ("ipv4", "ipv6") if (v := live.get("public_net", {}).get(f) or {}).get("id")}
            if linked != set(server.primary_ip_ids):
                return OperationResult("blocked", "primary_ip_identity_changed", server)
            for ip_id in server.primary_ip_ids:
                ip = self._ok(self._request("GET", "/primary_ips/" + ip_id)).get("primary_ip", {})
                if str(ip.get("id")) != ip_id or str(ip.get("assignee_id")) != server.provider_id or ip.get("assignee_type") != "server" or ip.get("labels") not in ({}, server.labels):
                    return OperationResult("blocked", "primary_ip_ownership_conflict", server)
                result = self._ok(self._request("PUT", "/primary_ips/" + ip_id, {"labels": server.labels}), allowed=(200,))
                if result.get("primary_ip", {}).get("labels") != server.labels:
                    return OperationResult("ip_tag_pending", "primary_ip_tag_unconfirmed", server)
            return OperationResult("tagged", server=server)
        except AdapterError as exc:
            return OperationResult("ip_tag_pending", exc.reason, server)
