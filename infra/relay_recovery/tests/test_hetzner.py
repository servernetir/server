"""Behavioural provider tests. No credentials, network or paid API calls."""
import copy
from dataclasses import replace
import hashlib
import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest
from urllib.parse import parse_qs, urlsplit

SPEC = importlib.util.spec_from_file_location("relay_hetzner", Path(__file__).parents[1] / "hetzner.py")
h = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = h
SPEC.loader.exec_module(h)


def page(rows, next_page=None):
    return h.Response(200, {"servers": rows, "meta": {"pagination": {"next_page": next_page}}})


def intent():
    return h.CandidateIntent("attempt-1", "incident-1", 2, "1", "711", "sn-relay-g2-a1",
                             "cx-test", "fsn1", "100", ("9",), 900,
                             user_data_hash=hashlib.sha256(b"").hexdigest())


def quote():
    return h.Quote("cx-test", "fsn1", "DE", "EUR", 5_000_000, 10_000, 0,
                   20_000_000_000_000, 1_000_000, 1000, 2, "4", 40)


def server():
    i = intent()
    return {"id": 51, "name": i.name, "labels": i.labels(),
            "server_type": {"name": "cx-test"}, "location": {"name": "fsn1"},
            "public_net": {"ipv4": {"id": 71, "ip": "203.0.113.51"}, "ipv6": {"id": 72}},
            "volumes": [], "private_net": []}


class World:
    """Stateful fake simulates ambiguous creation/deletion and orphan IP costs."""
    def __init__(self):
        anchor = server()
        anchor.update(id=40, name="sn-relay-g1")
        anchor["labels"].update({"sn-generation": "1", "sn-intent": "attempt-old"})
        self.calls, self.servers, self.ips = [], {"40": anchor}, {}
        self.post_timeout, self.disappear_on_delete = False, True
        self.list_status, self.server_get_status = 200, None
        self.ip_delete_status = 204
        self.list_split = False

    def __call__(self, method, url, body):
        u = urlsplit(url)
        path, query = u.path.removeprefix("/v1"), parse_qs(u.query)
        self.calls.append((method, path, copy.deepcopy(body)))
        if (method, path) == ("GET", "/servers"):
            if self.list_status != 200:
                return h.Response(self.list_status, {"error": {"message": "not disclosed"}})
            rows = list(copy.deepcopy(self.servers).values())
            p = int(query["page"][0])
            if self.list_split:
                return page(rows[:1], 2) if p == 1 else page(rows[1:])
            return page(rows)
        if (method, path) == ("GET", "/images/100"):
            return h.Response(200, {"image": {"id": 100, "status": "available", "architecture": "x86", "deprecation": None}})
        if (method, path) == ("POST", "/servers"):
            s = server()
            self.servers["51"] = s
            for ip_id in ("71", "72"):
                self.ips[ip_id] = {"id": int(ip_id), "labels": {}, "assignee_type": "server", "assignee_id": 51}
            if self.post_timeout:
                raise h.TransportUnknown("timeout_after_create")
            return h.Response(201, {"server": copy.deepcopy(s), "root_password": "must-never-return"})
        parts = path.split("/")
        if len(parts) == 3 and parts[1] == "servers":
            sid = parts[2]
            if method == "GET":
                if self.server_get_status:
                    return h.Response(self.server_get_status, {})
                return h.Response(200, {"server": copy.deepcopy(self.servers[sid])}) if sid in self.servers else h.Response(404, {})
            if method == "DELETE":
                if self.disappear_on_delete:
                    self.servers.pop(sid, None)
                    for ip in self.ips.values():
                        if str(ip.get("assignee_id")) == sid:
                            ip.update(assignee_type="unassigned", assignee_id=None)
                return h.Response(204, {})
        if len(parts) == 3 and parts[1] == "primary_ips":
            ip_id = parts[2]
            if method == "GET":
                return h.Response(200, {"primary_ip": copy.deepcopy(self.ips[ip_id])}) if ip_id in self.ips else h.Response(404, {})
            if method == "PUT":
                self.ips[ip_id]["labels"] = copy.deepcopy(body["labels"])
                return h.Response(200, {"primary_ip": copy.deepcopy(self.ips[ip_id])})
            if method == "DELETE":
                if self.ip_delete_status == 204:
                    self.ips.pop(ip_id, None)
                return h.Response(self.ip_delete_status, {})
        raise AssertionError((method, path))


