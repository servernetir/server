# Relay notification observer

`relay_notifier.py` is a notification-only sidecar for the independent WHM relay recovery controller. It reads controller files without invoking its worker, Journal constructor, adapters or APIs. It never buys, deletes, promotes, reconnects or restarts resources.

Run once per minute with the existing Python interpreter and a private configuration file:

```text
python3 /absolute/private/path/relay_notifier.py --config /absolute/private/path/relay-notifier.json
```

Configuration example (paths must be replaced by verified deployment paths):

```json
{
  "state_dir": "/absolute/private/path/relay-notifier-state",
  "controller_config": "/absolute/private/path/relay-controller.json",
  "controller_state_dir": "/absolute/private/path/controller-state",
  "owner_binding": "stable-owner-binding",
  "delivery_command": ["/absolute/path/to/php", "/absolute/private/path/relay-notify.php"],
  "adapter_sha256": "sha256-of-relay-notify-php",
  "probe_command": ["/absolute/path/to/php", "/absolute/private/path/relay-probe.php"],
  "probe_sha256": "sha256-of-relay-probe-php"
}
```

`controller_config` is optional; it supplies `state_dir` and `policy.suspect_seconds` / `policy.recover_seconds`. An explicit `controller_state_dir` takes precedence for location. With no controller policy, both thresholds are 600 seconds. Configuration and state files must be owned by the execution user and private, directories mode 0700 and files 0600. Symlinked source/state paths are refused.

The existing relay controller writes `status.json` with schema `servernet.relay.controller.v1`, and `controller.sqlite3` table `state(id=1,body JSON)`. This observer opens that database with SQLite `mode=ro` and `query_only`. It reads current `health.quality`, `last_at`, `bad_run_since`, `recovery_since`, active generation/config binding and clock safety. An inactive controller, stale/missing health, unknown quality or unsafe clock produces **monitoring unavailable**, never a confirmed relay failure or recovery. The gateway's ordinary TCP heartbeat alone is insufficient. Promotion clears health, so `phase=HEALTHY` alone never proves recovery.

Fresh observations must be no more than 120 seconds old. Confirmed continuous failure creates an outage event after the controller's suspect threshold. Confirmed continuous success creates a recovery event after its recovery threshold. Initial healthy state is silent. Monitoring unavailable/restored are distinct from relay outage/recovered. There is no repeating reminder.

Delivery adapter reads a bounded sanitized JSON event from stdin. It should load existing Laravel owner/Bale settings and return exactly a JSON object with `sent: true` only after the existing sender has positively acknowledged delivery. Any timeout, exception, malformed result, nonzero exit or `sent` other than boolean true becomes uncertain. The adapter is invoked without a shell; every argv item is an absolute trusted file, and optional adapter SHA-256 pins the PHP script. No bot tokens, customer data, private IPs or raw exception details are accepted from or emitted by the observer.

Event fields: `schema`, opaque `event_id`, `type`, `at`, `state`, `reason`, `observation_at`. Types are `relay_outage`, `relay_recovered`, `relay_monitor_unavailable`, `relay_monitor_restored`, `relay_access_unavailable`, `relay_access_restored`. The private output `status.json` uses schema `servernet.hermes.relay-monitor.v1` and adds `outage_open` plus the latest notification type/time/status. An independent `access_path` field reports only that configured path as available/unavailable/unknown; it never substitutes for controller tunnel health. It is suitable for sanitized Hermes knowledge input, but must remain inside authenticated owner-only paths.

The observer has its own lock and SQLite delivery journal. It commits a dispatch claim before sending. A crash after the claim or ambiguous delivery remains uncertain and is never blindly replayed. A global monotonic event sequence survives owner A→B→A changes, preventing event ID collisions while resetting per-owner observation state. Simultaneous controller and access-path transitions have separate durable event claims and both are delivered in the same tick, so neither is silently lost. Controller source state and journal are not modified. Run it outside the relay so an outage does not also stop its notification path.

Verification: `python3 -m unittest -v test_relay_notifier`. Tests use temporary controller databases and local delivery fixtures, never the real recovery controller or Bale network.

## Optional independent access-path probe

Before recovery controller adoption is complete, an optional `probe_command` can check one fixed read-only HTTPS access path from WHM. It uses the same trusted-file/no-shell/bounded-output runner as delivery, with independent `probe_sha256`. This probe must never execute controller effects or infer that the entire relay/tunnel is healthy.

The observer sends JSON `{"at": <tick-start Unix timestamp>}` on stdin. The PHP probe validates that this integer is within 30 seconds of its own clock, performs the fixed bounded strict-TLS/no-redirect check, and echoes that **same beginning timestamp** in `{"available": true-or-false, "at": <tick-start timestamp>}`. This records the observation window start and avoids falsely future-dating a result by assigning a timestamp after a slow HTTP response. A sample is accepted only when `available` is a boolean and `at` is no more than 120 seconds old and never in the future. Raw HTTP output is not returned.

Two distinct consecutive samples, separated by at most 120 seconds, confirm loss or restoration of that access path. Duplicated timestamps cannot advance the streak. Missing, invalid, stale or future samples break the streak and report unknown; they do not claim outage/recovery. Initial available state is silent. Two failures emit `relay_access_unavailable` even while the controller reports monitoring unavailable; two later successes emit `relay_access_restored`. These are independent of `relay_outage` / `relay_recovered`. Uncertain delivery is never replayed.
