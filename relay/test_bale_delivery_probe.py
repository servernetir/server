import importlib.util
from pathlib import Path
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
            if method == "sendMessage":
                marker = payload["text"]
                return {"message_id": 7}
            return [{"update_id": 8, "message": {"text": marker, "chat": {"id": 42}}}]

        self.assertEqual({"delivery_confirmed": True}, module.probe(call, "a", "b", "42"))

    def test_send_success_without_receiver_delivery_is_not_success(self):
        def call(token, method, payload):
            if method == "getWebhookInfo":
                return {"url": ""}
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


if __name__ == "__main__":
    unittest.main()
