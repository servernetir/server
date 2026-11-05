import json
from pathlib import Path
import unittest

from model import capacity, costs, report


class ModelTest(unittest.TestCase):
    def setUp(self):
        self.config = json.loads(Path(__file__).with_name("synthetic.example.json").read_text())

    def test_physical_cpu_and_no_ram_overcommit(self):
        small = capacity(self.config, 1, 1)
        self.assertEqual(28, small["capacity_per_node"])
        self.assertEqual(["cpu"], small["bottlenecks"])
        self.assertFalse(small["ram_overcommit"])
        self.assertEqual(14, capacity(self.config, 2, 4)["capacity_per_node"])
        self.config["node"]["logical_threads"] = 128
        self.assertEqual(28, capacity(self.config, 1, 1)["capacity_per_node"])

    def test_ipv4_and_iops_are_real_independent_limits(self):
        self.config["node"]["usable_guest_ipv4"] = 3
        self.assertEqual(3, capacity(self.config, 1, 1)["capacity_per_node"])
        self.config["node"]["usable_guest_ipv4"] = 29
        self.config["node"]["measured_sustained_iops"] = 5200
        self.assertEqual(1, capacity(self.config, 1, 1)["capacity_per_node"])

    def test_fleet_expansion_pays_for_every_node(self):
        per_node = capacity(self.config, 1, 1)["capacity_per_node"]
        one = costs(self.config, 20, per_node)
        hundred = costs(self.config, 100, per_node)
        self.assertEqual(4, hundred["required_nodes"])
        self.assertFalse(hundred["single_node_feasible"])
        self.assertGreater(float(hundred["provider_cost_eur_ex_vat"]), 3 * float(one["provider_cost_eur_ex_vat"]))

    def test_tomans_not_rials_and_exact_fee_rounding(self):
        result = costs(self.config, 10, 28)
        self.assertEqual("10000000.00", result["gross_revenue_irt"])
        self.assertEqual("100000.00", result["payment_fees_irt"])
        self.config["commercial"]["retail_monthly_irt"] = 101
        self.config["commercial"]["payment_fee_percent"] = "0.5"
        self.assertEqual("1.00", costs(self.config, 1, 28)["payment_fees_irt"])
        self.config["currency"] = "IRR"
        with self.assertRaisesRegex(ValueError, "Toman"):
            report(self.config)

    def test_traffic_overage_and_sales_vat_are_not_omitted(self):
        self.config["commercial"]["included_traffic_gb_per_node"] = 100
        self.config["commercial"]["sales_vat_percent"] = "10"
        result = costs(self.config, 10, 28)
        self.assertEqual("4900", result["traffic_overage_gb"])
        self.assertEqual("9090909.09", result["net_revenue_irt"])

    def test_reserves_and_undocumented_overcommit_rejected(self):
        self.config["node"]["cpu_allocation_ratio"] = "2"
        with self.assertRaisesRegex(ValueError, "justification"):
            capacity(self.config, 1, 1)
        self.config["node"]["cpu_overcommit_justification"] = "Synthetic test only; CPU latency may double under saturation"
        self.assertEqual("2", capacity(self.config, 1, 1)["cpu_allocation_ratio"])
        self.config["node"]["reserved_ram_gib"] = 128
        with self.assertRaises(ValueError):
            capacity(self.config, 1, 1)

    def test_report_documents_non_viable_scenarios(self):
        result = report(self.config)
        self.assertEqual(23, result["plans"][0]["first_break_even_customers"])
        self.assertIsNone(result["plans"][2]["first_break_even_customers"])
        self.assertFalse(result["hardware_purchase_authorized"])
        self.assertTrue(result["synthetic_inputs"])
        self.assertEqual([10, 20, 30, 50, 100], [r["active_customers"] for r in result["plans"][0]["occupancy"]])


if __name__ == "__main__":
    unittest.main()
