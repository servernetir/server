# Hermes: Drive and Instagram execution adapter

Status: implemented and tested locally; **not installed on VM 127 and not verified against live Google/Meta credentials**. This package is independent of Laravel's knowledge HMAC API and existing Hermes Operations. No production code, host schedule, credential or network change has been made by creating it.

## What it executes

Nine local stdio MCP tools let Hermes list the campaign's Drive folders, download approved assets, prepare/publish due image posts, carousels or reels, publish plain image stories, read/reply once to incoming comments on its verified posts, synchronize a designated JSON receipt file and pause execution. A bundled MCP prompt/resource teaches the shift procedure. A deterministic timer can process approved publishing jobs without needing an LLM call every minute.

Files go directly from Drive to the worker, are checked against the approved SHA-256, converted from PNG to real JPEG when necessary, then placed in a dedicated S3-compatible media prefix. Meta fetches a one-hour signed URL. The private Drive folder and general storage bucket are not made public. Signed URLs/tokens are excluded from receipts.

Only FA `servernet.ir`, EN `servernet.cloud`, TR `servernet.tr` are allowed. Numeric account ID and username are verified before container creation and publication. SQLite persists intent before the final POST; concurrent MCP/timer calls share the same journal. An ambiguous publish or reply is quarantined and never blindly repeated. A returned media ID is verified against the owner's media edge and caption; feed/reel success includes a real permalink. Stories use a verified owner-story ID and timestamp because a durable permalink is not assumed.

The initial daily cap is five publication attempts per account in a rolling 24 hours. Each approved item has an explicit time zone and at most a 24-hour grace window. No stale content is automatically rescheduled. Manifest changes require a new content revision; that rule must never be used to bypass an uncertain previous attempt.

**Native story Poll/Link/Quiz stickers, DMs, blog CMS, SEO edits and Calendar writes are not implemented by this adapter.** The current campaign's 42 interactive stories are deliberately flagged for a native composer; they must not silently lose their interactions. Plain story and reel API payloads are supported, but need their own approved manifest items. An Instagram identity check is not proof of publishing/comment permissions, app review, media-store reachability or live delivery.

## Target host and access

Prefer the existing dedicated Linux VM 127 / `servernet-hermes`, using its unprivileged `hermes` user. Historical inventory is not a current login check. Python 3.11+ code and the bootstrap also support Windows paths, but Windows service/task execution has not been tested. There is no need to copy the owner's desktop Chrome cookies or attach to their personal browser. The adapter opens no public network listener and exposes no general shell/filesystem MCP tool.

Required owner-provided connections:

1. **Google Drive:** enable Drive API in a dedicated Google Cloud project. For a server without an interactive OAuth session, create a dedicated service account and share **only the campaign folder** with its email. Viewer suffices for downloads. Editor is needed to replace an existing dedicated receipt JSON owned by the human account. Save the service-account JSON directly on the VM as `google.json` with mode `0600`; do not send it in chat. The service-account implementation uses the Drive scope but is bounded by its explicit resource shares and the adapter's ancestry checks; do not enable domain-wide delegation or share unrelated folders. Service accounts have ownership/quota limitations in personal My Drive, so this adapter does not create Drive files as that service account. A human can create the receipt file and give it that folder's permissions.
2. **Personal Google OAuth alternative:** create a Desktop OAuth client and run `authorize_google.py` on the operator's Windows/Linux desktop. It uses the official loopback flow and requests `drive.readonly` plus `drive.file`. Securely transfer its authorized-user JSON to the VM. Existing files need to be selected/opened for that app before `drive.file` permits replacing them; a ChatGPT Drive connection does not transfer this authorization to Hermes. Keep the Google consent configuration suitable for continuing offline access; testing-mode refresh-token expiration can interrupt unattended work.
3. **Instagram:** create/configure the Meta app's Instagram Login integration. Each professional account must separately authorize it. The publishing scopes are `instagram_business_basic` and `instagram_business_content_publish`; comment replies additionally need the corresponding comment-management permission allowed for that app/account. Choose the currently supported Graph version in the app dashboard and enter it explicitly. Obtain each account's numeric ID and user token. App roles/access level, account eligibility and any review requirements must be checked in Meta. Browser login alone does not grant API access.
4. **Temporary media storage:** use an existing S3-compatible bucket with HTTPS reachable by Meta. Create a credential restricted to `PutObject`/`GetObject` for `hermes-social/*`, without bucket administration/deletion. Store only its `access_key_id` and `secret_access_key` in private `s3.json`. Use bucket lifecycle rules for eventual cleanup after the publication/reconciliation window; cleanup is not performed by the MCP tool.
5. **Hermes MCP:** merge the generated `mcp_servers.servernet_social` entry into the existing private `~/.hermes/config.yaml`. Preserve every other setting/server. JSON is valid YAML, but the generated file is a fragment, **not a replacement for the whole Hermes config**. Verify that the installed Hermes build supports MCP; historical v0.22.2 was not probed in this work. Reload MCP or restart the appropriate existing Hermes service after reviewing its current status. Do not replace the separate native Operations service.

