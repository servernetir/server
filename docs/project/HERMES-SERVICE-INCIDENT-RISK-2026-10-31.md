# Hermes: reported data loss and recurring outage risk

Base: `develop` at `b93d3ed6b379c28a709b83c472d7f6698ada46a4`; the two runtime
snapshots match the earlier inspected `6f1dd7e7bd9d7a698c6d62b18dd8b1dfcc3b7066`.

## Problem and behavior

Reports of lost data or a repeated connection outage could fall through the
support classifier as `low_risk` when they contained no existing finance,
security or mutation keywords. The classifier now returns `service_incident`
for reported data loss or recurrence together with an outage signal, in Persian,
English or Turkish. This records reported impact; it is not proof that an outage
or data loss occurred.

Existing credential exclusion and finance/security classifications keep their
precedence. A refund request remains financial. Existing mutation checks and
bounded low-risk clarification are unchanged. `service_incident` cannot use the
automatic clarification or authorize customer delivery through `maySend`.

A first customer message with reported loss or recurrence now also enters the
existing required-investigation path without needing a preceding staff reply.
This fixed internal plan preserves original data, calls for read-only checking of
machine/connectivity/console and recorded events, and distinguishes lost access
from confirmed loss. A refund remains financial while the incident still requires
investigation. No provider call or mutation is introduced by the gate.

## Scope

- Production snapshot source: `infra/hermes/support-firewall-20261028/HermesSupportPolicy.php`.
- Investigation gate: `infra/hermes/server-ticket-followup-20261028/HermesSupportInvestigation.php`.
- Standalone regression: `website/tests/standalone/hermes-support-incident.php`.
- No changes to customer/service ownership, provider requests, account balance,
  approval, delivery, scheduler, infrastructure or data recovery.
- Location words remain a customer intent hint; they do not establish ownership
  or authorize choosing a machine from prose.

Candidate location projection and customer-visible console capability remain
separate work. A classification or internal plan does not prove diagnosis or
recovery ran. The gate tests the latest customer report, so a historical incident
does not force this branch when the latest customer message reports resolution.

## Validation

The standalone harness takes an explicit policy source path and loads no app,
Composer, database or environment. It uses synthetic FA/EN/TR reports, precedence
regressions and negative cases, and checks the automatic-send predicates.

```sh
php website/tests/standalone/hermes-support-incident.php \
  infra/hermes/support-firewall-20261028/HermesSupportPolicy.php \
  infra/hermes/server-ticket-followup-20261028/HermesSupportInvestigation.php
```

PHP execution is pending on an isolated runtime; local PHP is unavailable.
Baseline and modified-source results must be recorded before claiming acceptance.

## Release and rollback

Status: prepared source only; no production installation performed by this change.
Production destinations are `app/Services/Hermes/HermesSupportPolicy.php` and
`app/Services/Hermes/HermesSupportInvestigation.php` outside the tracked website
tree on this baseline. Before any installation, read both current files, preserve
later edits, compare hashes, lint, back up and invalidate only the changed OPcache
entries. Do not copy whole snapshot directories.

Rollback restores only the two prior files from their recorded backup; no
database or customer state is modified by this classifier. Tickets already
recorded under a classification are not silently rewritten or retried.
