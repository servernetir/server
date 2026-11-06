"""Bounded owner-private reference retrieval. This module grants no tool rights."""
from __future__ import annotations

import datetime
import json
import os
import pathlib
import re
import stat
import unicodedata

ROOT = pathlib.Path('/var/lib/servernet-hermes-owner')
MAX_BYTES = 100 * 1024
MAX_DOCUMENTS = 200
VERSION = 'owner-project-context-v1-20261102'
PROJECTS = {'servernet': 'ServerNet', 'kianpet': 'Kianpet', 'urmia_city': 'Urmia.city', 'lubercar': 'Lubercar'}
SOURCE_TYPES = frozenset(('owner_chat', 'chatgpt_report', 'repository_document'))
STATUSES = frozenset(('reported', 'requested', 'documented'))
CREDENTIAL = re.compile(
    r'(?:password|passwd|secret|api[ _-]?key|api[ _-]?token|token|رمز(?:\s*عبور)?|توکن)\s*[:=]\s*\S{6,}'
    r'|BEGIN [^\n]*PRIVATE KEY|ssh-(?:rsa|ed25519)\s|\bsk-[A-Za-z0-9]{20,}'
    r'|\bBearer\s+[A-Za-z0-9._~+/=-]{8,}|https?://[^\s/@]+:[^\s/@]+@', re.I)
STOPWORDS = frozenset(('درباره', 'برای', 'بگو', 'چیست', 'چیه', 'وضعیت', 'چطور', 'است', 'این', 'های',
                       'به', 'از', 'با', 'را', 'من', 'تو', 'ما', 'کن', 'هم', 'آن', 'the', 'what', 'about',
                       'our', 'and', 'for', 'your', 'you', 'can', 'how', 'are', 'is', 'of', 'to',
                       'bir', 've', 'bu', 'için', 'nedir', 'nasıl', 'سلام', 'درود', 'hello', 'hi', 'hey', 'merhaba', 'selam'))
ALIASES = {'servernet': ('servernet', 'سرورنت'), 'kianpet': ('kianpet', 'کیانپت', 'کیان پت'),
           'urmia_city': ('urmia.city', 'urmia city', 'ارومیه سیتی'), 'lubercar': ('lubercar', 'لوبرکار', 'لوبر کار')}


def normalize(value: str) -> str:
    return unicodedata.normalize('NFKC', value).casefold().translate(str.maketrans({'ي': 'ی', 'ك': 'ک', '\u200c': ' '}))


def _field(row: dict, key: str, maximum: int) -> str:
    value = row.get(key)
    if not isinstance(value, str) or not value.strip() or len(value) > maximum:
        raise ValueError('Invalid field')
    if any((ord(ch) < 32 and ch not in '\n\r\t') or 0xD800 <= ord(ch) <= 0xDFFF for ch in value):
        raise ValueError('Invalid control character')
    return value


def _validate(rows: object) -> list[dict]:
    if not isinstance(rows, list) or not rows or len(rows) > MAX_DOCUMENTS:
        raise ValueError('Invalid library')
    result = []
    seen = set()
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError('Invalid record')
        ident = _field(row, 'id', 80)
        if re.fullmatch(r'[a-z0-9][a-z0-9_-]{1,79}', ident) is None or ident in seen:
            raise ValueError('Invalid identity')
        seen.add(ident)
        safe = {'id': ident}
        for key, maximum in (('title', 160), ('text', 1800), ('source_ref', 300), ('source_date', 10)):
            safe[key] = _field(row, key, maximum)
        if re.fullmatch(r'\d{4}-\d{2}-\d{2}', safe['source_date']) is None:
            raise ValueError('Invalid source date')
        datetime.date.fromisoformat(safe['source_date'])
        if row.get('source_type') not in SOURCE_TYPES or row.get('status') not in STATUSES:
            raise ValueError('Invalid provenance')
        topics = row.get('topics')
        if not isinstance(topics, list) or len(topics) > 12 or any(not isinstance(t, str) or not t.strip() or len(t) > 50 or any(ord(c) < 32 or 0xD800 <= ord(c) <= 0xDFFF for c in t) for t in topics):
            raise ValueError('Invalid topics')
        if ('audience' in row and row['audience'] != 'owner_private') or ('reference_only' in row and row['reference_only'] is not True) \
                or ('current_state_verified' in row and row['current_state_verified'] is not False):
            raise ValueError('Invalid scope')
        safe.update(topics=list(dict.fromkeys(topics)), source_type=row['source_type'], status=row['status'],
                    audience='owner_private', reference_only=True, current_state_verified=False)
        if CREDENTIAL.search(' '.join([safe['title'], safe['text'], safe['source_ref'], *safe['topics']])):
            raise ValueError('Credential in source')
        result.append(safe)
    return result


