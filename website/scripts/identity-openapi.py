#!/usr/bin/env python3
"""Regenerate the ServerNet contract from its reviewed registry, without network or secrets."""
import argparse
import copy
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
catalog = json.loads((root / 'resources/identity/catalog.json').read_text(encoding='utf8'))
services = [s for s in catalog if not s['contract_blocker']]
schemas = {}
for s in services:
    body = copy.deepcopy(s['operations'][0]['input'])
    body['additionalProperties'] = False
    if 'birth_date' in body.get('properties', {}):
        date = body['properties']['birth_date']
        date.pop('format', None)
        date.update({'description': 'Jalali date; YYYY/MM/DD. Not a Gregorian ISO date.', 'x-calendar': 'persian'})
    schemas[s['slug']] = body
    schemas[s['slug'] + '.result'] = s['operations'][0]['output']['properties']['response_body']['properties']['data']
receipt = {'type': 'object', 'properties': {
    'request_id': {'type': 'string', 'format': 'uuid'}, 'service': {'type': 'string'},
    'processing_status': {'enum': ['accepted', 'processing', 'completed', 'failed', 'unknown']},
    'inquiry_outcome': {'type': ['string', 'null']},
    'financial_status': {'enum': ['reserved', 'captured', 'released', 'pending_reconciliation', 'refunded']},
    'charged': {'type': 'integer', 'minimum': 0}, 'reserved': {'type': 'integer', 'minimum': 0},
    'currency': {'const': 'IRT'}, 'price_version': {'type': 'integer'},
    'result': {'anyOf': [{'$ref': '#/components/schemas/' + s['slug'] + '.result'} for s in services] + [{'type': 'null'}]},
}}
response = {'description': 'ServerNet receipt. Result expires after 24 hours; the used key remains a tombstone.',
            'content': {'application/json': {'schema': receipt}}}
spec = {'openapi': '3.1.0', 'info': {'title': 'ServerNet Identity & Inquiry API', 'version': '1.0.0'},
        'servers': [{'url': 'https://servernet.cloud/api/v1/identity'}], 'security': [{'ServerNetBearer': []}],
        'components': {'securitySchemes': {'ServerNetBearer': {'type': 'http', 'scheme': 'bearer'}}, 'schemas': schemas}, 'paths': {}}
for path in ['/services', '/pricing']:
    spec['paths'][path] = {'get': {'summary': 'Available catalog, accepted input schemas and current price versions',
        'x-required-scopes': ['identity:services:read'], 'responses': {'200': {'description': 'Prices are integer IRT including output tax; unavailable prices and price versions are null.'},
        '401': {'description': 'Invalid key'}, '403': {'description': 'Explicit scope and owned active application required'}}}}
spec['paths']['/inquiries/{service}'] = {'post': {
    'summary': 'Execute a paid inquiry with an accepted maximum fee',
    'x-required-scopes': ['identity:execute', 'identity:<service category>'],
    'x-service-inputs': {s['slug']: '#/components/schemas/' + s['slug'] for s in services},
    'parameters': [{'name': 'service', 'in': 'path', 'required': True, 'schema': {'type': 'string', 'enum': [s['slug'] for s in services]}},
        {'name': 'Idempotency-Key', 'in': 'header', 'required': True, 'schema': {'type': 'string', 'pattern': '^[A-Za-z0-9_.:-]{16,128}$'}}],
    'requestBody': {'required': True, 'content': {'application/json': {'schema': {'type': 'object', 'required': ['input', 'max_charge_irt'],
        'properties': {'input': {'anyOf': [{'$ref': '#/components/schemas/' + s['slug']} for s in services]},
                       'max_charge_irt': {'type': 'integer', 'minimum': 0}, 'price_version': {'type': 'integer', 'minimum': 1}}}}}},
    'responses': {'200': response, '202': response, **{str(code): {'description': text} for code, text in {
        401: 'Invalid or expired key', 402: 'Insufficient available funds or budget', 403: 'Explicit scopes and owned eligible application required',
        409: 'Idempotency payload or quote conflict', 422: 'Invalid input; identifiers must be strings', 429: 'Rate, quota or concurrency limit',
        503: 'Service, pricing, funding or contract prerequisites unavailable'}.items()}}}}
spec['paths']['/requests/{id}'] = {'get': {'x-required-scopes': ['identity:requests:read'], 'parameters': [
    {'name': 'id', 'in': 'path', 'required': True, 'schema': {'type': 'string', 'format': 'uuid'}}],
    'responses': {'200': response, '403': {'description': 'Explicit scope required'}, '404': {'description': 'No owned request'}}}}
encoded = json.dumps(spec, ensure_ascii=False, indent=2) + '\n'
target = root / 'resources/identity/openapi.json'
if argparse.ArgumentParser().parse_known_args()[1] == ['--check']:
    if target.read_text(encoding='utf8') != encoded:
        raise SystemExit('OpenAPI differs from reviewed registry; regenerate it')
else:
    target.write_text(encoded, encoding='utf8')
print(f'{len(services)} reviewed JSON services, {len(schemas)} input/result schemas')
