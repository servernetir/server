"""Compare existing StoreOrderTest outcomes, without hiding baseline failures."""
import sys
import xml.etree.ElementTree as ET
import re

def failures(filename):
    result = {}
    for case in ET.parse(filename).getroot().iter('testcase'):
        for kind in ('failure', 'error'):
            node = case.find(kind)
            if node is not None:
                lines = (node.text or '').strip().splitlines()
                meaningful = [line.strip() for line in lines if line.strip()
                              and '::'+str(case.get('name')) not in line
                              and not line.strip().startswith(('/', '#'))]
                message = node.get('message') or (meaningful[0] if meaningful else '')
                message = re.sub(r'/home/runner/work/server/server/(?:sms-baseline/)?website', '<website>', message)
                result[(case.get('classname'), case.get('name'))] = (kind, node.get('type'), message)
    return result

base_cases, current_cases = [list(ET.parse(path).getroot().iter('testcase')) for path in sys.argv[1:3]]
case_keys = lambda cases: {(c.get('classname'), c.get('name')) for c in cases}
if not base_cases or case_keys(base_cases) != case_keys(current_cases):
    sys.exit('Baseline and current must execute the same nonempty test set.')
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