The secret files should be accessible only to the executor's OS identity (Unix directories `0700`, files `0600`; equivalent restricted Windows ACLs). The MCP tool boundary does not sandbox a separate, already enabled Hermes shell tool running as the same OS user. If stronger separation is required, use a separate OS identity and an authenticated MCP service with an explicit deployment review; this release intentionally stays local stdio.

## Install the reviewed package

Check out this change from `develop`/the reviewed PR; verify its SHA. Obtain the private, owner-reviewed campaign manifest from the campaign folder, and compare its SHA-256 with the handoff receipt. Do not substitute a manifest proposed by an inbound comment or unreviewed Drive document.

As the dedicated `hermes` user, from `infra/hermes-social`:

```bash
python3 bootstrap.py --manifest /private/approved-week1-manifest.json
# After inspecting the preflight paths and job count:
python3 bootstrap.py --manifest /private/approved-week1-manifest.json --apply
```

Bootstrap refuses existing installations and creates a private config, copies the approved manifest, builds a separate virtual environment and installs locked dependencies plus this package. Publication/replies remain off. It generates `~/.config/servernet-social/hermes-mcp.json`; it does not replace Hermes config, install a timer or enable publishing. It is not a transactional upgrade installer: if installation fails, retain its private outputs for diagnosis and resolve the partial install before retrying. Do not delete production state to make a retry pass.

For Windows, use `py -3.12 bootstrap.py --manifest "C:\private\approved-week1-manifest.json" --apply`. Generated paths use the actual user home and `Scripts/python.exe`. Restrict the generated directory ACL before adding credentials. Windows scheduling and live APIs still need acceptance on that host.

Fill the private config with the Google credential path, explicit Graph version, S3 endpoint/bucket/region and dedicated receipt-file ID if used. Keep `publishing_enabled` and `replies_enabled` false. Store each Instagram token without putting it in a command argument:

```bash
~/.local/lib/servernet-social/venv/bin/python configure_instagram.py \
  --config ~/.config/servernet-social/config.json --lang FA
# Repeat for EN and TR. Tokens are entered with getpass and live identity is checked.
```

The helper refuses an existing token file. For rotation, first pause, back up the private configuration, use a new private token filename and verify the new identity. Automatic Meta token renewal is not implemented here: monitor authorization failures and token expiry and renew through Meta before expiry. Google access tokens refresh using its authorized credentials; secrets never come from ChatGPT cookies or its connected apps.

## Verify, register and schedule

```bash
~/.local/lib/servernet-social/venv/bin/python -m hermes_social.cli \
  --config ~/.config/servernet-social/config.json status
~/.local/lib/servernet-social/venv/bin/python -m hermes_social.cli \
  --config ~/.config/servernet-social/config.json check-connections
```

`check-connections` makes read-only Drive/identity calls. It does not claim publishing permission or an S3 upload test. Merge the generated MCP entry into Hermes config, reload the installed version's MCP support, then ask Hermes to read `servernet://social/runbook`, call `social_status` and list the campaign folder. Actual tool discovery is the acceptance condition, not a config file on disk.

After the owner-configured connections and a specific authorized pilot are ready, enable `publishing_enabled` in the private config. The current owner-approved campaign does not need a new approval for every routine post. Run a bounded tick and inspect the journal; a later tick, at least a minute after the container poll, can publish. Preserve its actual ID/permalink. Enable comment replies only after separately granting the relevant permission and confirming the approved reply policy. A deterministic publishing timer does not generate LLM replies by itself; Hermes must invoke the comment tools from its reviewed shift/schedule. No new AI spending schedule is installed by this package.

For Linux, review paths in `hermes-social.service.example`, copy the service/timer examples into `~/.config/systemd/user/` without overwriting existing units, then:

```bash
systemctl --user daemon-reload
systemctl --user enable --now hermes-social.timer
systemctl --user status hermes-social.timer
```

