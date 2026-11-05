# Private observation query proxy

This source is prepared and offline tested. No collector service, SSH access,
Unix socket, enrollment, scheduler or application was installed by this package.

The proxy listens **only on `127.0.0.1:19101`** and connects **only to
`127.0.0.1:9090`**. Both are literal IPv4 sockets; it does not resolve DNS, use
environment proxies, follow redirects, invoke SSH, or contact exporters,
providers, Laravel or a database. Preserve the existing collector and guest
tunnels. A separately supervised, authenticated SSH forward from the application
host should expose this proxy through an account-owned Unix socket, never an
unauthenticated TCP forward on a shared application host.

The SSH server key must be limited to local forwarding to exactly
`127.0.0.1:19101`, with no shell/session, agent, X11 or remote forwarding. A
`permitopen` restriction controls the destination socket; this HTTP proxy is
what rejects administration and other Prometheus API requests. Its own loopback
listener assumes the collector's local users are trusted.

## Trusted bindings

The required operator file is an exact, pinned JSON document:

```json
{
  "version": 1,
  "identities": [
    {
      "service_id": 613,
      "instance_id": 407,
      "customer_id": 545,
      "provider_ref": "ir-thr-ba1:00000000-0000-0000-0000-000000000001",
      "enrollment_id": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "device": "ens3"
    }
  ]
}
```

These values are **synthetic examples**, not accepted live bindings. Independently
match the current application ownership, provider instance, trusted target
labels, authenticated guest/default-route device and enrollment generation.
Add an identity only after those checks. The enrollment ID is not a secret or
an authentication credential.

The canonical absolute file must be a root-owned regular file with one link,
mode `0600` or `0640`, at most 256KiB, with no path symlinks. For a nonroot proxy
service, use a dedicated group and `0640`; the proxy needs no privileged port.
Pin the **exact file bytes** with the required SHA-256 argument. File identity,
metadata and hash are checked during startup; no hot reload occurs. New
enrollment requires a newly reviewed file/hash and an explicit service restart.
The file permits 1–512 identities and rejects duplicate service, instance,
provider reference, enrollment ID or JSON keys. IDs must be positive integers;
the full six-field identity matches `ArvanTrafficObservation::validIdentity()`.

Example operator invocation, only after separate installation approval:

```bash
python3 -I -S /opt/servernet-observation/prometheus-query-proxy.py \
  --bindings /etc/servernet-prometheus/observation-bindings.json \
  --bindings-sha256 REVIEWED_64_CHARACTER_LOWERCASE_SHA256
```

The script refuses startup on an invalid pin/configuration or occupied listener.
It prints no request log, raw URL, identity, upstream body or exception detail.

## HTTP contract and bounds

Only `GET /api/v1/query?query=...&time=...` is accepted. Both parameters occur
exactly once, and no other parameter is allowed. `time` is a 10-digit integer
Unix second within 30 seconds of the proxy clock. Query ranges, historical
queries, expressions, labels, regular-expression matchers and whitespace
variants outside the canonical query are refused.

For each trusted binding the proxy rebuilds the **exact** query emitted by
`PrivatePrometheusTrafficSource`: five raw metrics (`up`, netdev success,
selected-device RX/TX and boot time), each paired with direct-selector
`timestamp()` evidence and the two fixed evidence labels, joined in the same
order. Only complete string equality to one enrolled query authorizes a
forward. The actual PHP class generates the comparison query in the offline
test, so a future builder change must update and review both sides together.

The application Unix-socket client must send a fixed `Host: localhost` or
`Host: 127.0.0.1` (optional `:19101`). Only `Host`, `Accept`, `User-Agent`,
`Connection` and `Content-Length: 0` request headers are allowed. Duplicate
headers, folded/control headers, bodies, observed pipelining and non-query
paths are refused. No request header or body is forwarded upstream.

| Boundary | Limit |
| --- | --- |
| Request line | 16KiB |
| Decoded canonical query | 8KiB |
| Header section / one header / count | 8KiB / 2KiB / 32 |
| Client request header deadline | 2 seconds |
| Upstream connect, headers and body together | 5 seconds absolute |
| Upstream status line | 256 bytes |
| Upstream / returned JSON body | 256KiB |
| Response write | 1 second |
| Concurrent handlers / pending listen backlog | 4 / 8 |

The proxy makes one HTTP/1.0 GET with `Connection: close` and identity encoding.
It accepts a bounded Content-Length or an EOF-terminated response; chunked or
compressed responses are refused. This avoids unbounded library header/chunk
reads and must be accepted against the actual Prometheus version before use.
Every receive recomputes the remaining absolute deadline, including slow
header reads. Only HTTP 200 JSON `success` instant vectors with at most 20 rows,
without warnings or infos, can be returned. Other responses become a fixed
generic 502; rejected client requests become a generic 400; excess workers
receive a generic 503. Redirects and error bodies never reach the application.
The existing PHP parser still independently verifies all target identities,
metrics, source timestamps, freshness, counters and boot identity.

## Acceptance and rollback

Before production use, prove the collector binding is loopback, the upstream
HTTP/1.0 response is compatible, the existing three targets/tunnel are intact,
and an enrolled identity yields ten coherent fresh series. Prove the application
socket is owned by the application account with a `0700` parent and `0600`
socket, another local account cannot connect, and the SSH peer key is pinned
from the trusted collector channel. Require two increasing real scrape
timestamps in the actual application consumer; a proxy 200 alone is not
enrollment, traffic charging, lifecycle enforcement or fleet coverage.

On rollback stop only the new proxy/forward and disable its application
transport. Preserve the existing collector, guest exporter/tunnel and all prior
checkpoints. A central transport outage must remain central `unknown`, not a
claim that an individual guest disabled its exporter.

Offline validation (fake wire sockets/upstream and two pure PHP classes only):

```bash
PHP_BINARY=/path/to/php PYTHONDONTWRITEBYTECODE=1 \
  python3 -B -m unittest discover -s scripts/arvan-monitoring -p test_query_proxy.py -v
```

The tests never start the proxy or connect to Prometheus, SSH, guests or a
provider. They do not bootstrap the application or establish production
permissions, sockets, identity bindings or scrape compatibility.