class ProviderLifecycleTests(unittest.TestCase):
    def setUp(self):
        self.world = World()
        self.api = h.Hetzner(self.world, now=lambda: 1000)

    def create(self):
        result = self.api.create_candidate(intent(), quote())
        self.assertEqual("created", result.state)
        self.assertNotIn("root_password", repr(result))
        return result.server

    def permit(self, record, **changes):
        p = h.DeletePermit("rejected_candidate", record.provider_id, record.generation,
                           "1", "711", "revision-1", 1000, ("40",), "a" * 64, True)
        return replace(p, **changes)

    def test_timeout_after_actual_create_reconciles_without_second_purchase(self):
        self.world.post_timeout = True
        self.assertEqual("create_unknown", self.api.create_candidate(intent(), quote()).state)
        result = self.api.create_candidate(intent(), reconciliation_only=True)
        self.assertEqual("found", result.state)
        self.assertEqual("51", result.server.provider_id)
        self.assertEqual(1, sum(m == "POST" for m, _, _ in self.world.calls))

    def test_absence_after_unknown_is_not_permission_to_buy(self):
        result = self.api.create_candidate(intent(), reconciliation_only=True)
        self.assertEqual("create_unknown", result.state)
        self.assertFalse(any(m == "POST" for m, _, _ in self.world.calls))

    def test_all_pages_checked_and_conflicting_name_is_never_adopted(self):
        self.world.list_split = True
        self.world.servers = {"8": {"id": 8, "name": "unrelated", "labels": {}}, "51": server()}
        self.assertEqual("found", self.api.reconcile_candidate(intent()).state)
        self.world.servers["51"]["labels"]["sn-owner"] = "someone-else"
        self.assertEqual("blocked", self.api.create_candidate(intent(), quote()).state)
        self.assertFalse(any(m == "POST" for m, _, _ in self.world.calls))

    def test_duplicate_intent_different_name_blocks_reconciliation(self):
        a, b = server(), server()
        b.update(id=52, name="another-name")
        self.world.servers = {"51": a, "52": b}
        self.assertEqual("blocked", self.api.reconcile_candidate(intent()).state)

    def test_failed_inventory_is_not_empty_success_and_never_creates(self):
        for status in (401, 403, 500):
            self.world.list_status = status
            self.assertEqual("create_unknown", self.api.create_candidate(intent(), quote()).state)
        self.assertFalse(any(m == "POST" for m, _, _ in self.world.calls))

    def test_missing_primary_ip_id_cannot_lose_future_ip_cleanup_identity(self):
        self.world.servers["51"] = server()
        self.world.servers["51"]["public_net"]["ipv4"] = None
        result = self.api.reconcile_candidate(intent())
        self.assertEqual("create_unknown", result.state)
        self.assertEqual("primary_ipv4_not_captured", result.reason)
        self.assertIsNone(result.server)

    def test_stale_quote_bad_bootstrap_and_missing_ssh_prevent_create(self):
        self.assertEqual("blocked", self.api.create_candidate(intent(), replace(quote(), observed_at=699)).state)
        for bad in (replace(intent(), ssh_key_ids=()), replace(intent(), user_data="changed"),
                    replace(intent(), image="100/other"), replace(intent(), created_at=1001)):
            with self.assertRaises(h.AdapterError):
                self.api.create_candidate(bad, quote())
        self.assertFalse(any(m == "POST" for m, _, _ in self.world.calls))

    def test_full_rejected_candidate_cleanup_proves_server_and_ips_absent(self):
        record = self.create()
        self.assertEqual("tagged", self.api.tag_captured_ips(record).state)
        result = self.api.delete_server(record, self.permit(record))
        self.assertEqual("deleted", result.state)
        self.assertEqual({"40"}, set(self.world.servers))
        self.assertEqual({}, self.world.ips)

    def test_delete_ack_without_absence_keeps_ips_and_pending_state(self):
        record = self.create()
        self.api.tag_captured_ips(record)
        self.world.disappear_on_delete = False
        result = self.api.delete_server(record, self.permit(record))
        self.assertEqual("delete_pending", result.state)
        self.assertEqual({"71", "72"}, set(self.world.ips))
        self.assertFalse(any(m == "DELETE" and p.startswith("/primary_ips/") for m, p, _ in self.world.calls))

    def test_401_or_500_is_never_absence_proof(self):
        record = self.create()
        for status in (401, 500):
            self.world.server_get_status = status
            self.assertEqual("delete_pending", self.api.delete_server(record, self.permit(record)).state)
        self.assertFalse(any(m == "DELETE" for m, _, _ in self.world.calls))

    def test_unlabelled_or_reassigned_ip_is_retained_and_reported(self):
        record = self.create()
        self.api.tag_captured_ips(record)
        self.world.ips["71"]["labels"] = {}
        self.world.ips["72"]["assignee_id"] = 999
        result = self.api.delete_server(record, self.permit(record))
        self.assertEqual("delete_pending", result.state)
        self.assertEqual({"71", "72"}, set(result.remaining_ip_ids))
        self.assertEqual({"71", "72"}, set(self.world.ips))

    def test_cleanup_can_resume_after_server_has_disappeared(self):
        record = self.create()
        self.api.tag_captured_ips(record)
        self.world.ip_delete_status = 500
        self.assertEqual("delete_pending", self.api.delete_server(record, self.permit(record)).state)
        self.world.ip_delete_status = 204
        self.assertEqual("deleted", self.api.delete_server(record, self.permit(record)).state)

    def test_wrong_active_stale_or_data_bearing_permit_never_deletes(self):
        record = self.create()
        for changes in ({"provider_id": "52"}, {"generation": 3}, {"owner_id": "2"},
                        {"active_provider_ids": ("51",)}, {"active_provider_ids": ()},
                        {"checked_at": 969}, {"no_writes": False}):
            with self.assertRaises(h.AdapterError):
                self.api.delete_server(record, self.permit(record, **changes))
        for changed in (replace(record, has_writes=True), replace(record, promoted_at=999)):
            with self.assertRaises(h.AdapterError):
                self.api.delete_server(changed, self.permit(changed))
        self.assertFalse(any(m == "DELETE" for m, _, _ in self.world.calls))

    def test_wrong_project_404_cannot_falsely_confirm_deletion(self):
        record = self.create()
        self.world.server_get_status = 404
        result = self.api.delete_server(record, self.permit(record))
        self.assertEqual("delete_pending", result.state)
        self.assertIn("51", self.world.servers)
        self.assertFalse(any(m == "DELETE" for m, _, _ in self.world.calls))

    def test_active_anchor_owner_drift_blocks_target_delete(self):
        record = self.create()
        self.world.servers["40"]["labels"]["sn-service"] = "other-service"
        self.assertEqual("blocked", self.api.delete_server(record, self.permit(record)).state)
        self.assertFalse(any(m == "DELETE" for m, _, _ in self.world.calls))

    def test_provider_identity_drift_and_attached_data_block_delete(self):
        record = self.create()
        self.world.servers["51"]["public_net"]["ipv4"]["id"] = 999
        self.assertEqual("blocked", self.api.delete_server(record, self.permit(record)).state)
        self.world.servers["51"] = server()
        self.world.servers["51"]["volumes"] = [3]
        self.assertEqual("blocked", self.api.delete_server(record, self.permit(record)).state)
        self.assertFalse(any(m == "DELETE" for m, _, _ in self.world.calls))

    def test_missing_or_reused_ip_not_adopted_by_address(self):
        record = self.create()
        self.world.ips["71"]["assignee_id"] = 999
        self.assertEqual("blocked", self.api.tag_captured_ips(record).state)
        self.assertFalse(any(m == "PUT" for m, _, _ in self.world.calls))

    def test_origin_override_and_insecure_token_file_are_refused(self):
        with self.assertRaises(h.AdapterError):
            h.Hetzner(self.world, base_url="https://attacker.invalid/v1")
        transport = h.HttpTransport("private-token")
        with self.assertRaises(h.AdapterError):
            transport("GET", "https://attacker.invalid/v1/servers", None)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "token"
            path.write_text("private-token")
            path.chmod(0o644)
            with self.assertRaises(h.AdapterError):
                h.HttpTransport.from_private_file(path)
            self.assertNotIn("private-token", repr(h.AdapterError("provider_request_failed", 401)))


