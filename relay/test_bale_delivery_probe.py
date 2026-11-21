import importlib.util
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from queue import Empty, Queue
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location(
    "probe", Path(__file__).with_name("bale-delivery-probe.py")
)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class DeliveryTest(unittest.TestCase):
    def test_receipt_requires_the_matching_message_in_the_receiver(self):
        marker = None

        def call(token, method, payload):
            nonlocal marker
            if method == "getWebhookInfo":
                return {"url": ""}
            if method == "getMe":
                return {"id": 1 if token == "a" else 2}
            if method == "sendMessage":
                marker = payload["text"]
                return {"message_id": 7}
            return [{"update_id": 8, "message": {"text": marker, "chat": {"id": 42}, "from": {"id": 1}}}]

        self.assertEqual({"delivery_confirmed": True}, module.probe(call, "a", "b", "42"))

    def test_send_success_without_receiver_delivery_is_not_success(self):
        def call(token, method, payload):
            if method == "getWebhookInfo":
                return {"url": ""}
            if method == "getMe":
                return {"id": 1 if token == "a" else 2}
            if method == "sendMessage":
                return {"message_id": 7}
            return []

        ticks = iter([0, 0, 31])
        with patch.object(module.time, "sleep"):
            self.assertEqual({"delivery_confirmed": False}, module.probe(call, "a", "b", "42", clock=lambda: next(ticks)))

    def test_existing_webhook_is_not_modified(self):
        with self.assertRaises(module.ProbeError):
            module.probe(lambda *_: {"url": "https://example.test"}, "a", "b", "42")

    def test_missing_or_shared_test_credentials_are_rejected(self):
        with self.assertRaises(module.ProbeError):
            module.probe(lambda *_: None, "a", "a", "42")


class RoundtripTest(unittest.TestCase):
    key = b"x" * 32
    now = 1000
    nonce = "a" * 32

    def verify(self, marker, **kwargs):
        return module.verify_marker(marker, self.key, "ping", 1, 2, "42", self.now, **kwargs)

    def marker(self, **kwargs):
        params = dict(key=self.key, kind="ping", nonce=self.nonce, timestamp=self.now,
                      source=1, dest=2, chat="42")
        params.update(kwargs)
        return module.signed_marker(**params)

    def test_signed_probe_is_correlation_and_context_bound(self):
        self.assertEqual(self.nonce, self.verify(self.marker()))
        for changed in [dict(kind="pong"), dict(source=3), dict(dest=3), dict(chat="43"),
                        dict(key=b"y" * 32), dict(timestamp=self.now-121), dict(timestamp=self.now+6)]:
            with self.subTest(changed=changed):
                self.assertIsNone(self.verify(self.marker(**changed)))
        self.assertIsNone(self.verify(self.marker(), expected_nonce="b" * 32))

    def test_only_exact_synthetic_format_accepted(self):
        for malformed in ["service_create", None, 1, self.marker()+":extra", self.marker().replace(self.nonce, "b"*32)]:
            self.assertIsNone(self.verify(malformed))

    def test_initiator_confirms_roundtrip_only_on_signed_matching_reply(self):
        def call(token, method, payload):
            if method == "getWebhookInfo":
                return {"url": ""}
            if method == "getMe":
                return {"id": 1}
            if method == "sendMessage":
                self.assertEqual(self.nonce, self.verify(payload["text"]))
                return {"message_id": 7}
            pong = self.marker(kind="pong", source=2, dest=1)
            return [{"update_id": 1, "message": {"text": pong, "chat": {"id": 42}, "from": {"id": 2}}}]
        with patch.object(module.secrets, "token_hex", return_value=self.nonce):
            result = module.roundtrip(call, "initiator", "own", 2, "42", self.key, wall=lambda: self.now)
        self.assertTrue(result["roundtrip_confirmed"])
        self.assertGreaterEqual(result["roundtrip_ms"], 0)

    def test_broker_send_receipt_and_wrong_chat_or_sender_do_not_count(self):
        def call(token, method, payload):
            if method == "getWebhookInfo":
                return {"url": ""}
            if method == "getMe":
                return {"id": 1}
            if method == "sendMessage":
                return {"message_id": 7}
            pong = self.marker(kind="pong", source=2, dest=1)
            return [
                {"update_id": 1, "message": {"text": pong, "chat": {"id": 43}, "from": {"id": 2}}},
                {"update_id": 2, "message": {"text": pong, "chat": {"id": 42}, "from": {"id": 3}}},
                {"update_id": 3, "message": {"text": self.marker(kind="pong", source=2, dest=1, nonce="b"*32), "chat": {"id": 42}, "from": {"id": 2}}},
            ]
        ticks = iter([0, 0, 0, 31])
        with patch.object(module.time, "sleep"), patch.object(module.secrets, "token_hex", return_value=self.nonce):
            result = module.roundtrip(call, "initiator", "own", 2, "42", self.key, clock=lambda: next(ticks), wall=lambda: self.now)
        self.assertEqual({"role": "initiator", "roundtrip_confirmed": False}, result)

    def test_responder_does_not_declare_roundtrip_just_for_sending_ack(self):
        sent = []
        def call(token, method, payload):
            if method == "getWebhookInfo":
                return {"url": ""}
            if method == "getMe":
                return {"id": 2}
            if method == "sendMessage":
                sent.append(payload["text"])
                return {"message_id": 7}
            ping = self.marker()
            return [{"update_id": 1, "message": {"text": ping, "chat": {"id": 42}, "from": {"id": 1}}}]*2
        result = module.roundtrip(call, "responder", "own", 1, "42", self.key, wall=lambda: self.now)
        self.assertEqual({"role": "responder", "peer_ping_received": True, "ack_submitted": True, "roundtrip_confirmed": False}, result)
        self.assertEqual([self.marker(kind="pong", source=2, dest=1)], sent)

    def test_active_webhook_blocks_two_host_mode_before_any_send(self):
        calls = []
        def call(token, method, payload):
            calls.append(method)
            return {"url": "https://example.test/webhook"}
        with self.assertRaises(module.ProbeError):
            module.roundtrip(call, "initiator", "own", 2, "42", self.key)
        self.assertEqual(["getWebhookInfo"], calls)

    def test_two_independent_peers_and_failure_in_either_direction(self):
        for drop_from in [None, 1, 2]:
            with self.subTest(drop_from=drop_from):
                inbox = {1: Queue(), 2: Queue()}

                def call(token, method, payload):
                    bot = int(token)
                    if method == "getWebhookInfo":
                        return {"url": ""}
                    if method == "getMe":
                        return {"id": bot}
                    if method == "sendMessage":
                        if bot != drop_from:
                            inbox[3-bot].put({"update_id": 1, "message": {"text": payload["text"],
                                                                         "chat": {"id": 42}, "from": {"id": bot}}})
                        return {"message_id": 7}
                    try:
                        return [inbox[bot].get(timeout=0.02)]
                    except Empty:
                        return []

                with patch.object(module.time, "sleep"), ThreadPoolExecutor(max_workers=2) as pool:
                    responder = pool.submit(module.roundtrip, call, "responder", "2", 1, "42", self.key, 0.15)
                    initiator = module.roundtrip(call, "initiator", "1", 2, "42", self.key, 0.15)
                    reply = responder.result()
                self.assertEqual(drop_from is None, initiator["roundtrip_confirmed"])
                self.assertEqual(drop_from != 1, reply["peer_ping_received"])
                self.assertFalse(reply["roundtrip_confirmed"])


if __name__ == "__main__":
    unittest.main()