def _load() -> list[dict]:
    # A fixed private path, no user-selected filename and no symlink traversal.
    fd = os.open(ROOT / 'project-references.json', os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0))
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_BYTES or info.st_mode & 0o022:
            raise ValueError('Unsafe reference file')
        raw = stream.read(MAX_BYTES + 1)
        if len(raw) > MAX_BYTES:
            raise ValueError('Reference size limit')
    return _validate(json.loads(raw.decode('utf-8')))


def _index(rows: list[dict] | None) -> list[dict]:
    index = []
    for project, name in PROJECTS.items():
        matching = [row for row in rows or [] if 'project:' + project in row['topics']]
        index.append({'id': project, 'name': name,
                      'reference_count': len(matching) if rows is not None else None,
                      'source_date': max((r['source_date'] for r in matching), default=None)})
    return index


def context(query: str) -> dict:
    """Read data for the authenticated owner, with no writes or external calls."""
    base = {'version': VERSION, 'audience': 'owner_private', 'reference_only': True,
            'current_state_verified': False, 'grants_execution_rights': False,
            'coverage': 'recorded_project_references_only'}
    try:
        rows = _load()
    except (OSError, ValueError, UnicodeError, TypeError, OverflowError, RecursionError):
        return {**base, 'source_status': 'unavailable', 'reference_count': None,
                'projects': _index(None), 'relevant_references': []}
    # MiniApp context precedes this delimiter; rank against the actual final request.
    prompt = query.rsplit('END_APPLICATION_CONTEXT', 1)[-1] if isinstance(query, str) else ''
    prompt = normalize(prompt[-4000:])
    terms = list(dict.fromkeys(t for t in re.findall(r'[^\W_]+', prompt) if 1 < len(t) <= 80 and t not in STOPWORDS))[:48]
    projects = {key for key, aliases in ALIASES.items() if any(normalize(alias) in prompt for alias in aliases)}
    ranked = []
    for row in rows:
        head = normalize(row['title'] + ' ' + ' '.join(row['topics']))
        body = normalize(row['text'])
        score = sum(5 if term in head else 1 if term in body else 0 for term in terms)
        score += sum(30 for project in projects if 'project:' + project in row['topics'])
        if score:
            ranked.append((score, row))
    ranked.sort(key=lambda pair: (-pair[0], pair[1]['id']))
    snippets = [{**{key: value for key, value in row.items() if key != 'topics'}, 'text': row['text'][:850]}
                for _, row in ranked[:4]]
    return {**base, 'source_status': 'available', 'reference_count': len(rows),
            'projects': _index(rows), 'relevant_references': snippets}


def instruction(query: str) -> str:
    """Explicit data boundary; source text is never an execution-policy instruction."""
    return ('\nOwner-private project reference DATA follows. These are dated records, not current health, completed work, '
            'or tool permission. Use the relevant current tools before relying on volatile facts. '
            'An unavailable source means unknown. Never expose this context in customer/public output.\n'
            'BEGIN_OWNER_PROJECT_REFERENCE_DATA\n' + json.dumps(context(query), ensure_ascii=False, separators=(',', ':'))
            + '\nEND_OWNER_PROJECT_REFERENCE_DATA\n')
