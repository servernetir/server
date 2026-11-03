"""Compare existing StoreOrderTest outcomes, without hiding baseline failures."""
import sys
import xml.etree.ElementTree as ET

def failures(filename):
    result = {}
    for case in ET.parse(filename).getroot().iter('testcase'):
        for kind in ('failure', 'error'):
            node = case.find(kind)
            if node is not None:
                message = node.get('message') or (node.text or '').splitlines()[0]
                result[(case.get('classname'), case.get('name'))] = (kind, message)
    return result

base, current = map(failures, sys.argv[1:3])
new = {test: issue for test, issue in current.items() if base.get(test) != issue}
for test, issue in base.items():
    print('BASELINE:', test, issue)
for test, issue in current.items():
    print('CURRENT:', test, issue)
if new:
    print('NEW OR CHANGED FAILURES:', new)
    sys.exit(1)
print('No new StoreOrderTest failures. Existing baseline failures remain explicitly reported:', len(current))
