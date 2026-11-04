"""Reject new failures, missing tests and skipped coverage; report baseline failures."""
import json
import sys
import xml.etree.ElementTree as ET


def report(path):
    cases = {}
    for case in ET.parse(path).getroot().iter("testcase"):
        key = case.get("classname", "") + "::" + case.get("name", "")
        if key in cases:
            raise ValueError("Duplicate testcase identity")
        cases[key] = (
            bool(case.findall("failure") or case.findall("error")),
            bool(case.findall("skipped")),
        )
    if not cases:
        raise ValueError("Empty JUnit report")
    return cases


def compare(before, after):
    if before.keys() != after.keys():
        raise ValueError("Legacy testcase identities changed; baseline comparison is invalid")
    failures_before = {key for key, value in before.items() if value[0]}
    failures_after = {key for key, value in after.items() if value[0]}
    newly_skipped = {key for key, value in after.items() if value[1] and not before[key][1]}
    result = {
        "tests": len(after),
        "baseline_failures": sorted(failures_before),
        "remaining_failures": sorted(failures_after),
        "fixed_failures": sorted(failures_before - failures_after),
        "new_failures": sorted(failures_after - failures_before),
        "newly_skipped": sorted(newly_skipped),
    }
    if result["new_failures"] or newly_skipped:
        raise ValueError(json.dumps(result))
    return result


if __name__ == "__main__":
    if len(sys.argv) != 5:
        raise SystemExit("Expected before.xml after.xml before_exit after_exit")
    try:
        before, after = report(sys.argv[1]), report(sys.argv[2])
        for cases, status in ((before, sys.argv[3]), (after, sys.argv[4])):
            # Infrastructure/startup failures cannot be relabelled as baseline failures.
            if int(status) not in (0, 1, 2) or (int(status) != 0 and not any(v[0] for v in cases.values())):
                raise ValueError("PHPUnit failed without a comparable testcase failure")
        print(json.dumps(compare(before, after), indent=2))
    except (OSError, ET.ParseError, ValueError) as error:
        raise SystemExit(str(error))
