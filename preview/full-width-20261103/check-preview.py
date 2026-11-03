#!/usr/bin/env python3
"""Focused static checks for this isolated preview; no browser or network calls.

This checks source contracts, not rendered contrast, layout, focus, or real flows.
Run from any directory: python preview/full-width-20261103/check-preview.py
"""
from collections import Counter
from html.parser import HTMLParser
import ipaddress
import json
from pathlib import Path
import re
import sys
from urllib.parse import unquote, urlsplit


ROOT = Path(__file__).resolve().parent
VOID = set("area base br col embed hr img input link meta param source track wbr".split())


class Element:
    def __init__(self, tag, attrs, line, parent=None):
        self.tag, self.attrs, self.line, self.parent = tag, dict(attrs), line, parent
        self.children, self.text = [], []

    def words(self):
        if self.attrs.get("aria-hidden") == "true" or self.tag == "svg":
            return ""
        return " ".join(self.text + [child.words() for child in self.children]).strip()


class Document(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.elements, self.stack = [], []

    def handle_starttag(self, tag, attrs):
        node = Element(tag, attrs, self.getpos()[0], self.stack[-1] if self.stack else None)
        self.elements.append(node)
        if node.parent:
            node.parent.children.append(node)
        if tag not in VOID:
            self.stack.append(node)

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        if tag not in VOID:
            self.handle_endtag(tag)

    def handle_endtag(self, tag):
        for index in range(len(self.stack) - 1, -1, -1):
            if self.stack[index].tag == tag:
                del self.stack[index:]
                return

    def handle_data(self, text):
        if self.stack:
            self.stack[-1].text.append(text)


errors, warnings = [], []


def require(condition, message):
    if not condition:
        errors.append(message)


def local_reference(reference, owner):
    """Resolve real assets and prevent escape from the self-contained preview."""
    parsed = urlsplit(reference)
    require(not parsed.scheme and not parsed.netloc, f"Remote resource in {owner.name}: {reference}")
    if parsed.scheme or parsed.netloc or not parsed.path:
        return
    resolved = (owner.parent / unquote(parsed.path)).resolve()
    require(resolved.is_relative_to(ROOT), f"Asset escapes preview: {reference}")
    require(resolved.is_file(), f"Missing asset in {owner.name}: {reference}")


html_path = ROOT / "index.html"
require(html_path.is_file(), "Missing index.html")
if not html_path.is_file():
    print("FAIL: Missing index.html")
    sys.exit(1)
markup = html_path.read_text(encoding="utf-8")
document = Document()
document.feed(markup)
elements = document.elements
ids = Counter(node.attrs["id"] for node in elements if node.attrs.get("id"))
by_id = {node.attrs["id"]: node for node in elements if node.attrs.get("id")}
for identifier, count in ids.items():
    require(count == 1, f"Duplicate HTML id: {identifier}")


def accessible_name(node):
    if node.attrs.get("aria-label", "").strip():
        return node.attrs["aria-label"]
    if node.attrs.get("aria-labelledby"):
        return " ".join(by_id[identifier].words() for identifier in node.attrs["aria-labelledby"].split()
                        if identifier in by_id)
    return node.words()


roots = [node for node in elements if node.tag == "html"]
require(len(roots) == 1 and roots[0].attrs.get("lang") == "fa" and roots[0].attrs.get("dir") == "rtl",
        "Initial document must declare Persian RTL")
require(any(node.tag == "meta" and node.attrs.get("name") == "robots" and
            {"noindex", "nofollow"}.issubset(set(node.attrs.get("content", "").replace(" ", "").split(",")))
            for node in elements), "Missing preview noindex,nofollow metadata")
require(any(node.tag == "meta" and node.attrs.get("name") == "viewport" and
            "width=device-width" in node.attrs.get("content", "") for node in elements), "Missing fluid viewport metadata")
require(sum(node.tag == "main" for node in elements) == 1, "Expected one main landmark")
require(any(node.tag == "a" and node.attrs.get("href") == "#main-content" for node in elements), "Missing main skip link")

views = {node.attrs["data-view-panel"] for node in elements if "data-view-panel" in node.attrs}
require(views == {"dashboard", "hosting", "detail"}, "Initial preview must contain dashboard, hosting, detail views")
labels = {node.attrs["for"] for node in elements if node.tag == "label" and "for" in node.attrs}
buttons = [node for node in elements if node.tag == "button"]
for node in elements:
    attrs = node.attrs
    for name in ("aria-labelledby", "aria-describedby", "aria-controls"):
        for identifier in attrs.get(name, "").split():
            require(identifier in by_id, f"{name} points to missing #{identifier} at line {node.line}")
    if node.tag == "label" and "for" in attrs:
        require(attrs["for"] in by_id, f"Label target missing at line {node.line}")
    if node.tag == "button":
        require(attrs.get("type") == "button", f"Button lacks explicit safe type at line {node.line}")
        require(bool(accessible_name(node)), f"Button lacks an accessible name at line {node.line}")
        require(any(key in attrs for key in ("data-view", "data-demo-action", "data-copy", "data-modal-close", "data-filter", "data-detail-tab", "id")),
                f"Button has no interaction hook at line {node.line}")
    if "data-view" in attrs:
        require(attrs["data-view"] in views, f"Unknown view target at line {node.line}: {attrs['data-view']}")
    if node.tag in {"input", "select", "textarea"}:
        wrapped = node.parent
        while wrapped and wrapped.tag != "label":
            wrapped = wrapped.parent
        require(bool(attrs.get("aria-label") or attrs.get("aria-labelledby") or attrs.get("id") in labels or wrapped),
                f"Unlabelled form field at line {node.line}")
        require(attrs.get("type") not in {"password", "file", "email", "tel"},
                f"Sensitive data input in design preview at line {node.line}")
    require(not any(name.lower().startswith("on") for name in attrs), f"Inline handler at line {node.line}")
    require(node.tag not in {"form", "iframe", "object", "embed", "base"},
            f"Unexpected active/external container <{node.tag}> at line {node.line}")
    if node.tag == "script":
        require(bool(attrs.get("src")), f"Unexpected inline script at line {node.line}")
    if node.tag in {"script", "img", "source", "audio", "video"} and attrs.get("src"):
        local_reference(attrs["src"], html_path)
    if node.tag == "link" and attrs.get("href"):
        local_reference(attrs["href"], html_path)
    if node.tag in {"a", "use"} and attrs.get("href"):
        require(attrs["href"].startswith("#"), f"Navigation outside preview at line {node.line}: {attrs['href']}")
        require(attrs["href"][1:] in by_id, f"Missing fragment target at line {node.line}: {attrs['href']}")
    if attrs.get("role") == "dialog" or node.tag == "dialog":
        require(bool(attrs.get("aria-labelledby") or attrs.get("aria-label")), "Dialog lacks a name")

source_paths = sorted(ROOT.rglob("*.js")) + sorted(ROOT.rglob("*.css")) + [html_path]
require((ROOT / "app.js").is_file(), "Missing app.js")
request_api = re.compile(r"\b(?:fetch|XMLHttpRequest|WebSocket|EventSource|sendBeacon|importScripts)\s*\(|\bserviceWorker\s*\.\s*register\s*\(|\bwindow\s*\.\s*open\s*\(")
doc_networks = [ipaddress.ip_network(value) for value in ("192.0.2.0/24", "198.51.100.0/24", "203.0.113.0/24")]
for path in source_paths:
    source = path.read_text(encoding="utf-8")
    if path.suffix == ".js":
        require(not request_api.search(source), f"Outgoing-request API found in {path.relative_to(ROOT)}")
        require(not re.search(r"\blocation\s*\.\s*(?:assign|replace|href)\b", source),
                f"External navigation mechanism found in {path.relative_to(ROOT)}")
    if path.suffix == ".css":
        require(not re.search(r"@import\b", source), f"CSS import requires explicit review: {path.name}")
        for value in re.findall(r"url\(\s*['\"]?([^)'\"]+)['\"]?\s*\)", source):
            local_reference(value.strip(), path)
    for match in re.findall(r"(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])", source):
        try:
            address = ipaddress.ip_address(match)
        except ValueError:
            require(False, f"Invalid IPv4 value in {path.name}: {match}")
        else:
            require(any(address in network for network in doc_networks), f"Non-documentation IP in {path.name}: {match}")

catalogue_path = ROOT / "locales.js"
catalogue = {}
require(catalogue_path.is_file(), "Missing offline locale catalogue")
if catalogue_path.is_file():
    match = re.search(r"window\.SN_PREVIEW_MESSAGES\s*=\s*(\{[\s\S]*\})\s*;?\s*$", catalogue_path.read_text(encoding="utf-8"))
    require(bool(match), "Locale catalogue must remain a pure JSON assignment")
    if match:
        try:
            catalogue = json.loads(match.group(1))
        except json.JSONDecodeError as error:
            require(False, f"Invalid catalogue JSON: {error}")
    require(set(catalogue) == {"fa", "en", "tr"}, "Catalogue must contain fa/en/tr")
    source_keys = {value for node in elements for name, value in node.attrs.items()
                   if name == "data-i18n" or name.startswith("data-i18n-")}
    if (ROOT / "app.js").is_file():
        source_keys.update(re.findall(r"\bt\(['\"]([^'\"]+)['\"]\)", (ROOT / "app.js").read_text(encoding="utf-8")))
    key_sets = [set(values) for values in catalogue.values() if isinstance(values, dict)]
    require(len(key_sets) == 3 and all(keys == key_sets[0] for keys in key_sets), "Locale keys differ across fa/en/tr")
    for language, values in catalogue.items():
        require(isinstance(values, dict), f"Invalid {language} catalogue")
        if not isinstance(values, dict):
            continue
        require(all(isinstance(value, str) and value.strip() for value in values.values()), f"Empty/non-string translation in {language}")
        missing = sorted(source_keys - set(values))
        require(not missing, f"Missing {language} translations: {', '.join(missing)}")
        if language in {"en", "tr"}:
            leaked = sorted(key for key, value in values.items() if isinstance(value, str) and re.search(r"[\u0600-\u06ff]", value))
            require(not leaked, f"Persian characters in {language} copy: {', '.join(leaked)}")

style_path = ROOT / "styles.css"
if style_path.is_file():
    css = style_path.read_text(encoding="utf-8")
    require("prefers-reduced-motion" in css, "Missing reduced-motion CSS")
    require(":focus-visible" in css, "Missing keyboard focus CSS")
    require("safe-area-inset-bottom" in css, "Missing mobile bottom safe-area CSS")
    require("@media" in css and "minmax(0,1fr)" in css.replace(" ", ""), "Missing responsive fluid grid CSS")
    small_sizes = sorted({float(value) for value in re.findall(r"font-size:\s*(\d*\.?\d+)px", css) if float(value) < 11})
    if small_sizes:
        warnings.append("Source still contains font sizes below 11px; inspect their rendered use: " + ", ".join(map(str, small_sizes)))

print(f"Checked {len(elements)} HTML elements, {len(buttons)} buttons, {len(ids)} IDs, {len(source_paths)} source files.")
if catalogue:
    print("Locale keys: " + ", ".join(f"{language}={len(values)}" for language, values in catalogue.items()))
for warning in warnings:
    print("REVIEW:", warning)
for error in errors:
    print("FAIL:", error)
if errors:
    print(f"Static source checks failed: {len(errors)} issue(s).")
    sys.exit(1)
print("PASS: Static source contracts only. Browser layout, keyboard/focus, contrast, locales and interactions remain separate verification.")