class TariffTransport:
    def __init__(self):
        self.currency, self.vat, self.calls = "EUR", "19.00", []
        self.bad_price, self.bad_pagination = False, False

    @staticmethod
    def amount(net):
        from decimal import Decimal
        return {"net": net, "gross": str(Decimal(net) * Decimal("1.19"))}

    def __call__(self, method, url, body):
        path = urlsplit(url).path.removeprefix("/v1")
        self.calls.append(path)
        locations = [{"id": n, "name": name, "country": country}
                     for n, (name, country) in enumerate((("fsn1", "DE"), ("nbg1", "DE"), ("hel1", "FI"), ("ash", "US")), 1)]
        meta = {"pagination": {"next_page": None}}
        if path == "/locations":
            return h.Response(200, {"locations": locations, "meta": meta})
        if path == "/server_types":
            rows = [{"id": 1, "name": "cx-test", "cores": 2, "memory": 4.0, "disk": 40,
                     "architecture": "x86", "locations": [{"name": l["name"], "available": True, "deprecation": None} for l in locations]}]
            rows[0]["locations"][1]["available"] = False
            rows.append({**rows[0], "id": 2, "name": "cax-cheap", "architecture": "arm"})
            rows.append({**rows[0], "id": 3, "name": "cx-retired", "locations": [{"name": "fsn1", "available": True, "deprecation": {"unavailable_after": "2030-01-01T00:00:00Z"}}]})
            return h.Response(200, {"server_types": rows, "meta": {} if self.bad_pagination else meta})
        if path == "/pricing":
            types = []
            for kind_id in (1, 2, 3):
                prices = []
                for loc in locations:
                    base = {"DE": "5", "FI": "4", "US": "3"}[loc["country"]]
                    prices.append({"location": loc["name"], "price_monthly": self.amount(base),
                                   "price_hourly": self.amount("0.01"),
                                   "included_traffic": 20_000_000_000_000 if loc["country"] != "US" else 1_000_000_000_000,
                                   "price_per_tb_traffic": self.amount("1")})
                types.append({"id": kind_id, "prices": prices})
            if self.bad_price:
                types[0]["prices"][0]["price_monthly"]["gross"] = "NaN"
            ips = [{"location": l["name"], "price_monthly": self.amount("0.5"), "price_hourly": self.amount("0.0008")} for l in locations]
            return h.Response(200, {"pricing": {"currency": self.currency, "vat_rate": self.vat,
                                                "primary_ips": [{"type": "ipv4", "prices": ips}], "server_types": types}})
        raise AssertionError(path)


