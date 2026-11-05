#!/usr/bin/env python3
"""Conservative capacity and commercial model; all prices are operator inputs."""
import argparse
from decimal import Decimal, ROUND_CEILING, ROUND_FLOOR, ROUND_HALF_UP
import json
from pathlib import Path

D = Decimal
SIZES = [(1, 1), (1, 2), (2, 2), (2, 4)]
OCCUPANCIES = [10, 20, 30, 50, 100]


def number(obj, key, minimum=D(0), integer=False):
    if key not in obj or isinstance(obj[key], bool):
        raise ValueError(f"Missing numeric input: {key}")
    value = D(str(obj[key]))
    if not value.is_finite() or value < minimum or (integer and value != value.to_integral_value()):
        raise ValueError(f"Invalid numeric input: {key}")
    return value


def floor(value):
    return int(value.to_integral_value(rounding=ROUND_FLOOR))


def ceil(value):
    return int(value.to_integral_value(rounding=ROUND_CEILING))


def amount(value):
    return str(value.quantize(D("0.01"), rounding=ROUND_HALF_UP))


def capacity(config, vcpu, ram_gib):
    n, p = config["node"], config["workload"]
    physical = number(n, "physical_cores", D(1), True)
    logical = number(n, "logical_threads", physical, True)
    cpu_reserve = number(n, "reserved_physical_cores", integer=True)
    cpu_ratio = number(n, "cpu_allocation_ratio", D(1))
    if cpu_ratio > 1 and not str(n.get("cpu_overcommit_justification", "")).strip():
        raise ValueError("CPU overcommit requires a documented performance impact/justification")
    if cpu_ratio > 4:
        raise ValueError("This economical-host model refuses allocation ratios above 4")
    ram = number(n, "ram_gib", D(1)) - number(n, "reserved_ram_gib")
    disk = number(n, "usable_mirrored_disk_gib", D(1)) - number(n, "reserved_disk_gib")
    iops = number(n, "measured_sustained_iops", D(1)) - number(n, "reserved_iops")
    network = number(n, "sustained_network_mbps", D(1)) - number(n, "reserved_network_mbps")
    traffic = number(n, "monthly_traffic_budget_gb", D(1))
    if min(physical - cpu_reserve, ram, disk, iops, network) <= 0:
        raise ValueError("Node safety reserves consume an entire required resource")
    limits = {
        "cpu": floor((physical - cpu_reserve) * cpu_ratio / D(vcpu)),
        "ram": floor(ram / (D(ram_gib) + number(p, "vm_overhead_gib"))),
        "disk": floor(disk / number(p, "disk_gib_per_vm", D(1))),
        "iops": floor(iops / number(p, "sustained_iops_per_vm", D(1))),
        "network": floor(network / number(p, "sustained_mbps_per_vm", D("0.001"))),
        "traffic": floor(traffic / number(p, "monthly_traffic_gb_per_vm", D("0.001"))),
        "ipv4": int(number(n, "usable_guest_ipv4", D(0), True)),
    }
    count = min(limits.values())
    return {"vcpu": vcpu, "ram_gib": ram_gib, "capacity_per_node": count, "limits": limits,
            "bottlenecks": [key for key, value in limits.items() if value == count],
            "physical_cores": int(physical), "logical_threads": int(logical),
            "cpu_allocation_ratio": str(cpu_ratio), "ram_overcommit": False}


