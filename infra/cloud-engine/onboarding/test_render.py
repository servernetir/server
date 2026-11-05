import json
from pathlib import Path
import tempfile
import unittest

from render import guest_firewall, render, validate


class RenderTest(unittest.TestCase):
    def setUp(self):
        self.config = json.loads(Path(__file__).with_name("node.example.json").read_text())

    def test_repeatable_candidates_never_enable_sales(self):
        with tempfile.TemporaryDirectory() as directory:
            first = render(self.config, directory)
            self.assertEqual(first, render(self.config, directory))
            node = json.loads((Path(directory) / "node-registration.json").read_text())
            self.assertFalse(node["sales_enabled"])
            self.assertEqual("unknown", node["health"])
            self.assertIsNone(node["token_secret"])
            self.assertIn("bridge-ports none", (Path(directory) / "interfaces.fragment").read_text())
            self.assertIn("policy_in: DROP", (Path(directory) / "host.fw").read_text())

    def test_management_and_gateway_validation(self):
        self.config["management_cidrs"] = ["0.0.0.0/0"]
        with self.assertRaisesRegex(ValueError, "Unrestricted"):
            validate(self.config)
        self.config["management_cidrs"] = ["198.51.100.4/32"]
        self.config["guest_gateway"] = "203.0.113.31"
        with self.assertRaisesRegex(ValueError, "gateway"):
            validate(self.config)

    def test_simulation_ranges_cannot_be_used_as_production(self):
        self.config["simulation"] = False
        with self.assertRaisesRegex(ValueError, "global IPv4"):
            validate(self.config)

    def test_identifier_injection_and_network_overlap_rejected(self):
        self.config["storage"] = "local;rm -rf /"
        with self.assertRaises(ValueError):
            validate(self.config)
        self.config["storage"] = "local-zfs"
        self.config["primary_ipv4"] = "203.0.113.2"
        with self.assertRaisesRegex(ValueError, "separate"):
            validate(self.config)

    def test_existing_different_file_is_preserved(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "interfaces.fragment"
            target.write_text("operator changes\n")
            with self.assertRaisesRegex(ValueError, "overwrite"):
                render(self.config, directory)
            self.assertEqual("operator changes\n", target.read_text())

    def test_guest_firewall_is_bound_to_one_ip_and_default_closed(self):
        result = guest_firewall(self.config, '203.0.113.2')
        self.assertIn('[IPSET ipfilter-net0]\n203.0.113.2\n', result)
        self.assertIn('IN DROP -source 203.0.113.0/27', result)
        self.assertIn('OUT DROP -dest 203.0.113.0/27', result)
        self.assertIn('policy_in: DROP', result)
        self.assertNotIn('IN ACCEPT', result)
        with self.assertRaises(ValueError):
            guest_firewall(self.config, '203.0.113.1')
        self.config['public_ingress'] = [{'protocol':'udp', 'port':51820}]
        self.assertIn('IN ACCEPT -p udp -dport 51820', guest_firewall(self.config, '203.0.113.2'))

    def test_firewall_port_injection_is_rejected(self):
        self.config['public_ingress'] = [{'protocol':'udp', 'port':'51820\nIN ACCEPT'}]
        with self.assertRaisesRegex(ValueError, 'port'):
            validate(self.config)


if __name__ == "__main__":
    unittest.main()
