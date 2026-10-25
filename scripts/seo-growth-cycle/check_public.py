#!/usr/bin/env python3
"""Read-only, bounded public HTML checks for the ServerNet growth cycle.

No login, cookies, redirects, forms, browser actions, CMS writes or deployment.
Exit 0: checks pass; 1: HTTP/HTML mismatch; 2: incomplete network observation.
This is not an indexation, ranking, accessibility or conversion test.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import time
import uuid
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

ORIGIN = "https://servernet.cloud"
MAX_BYTES = 2 * 1024 * 1024
DEFAULT_TARGETS = Path(__file__).with_name("targets.json")


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, new_url):
        return None


class Page(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.lang = ""
        self.titles = []
        self.h1_count = 0
        self.canonicals = []
        self.alternates = {}
        self.robots = []
        self.schemas = []
        self.ids = set()
        self._title = None
        self._schema = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "html":
            self.lang = attrs.get("lang", "")
        if attrs.get("id"):
            self.ids.add(attrs["id"])
        if tag == "h1":
            self.h1_count += 1
        if tag == "title":
            self._title = []
        if tag == "link":
            rel = attrs.get("rel", "").lower().split()
            if "canonical" in rel:
                self.canonicals.append(attrs.get("href", ""))
            if "alternate" in rel and attrs.get("hreflang"):
                self.alternates.setdefault(attrs["hreflang"], []).append(attrs.get("href", ""))
        if tag == "meta" and attrs.get("name", "").lower() in ("robots", "googlebot"):
            self.robots.append(attrs.get("content", ""))
        if tag == "script" and attrs.get("type", "").lower() == "application/ld+json":
            self._schema = []

    def handle_data(self, data):
        if self._title is not None:
            self._title.append(data)
        if self._schema is not None:
            self._schema.append(data)

    def handle_endtag(self, tag):
        if tag == "title" and self._title is not None:
            self.titles.append("".join(self._title).strip())
            self._title = None
        if tag == "script" and self._schema is not None:
            self.schemas.append("".join(self._schema))
            self._schema = None


def path_url(path):
    parts = urlsplit(path)
    if (parts.scheme or parts.netloc or parts.query or parts.fragment or not path.startswith("/")
            or path.startswith("//") or "\\" in path or any(x in path for x in ("\r", "\n"))
            or "/.." in path or "/./" in path or "%" in path):
        raise ValueError("Targets must be plain absolute public paths without query or fragment")
    if not re.fullmatch(r"/(?:en/|tr/)?(?:gpu|vps/hourly|hosting/python|cloud/(?:n8n|object-storage|block-storage)|blog/[a-z0-9-]+)", path):
        raise ValueError("Target is outside the reviewed public page families")
    return ORIGIN + path


def load_targets(path):
    data = json.loads(path.read_text(encoding="utf-8"))
    targets = data.get("targets", [])
    if data.get("schema_version") != 1 or not 1 <= len(targets) <= 48:
        raise ValueError("Expected schema 1 and 1–48 curated public targets")
    seen = set()
    for row in targets:
        url = path_url(row["path"])
        if url in seen or row.get("language") not in ("fa", "en", "tr"):
            raise ValueError("Duplicate target or unsupported language")
        prefix = "/" if row["language"] == "fa" else "/" + row["language"] + "/"
        if row["language"] == "fa" and row["path"].startswith(("/en/", "/tr/")):
            raise ValueError("Persian target has a foreign-language prefix")
        if not row["path"].startswith(prefix):
            raise ValueError("Language does not match path prefix")
        for lang, expected in row.get("alternates", {}).items():
            if lang not in ("fa", "en", "tr", "x-default"):
                raise ValueError("Unsupported alternate language")
            path_url(expected)
        seen.add(url)
    return targets


def inspect_html(body, target, headers=None):
    page = Page()
    page.feed(body.decode("utf-8", errors="strict"))
    page.close()
    url = path_url(target["path"])
    issues = []
    if len(page.titles) != 1 or not page.titles[0]:
        issues.append("title_missing_or_duplicate")
    if page.h1_count != 1:
        issues.append("h1_count")
    if page.lang != target["language"]:
        issues.append("language")
    if len(page.canonicals) != 1 or urljoin(url, page.canonicals[0]) != url:
        issues.append("self_canonical")
    for lang, expected in target.get("alternates", {}).items():
        actual = [urljoin(url, item) for item in page.alternates.get(lang, [])]
        if actual != [path_url(expected)]:
            issues.append("alternate_" + lang)
    robot_values = page.robots + ([headers.get("X-Robots-Tag", "")] if headers else [])
    if any(re.search(r"(?:^|[\s,:])(?:noindex|none)(?:$|[\s,;])", value, re.I) for value in robot_values):
        issues.append("noindex")
    if not page.schemas:
        issues.append("jsonld_missing")
    for source in page.schemas:
        try:
            value = json.loads(source)
            if not isinstance(value, (dict, list)):
                issues.append("jsonld_not_object_or_array")
        except ValueError:
            issues.append("jsonld_invalid")
    for expected_id in target.get("required_ids", []):
        if expected_id not in page.ids:
            issues.append("missing_id:" + expected_id)
    return {"issues": sorted(set(issues)), "title": page.titles[0] if page.titles else None,
            "h1_count": page.h1_count, "html_language": page.lang,
            "canonical_count": len(page.canonicals), "jsonld_count": len(page.schemas),
            "body_sha256": hashlib.sha256(body).hexdigest()}


def fetch_once(target, timeout, run_id):
    url = path_url(target["path"])
    started = time.monotonic()
    result = {"state": "unknown", "http_status": None, "x_cache": None}
    # PageCache::eligible excludes all query strings; verify its BYPASS receipt.
    request = Request(url + "?seo_cycle=" + run_id, headers={"User-Agent": "ServerNet-Growth-Check/1.0",
                                   "Accept": "text/html", "Accept-Encoding": "identity"})
    try:
        # No cookie jar or Authorization; do not follow redirects to any host.
        with build_opener(NoRedirect()).open(request, timeout=timeout) as response:
            result["http_status"] = response.status
            result["x_cache"] = response.headers.get("X-Cache")
            body = response.read(MAX_BYTES + 1)
            if response.status != 200:
                result.update(state="fail", issues=["http_status"])
            elif len(body) > MAX_BYTES:
                result.update(state="unknown", issues=["response_limit"])
            elif response.headers.get_content_type() != "text/html":
                result.update(state="fail", issues=["content_type"])
            else:
                result.update(inspect_html(body, target, response.headers))
                if result["x_cache"] == "STALE":
                    result["issues"].append("stale_response")
                elif result["x_cache"] not in (None, "BYPASS"):
                    result["issues"].append("cache_not_bypassed")
                result["state"] = "fail" if result["issues"] else "pass"
                if result["x_cache"] is None and result["state"] == "pass":
                    result.update(state="unknown", issues=["cache_bypass_unverified"])
    except HTTPError as error:
        result.update(state="fail", http_status=error.code,
                      issues=["unexpected_redirect" if 300 <= error.code < 400 else "http_status"])
    except (URLError, TimeoutError, OSError):
        result.update(state="unknown", issues=["network_observation_failed"])
    except (UnicodeError, ValueError):
        result.update(state="unknown", issues=["html_parse_failed"])
    result["elapsed_ms"] = round((time.monotonic() - started) * 1000)
    return result


def check_target(target, timeout, run_id=None):
    run_id = run_id or uuid.uuid4().hex
    if not re.fullmatch(r"[A-Za-z0-9-]{1,64}", run_id):
        raise ValueError("Run ID must be 1–64 ASCII letters, digits or hyphens")
    attempts = [fetch_once(target, timeout, run_id)]
    if attempts[-1]["state"] != "pass":
        attempts.append(fetch_once(target, timeout, run_id))
    return {"url": path_url(target["path"]), "language": target["language"],
            "state": attempts[-1]["state"], "recovered_on_retry": len(attempts) == 2 and attempts[-1]["state"] == "pass",
            "attempts": attempts}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--targets", type=Path, default=DEFAULT_TARGETS)
    parser.add_argument("--output", type=Path, help="New JSON receipt; an existing file is never overwritten")
    parser.add_argument("--run-id", help="Public non-secret run identifier; default is a random UUID")
    parser.add_argument("--timeout", type=int, default=20, choices=range(5, 31), metavar="5..30")
    args = parser.parse_args()
    targets = load_targets(args.targets)
    run_id = args.run_id or uuid.uuid4().hex
    if not re.fullmatch(r"[A-Za-z0-9-]{1,64}", run_id):
        parser.error("--run-id must be 1–64 ASCII letters, digits or hyphens")
    with ThreadPoolExecutor(max_workers=4) as workers:
        results = list(workers.map(lambda item: check_target(item, args.timeout, run_id), targets))
    counts = {key: sum(item["state"] == key for item in results) for key in ("pass", "fail", "unknown")}
    report = {"schema_version": 1, "run_id": run_id, "checked_at_utc": datetime.now(timezone.utc).isoformat(),
              "request_query": "seo_cycle=" + run_id, "required_cache_state": "BYPASS",
              "scope": "public HTML only; no Google indexation, JavaScript, mobile, conversion or traffic proof",
              "targets_sha256": hashlib.sha256(args.targets.read_bytes()).hexdigest(),
              "summary": counts, "results": results}
    serialized = json.dumps(report, ensure_ascii=False, indent=2) + "\n"
    if args.output:
        with args.output.open("x", encoding="utf-8") as output:
            output.write(serialized)
        print(json.dumps({"output": str(args.output), "summary": counts}))
    else:
        print(serialized, end="")
    return 2 if counts["unknown"] else (1 if counts["fail"] else 0)


if __name__ == "__main__":
    raise SystemExit(main())