def costs(config, customers, per_node_capacity):
    if customers < 1 or per_node_capacity < 1:
        raise ValueError("Customer count and node capacity must be positive")
    c, n, p = config["commercial"], config["node"], config["workload"]
    price = number(c, "retail_monthly_irt", D(1), True)
    fx = number(c, "eur_to_irt", D("0.0001"))
    nodes = ceil(D(customers) / D(per_node_capacity))
    fixed_keys = ["rental_eur", "subnet_monthly_eur", "primary_ipv4_eur", "storage_eur", "backup_eur", "network_base_eur", "node_overhead_eur"]
    monthly_node = sum((number(c, key) for key in fixed_keys), D(0))
    amortization = number(c, "setup_amortization_months", D(1), True)
    setup = number(c, "node_setup_eur") + number(c, "subnet_setup_eur")
    monthly_node += setup / amortization
    total_traffic = number(p, "monthly_traffic_gb_per_vm") * customers
    included = number(c, "included_traffic_gb_per_node") * nodes
    excess_traffic = max(D(0), total_traffic - included)
    variable = number(c, "ipv4_per_customer_eur") * customers + excess_traffic * number(c, "traffic_overage_eur_per_gb")
    eur_ex_vat = monthly_node * nodes + variable + number(c, "fleet_overhead_eur")
    vat = number(c, "supplier_vat_percent") / 100
    sales_vat = number(c, "sales_vat_percent") / 100
    fee_rate = number(c, "payment_fee_percent") / 100
    if max(vat, sales_vat, fee_rate) >= 1:
        raise ValueError("Tax and payment percentages must be below 100")
    gross_sales = price * customers
    net_sales = gross_sales / (1 + sales_vat)
    payment_fee_per_invoice = D(ceil(price * fee_rate + number(c, "payment_fee_fixed_irt", integer=True)))
    payment_fees = payment_fee_per_invoice * customers
    infrastructure_irt = eur_ex_vat * (1 + vat) * fx
    total_cost_irt = infrastructure_irt + payment_fees
    margin = net_sales - total_cost_irt
    return {"active_customers": customers, "required_nodes": nodes,
            "single_node_feasible": customers <= per_node_capacity,
            "fleet_capacity": nodes * per_node_capacity,
            "retail_currency": "IRT", "retail_monthly_irt": int(price),
            "gross_revenue_irt": amount(gross_sales), "net_revenue_irt": amount(net_sales),
            "provider_cost_eur_ex_vat": amount(eur_ex_vat),
            "provider_cost_eur_inc_vat": amount(eur_ex_vat * (1 + vat)),
            "payment_fees_irt": amount(payment_fees), "total_cost_irt": amount(total_cost_irt),
            "gross_margin_irt": amount(margin),
            "gross_margin_percent": amount(margin / net_sales * 100),
            "traffic_overage_gb": str(excess_traffic), "commercially_positive": margin >= 0}


def report(config):
    if config.get("currency") != "IRT":
        raise ValueError("Retail/FX inputs must be IRT (Toman), not IRR")
    for key in ("quote_date", "quote_source", "assumptions"):
        if not str(config.get(key, "")).strip():
            raise ValueError(f"Document {key}")
    result = {"currency": "IRT", "irt_to_irr": 10, "quote_date": config["quote_date"],
              "quote_source": config["quote_source"], "synthetic_inputs": config.get("synthetic_inputs") is True,
              "hardware_purchase_authorized": False, "plans": []}
    for cpu, ram in SIZES:
        cap = capacity(config, cpu, ram)
        if cap["capacity_per_node"] == 0:
            cap.update({"occupancy": [], "first_break_even_customers": None, "reason": "No usable capacity"})
        else:
            cap["occupancy"] = [costs(config, occupancy, cap["capacity_per_node"]) for occupancy in OCCUPANCIES]
            cap["first_break_even_customers"] = next((count for count in range(1, 10001)
                if costs(config, count, cap["capacity_per_node"])["commercially_positive"]), None)
            cap["break_even_search_limit"] = 10000
            cap["break_even_note"] = "First positive fleet scenario; node expansion creates new cost steps and later occupancy may be negative"
        result["plans"].append(cap)
    return result


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("config", type=Path)
    p.add_argument("--output", type=Path)
    a = p.parse_args()
    try:
        result = report(json.loads(a.config.read_text(), parse_float=D))
    except (ValueError, KeyError, ArithmeticError, OSError) as exc:
        p.exit(2, f"Model rejected: {exc}\n")
    rendered = json.dumps(result, indent=2) + "\n"
    if a.output:
        with a.output.open("x") as file:
            file.write(rendered)
    else:
        print(rendered, end="")


if __name__ == "__main__":
    main()