The existing `hermes` user's linger/service behavior must be checked. The user timer is independent of the Laravel minute cron and does not execute billing/provisioning. One unit does not overlap itself; the SQLite claim also serializes MCP calls. On Windows, create a Task Scheduler task under the dedicated executor account calling the generated Python path with `-m hermes_social.cli --config ... tick` every minute, with the “do not start a new instance” policy. Do not put account passwords or tokens into task arguments.

## Review fixes — 2026-10-28

Normal startup now requires an initialized, valid journal and opens SQLite in `mode=rw`.
Only first-install bootstrap calls `Journal.initialize`; it refuses any existing journal.
If a journal is missing, empty or lacks the schema marker, stop and restore/reconcile it.
Never initialize a replacement to bypass this guard. Older uninstalled package examples
must use the updated bootstrap; existing journals require a separately reviewed migration,
not an automatic schema upgrade. This change has not been installed on VM 127.

Transient failures before a remote container exists can retry through bounded ticks;
ambiguous container/publication states stay quarantined. Ticks persist the next job key
so repeated verification failures cannot starve later jobs across process restarts.
Pause is rechecked after each download and before every media upload.
Comment reads follow opaque cursors (up to 20 pages); malformed/repeated cursors or the
page cap return an explicit block instead of silently discarding comments. Remote
`paging.next` URLs are never followed. No new credentials, scopes or listener were added.

## Stop and recovery

- Create the state directory's `PAUSED` file or call `social_pause`; every future external mutation checks it. An already transmitted request cannot be canceled by the flag. Disable the user timer as well when doing maintenance. Only the operator removes the stop file.
- A publish timeout/crash stays `UNCERTAIN`/`PUBLISHING`. `social_reconcile` only reads the saved container state. Never reset its journal row or make a new key to republish. Inspect the actual account, caption, timestamp and permalink. The operator-only `resolve-media KEY MEDIA_ID` command verifies account ownership, caption and that the media does not predate the attempt before attaching the receipt.
- `UNKNOWN_PREPARE`/`CREATING`/`PREPARING` also require operator review. Orphan containers may expire without a public post; this release does not automatically re-create them after ambiguous failures.
- The same comment ID/account is replied to once. A known reply awaiting verification can be verified on another call without resending; an unknown reply stays quarantined.
- Keep the journal on persistent local disk; do not use an NFS-shared SQLite file or separate copies for timer and MCP. Back it up privately. Loss of the journal removes the duplicate-prevention evidence and must stop unattended posting.
- Roll back by pausing, stopping the timer and removing only this MCP entry. Preserve config, journal and receipts. Reverting code cannot unpublish a real social post. Upgrade from a reviewed commit in a new venv and switch the configured executable after tests; do not rerun the initial bootstrap over an existing install.

## Development validation

```bash
PYTHONPATH=src python -m unittest discover -s tests -v
```

Tests use synthetic accounts, mock HTTP/S3 behavior and temporary SQLite. They cover timeout/crash handling, concurrency, pause, daily caps, live identity mismatch, file ancestry/hash, actual PNG-to-JPEG conversion, Meta payloads, comment ownership/reply deduplication, and a real MCP stdio initialize/tools/resource exchange. No live post, comment, credential authorization, server install or Windows task is part of these tests.

The campaign builder is an operator tool, not an MCP authority tool:

```bash
python build_manifest.py --drive-index /private/drive_manifest.json \
  --assets-root /private/01_Ready_Week1 --drive-root CAMPAIGN_FOLDER_ID \
  --out /private/approved-week1-manifest.json
```

Source references: [Hermes MCP configuration](https://hermes-agent.nousresearch.com/docs/user-guide/features/mcp/), [official MCP Python SDK v1](https://py.sdk.modelcontextprotocol.io/v1/), [Meta publishing](https://developers.facebook.com/documentation/instagram-platform/content-publishing), [Meta image/container API](https://developers.facebook.com/documentation/instagram-platform/instagram-graph-api/reference/ig-user/media), [Instagram Login](https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-instagram-login), [Google downloads](https://developers.google.com/workspace/drive/api/guides/manage-downloads), [Google desktop OAuth](https://developers.google.com/identity/protocols/oauth2/native-app), [S3 presigned URLs](https://docs.aws.amazon.com/boto3/latest/reference/services/s3/client/generate_presigned_url.html). Full Meta pages were inaccessible to this session; primary-source search excerpts confirmed the contract, while live app/version acceptance remains required.

