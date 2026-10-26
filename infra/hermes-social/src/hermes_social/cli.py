from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from .policy import Blocked


def main():
    parser = argparse.ArgumentParser(description="ServerNet Hermes social executor")
    parser.add_argument("--config", required=True)
    sub = parser.add_subparsers(dest="command", required=True)
    for command in ("serve", "status", "tick", "check-connections", "sync-receipts"):
        sub.add_parser(command)
    resolve = sub.add_parser("resolve-media")
    resolve.add_argument("key")
    resolve.add_argument("media_id")
    args = parser.parse_args()
    try:
        from .server import make_engine, serve, status
        if args.command == "serve":
            serve(args.config)
            return
        engine = make_engine(args.config)
        if args.command == "status":
            result = status(engine)
        elif args.command == "tick":
            result = engine.tick()
        elif args.command == "sync-receipts":
            result = engine.drive.sync_receipts(engine.journal.snapshot())
        elif args.command == "resolve-media":
            result = engine.resolve_media(args.key, args.media_id)
        else:
            from .policy import ACCOUNTS
            listing = engine.drive.list_files(engine.policy.root)
            result = {"drive": {"folder_verified": True, "first_page_items": len(listing.get("files", []))},
                      "instagram": {}, "publication_permissions_verified": False,
                      "media_store_upload_verified": False}
            for lang in ACCOUNTS:
                result["instagram"][lang] = engine.instagram_factory(lang).verify_account()
        print(json.dumps(result, ensure_ascii=False, indent=2))
    except Blocked as error:
        print(json.dumps({"blocked": str(error)}))
        sys.exit(2)
    except Exception:
        # Deliberately omit raw exception strings, URL query strings and credentials.
        print(json.dumps({"blocked": "configuration_or_adapter_error"}))
        sys.exit(2)


if __name__ == "__main__":
    main()
