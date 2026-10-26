"""Run on the operator's own desktop, then securely transfer the result to Hermes."""
import argparse
import os
from pathlib import Path

from google_auth_oauthlib.flow import InstalledAppFlow

parser = argparse.ArgumentParser()
parser.add_argument("--client", required=True, help="Private Desktop OAuth client JSON")
parser.add_argument("--out", required=True, help="New private authorized-user credential JSON")
args = parser.parse_args()
out = Path(args.out).expanduser().resolve()
if out.exists():
    raise SystemExit("Output exists; do not overwrite credentials without a rotation plan.")
flow = InstalledAppFlow.from_client_secrets_file(args.client, scopes=[
    "https://www.googleapis.com/auth/drive.readonly", "https://www.googleapis.com/auth/drive.file"])
credentials = flow.run_local_server(host="127.0.0.1", port=0, open_browser=True,
                                    authorization_prompt_message="Open the displayed Google authorization URL on this computer: {url}")
out.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
fd = os.open(out, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(fd, "w") as target:
    target.write(credentials.to_json())
print("Google credentials saved privately. Do not paste the file or tokens into chat.")