class PricingTests(unittest.TestCase):
    def setUp(self):
        self.fake = TariffTransport()
        self.api = h.Hetzner(self.fake, now=lambda: 1000)

    def test_country_priority_costs_ipv4_tax_and_traffic_are_explicit(self):
        offers = self.api.quotes(expected_currency="EUR", predicted_outgoing_bytes=2_000_000_000_001)
        self.assertEqual(["DE", "FI", "US"], [q.country for q in offers])
        self.assertEqual(["fsn1", "hel1", "ash"], [q.location for q in offers])
        self.assertTrue(all(q.server_type == "cx-test" for q in offers))
        self.assertEqual(6_545_000, offers[0].monthly_micro)  # (5 + .5) * 1.19
        self.assertEqual(1_190_119, offers[2].predicted_traffic_micro)  # rounded 100 MB
        self.assertEqual(12_852, offers[0].hourly_micro)
        self.assertNotIn("/datacenters", self.fake.calls)

    def test_budget_filter_does_not_choose_foreign_currency_or_ignore_egress(self):
        offers = self.api.quotes(expected_currency="EUR", predicted_outgoing_bytes=10_000_000_000_000,
                                 monthly_caps_micro={"DE": 7_000_000, "FI": 7_000_000, "US": 7_000_000})
        self.assertEqual(["DE", "FI"], [q.country for q in offers])
        self.fake.currency = "USD"
        with self.assertRaisesRegex(h.AdapterError, "currency_mismatch"):
            self.api.quotes(expected_currency="EUR")
        with self.assertRaisesRegex(h.AdapterError, "unsupported_currency"):
            self.api.quotes(expected_currency="TRY")

    def test_invalid_money_and_missing_pagination_fail_closed(self):
        self.fake.bad_price = True
        with self.assertRaises(h.AdapterError):
            self.api.quotes(expected_currency="EUR")
        self.fake.bad_price, self.fake.bad_pagination = False, True
        with self.assertRaisesRegex(h.AdapterError, "missing_pagination"):
            self.api.quotes(expected_currency="EUR")


if __name__ == "__main__":
    unittest.main()
