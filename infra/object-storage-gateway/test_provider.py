import os
import unittest
from unittest.mock import Mock, patch

import provider


class ProviderBucketTest(unittest.TestCase):
    def setUp(self):
        os.environ["SN_OBJECT_REGIONS_JSON"] = """{
          "fsn1":{"local_endpoint":"http://127.0.0.1:1","public_endpoint":"https://fsn1.example",
          "root_access":"root","root_secret":"secret","upstream_endpoint":"https://fsn1.example",
          "upstream_access":"up","upstream_secret":"secret","meta_bucket":"meta-fsn1"},
          "nbg1":{"local_endpoint":"http://127.0.0.1:2","public_endpoint":"https://nbg1.example",
          "root_access":"root","root_secret":"secret","upstream_endpoint":"https://nbg1.example",
          "upstream_access":"up","upstream_secret":"secret","meta_bucket":"meta-nbg1"},
          "hel1":{"local_endpoint":"http://127.0.0.1:3","public_endpoint":"https://hel1.example",
          "root_access":"root","root_secret":"secret","upstream_endpoint":"https://hel1.example",
          "upstream_access":"up","upstream_secret":"secret","meta_bucket":"meta-hel1"}}
        """

    @patch("provider._signed")
    @patch("provider.upstream_client")
    def test_creates_upstream_then_assigns_gateway_owner(self, upstream, signed):
        client = upstream.return_value
        provider.create_or_assign_bucket("fsn1", "sn-fsn1-7", "SNACCESS")
        client.create_bucket.assert_called_once_with(
            Bucket="sn-fsn1-7", CreateBucketConfiguration={"LocationConstraint": "fsn1"})
        signed.assert_called_once_with(
            "fsn1", "PATCH", "/change-bucket-owner?bucket=sn-fsn1-7&owner=SNACCESS")

    @patch("provider._signed", side_effect=RuntimeError("owner failed"))
    @patch("provider.upstream_client")
    def test_removes_new_bucket_when_owner_assignment_fails(self, upstream, _signed):
        client = upstream.return_value
        with self.assertRaisesRegex(RuntimeError, "owner failed"):
            provider.create_or_assign_bucket("fsn1", "sn-fsn1-8", "SNACCESS")
        client.delete_bucket.assert_called_once_with(Bucket="sn-fsn1-8")

    @patch("provider._signed")
    def test_delete_user_accepts_current_and_legacy_success_statuses(self, signed):
        provider.delete_user("fsn1", "SNACCESS")
        signed.assert_called_once_with(
            "fsn1", "PATCH", "/delete-user?access=SNACCESS", ok=(200, 204))


if __name__ == "__main__":
    unittest.main()
