"""Reject new StoreOrder failures while reporting existing baseline debt."""
import sys
import xml.etree.ElementTree as ET

def failures(path):
    root = ET.parse(path).getroot()
    cases = root.findall(".//testcase")
    if not cases:
        raise SystemExit("No baseline test cases; refusing comparison")
    return {(c.get("classname"), c.get("name")) for c in cases if c.find("failure") is not None or c.find("error") is not None}

base, head = map(failures, sys.argv[1:3])
for case in sorted(base):
    print("Existing baseline failure:", ".".join(case))
new = head - base
for case in sorted(new):
    print("NEW REGRESSION:", ".".join(case))
print(f"StoreOrder baseline failures: {len(base)}; current: {len(head)}; new: {len(new)}")
raise SystemExit(bool(new))
