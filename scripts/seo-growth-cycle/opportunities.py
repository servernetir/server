#!/usr/bin/env python3
"""Offline, PII-filtered opportunity analysis of first-party Windsor/GSC exports.

CTR is recomputed as clicks/impressions (fraction, not percentage). Scores order
review effort; they are not search volumes, expected clicks or revenue forecasts.
No network access, provider requests, publishing or automatic title changes.
"""
import argparse
from collections import defaultdict
from datetime import date
import json
import math
from pathlib import Path
import re
from urllib.parse import urlsplit

BRAND = re.compile(r"server[\s.-]*net|سرور\s*نت", re.I)
COMMERCIAL = re.compile(r"vps|gpu|hosting|cloud|سرور|هاست|گرافیک|barındırma|kirala", re.I)
PRIVATE_QUERY = re.compile(r"@|\d{7,}")
PUBLIC_PAGE = re.compile(r"^/(?:en/|tr/)?(?:vps|gpu|hosting|cloud)(?:/[a-z0-9-]+)?/?$")


def rows(path):
    value = json.loads(Path(path).read_text(encoding="utf-8"))
    result = value if isinstance(value, list) else value.get("data")
    if not isinstance(result, list):
        raise ValueError("Expected a row array or a Windsor export with data[]")
    return result


def metrics(items):
    clicks = sum(float(row["clicks"]) for row in items)
    impressions = sum(float(row["impressions"]) for row in items)
    return {
        "clicks": clicks, "impressions": impressions,
        "ctr": clicks / impressions if impressions else None,
        "position": sum(float(row["position"]) * float(row["impressions"]) for row in items) / impressions if impressions else None,
    }


def valid_pair(row, floor):
    try:
        url = urlsplit(row["page"])
        position = float(row["position"])
        impressions, clicks = float(row["impressions"]), float(row["clicks"])
        return (url.scheme == "https" and url.netloc == "servernet.cloud"
                and not url.query and not url.fragment and PUBLIC_PAGE.fullmatch(url.path)
                and COMMERCIAL.search(row["query"]) and not BRAND.search(row["query"])
                and not PRIVATE_QUERY.search(row["query"])
                and math.isfinite(position) and position >= 1
                and math.isfinite(impressions) and impressions >= floor
                and math.isfinite(clicks) and 0 <= clicks <= impressions)
    except (KeyError, TypeError, ValueError):
        return False


def opportunities(pairs, minimum_impressions=25):
    accepted = [row for row in pairs if valid_pair(row, minimum_impressions)]
    contenders = defaultdict(set)
    for row in accepted:
        contenders[row["query"]].add(row["page"])
    result = []
    for row in accepted:
        clicks, impressions, position = float(row["clicks"]), float(row["impressions"]), float(row["position"])
        ctr = clicks / impressions
        if position > 20:
            action, priority, weight = "ranking_first", "P2", 0.5
        elif ctr >= 0.05:
            action, priority, weight = "protect_snippet_improve_relevance", "P2", 0.7
        else:
            action, priority, weight = "ranking_and_snippet_review", "P1", 1.0
        result.append({
            "page": row["page"], "query": row["query"],
            "clicks": clicks, "impressions": impressions, "ctr": ctr, "position": position,
            "action": action, "priority": priority,
            "review_score": round(impressions * weight * (1 - ctr), 2),
            "ctr_diagnosis": "requires daily position and device review",
            "competing_urls": sorted(contenders[row["query"]]),
        })
    return sorted(result, key=lambda row: (-row["review_score"], row["page"], row["query"]))


def period(daily, start, end):
    date.fromisoformat(start)
    date.fromisoformat(end)
    if start > end:
        raise ValueError("Period start must not follow end")
    return metrics([row for row in daily if start <= row["date"] <= end])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pairs", required=True)
    parser.add_argument("--daily", required=True, help="Property totals by date, WITHOUT query/page dimensions")
    parser.add_argument("--start", required=True)
    parser.add_argument("--end", required=True)
    parser.add_argument("--previous-start", required=True)
    parser.add_argument("--previous-end", required=True)
    parser.add_argument("--minimum-impressions", type=int, default=25)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    if args.minimum_impressions < 1:
        parser.error("minimum-impressions must be positive")
    daily = rows(args.daily)
    output = {
        "schema_version": 1, "source": "Google Search Console via Windsor.ai",
        "ctr_unit": "fraction", "forecast": None,
        "current_window": [args.start, args.end],
        "previous_window": [args.previous_start, args.previous_end],
        "current": period(daily, args.start, args.end),
        "previous": period(daily, args.previous_start, args.previous_end),
        "minimum_pair_impressions": args.minimum_impressions,
        "notes": [
            "Property totals come only from date-level exports.",
            "Query/page rows omit anonymised queries and use different aggregation; their sum is not a property total.",
            "A review score is a sorting heuristic, not predicted clicks.",
            "Multiple URLs are review candidates, not proof of cannibalization.",
            "Catalog availability, position stability and device mix require separate verification.",
        ],
        "opportunities": opportunities(rows(args.pairs), args.minimum_impressions),
    }
    Path(args.output).write_text(json.dumps(output, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
