"""Compile the owner's existing campaign files; never exposed as an MCP tool."""
import argparse
import hashlib
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

parser = argparse.ArgumentParser()
parser.add_argument("--drive-index", required=True)
parser.add_argument("--assets-root", required=True)
parser.add_argument("--drive-root", required=True)
parser.add_argument("--campaign-data", help="Optional reviewed page copy for descriptive image alt text")
parser.add_argument("--out", required=True)
args = parser.parse_args()
index = json.loads(Path(args.drive_index).read_text())
assets = Path(args.assets_root).resolve()
out = Path(args.out).resolve()
if out.exists():
    raise SystemExit("Output already exists; use a new revision filename.")
mapping = {(x["date"], x["lang"], x["name"]): x["id"] for x in index["files"]}
copy_by_date = {}
if args.campaign_data:
    copy_by_date = {x["date"]: x for x in json.loads(Path(args.campaign_data).read_text())["data"]}
accounts = {"FA": ("servernet.ir", "Asia/Tehran", "20:30"),
            "EN": ("servernet.cloud", "Europe/Berlin", "17:30"),
            "TR": ("servernet.tr", "Asia/Istanbul", "20:00")}
jobs = []
for day in sorted(assets.iterdir()):
    if not day.is_dir():
        continue
    for lang, (username, zone, slot) in accounts.items():
        folder = day / lang
        start = datetime.fromisoformat(day.name + "T" + slot).replace(tzinfo=ZoneInfo(zone))
        def asset(path):
            alt = "ServerNet " + lang + " " + day.name + " " + path.stem
            page_copy = copy_by_date.get(day.name, {}).get(lang, {})
            pages = page_copy.get("slides" if path.stem.startswith("post-") else "stories", [])
            number = int(path.stem.split("-")[-1]) - 1
            if pages:
                alt = ". ".join(pages[number][:3]).replace("\n", " ")
            return {"drive_id": mapping[(day.name, lang, path.name)],
                    "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
                    "mime_type": "image/png", "alt": alt}
        images = [asset(path) for path in sorted(folder.glob("post-*.png"))]
        if not images:
            raise SystemExit("Missing feed images: " + str(folder))
        base = {"key": "sn-" + day.name + "-" + lang.lower() + "-feed",
                "lang": lang, "username": username, "kind": "CAROUSEL" if len(images) > 1 else "IMAGE",
                "publish_at": start.isoformat(), "expires_at": (start.astimezone(timezone.utc) + timedelta(hours=24)).isoformat(),
                "caption": (folder / "caption.txt").read_text().strip(), "assets": images}
        jobs.append(base)
        for number, path in enumerate(sorted(folder.glob("story-*.png"))):
            when = start + timedelta(minutes=-45 if number == 0 else 30)
            jobs.append({**base, "key": base["key"].replace("-feed", "-" + path.stem), "kind": "STORY",
                         "publish_at": when.isoformat(), "expires_at": (when.astimezone(timezone.utc) + timedelta(hours=24)).isoformat(),
                         "caption": "", "assets": [asset(path)],
                         "native_stickers": ["owner_plan_requires_native_interaction_or_link"]})
result = {"schema_version": 1, "campaign_id": "sn_social_20261026", "drive_root_folder_id": args.drive_root,
          "authority_reference": "Owner's explicit ServerNet social campaign request, 2026-10-26",
          "jobs": jobs}
out.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
out.write_text(json.dumps(result, ensure_ascii=False, indent=2))
out.chmod(0o600)
print(json.dumps({"jobs": len(jobs), "feed_jobs": sum(x["kind"] != "STORY" for x in jobs),
                  "native_story_jobs": sum(x["kind"] == "STORY" for x in jobs),
                  "sha256": hashlib.sha256(out.read_bytes()).hexdigest()}))
