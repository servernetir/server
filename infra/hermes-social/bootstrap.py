"""Install this reviewed adapter as the unprivileged Hermes user. No secrets in arguments."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import venv

parser = argparse.ArgumentParser()
parser.add_argument("--manifest", required=True, help="Owner-reviewed campaign JSON")
parser.add_argument("--apply", action="store_true")
args = parser.parse_args()
source = Path(__file__).resolve().parent
manifest = Path(args.manifest).expanduser().resolve()
if not manifest.is_file():
    raise SystemExit("Manifest is missing.")
data = json.loads(manifest.read_text())
home = Path.home()
settings = home / ".config" / "servernet-social"
install = home / ".local" / "lib" / "servernet-social"
state = home / ".local" / "share" / "servernet-social" / "state"
config_path = settings / "config.json"
if config_path.exists() or install.exists() or (settings / "manifest.json").exists():
    raise SystemExit("Existing installation/config found; do not overwrite it. Use the documented upgrade procedure.")
if not args.apply:
    print(json.dumps({"mode": "preflight", "writes": 0, "settings": str(settings),
                      "install": str(install), "approved_jobs": len(data["jobs"])}))
    raise SystemExit(0)
if os.name != "nt" and os.geteuid() == 0:
    raise SystemExit("Run as the dedicated hermes user, not root.")
for folder in (settings, install, state):
    folder.mkdir(parents=True, exist_ok=True, mode=0o700)
    if os.name != "nt":
        folder.chmod(0o700)
config = json.loads((source / "config.example.json").read_text())
config.update(state_dir=str(state), manifest_path=str(settings / "manifest.json"),
              drive_root_folder_id=data["drive_root_folder_id"], google_credentials_file=str(settings / "google.json"))
for lang, account in config["instagram"].items():
    account["token_file"] = str(settings / ("instagram-" + lang.lower() + ".token"))
config["media_store"]["credentials_file"] = str(settings / "s3.json")
config_path.write_text(json.dumps(config, ensure_ascii=False, indent=2))
config_path.chmod(0o600)
target_manifest = settings / "manifest.json"
shutil.copyfile(manifest, target_manifest)
target_manifest.chmod(0o600)
environment = install / "venv"
venv.create(environment, with_pip=True)
python = environment / ("Scripts/python.exe" if os.name == "nt" else "bin/python")
subprocess.run([str(python), "-m", "pip", "install", "-r", str(source / "requirements.lock")], check=True)
subprocess.run([str(python), "-m", "pip", "install", "--no-deps", str(source)], check=True)
snippet = {"mcp_servers": {"servernet_social": {
    "command": str(python), "args": ["-m", "hermes_social.cli", "--config", str(config_path), "serve"],
    "timeout": 180, "connect_timeout": 30, "supports_parallel_tool_calls": False,
    "tools": {"include": ["social_status", "drive_list_folder", "social_prepare", "social_tick",
                          "social_reconcile", "social_read_comments", "social_reply_comment",
                          "social_sync_receipts", "social_pause"]}}}}
snippet_path = settings / "hermes-mcp.json"
snippet_path.write_text(json.dumps(snippet, indent=2))
snippet_path.chmod(0o600)
print(json.dumps({"installed_adapter": True, "hermes_config_fragment": str(snippet_path),
                  "publishing_enabled": False, "mcp_registered": False, "timer_installed": False}))
