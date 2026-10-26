"""Owner console helper: receive a token privately, verify identity, then save it."""
import argparse
from getpass import getpass
import json
import os
from pathlib import Path
import sys
import tempfile

from hermes_social.clients import Instagram
from hermes_social.policy import ACCOUNTS, Blocked, Policy

parser = argparse.ArgumentParser()
parser.add_argument("--config", required=True)
parser.add_argument("--lang", choices=list(ACCOUNTS), required=True)
args = parser.parse_args()
policy = Policy(args.config)
lang = args.lang
account = policy.data["instagram"][lang]
target = Path(account["token_file"]).expanduser().resolve()
if target.exists():
    raise SystemExit("Token file already exists. Review rotation separately; it was not overwritten.")
account_id = input("Numeric Instagram ID for @" + ACCOUNTS[lang] + ": ").strip()
token = getpass("Instagram user access token (hidden; never paste into chat): ").strip()
if not account_id.isdigit() or not token or "\n" in token:
    raise SystemExit("Invalid ID/token.")
fd, temporary = tempfile.mkstemp(dir=target.parent, prefix=".verify-token-")
try:
    with os.fdopen(fd, "w") as file:
        file.write(token)
    os.chmod(temporary, 0o600)
    original = dict(account)
    policy.data["instagram"][lang] = {"id": account_id, "username": ACCOUNTS[lang], "token_file": temporary}
    Instagram(policy, lang).verify_account()
    os.replace(temporary, target)
    policy.data["instagram"][lang] = {**original, "id": account_id}
    output_fd, output_name = tempfile.mkstemp(dir=policy.path.parent, prefix=".config-update-")
    with os.fdopen(output_fd, "w") as file:
        file.write(json.dumps(policy.data, ensure_ascii=False, indent=2))
    os.chmod(output_name, 0o600)
    os.replace(output_name, policy.path)
    print("Identity verified and token saved for @" + ACCOUNTS[lang] + ". Publication remains separately gated.")
except Blocked as error:
    print("Blocked: " + str(error), file=sys.stderr)
    raise SystemExit(2)
finally:
    if Path(temporary).exists():
        Path(temporary).unlink()
