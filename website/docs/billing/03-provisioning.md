# provisioning

> بخشی از معماری CMS اختصاصی سرورنت. برای نمای کلی `00-overview.md` را ببینید.

## خلاصه

The provisioning layer is built around one durable unit of work — `provisioning_tasks` — and a thin driver contract split into a mandatory core plus optional capability interfaces, so a cPanel driver never stubs `console()` and the customer panel renders buttons from `capabilities()` rather than from hardcoded product-type checks. Idempotency is enforced in four layers that all live in the database, not in application convention: a per-task UUID `idempotency_key` that never changes across retries; a DB-level unique index on `lock_key` that makes it physically impossible to have two exclusive tasks in flight for the same service; a lease CAS (`state='running'` + `lease_expires_at`) so a crashed worker's task recovers without a second worker running it concurrently; and a mandatory `reconcile()` call before any retry of `create`, where the driver looks the resource up by a deterministic `remote_name` it derived from the task itself. That last one is the real protection: no external provider gives us reliable idempotency keys, but every one of them lets us name or tag a resource and search for it, so "did my create actually land before the socket died?" is always answerable. We deliberately do NOT use Laravel's `$tries`/backoff — the job is `tries=1` and we own retry accounting in SQL, because Laravel retries re-dispatch blindly and we need reconcile-before-retry plus a permanent audit row per attempt. Nodes, providers, IP pools, capacity and power limits are real columns with real foreign keys; JSON appears in exactly five places, all of them either encrypted credentials, driver-specific option bags validated against a schema the driver itself publishes, or immutable audit snapshots. Capacity is reserved by an atomic compare-and-swap increment of `used_*` on the node row inside the same transaction that writes the task, so we cannot oversell even under a concurrent order burst; IPv4 allocation uses `SELECT ... FOR UPDATE SKIP LOCKED` on `ip_addresses` (which makes MySQL 8.0+ / MariaDB 10.6+ a hard requirement, not a preference). Termination is two-phase — `terminate` stops and marks `pending_destroy` with a `destroy_after` grace window, and only a separate scheduled `destroy` task actually deletes data — because a bug in a terminate path is unrecoverable customer data loss and this is the one place where being slower is unambiguously correct. Customer-facing text never stores provider strings: failures normalise to an `error_code` that the panel renders via `__('ui.PROV_ERR_'.$code)`, guaranteeing fa/en/tr coverage with zero DB translation rows.

## جدول‌ها

### `datacenters`

Physical location metadata shared by nodes, IP pools and the public site. Small, stable, customer-facing.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `code` | `VARCHAR(32) NOT NULL` | UNIQUE. 'ir-thr-1', 'de-fsn-1', 'nl-ams-1' |
| `country_code` | `CHAR(2) NOT NULL` | ISO-3166. Drives legal routing (Iranian services must land on IR nodes) |
| `city_fa` | `VARCHAR(80) NOT NULL` | Triplet-column i18n; see decision on i18n strategy |
| `city_en` | `VARCHAR(80) NOT NULL` | — |
| `city_tr` | `VARCHAR(80) NOT NULL` | — |
| `name_fa` | `VARCHAR(120) NOT NULL` | Marketing name, e.g. 'دیتاسنتر افرانت تهران' |
| `name_en` | `VARCHAR(120) NOT NULL` | — |
| `name_tr` | `VARCHAR(120) NOT NULL` | — |
| `latitude` | `DECIMAL(9,6) NULL` | Map pin on the site |
| `longitude` | `DECIMAL(9,6) NULL` | — |
| `is_public` | `BOOLEAN NOT NULL DEFAULT 1` | Show in the location picker at checkout |
| `sort_order` | `SMALLINT NOT NULL DEFAULT 100` | — |
| `created_at` | `TIMESTAMP NULL` | — |
| `updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE KEY uq_dc_code (code)` · `KEY idx_dc_public (is_public, sort_order)`

### `provisioning_providers`

One credentialed ACCOUNT against one driver: 'Proxmox-Tehran-cluster', 'Hetzner-main', 'OVH-eu', 'cPanel-shared-de'. Credentials live here, not on nodes.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `driver_slug` | `VARCHAR(64) NOT NULL` | Resolved through ProvisioningDriverRegistry. 'proxmox','whm','plesk','directadmin','hetzner_cloud','ovh_cloud','gcp_compute','ibm_vpc' |
| `name` | `VARCHAR(120) NOT NULL` | Admin label |
| `code` | `VARCHAR(64) NOT NULL` | UNIQUE, machine key used in remote resource tags |
| `api_base` | `VARCHAR(255) NULL` | Override for self-hosted endpoints |
| `credentials_encrypted` | `TEXT NULL` | JSON, Laravel `encrypted:array` cast. JSON IS CORRECT HERE: shape is driver-defined (token vs user/pass vs key+secret+project-id vs PEM), never queried, must be encrypted as one blob |
| `settings` | `JSON NULL` | Driver knobs validated against driver::settingsSchema(). Never queried in SQL |
| `egress_mode` | `ENUM('direct','broker') NOT NULL DEFAULT 'direct'` | SANCTIONS CONTROL. Iran server must call Hetzner/OVH/GCP via 'broker' |
| `broker_url` | `VARCHAR(255) NULL` | HTTPS endpoint on the German server that relays driver calls |
| `broker_token_encrypted` | `TEXT NULL` | mTLS/bearer for the broker hop |
| `rate_limit_per_min` | `SMALLINT UNSIGNED NOT NULL DEFAULT 60` | Enforced by a Redis token bucket keyed on provider_id |
| `vmid_min` | `INT UNSIGNED NULL` | Proxmox only. Cluster-wide VMID range |
| `vmid_max` | `INT UNSIGNED NULL` | — |
| `vmid_next` | `INT UNSIGNED NULL` | Bumped under SELECT ... FOR UPDATE on this row. The DB is the VMID allocator |
| `account_currency` | `CHAR(3) NULL` | Currency we are billed in by this provider |
| `balance_minor` | `BIGINT NULL` | Prepaid balance in minor units, synced by driver where supported. NEVER float |
| `is_active` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `health_state` | `ENUM('unknown','ok','degraded','down') NOT NULL DEFAULT 'unknown'` | API-level reachability, distinct from per-node health |
| `last_health_at` | `TIMESTAMP NULL` | — |
| `created_at` | `TIMESTAMP NULL` | — |
| `updated_at` | `TIMESTAMP NULL` | — |
| `deleted_at` | `TIMESTAMP NULL` | Soft delete: never hard-delete a provider that has historical tasks |

**ایندکس:** `UNIQUE KEY uq_prov_code (code)` · `KEY idx_prov_driver (driver_slug, is_active)`

### `provisioning_nodes`

A concrete provisioning target: one WHM box, one Plesk box, one PVE host, or one (provider, region) pair for external clouds. Routing, capacity and health all hang off this single table.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `provider_id` | `BIGINT UNSIGNED NOT NULL` | FK -> provisioning_providers.id RESTRICT |
| `datacenter_id` | `BIGINT UNSIGNED NOT NULL` | FK -> datacenters.id RESTRICT |
| `kind` | `ENUM('control_panel','hypervisor','cloud_region','service_endpoint') NOT NULL` | cloud_region = virtual node representing Hetzner fsn1 etc. |
| `code` | `VARCHAR(64) NOT NULL` | UNIQUE. 'de-fsn-whm-03', 'ir-thr-pve-01', 'hetzner-nbg1' |
| `hostname` | `VARCHAR(255) NULL` | Public FQDN, used in welcome emails |
| `api_host` | `VARCHAR(255) NULL` | — |
| `api_port` | `SMALLINT UNSIGNED NULL` | 2087 WHM, 8006 PVE, 8443 Plesk, 2222 DA |
| `api_scheme` | `ENUM('https','http') NOT NULL DEFAULT 'https'` | — |
| `verify_tls` | `BOOLEAN NOT NULL DEFAULT 1` | Explicit column, not buried in JSON — self-signed PVE certs are common and this is a security decision an admin must see |
| `credentials_encrypted` | `TEXT NULL` | OPTIONAL per-node override of provider credentials (each WHM box has its own root API token). JSON encrypted, same justification |
| `ssh_host` | `VARCHAR(255) NULL` | Some ops (cPanel transfers, PVE qm exec) need SSH |
| `ssh_port` | `SMALLINT UNSIGNED NULL DEFAULT 22` | — |
| `ssh_user` | `VARCHAR(64) NULL` | — |
| `ssh_key_encrypted` | `TEXT NULL` | Private key, encrypted |
| `cluster_key` | `VARCHAR(64) NULL` | Groups PVE hosts in one cluster; scopes migration and shared storage |
| `status` | `ENUM('active','draining','maintenance','disabled','failed') NOT NULL DEFAULT 'active'` | 'draining' keeps existing services working but blocks new placement |
| `accepts_new` | `BOOLEAN NOT NULL DEFAULT 1` | Fast kill switch independent of status |
| `weight` | `SMALLINT UNSIGNED NOT NULL DEFAULT 100` | weighted_random selector input |
| `capacity_slots` | `INT UNSIGNED NULL` | Shared hosting: max accounts on this box |
| `used_slots` | `INT UNSIGNED NOT NULL DEFAULT 0` | COMMITTED, not observed. Mutated by CAS during reservation |
| `capacity_vcpu` | `INT UNSIGNED NULL` | Physical cores |
| `used_vcpu` | `INT UNSIGNED NOT NULL DEFAULT 0` | Sum of committed vCPU |
| `capacity_memory_mb` | `INT UNSIGNED NULL` | — |
| `used_memory_mb` | `INT UNSIGNED NOT NULL DEFAULT 0` | — |
| `capacity_disk_gb` | `INT UNSIGNED NULL` | — |
| `used_disk_gb` | `INT UNSIGNED NOT NULL DEFAULT 0` | — |
| `overcommit_cpu` | `DECIMAL(4,2) NOT NULL DEFAULT 4.00` | vCPU may be oversold 4:1 |
| `overcommit_mem` | `DECIMAL(4,2) NOT NULL DEFAULT 1.00` | RAM must NOT be oversold. Default 1.00 deliberately |
| `reserve_headroom_pct` | `TINYINT UNSIGNED NOT NULL DEFAULT 10` | Never fill past 90% — leaves room for resize and migration |
| `monthly_cost_minor` | `BIGINT NULL` | Our COGS for this node, minor units. Enables true margin per node |
| `cost_currency` | `CHAR(3) NULL` | — |
| `health_state` | `ENUM('unknown','ok','degraded','down') NOT NULL DEFAULT 'unknown'` | — |
| `last_health_at` | `TIMESTAMP NULL` | — |
| `consecutive_failures` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | >=3 auto-sets accepts_new=0 and alerts |
| `notes` | `TEXT NULL` | Admin-only, not customer-facing, so no i18n |
| `created_at` | `TIMESTAMP NULL` | — |
| `updated_at` | `TIMESTAMP NULL` | — |
| `deleted_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE KEY uq_node_code (code)` · `KEY idx_node_place (status, accepts_new, health_state, datacenter_id)` · `KEY idx_node_provider (provider_id)` · `KEY idx_node_cluster (cluster_key)`

### `node_health_checks`

Rolling time-series of node probes. Feeds health_state, capacity drift detection and the admin dashboard.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `node_id` | `BIGINT UNSIGNED NOT NULL` | FK -> provisioning_nodes.id CASCADE |
| `checked_at` | `TIMESTAMP NOT NULL` | — |
| `ok` | `BOOLEAN NOT NULL` | — |
| `latency_ms` | `INT UNSIGNED NULL` | — |
| `load_1m` | `DECIMAL(6,2) NULL` | — |
| `mem_used_pct` | `TINYINT UNSIGNED NULL` | OBSERVED usage, compared against committed used_memory_mb to detect drift |
| `disk_used_pct` | `TINYINT UNSIGNED NULL` | — |
| `observed_guests` | `INT UNSIGNED NULL` | Count of VMs/accounts the node actually reports. Mismatch vs used_slots = orphan or drift |
| `api_version` | `VARCHAR(32) NULL` | Detects a panel upgrade that may break the driver |
| `error` | `TEXT NULL` | Operator-facing |
| `created_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `KEY idx_hc_node_time (node_id, checked_at)` · `KEY idx_hc_purge (checked_at)`

### `os_templates`

Selectable OS images per provider/node. Customer-facing names, so trilingual.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `provider_id` | `BIGINT UNSIGNED NOT NULL` | FK -> provisioning_providers.id CASCADE |
| `node_id` | `BIGINT UNSIGNED NULL` | FK -> provisioning_nodes.id SET NULL. Non-null when the template only exists on one PVE host's local storage |
| `remote_ref` | `VARCHAR(191) NOT NULL` | PVE template VMID or storage path, Hetzner image slug 'ubuntu-24.04', GCP image family |
| `family` | `ENUM('linux','windows','bsd','other') NOT NULL DEFAULT 'linux'` | Windows implies licensing cost — priced separately |
| `os_name` | `VARCHAR(64) NOT NULL` | 'Ubuntu' — a proper noun, not translated |
| `os_version` | `VARCHAR(32) NOT NULL` | '24.04 LTS' |
| `arch` | `ENUM('x86_64','aarch64') NOT NULL DEFAULT 'x86_64'` | — |
| `name_fa` | `VARCHAR(120) NOT NULL` | Display label, e.g. 'اوبونتو ۲۴.۰۴ (پیشنهادی)' |
| `name_en` | `VARCHAR(120) NOT NULL` | — |
| `name_tr` | `VARCHAR(120) NOT NULL` | — |
| `min_disk_gb` | `SMALLINT UNSIGNED NOT NULL DEFAULT 10` | Hard filter at order time |
| `min_memory_mb` | `INT UNSIGNED NOT NULL DEFAULT 512` | — |
| `supports_cloud_init` | `BOOLEAN NOT NULL DEFAULT 1` | Proxmox: false means no automated root password / SSH key injection |
| `license_cost_minor` | `BIGINT NULL` | Windows/cPanel license COGS per month, minor units |
| `license_currency` | `CHAR(3) NULL` | — |
| `is_active` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `sort_order` | `SMALLINT NOT NULL DEFAULT 100` | — |
| `created_at` | `TIMESTAMP NULL` | — |
| `updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE KEY uq_tpl_remote (provider_id, remote_ref, arch)` · `KEY idx_tpl_active (is_active, sort_order)`

### `product_provisioning_profiles`

The single binding between a catalogue product and the provisioning layer. One row per product. This is what makes the system data-driven: admins create products and bind them here with no code change.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `product_id` | `BIGINT UNSIGNED NOT NULL` | FK -> products.id CASCADE, UNIQUE |
| `driver_slug` | `VARCHAR(64) NOT NULL` | Must match candidate nodes' provider driver |
| `provider_id` | `BIGINT UNSIGNED NULL` | FK RESTRICT. Pin to one account when the product is provider-specific |
| `datacenter_id` | `BIGINT UNSIGNED NULL` | FK RESTRICT. Hard location constraint |
| `node_selector` | `ENUM('least_used','fill_first','weighted_random','pinned','spread_by_customer') NOT NULL DEFAULT 'least_used'` | — |
| `pinned_node_id` | `BIGINT UNSIGNED NULL` | FK RESTRICT. Required when node_selector='pinned' |
| `plan_ref` | `VARCHAR(191) NULL` | cPanel package name / Plesk service plan / Hetzner server_type 'cx22' / GCP machine type |
| `default_os_template_id` | `BIGINT UNSIGNED NULL` | FK -> os_templates.id SET NULL |
| `allow_os_choice` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `spec_vcpu` | `INT UNSIGNED NULL` | Committed vCPU, used for capacity CAS |
| `spec_memory_mb` | `INT UNSIGNED NULL` | — |
| `spec_disk_gb` | `INT UNSIGNED NULL` | — |
| `spec_bandwidth_gb` | `INT UNSIGNED NULL` | Monthly transfer quota |
| `spec_slots` | `SMALLINT UNSIGNED NOT NULL DEFAULT 1` | Shared hosting consumes 1 slot |
| `ipv4_count` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` | 0 for shared hosting (no dedicated IP) |
| `ipv6_subnets` | `TINYINT UNSIGNED NOT NULL DEFAULT 0` | Count of /64 delegations |
| `ip_pool_id` | `BIGINT UNSIGNED NULL` | FK -> ip_pools.id RESTRICT. Forces allocation from a specific pool |
| `allow_power` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `allow_rebuild` | `BOOLEAN NOT NULL DEFAULT 1` | Destructive; gated by re-auth |
| `allow_console` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `allow_reverse_dns` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `power_min_interval_s` | `SMALLINT UNSIGNED NOT NULL DEFAULT 60` | Hard cooldown between power actions |
| `power_hourly_limit` | `SMALLINT UNSIGNED NOT NULL DEFAULT 10` | — |
| `power_daily_limit` | `SMALLINT UNSIGNED NOT NULL DEFAULT 30` | — |
| `provision_mode` | `ENUM('auto','manual_review','hold_first_order') NOT NULL DEFAULT 'auto'` | ANTI-FRAUD GATE. 'hold_first_order' auto-provisions returning customers only |
| `retention_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 7` | Grace between terminate and irreversible destroy |
| `provision_timeout_s` | `SMALLINT UNSIGNED NOT NULL DEFAULT 900` | Beyond this a running create is declared 'unknown' and reconciled |
| `max_create_attempts` | `TINYINT UNSIGNED NOT NULL DEFAULT 3` | Money fence |
| `options` | `JSON NULL` | Driver-specific extras validated against driver::planOptionSchema(). JSON IS CORRECT HERE: genuinely open-ended per driver (PVE bridge/CPU-type/balloon, Hetzner placement group, GCP labels), never filtered on in SQL |
| `created_at` | `TIMESTAMP NULL` | — |
| `updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE KEY uq_ppp_product (product_id)` · `KEY idx_ppp_driver (driver_slug)`

### `product_node_candidates`

Explicit allow-list of nodes a product may be placed on, with per-pair weight. A real table, not a JSON array, because admins toggle single pairs and the selector JOINs on it.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `product_id` | `BIGINT UNSIGNED NOT NULL` | FK -> products.id CASCADE |
| `node_id` | `BIGINT UNSIGNED NOT NULL` | FK -> provisioning_nodes.id CASCADE |
| `weight` | `SMALLINT UNSIGNED NOT NULL DEFAULT 100` | weighted_random |
| `priority` | `TINYINT UNSIGNED NOT NULL DEFAULT 100` | Lower wins first in fill_first |
| `is_enabled` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `created_at` | `TIMESTAMP NULL` | — |
| `updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE KEY uq_pnc (product_id, node_id)` · `KEY idx_pnc_select (product_id, is_enabled, priority)`

### `provisioned_resources`

Local mirror of what actually exists on a backend. One row per remote object. The reconcile anchor and the drift-detection target.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `service_id` | `BIGINT UNSIGNED NOT NULL` | FK -> services.id RESTRICT |
| `node_id` | `BIGINT UNSIGNED NOT NULL` | FK -> provisioning_nodes.id RESTRICT |
| `provider_id` | `BIGINT UNSIGNED NOT NULL` | FK RESTRICT. Denormalised from node for the uniqueness key |
| `driver_slug` | `VARCHAR(64) NOT NULL` | Frozen at creation — a node can be re-pointed, history must not lie |
| `resource_type` | `ENUM('vm','panel_account','certificate','mailbox','storage','dns_zone','other') NOT NULL` | — |
| `remote_id` | `VARCHAR(191) NULL` | NULL only between intent-write and first successful API response |
| `remote_name` | `VARCHAR(191) NOT NULL` | THE IDEMPOTENCY ANCHOR. Deterministic, from driver::remoteNameFor(). Written BEFORE the create call |
| `label` | `VARCHAR(120) NULL` | Customer-set hostname/nickname. Free text, locale-agnostic |
| `state` | `ENUM('pending','active','suspended','stopped','pending_destroy','destroyed','error','unknown') NOT NULL DEFAULT 'pending'` | Our intent-state, distinct from power_state |
| `power_state` | `ENUM('running','stopped','starting','stopping','unknown') NULL` | Observed. NULL for panel_account |
| `spec_vcpu` | `INT UNSIGNED NULL` | As actually provisioned; may diverge from the profile after a resize |
| `spec_memory_mb` | `INT UNSIGNED NULL` | — |
| `spec_disk_gb` | `INT UNSIGNED NULL` | — |
| `os_template_id` | `BIGINT UNSIGNED NULL` | FK -> os_templates.id SET NULL |
| `primary_ip_id` | `BIGINT UNSIGNED NULL` | FK -> ip_addresses.id SET NULL |
| `root_credential_encrypted` | `TEXT NULL` | Initial password/SSH key. Encrypted; NULLed by a scheduled job 14 days after first customer view |
| `credential_viewed_at` | `TIMESTAMP NULL` | — |
| `meta` | `JSON NULL` | Driver-specific remote facts (PVE node name, Hetzner datacenter id, cPanel domain). JSON IS CORRECT HERE: read-only audit/display data whose shape is the provider's, not ours |
| `destroy_after` | `TIMESTAMP NULL` | Set on terminate. The destroy job refuses to run before this instant |
| `last_synced_at` | `TIMESTAMP NULL` | — |
| `drift_state` | `ENUM('ok','drift','missing','orphan') NOT NULL DEFAULT 'ok'` | Set by the reconciliation sweeper |
| `created_at` | `TIMESTAMP NULL` | — |
| `updated_at` | `TIMESTAMP NULL` | — |
| `deleted_at` | `TIMESTAMP NULL` | Soft delete only. A destroyed VM's row is evidence |

**ایندکس:** `UNIQUE KEY uq_res_remote (provider_id, resource_type, remote_id)` · `UNIQUE KEY uq_res_name (remote_name)` · `KEY idx_res_service (service_id)` · `KEY idx_res_destroy (state, destroy_after)` · `KEY idx_res_node (node_id, state)` · `KEY idx_res_drift (drift_state, last_synced_at)`

### `provisioning_tasks`

The durable unit of work. One row per INTENT (not per attempt). Survives retries, worker crashes and deploys. Also the complete audit trail of every action ever taken on a service.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `ulid` | `CHAR(26) NOT NULL` | UNIQUE. Public/external identifier shown to customers and in tickets |
| `idempotency_key` | `CHAR(36) NOT NULL` | UNIQUE. UUIDv4, generated ONCE at insert, NEVER regenerated. Sent to providers that accept one (GCP requestId); otherwise its first 8 chars seed remote_name |
| `service_id` | `BIGINT UNSIGNED NULL` | FK -> services.id RESTRICT. NULL for node-level maintenance tasks |
| `resource_id` | `BIGINT UNSIGNED NULL` | FK -> provisioned_resources.id RESTRICT |
| `node_id` | `BIGINT UNSIGNED NULL` | FK RESTRICT |
| `provider_id` | `BIGINT UNSIGNED NULL` | FK RESTRICT |
| `driver_slug` | `VARCHAR(64) NOT NULL` | — |
| `action` | `VARCHAR(32) NOT NULL` | create\|suspend\|unsuspend\|terminate\|destroy\|status\|sync\|power_start\|power_stop\|power_shutdown\|power_reboot\|power_reset\|rebuild\|resize\|console\|reverse_dns\|snapshot_create\|snapshot_restore\|change_password\|release_ips |
| `action_class` | `ENUM('exclusive','power','readonly') NOT NULL` | Drives lock_key, queue routing and rate limiting |
| `lock_key` | `VARCHAR(80) NULL` | UNIQUE. 'svc:1234' while state is non-terminal for exclusive actions; set to NULL on completion. InnoDB permits many NULLs, so this single index makes double-provisioning physically impossible |
| `state` | `ENUM('queued','running','awaiting_remote','succeeded','failed','cancelled','needs_review') NOT NULL DEFAULT 'queued'` | 'needs_review' = fatal or ambiguous; never auto-retried |
| `payload` | `JSON NOT NULL` | Immutable snapshot of the action input. JSON IS CORRECT HERE: heterogeneous per action, written once, never queried |
| `result` | `JSON NULL` | Redacted success payload. Same justification |
| `error_code` | `VARCHAR(64) NULL` | NORMALISED code, e.g. 'node_capacity','ip_pool_exhausted','provider_auth','provider_quota','timeout'. Panel renders __('ui.PROV_ERR_'.$code) — this is how fa/en/tr is guaranteed without storing translated text |
| `error_message` | `TEXT NULL` | Raw operator-facing text. NEVER shown to customers |
| `attempt` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | — |
| `max_attempts` | `SMALLINT UNSIGNED NOT NULL DEFAULT 3` | From the product profile for creates |
| `retryable` | `BOOLEAN NOT NULL DEFAULT 1` | Set false by a fatal classification. Sweeper skips it |
| `remote_operation_ref` | `VARCHAR(191) NULL` | PVE UPID / Hetzner action id / GCP operation name. Enables awaiting_remote polling instead of holding a worker |
| `lease_owner` | `VARCHAR(64) NULL` | hostname:pid of the worker holding it |
| `lease_expires_at` | `TIMESTAMP NULL` | CAS fence. Expired lease = crashed worker, safe to re-lease AFTER reconcile |
| `available_at` | `TIMESTAMP NOT NULL` | Exponential backoff target |
| `started_at` | `TIMESTAMP NULL` | — |
| `finished_at` | `TIMESTAMP NULL` | — |
| `duration_ms` | `INT UNSIGNED NULL` | — |
| `requested_by_type` | `ENUM('customer','admin','system','webhook') NOT NULL` | — |
| `requested_by_id` | `BIGINT UNSIGNED NULL` | users.id or customers.id depending on type |
| `request_ip` | `VARBINARY(16) NULL` | Binary, not string. Forensics for destructive actions |
| `parent_task_id` | `BIGINT UNSIGNED NULL` | FK self, SET NULL. Compensating/cleanup tasks link back to what they undo |
| `priority` | `TINYINT UNSIGNED NOT NULL DEFAULT 100` | Lower = sooner |
| `created_at` | `TIMESTAMP NULL` | — |
| `updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE KEY uq_task_lock (lock_key)` · `UNIQUE KEY uq_task_idem (idempotency_key)` · `UNIQUE KEY uq_task_ulid (ulid)` · `KEY idx_task_pickup (state, available_at, priority)` · `KEY idx_task_service (service_id, created_at)` · `KEY idx_task_lease (state, lease_expires_at)` · `KEY idx_task_node (node_id, state)` · `KEY idx_task_ratelimit (service_id, action_class, created_at)`

### `provisioning_task_attempts`

One row per physical execution attempt: the forensic record. Separate from tasks so the task row stays small and hot while the evidence can be purged on a retention schedule.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `task_id` | `BIGINT UNSIGNED NOT NULL` | FK -> provisioning_tasks.id CASCADE |
| `attempt_no` | `SMALLINT UNSIGNED NOT NULL` | — |
| `phase` | `ENUM('reconcile','execute','poll','compensate') NOT NULL DEFAULT 'execute'` | Makes the reconcile step itself auditable — critical when arguing 'did we create two VMs?' |
| `started_at` | `TIMESTAMP NOT NULL` | — |
| `finished_at` | `TIMESTAMP NULL` | NULL = worker died mid-attempt. This is the 'unknown' signal |
| `duration_ms` | `INT UNSIGNED NULL` | — |
| `outcome` | `ENUM('success','retryable_error','fatal_error','unknown') NULL` | NULL until finished. 'unknown' forces reconcile before any retry |
| `http_status` | `SMALLINT UNSIGNED NULL` | — |
| `request_snapshot` | `JSON NULL` | Redacted via driver::redact(). JSON IS CORRECT HERE: opaque debugging evidence |
| `response_snapshot` | `JSON NULL` | Redacted, truncated to 64 KB |
| `error_code` | `VARCHAR(64) NULL` | — |
| `error_message` | `TEXT NULL` | — |
| `worker` | `VARCHAR(64) NULL` | hostname:pid |
| `created_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE KEY uq_attempt (task_id, attempt_no, phase)` · `KEY idx_attempt_purge (created_at)` · `KEY idx_attempt_outcome (outcome, created_at)`

### `ip_pools`

A routed or bridged block we control. Scoped to a datacenter and optionally to one node or PVE cluster.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `name` | `VARCHAR(80) NOT NULL` | Admin-only label, no i18n |
| `kind` | `ENUM('ipv4','ipv6') NOT NULL` | — |
| `cidr` | `VARCHAR(43) NOT NULL` | '185.x.y.0/24' or '2a01:...::/48' |
| `network` | `VARBINARY(16) NOT NULL` | Binary network address for containment queries |
| `prefix_len` | `TINYINT UNSIGNED NOT NULL` | — |
| `delegate_prefix_len` | `TINYINT UNSIGNED NULL` | IPv6 only: size of each customer delegation, normally 64 |
| `gateway` | `VARBINARY(16) NULL` | — |
| `netmask_len` | `TINYINT UNSIGNED NULL` | Guest-side mask; often /32 with on-link gateway for routed setups |
| `vlan_id` | `SMALLINT UNSIGNED NULL` | — |
| `bridge` | `VARCHAR(32) NULL` | 'vmbr0' — passed straight into the PVE net0 config |
| `datacenter_id` | `BIGINT UNSIGNED NOT NULL` | FK RESTRICT |
| `provider_id` | `BIGINT UNSIGNED NULL` | FK SET NULL |
| `cluster_key` | `VARCHAR(64) NULL` | Usable by any node in this PVE cluster |
| `node_id` | `BIGINT UNSIGNED NULL` | FK SET NULL. Non-null when the block is only routed to one host |
| `purpose` | `ENUM('primary','additional','private','floating','anycast') NOT NULL DEFAULT 'primary'` | — |
| `quarantine_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 7` | Cool-down before a released IP is reissued |
| `monthly_cost_minor` | `BIGINT NULL` | COGS for the block, minor units |
| `cost_currency` | `CHAR(3) NULL` | — |
| `is_active` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `created_at` | `TIMESTAMP NULL` | — |
| `updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE KEY uq_pool_cidr (cidr)` · `KEY idx_pool_scope (datacenter_id, kind, purpose, is_active)` · `KEY idx_pool_node (node_id)` · `KEY idx_pool_cluster (cluster_key)`

### `ip_addresses`

One materialised row per usable IPv4 address. Materialised (a /24 is only 254 rows) because we need per-IP status, PTR, abuse history and an atomic SKIP LOCKED grab. IPv6 is NOT materialised — see ip_allocations.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `pool_id` | `BIGINT UNSIGNED NOT NULL` | FK -> ip_pools.id RESTRICT |
| `ip` | `VARBINARY(16) NOT NULL` | inet6_pton form. Sorts and compares correctly |
| `ip_text` | `VARCHAR(45) AS (INET6_NTOA(ip)) STORED` | Generated column for display, admin search and log joins |
| `status` | `ENUM('free','reserved','assigned','quarantine','blocked') NOT NULL DEFAULT 'free'` | The hot filter. 'blocked' = gateway/network/broadcast or RBL-listed |
| `mac` | `VARCHAR(17) NULL` | Pinned MAC for anti-spoofing filters on the bridge |
| `ptr` | `VARCHAR(255) NULL` | Reverse DNS. Customer-settable, admin-approvable |
| `ptr_synced_at` | `TIMESTAMP NULL` | — |
| `reserved_until` | `TIMESTAMP NULL` | Short TTL (10 min) held during an in-flight create. Expired reservations are swept back to free ONLY if no active task holds them |
| `quarantine_until` | `TIMESTAMP NULL` | Set on release to now + pool.quarantine_days |
| `assigned_at` | `TIMESTAMP NULL` | — |
| `released_at` | `TIMESTAMP NULL` | — |
| `abuse_flags` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | Increment on each abuse report; >=3 auto-sets status='blocked' |
| `last_abuse_at` | `TIMESTAMP NULL` | — |
| `created_at` | `TIMESTAMP NULL` | — |
| `updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE KEY uq_ip (pool_id, ip)` · `KEY idx_ip_grab (pool_id, status, id)` · `KEY idx_ip_quarantine (status, quarantine_until)` · `KEY idx_ip_reserved (status, reserved_until)` · `KEY idx_ip_text (ip_text)`

### `ip_allocations`

The billable, auditable record of an address (v4) or delegated subnet (v6) belonging to a service. One table unifies both so 'extra IP' add-ons bill identically regardless of family.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `service_id` | `BIGINT UNSIGNED NOT NULL` | FK RESTRICT |
| `resource_id` | `BIGINT UNSIGNED NULL` | FK -> provisioned_resources.id SET NULL. NULL between reservation and successful create |
| `pool_id` | `BIGINT UNSIGNED NOT NULL` | FK RESTRICT |
| `kind` | `ENUM('ipv4','ipv6_subnet') NOT NULL` | — |
| `ip_address_id` | `BIGINT UNSIGNED NULL` | FK -> ip_addresses.id RESTRICT. Set for kind='ipv4'. The ONLY link direction — ip_addresses deliberately has no allocation_id, to avoid a circular FK |
| `cidr` | `VARCHAR(43) NULL` | Set for kind='ipv6_subnet', e.g. '2a01:...:1234::/64' |
| `cidr_network` | `VARBINARY(16) NULL` | Binary form for uniqueness/containment |
| `role` | `ENUM('primary','additional','failover') NOT NULL DEFAULT 'primary'` | — |
| `is_billable` | `BOOLEAN NOT NULL DEFAULT 0` | Primary IP normally included; extras billed |
| `price_minor` | `BIGINT NULL` | Recurring price in MINOR UNITS. Snapshot at allocation so a later price change does not rewrite history |
| `currency` | `CHAR(3) NULL` | 'IRT' (exponent 0) or 'EUR' (exponent 2) |
| `task_id` | `BIGINT UNSIGNED NULL` | FK -> provisioning_tasks.id SET NULL. Which task allocated it |
| `allocated_at` | `TIMESTAMP NOT NULL` | — |
| `released_at` | `TIMESTAMP NULL` | NULL = live |
| `created_at` | `TIMESTAMP NULL` | — |
| `updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE KEY uq_alloc_v4 (ip_address_id, released_at)` · `UNIQUE KEY uq_alloc_v6 (pool_id, cidr_network, released_at)` · `KEY idx_alloc_service (service_id, released_at)` · `KEY idx_alloc_resource (resource_id)`

### `console_tickets`

Single-use, short-lived, IP-bound handles for VNC/SPICE/serial console. Exists so provider console credentials never reach the browser and every console open is auditable.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `resource_id` | `BIGINT UNSIGNED NOT NULL` | FK CASCADE |
| `service_id` | `BIGINT UNSIGNED NOT NULL` | FK RESTRICT |
| `user_id` | `BIGINT UNSIGNED NOT NULL` | FK -> customer users. Who opened it |
| `token_hash` | `CHAR(64) NOT NULL` | UNIQUE. SHA-256 of the token. The plaintext token exists only in the redirect URL and is never stored |
| `protocol` | `ENUM('vnc','spice','serial','ssh_web') NOT NULL` | — |
| `remote_ticket_encrypted` | `TEXT NULL` | The provider's ephemeral credential, encrypted; exchanged server-side by the websocket proxy, then NULLed |
| `issued_ip` | `VARBINARY(16) NOT NULL` | Ticket only valid from this address |
| `expires_at` | `TIMESTAMP NOT NULL` | now + 60 seconds. Not configurable |
| `used_at` | `TIMESTAMP NULL` | Non-NULL = already redeemed, reject |
| `session_ended_at` | `TIMESTAMP NULL` | — |
| `created_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE KEY uq_ct_token (token_hash)` · `KEY idx_ct_purge (expires_at)` · `KEY idx_ct_service (service_id, created_at)`

### `services`

OWNED BY THE BILLING AREA — listed here only for the columns provisioning requires as a cross-area contract. Do not create these in a provisioning migration; coordinate.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` | — |
| `customer_id` | `BIGINT UNSIGNED NOT NULL` | FK. Used by spread_by_customer selector and by authorisation |
| `product_id` | `BIGINT UNSIGNED NOT NULL` | FK -> products. Joins to product_provisioning_profiles |
| `status` | `ENUM('pending','provisioning','active','suspended','terminated','provision_failed','fraud_hold') NOT NULL` | 'provision_failed' and 'fraud_hold' are provisioning-owned states; billing must not overwrite them |
| `terminate_requested_at` | `TIMESTAMP NULL` | INTENT flag. Set when a cancel arrives while a create is still in flight; the sweeper enqueues terminate once the lock frees |
| `suspend_reason` | `ENUM('nonpayment','abuse','fraud','admin','other') NULL` | Drives which power actions remain permitted |
| `power_cooldown_until` | `TIMESTAMP NULL` | Hard per-service power fence, cheaper to check than a rate-limiter query |
| `provisioned_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `KEY idx_svc_terminate_intent (terminate_requested_at)` · `KEY idx_svc_status (status)`

## تصمیم‌های کلیدی

**Idempotency is enforced by four DB-level layers, and `reconcile()` before every create retry is mandatory in the contract**

No external provider gives us a reliable idempotency key we can count on (Hetzner Cloud has none for server create, OVH has none, GCP has requestId, Proxmox effectively has one because we choose the VMID). The universal capability every provider does have is: set a name/label at create time, and search by it. So the driver derives a deterministic `remote_name` from the task's immutable `idempotency_key`, writes the `provisioned_resources` intent row BEFORE the API call, and on any retry the runner calls `reconcile()` which searches the provider by that name. The four layers are: (1) `idempotency_key` UUID generated once, never regenerated; (2) `UNIQUE(lock_key)` where lock_key='svc:{id}' for non-terminal exclusive tasks — InnoDB allows many NULLs, so this single index makes two concurrent creates for one service physically impossible; (3) a lease CAS `UPDATE ... SET state='running', lease_expires_at=? WHERE id=? AND (lease_expires_at IS NULL OR lease_expires_at < NOW())` — 0 affected rows means another worker owns it, abort silently; (4) reconcile-before-retry. Layer 2 is the one that turns 'we are careful' into 'the database refuses'.

*رد شد:* Relying on Laravel's `ShouldBeUnique` job middleware (it uses a cache lock with a TTL — a Redis flush or a cache driver swap silently disables your double-provisioning protection, and it does nothing about a create that half-landed). Also rejected: a distributed lock alone, which protects concurrency but not crash-mid-write.

**We own retry logic in SQL; the Laravel job is `$tries = 1`**

Laravel's retry machinery re-dispatches the job blindly with no hook to run a reconcile first, and its attempt count lives in the queue payload, which vanishes when a job is finally failed. We need: attempt count durable in `provisioning_tasks.attempt`, a forensic row per attempt in `provisioning_task_attempts` (including a `phase='reconcile'` row proving we checked), error classification that decides retryability, and a `needs_review` terminal state that is never auto-retried. A scheduled `provisioning:sweep` command every minute re-dispatches tasks where `state IN ('queued','awaiting_remote') AND available_at <= NOW()` plus tasks whose lease expired. Backoff is exponential with jitter: 15s, 60s, 300s.

*رد شد:* Laravel `$tries`/`$backoff`/`retryUntil`. Rejected because a blind retry of a create is exactly the disaster the owner named.

**Terminate is two-phase: `terminate` stops and sets `state='pending_destroy'` + `destroy_after = now + retention_days`; a separate scheduled `destroy` task does the irreversible delete**

A bug, a mis-clicked admin action, or a billing false-positive on the suspension path destroys customer data with no recovery. The grace window costs disk we're already paying for and buys a complete undo. IPs are released to `quarantine` immediately on terminate (we cannot afford to hold IPs), so a restore inside the window may get a different address — that is an acceptable and documented trade. The `destroy` job hard-refuses to run if `NOW() < destroy_after` or if the service was reactivated.

*رد شد:* Immediate destroy on terminate. Rejected outright — the WHMCS world is full of stories of a failed payment webhook nuking a production customer, and the recovery cost is unbounded while the storage cost is a few euro.

**IPv4 addresses are materialised one row each; IPv6 is allocated as /64 delegations recorded only in `ip_allocations`**

An IPv4 /24 is 254 rows — the entire Iranian pool is a few thousand rows, trivial. Materialising gives an atomic `SELECT id FROM ip_addresses WHERE pool_id=? AND status='free' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED` grab, per-IP PTR, per-IP abuse counters and a real FK from `ip_allocations`. Materialising IPv6 is absurd (a /64 is 2^64 addresses), so v6 allocation writes a `cidr` on `ip_allocations` with `UNIQUE(pool_id, cidr_network, released_at)` doing the collision prevention. SKIP LOCKED makes MySQL 8.0.1+ or MariaDB 10.6+ a HARD requirement — under SQLite or older MySQL, concurrent orders will hand the same IP to two customers.

*رد شد:* Computing the next free IP arithmetically from the CIDR minus a set of used addresses. Rejected because it cannot be made atomic without locking the whole pool, it has nowhere to store PTR/abuse/quarantine, and it silently breaks on non-contiguous blocks.

**Released IPs go to `quarantine` for 7 days (per-pool configurable) before returning to `free`**

An IP inherits its previous tenant's reputation: RBL listings, abuse reports, stale DNS pointing a dead site at your new customer's VM, and firewall rules on third-party networks. Reissuing a spammer's address within an hour produces support tickets that look like a platform failure. Seven days is enough for most caches and delisting lag.

*رد شد:* Immediate reuse. It maximises pool utilisation, which is tempting given how scarce Iranian IPv4 is — but the support and reputation cost dominates. Pools that are genuinely tight can set `quarantine_days = 1` per-pool rather than globally abandoning the mechanism.

**Capacity is reserved by an atomic conditional UPDATE on `provisioning_nodes.used_*` inside the same transaction that creates the task — no separate reservations table**

`UPDATE provisioning_nodes SET used_vcpu = used_vcpu + :v, used_memory_mb = used_memory_mb + :m, used_slots = used_slots + :s WHERE id = :id AND used_vcpu + :v <= FLOOR(capacity_vcpu * overcommit_cpu * (100 - reserve_headroom_pct) / 100) AND used_memory_mb + :m <= FLOOR(capacity_memory_mb * overcommit_mem * (100 - reserve_headroom_pct) / 100)` — zero affected rows means the node filled up between selection and commit, so we try the next candidate. This is a compare-and-swap; it cannot oversell under concurrency, and it needs no extra table because the reversal path (failed create, terminate) is a symmetric decrement recorded as a compensating task. `overcommit_mem` defaults to 1.00: CPU may be oversold, RAM must not be, because RAM exhaustion on a Proxmox host takes down every customer on it.

*رد شد:* A `node_reservations` table with rows to expire. It's more auditable but adds a table, a sweeper and a second source of truth for 'how full is this node', which will drift from `used_*` and then nobody trusts either.

**One `provisioning_nodes` table with a `kind` enum covers control panels, hypervisors and external cloud regions**

Routing, health, capacity, cost and the admin UI are then uniform. A Hetzner region is modelled as a `cloud_region` node with NULL capacity columns (meaning 'unbounded, provider-managed') and the driver implementing `AllocatesOwnIps` so the IP allocator becomes a no-op. This means the node selector, the placement rules, the health dashboard and the COGS report all work identically for a WHM box in Frankfurt and for Hetzner nbg1 — which is exactly what 'admin adds things without code changes' requires.

*رد شد:* Separate `hosting_servers` / `hypervisors` / `cloud_accounts` tables. Rejected because every downstream query would need three branches and adding a fifth backend family would touch a dozen files.

**Customer-facing text uses `__('ui.KEY')` for anything static and `name_fa`/`name_en`/`name_tr` triplet columns for the two admin-entered entities that need it (`datacenters`, `os_templates`). No translations table in this area**

Provisioning has exactly two entities with admin-entered customer-facing names, each with 1–2 translatable fields and a few dozen rows. Triplet columns keep NOT NULL enforcement (a locale cannot go missing), need no join, and cannot drift key-wise — unlike `post_translations`, which is right for posts because a post has 4+ long translatable fields and unbounded rows. The far more important half of this decision: provider errors are NEVER stored as display text. Every failure normalises to `provisioning_tasks.error_code`, and the panel renders `__('ui.PROV_ERR_'.$code)`. That is what guarantees a Turkish customer never sees a raw German Hetzner error string, and it keeps lang/{fa,en,tr}/ui.php key-identical by construction. The rule to state in CLAUDE.md: ≤2 translatable fields and low row count → triplet columns; otherwise a translations table.

*رد شد:* A polymorphic `provisioning_translations(translatable_type, translatable_id, locale, field, value)` table. Rejected because it loses foreign-key integrity, needs a nightly orphan check, and adds a join to every location dropdown to save two columns on two tables.

**Optional driver behaviour lives in `Supports*` capability interfaces, not in a fat `ProvisioningDriver` with `NotSupportedException` stubs**

The customer panel must know BEFORE rendering whether a Reboot button exists — a fat interface only tells you at click time, which means either a broken button or a second hardcoded capability map that drifts from the driver. With `instanceof SupportsPower` plus the declarative `capabilities()` list, adding an SSL-issuance driver means implementing five core methods and nothing else, and the panel adapts with zero UI changes. `credentialSchema()` and `planOptionSchema()` returning field definitions is what lets the admin UI render a provider's credential form and a product's option form without any per-driver Blade template.

*رد شد:* A single wide interface with default no-op traits. Rejected for the render-time problem above and because it makes every new driver a 20-method stub exercise, which is how drivers end up silently returning success for operations they never performed.

**Node selection is a hard-filter chain followed by a pluggable `NodeSelector`, with `least_used` as the default and re-verification inside the write transaction**

Hard filters (non-negotiable, SQL WHERE): node enabled AND accepts_new AND health_state<>'down' AND status='active'; in `product_node_candidates` for this product with is_enabled=1; driver_slug matches the profile; datacenter matches the profile's constraint AND the legal constraint (a service sold on servernet.ir must land in an IR datacenter); capacity headroom sufficient; for drivers that don't implement `AllocatesOwnIps`, at least `ipv4_count` free addresses in a pool bound to that node or its cluster. Then the strategy orders the survivors. `least_used` (by committed percentage of the tightest dimension) is the default because it minimises blast radius — a node failure takes down fewer customers. `fill_first` is offered because on external clouds and on our own hardware it lowers cost, and `spread_by_customer` exists so a customer buying three VMs doesn't lose all three to one host. Whatever the selector picks is re-verified by the capacity CAS, so a stale read is always caught.

*رد شد:* Selecting on live observed load from the last health check. Rejected because observed load lags reality by up to a poll interval, and ten orders arriving in one minute would all pick the same 'idle' node. Observed load is used for drift alerting, never for placement.

**Console access is a single-use, 60-second, IP-bound ticket exchanged server-side; provider credentials never reach the browser**

Proxmox's noVNC ticket is effectively a credential for the cluster's web endpoint, and Hetzner's console URL is a bearer token in a query string. Handing either to a customer's browser exposes infrastructure directly and puts a long-lived secret in browser history and in any referrer. Instead: the panel issues a `console_tickets` row (we store only SHA-256 of the token, bound to `issued_ip`, `expires_at = now + 60s`), the customer hits our own websocket proxy with the token, the proxy redeems it server-side (setting `used_at` — a second redemption is refused), fetches the provider grant, and proxies the stream. Every console open is therefore an audit row with a user id and an IP.

*رد شد:* Redirecting the customer straight to the provider's console URL. Simplest possible implementation, and it leaks a cluster credential to an untrusted browser.

**Customer power actions are ordinary `provisioning_tasks` on a dedicated `prov-power` queue, with a three-layer rate limit and a per-resource advisory lock**

Making them tasks means every reboot is audited with actor and IP for free, and a stuck create can never block a customer's reboot because the queues are separate. The limits: (1) `services.power_cooldown_until` — a single indexed timestamp check, cheapest possible fence, default 60s from `power_min_interval_s`; (2) sliding window from `provisioning_tasks` counted on `idx_task_ratelimit`, defaults 10/hour and 30/day per service; (3) Laravel RateLimiter per-customer and per-IP, plus a per-node global limiter (`node.power:{id}` at 30/min) so one panicking customer with 40 VMs cannot hammer a PVE host. Ordering is protected by a 30s Redis advisory lock on `prov:res:{resource_id}` so a stop immediately followed by a start cannot execute out of order; failure to acquire returns 409 'operation in progress' rather than queueing. Authorisation: the service must belong to the actor's customer, `product_provisioning_profiles.allow_power` must be true, the driver must implement `SupportsPower`, the resource state must be `active` or `stopped`. When `services.status='suspended'`, only `stop` is permitted — never `start`, or non-payment suspension is trivially bypassed. `rebuild` additionally requires password re-confirmation plus a typed confirmation string, and records `request_ip`.

*رد شد:* Calling the driver synchronously in the HTTP request for 'fast' actions. Rejected because a slow Proxmox node then holds a PHP-FPM worker for 30 seconds, and there is no audit row if the request dies mid-flight.

**Money in this area is `BIGINT` minor units plus a `CHAR(3)` currency column, always as a pair. Never FLOAT, never DECIMAL for amounts**

Exponent comes from the shared currency table: IRT has exponent 0 (one minor unit = one Toman; there is no sub-Toman amount in practice), EUR has exponent 2. Provisioning holds money only for cost-of-goods (`provisioning_nodes.monthly_cost_minor`, `ip_pools.monthly_cost_minor`, `os_templates.license_cost_minor`) and for the snapshotted price of a billable extra IP (`ip_allocations.price_minor`). Tracking COGS per node and per IP block is what actually lets the owner compete on price with real numbers instead of a guess. `ip_allocations.price_minor` is a snapshot precisely so a later price change does not silently rewrite what a customer agreed to.

*رد شد:* DECIMAL(12,2) columns. They avoid float error but invite mixing an amount with a bare number, and a 2-decimal type is wrong for Toman anyway. One representation everywhere beats a per-currency special case.

**External-provider API calls originating from the Iranian server are routed through a broker on the German server, controlled by `provisioning_providers.egress_mode`**

The brief explicitly says an Iranian customer buying a German server keeps order/invoice/service in the Iran DB and 'only the provisioning API call goes abroad'. That call, made from an Iranian source IP with an Iranian-issued API token, is the single most likely way to get the Hetzner/OVH/GCP account terminated — most of them geo-block or flag Iranian source IPs outright, and their ToS forbid it. So the driver's HTTP transport is pluggable: `egress_mode='broker'` posts a signed, replay-protected envelope to `broker_url` on servernet.cloud, which executes the driver call and returns the result. The idempotency key travels in the envelope, so a broker timeout is still resolved by reconcile.

*رد شد:* Direct calls with a proxy configured per-driver in code. Rejected because it hides a compliance-critical routing decision inside driver implementations instead of surfacing it as an admin-visible column.

**An orphan reaper reports rather than auto-deletes, with one narrow auto-delete exception**

Nightly, for every hypervisor/cloud node, list remote resources carrying our `sn-` namespace and diff against `provisioned_resources`. Remote-but-not-local = an orphan we are paying for; local-but-not-remote = data loss or an out-of-band deletion. Default action is to set `drift_state` and alert an admin with a one-click destroy, because an auto-deleter with a bug in its diff is a mass-deletion weapon. The single auto-delete exception: the remote name maps to a task that is `failed`, older than 24 hours, whose service is `provision_failed`, and the resource has never been reachable — that is unambiguously our own half-created garbage.

*رد شد:* Fully automatic orphan cleanup. The failure mode (driver list call returns an empty array on an auth error, everything looks orphaned) deletes the entire fleet.

## ریسک‌ها

**Fully automatic provisioning with no human in the loop is a magnet for carders and abusers. Instant free-to-them VMs get used for spam, scanning, phishing and crypto mining within minutes. Hetzner, OVH and GCP terminate reseller accounts over this, and they do it without warning — one bad week could remove the company's entire foreign capacity. I think this is the single most underestimated item in the brief.**

→ `product_provisioning_profiles.provision_mode` with three values, defaulting new VPS products to `hold_first_order`: a customer's FIRST order goes to `services.status='fraud_hold'` and waits for review, subsequent orders auto-provision. Plus: SMTP port 25 blocked egress by default with an unblock request flow; per-VM egress bandwidth and packets-per-second caps on the Proxmox bridge; an abuse-report intake that increments `ip_addresses.abuse_flags` and auto-suspends at a threshold; and a rule that shared-hosting products (low abuse value) may default to `auto` while VPS products may not.

**Sanctions and provider ToS. Selling Hetzner/OVH/GCP/IBM capacity to Iranian customers, billed from an Iranian entity, is against those providers' terms regardless of how the API call is routed. The broker hides the source IP; it does not make the arrangement compliant. Account termination takes every customer VM with it.**

→ The broker (`egress_mode`) removes the most obvious detection signal and is worth doing. Beyond that this is a business/legal decision, not a technical one, and it must be made consciously: keep a written position on which providers are used for which market, keep the Iranian market on OWN Proxmox capacity wherever possible, and keep provider concentration low so no single termination is fatal. Technically: `provisioning_nodes.monthly_cost_minor` and the per-provider view make 'how exposed are we to provider X' a one-query answer.

**'Admin can add all of this WITHOUT code changes' is only true within an existing driver. A brand-new backend (a registrar-style API, a new panel, a new cloud) still requires a PHP class, testing against a real account, and a release. Building the expectation that a non-developer can onboard a new provider from the admin UI will produce a broken promise later.**

→ Be explicit about the boundary and design to maximise the data-driven half: new nodes, new datacenters, new packages/plans, new OS templates, new products, new IP pools and new placement rules are all pure data. `credentialSchema()` and `planOptionSchema()` mean a new driver ships with its own admin forms and needs no UI work. Budget roughly 2–5 days per new driver and say so.

**MySQL/MariaDB version. `FOR UPDATE SKIP LOCKED` is required for the IP allocator and strongly desired for task pickup. On SQLite (current) or MySQL < 8.0.1 / MariaDB < 10.6 the allocator silently degrades to handing the same IP to concurrent orders — a bug that only appears under the load you actually want.**

→ Make MySQL 8.0.1+ / MariaDB 10.6+ a hard deployment requirement, assert it at boot in a health check, and add a functional test that runs two concurrent allocations and asserts distinct addresses. Do NOT run provisioning against SQLite even in staging.

**Queue reliability on cPanel-style shared hosting. The site currently deploys to cPanel; a provisioning worker cannot live there. A cron-driven `queue:work --stop-when-empty` has minute-level latency and no supervision, so a customer's reboot takes up to 60 seconds and a crashed worker is invisible.**

→ Provisioning must run on the company's own VM with Redis and supervisor-managed workers on four separate queues (`prov-create`, `prov-lifecycle`, `prov-power`, `prov-poll`). The public site may stay on cPanel; the provisioning worker may not. Add a heartbeat: if no task has been picked up in 5 minutes while `state='queued'` rows exist, alert.

**Providers that do not let you set a name or tag at create time break the reconcile anchor. If a create times out mid-flight and we cannot search for what we made, we are choosing between paying for a possible orphan and possibly double-creating.**

→ For such a provider, the driver's `reconcile()` falls back to listing resources created within the task's `started_at`..`now` window and matching on spec; if that is ambiguous, it MUST throw and the task goes to `needs_review` — never to a blind retry. Vet this capability before adding any driver, and record it in `capabilities()`.

**Two independent databases means a customer with services on both servers has two accounts, two panels, two invoice histories and two passwords. Support will feel this daily, and the customer will read it as a broken product.**

→ Out of scope for provisioning to solve, but provisioning must not make it worse: `provisioned_resources.remote_name` is globally unique across both installs by including a per-install prefix, so an orphan reaper on either side can tell 'not mine' from 'lost'. Flag to the owner that cross-install identity needs an explicit decision before launch, not after.

**Own-Proxmox single points of failure. One cluster, unspecified shared storage, unspecified backup. A host failure with local storage means every VM on it is offline until the disk is recovered, and the design's `cluster_key` implies HA that may not exist.**

→ Treat backups as a first-class provisioning concern, not an afterthought: Proxmox Backup Server as a node with its own health check, a `SupportsBackups` driver capability, and a rule that a product may not go live without a backup target configured. Prefer `least_used` placement (already the default) so a host failure hits the fewest customers, and keep `overcommit_mem` at 1.00 so a failed host's VMs can actually be restarted elsewhere.

**`root_credential_encrypted` stores initial VM passwords in the database. A DB compromise hands over root on every recently provisioned server.**

→ Prefer SSH-key-only cloud-init where the template supports it (`os_templates.supports_cloud_init`). Where a password is unavoidable, encrypt with a key held outside the DB, show it once, and NULL the column via a scheduled job 14 days after `credential_viewed_at` (or 30 days after creation if never viewed), with the panel telling the customer plainly that it will disappear.

**Suspension for non-payment on a VM is not the same as on a shared account. Stopping a VM stops the customer's business; a driver that implements suspend as 'destroy' or a billing false-positive on a payment-gateway webhook is catastrophic and, in this design, would run automatically with no human involved.**

→ Contractually, `suspend()` must never delete data — enforce it with a driver test suite that asserts the resource still exists and `state='suspended'` after suspend and that `unsuspend()` restores it fully. Add a graduated path (network-level block, then stop, then terminate after N days) driven by billing, and require that any automated suspension of an `active` service older than 30 days emits an admin notification rather than running silently.

## قراردادها (PHP interfaces)

```php
<?php

namespace App\Provisioning\Contracts;

use App\Provisioning\Dto\{ProvisionContext, ProvisionResult, RemoteResource, ResourceStatus,
    OperationStatus, HealthReport, FieldSchema};
use App\Models\ProvisioningNode;

/**
 * قرارداد پایه‌ی هر درایور تحویل سرویس.
 *
 * فقط چیزهایی که *همه‌ی* بک‌اندها دارند اینجاست. توانایی‌های اختیاری
 * (روشن/خاموش، کنسول، ری‌بیلد و ...) از طریق اینترفیس‌های Supports* اعلام می‌شوند
 * تا پنل مشتری دکمه‌ها را از capabilities() بسازد، نه از if/else روی نوع محصول.
 *
 * قواعدی که پیاده‌سازی *باید* رعایت کند:
 *  1. create() هرگز مستقیم روی retry صدا زده نمی‌شود؛ runner اول reconcile() را می‌زند.
 *  2. remoteNameFor() باید کاملاً قطعی (deterministic) باشد و فقط به $ctx وابسته.
 *  3. هر متد یا موفق برمی‌گردد یا ProvisioningException پرتاب می‌کند — هرگز null مبهم.
 */
interface ProvisioningDriver
{
    /** شناسه‌ی یکتای درایور؛ همان چیزی که در provisioning_providers.driver_slug ذخیره می‌شود */
    public static function slug(): string;

    /** @return list<string> ثابت‌های Capability::* که این درایور پشتیبانی می‌کند */
    public static function capabilities(): array;

    /** @return list<FieldSchema> فرم اعتبارنامه در پنل ادمین — بدون کدنویسی رندر می‌شود */
    public static function credentialSchema(): array;

    /** @return list<FieldSchema> فرم گزینه‌های پلن (product_provisioning_profiles.options) */
    public static function planOptionSchema(): array;

    /** نام قطعی منبع روی سمت ارائه‌دهنده — لنگرگاه idempotency. مثلا 'sn-8f3-a71c4b2d' */
    public function remoteNameFor(ProvisionContext $ctx): string;

    /**
     * آیا منبع از قبل ساخته شده؟ قبل از هر تلاش مجدد create فراخوانی می‌شود.
     * باید بر اساس remoteNameFor()/tag جست‌وجو کند، نه بر اساس حافظه‌ی محلی.
     */
    public function reconcile(ProvisionContext $ctx): ?RemoteResource;

    public function create(ProvisionContext $ctx): ProvisionResult;

    public function suspend(RemoteResource $resource, string $reasonCode): ProvisionResult;

    public function unsuspend(RemoteResource $resource): ProvisionResult;

    /** توقف + علامت‌گذاری برای حذف. نباید داده را پاک کند. */
    public function terminate(RemoteResource $resource): ProvisionResult;

    /** حذف نهایی و برگشت‌ناپذیر. فقط از طریق تسک زمان‌بندی‌شده‌ی destroy. */
    public function destroy(RemoteResource $resource): ProvisionResult;

    public function status(RemoteResource $resource): ResourceStatus;

    /** پیگیری عملیات طولانی سمت ارائه‌دهنده (PVE UPID / Hetzner action / GCP operation) */
    public function pollOperation(RemoteResource $resource, string $operationRef): OperationStatus;

    public function healthCheck(ProvisioningNode $node): HealthReport;

    /** حذف رمز/توکن از payload پیش از ذخیره در provisioning_task_attempts */
    public function redact(array $payload): array;
}
```

```php
<?php

namespace App\Provisioning\Contracts;

/**
 * توانایی‌های اختیاری. پنل مشتری و پنل ادمین فقط بر اساس instanceof تصمیم می‌گیرند،
 * بنابراین افزودن ارائه‌دهنده‌ی جدید هیچ تغییری در لایه‌ی UI لازم ندارد.
 */
final class Capability
{
    public const POWER        = 'power';
    public const CONSOLE      = 'console';
    public const REBUILD      = 'rebuild';
    public const RESIZE       = 'resize';
    public const SNAPSHOTS    = 'snapshots';
    public const BACKUPS      = 'backups';
    public const REVERSE_DNS  = 'reverse_dns';
    public const METRICS      = 'metrics';
    public const CAPACITY     = 'capacity_probe';
    public const OWN_IPS      = 'provider_allocates_ips';
    public const CHANGE_PLAN  = 'change_plan';
    public const CHANGE_PASS  = 'change_password';
}

interface SupportsPower
{
    public function start(RemoteResource $r): ProvisionResult;
    /** خاموشی نرم از طریق ACPI/agent */
    public function shutdown(RemoteResource $r): ProvisionResult;
    /** قطع برق — داده از دست می‌رود، در UI باید هشدار بدهد */
    public function stop(RemoteResource $r): ProvisionResult;
    public function reboot(RemoteResource $r): ProvisionResult;
    public function reset(RemoteResource $r): ProvisionResult;
}

interface SupportsConsole
{
    /** اعتبارنامه‌ی موقت ارائه‌دهنده؛ هرگز به مرورگر داده نمی‌شود */
    public function issueConsole(RemoteResource $r, string $protocol): ConsoleGrant;
}

interface SupportsRebuild
{
    public function rebuild(RemoteResource $r, OsTemplateRef $template, CloudInitSpec $init): ProvisionResult;
}

interface SupportsResize
{
    /** @return bool آیا این تغییر نیاز به خاموشی دارد؟ برای هشدار قبل از تأیید مشتری */
    public function resizeRequiresDowntime(RemoteResource $r, ResourceSpec $to): bool;
    public function resize(RemoteResource $r, ResourceSpec $to): ProvisionResult;
}

interface SupportsReverseDns
{
    public function setReverseDns(RemoteResource $r, string $ip, string $ptr): ProvisionResult;
}

interface SupportsCapacityProbe
{
    /** ظرفیت *مشاهده‌شده*، برای کشف drift نسبت به used_* در جدول نودها */
    public function probeCapacity(ProvisioningNode $node): CapacitySnapshot;
}

interface SupportsMetrics
{
    public function usage(RemoteResource $r, \DateTimeImmutable $from, \DateTimeImmutable $to): UsageSample;
}

/** نشانگر: ارائه‌دهنده خودش IP می‌دهد (Hetzner) و ما نباید از استخر تخصیص بدهیم */
interface AllocatesOwnIps {}
```

```php
<?php

namespace App\Provisioning\Contracts;

use App\Models\{Product, ProvisioningNode, Service};

/**
 * استراتژی انتخاب نود. جدا شده تا افزودن استراتژی جدید (مثلا آگاه از برق/هزینه)
 * فقط یک کلاس جدید باشد و هیچ جای دیگری تغییر نکند.
 */
interface NodeSelector
{
    public static function key(): string;   // 'least_used' | 'fill_first' | ...

    /**
     * @param  list<ProvisioningNode>  $candidates  نودهایی که از فیلترهای سخت رد شده‌اند
     */
    public function choose(array $candidates, Service $service, Product $product): ?ProvisioningNode;
}

/**
 * رزرو اتمی ظرفیت. پیاده‌سازی باید با یک UPDATE ... WHERE شرطی انجام شود
 * (compare-and-swap) نه با read-then-write، وگرنه در بار همزمان oversell می‌شود.
 */
interface CapacityReserver
{
    /** @throws CapacityUnavailable اگر UPDATE صفر ردیف تحت تأثیر بگذارد */
    public function reserve(ProvisioningNode $node, ResourceSpec $spec, int $taskId): void;
    public function release(ProvisioningNode $node, ResourceSpec $spec, int $taskId): void;
}

/**
 * تخصیص IP. برای Proxmox از استخر خودمان، برای Hetzner یک no-op.
 * SELECT ... FOR UPDATE SKIP LOCKED در پیاده‌سازی الزامی است.
 */
interface IpAllocator
{
    /** @return list<IpAllocationRef> */
    public function allocate(Service $service, ProvisioningNode $node, int $v4Count, int $v6Subnets, int $taskId): array;

    /** آزادسازی → وضعیت quarantine، نه free */
    public function release(Service $service, ?int $taskId = null): void;
}
```

```php
<?php

namespace App\Provisioning\Exceptions;

/**
 * سلسله‌مراتب خطا. طبقه‌بندی خطا تعیین می‌کند تسک retry شود یا نه —
 * و این تصمیم *گران‌ترین* تصمیم کل سیستم است.
 */
abstract class ProvisioningException extends \RuntimeException
{
    /** کد نرمال‌شده؛ پنل با __('ui.PROV_ERR_'.$code) نمایش می‌دهد → fa/en/tr تضمینی */
    abstract public function errorCode(): string;

    /** آیا تلاش مجدد بی‌خطر است؟ */
    abstract public function isRetryable(): bool;

    /**
     * آیا ممکن است درخواست *سمت ارائه‌دهنده اجرا شده باشد* ولی پاسخ به ما نرسیده؟
     * true یعنی runner موظف است قبل از هر کاری reconcile() بزند.
     */
    abstract public function isAmbiguous(): bool;
}

/** timeout / connection reset / 5xx — قابل تکرار، اما مبهم */
final class TransportFailure extends ProvisioningException {
    public function errorCode(): string { return 'transport'; }
    public function isRetryable(): bool { return true; }
    public function isAmbiguous(): bool { return true; }
}

/** 429 — قابل تکرار، غیرمبهم */
final class RateLimited extends ProvisioningException {
    public function errorCode(): string { return 'provider_rate_limit'; }
    public function isRetryable(): bool { return true; }
    public function isAmbiguous(): bool { return false; }
}

/** 400/422 — پارامتر غلط. تکرار فقط پول و زمان هدر می‌دهد */
final class InvalidRequest extends ProvisioningException {
    public function errorCode(): string { return 'invalid_request'; }
    public function isRetryable(): bool { return false; }
    public function isAmbiguous(): bool { return false; }
}

/** 401/403 — اعتبارنامه‌ی خراب. تسک به needs_review می‌رود و به ادمین هشدار */
final class AuthFailure extends ProvisioningException {
    public function errorCode(): string { return 'provider_auth'; }
    public function isRetryable(): bool { return false; }
    public function isAmbiguous(): bool { return false; }
}

/** سهمیه تمام شده — نه قابل تکرار، نیاز به دخالت انسان یا نود دیگر */
final class QuotaExceeded extends ProvisioningException {
    public function errorCode(): string { return 'provider_quota'; }
    public function isRetryable(): bool { return false; }
    public function isAmbiguous(): bool { return false; }
}

final class CapacityUnavailable extends ProvisioningException {
    public function errorCode(): string { return 'node_capacity'; }
    public function isRetryable(): bool { return true; }   // نود دیگری امتحان می‌شود
    public function isAmbiguous(): bool { return false; }
}

final class IpPoolExhausted extends ProvisioningException {
    public function errorCode(): string { return 'ip_pool_exhausted'; }
    public function isRetryable(): bool { return false; }
    public function isAmbiguous(): bool { return false; }
}
```

```php
<?php

namespace App\Provisioning;

use App\Provisioning\Contracts\ProvisioningDriver;

/**
 * رجیستری درایورها. افزودن ارائه‌دهنده‌ی جدید = یک کلاس + یک سطر در config.
 * هیچ جای دیگری از کد نباید نام درایور را hard-code کند.
 *
 * صادقانه: افزودن یک ارائه‌دهنده‌ی *کاملاً جدید* همچنان یک کلاس PHP لازم دارد.
 * چیزی که بدون کد کار می‌کند: نود جدید، پکیج/پلن جدید، لوکیشن جدید، تمپلیت جدید،
 * محصول جدید — همگی روی درایورهای موجود.
 */
interface ProvisioningDriverRegistry
{
    /** @return array<string, class-string<ProvisioningDriver>> */
    public function all(): array;

    /** ساخت درایور با اعتبارنامه‌ی provider و override احتمالی نود */
    public function make(int $providerId, ?int $nodeId = null): ProvisioningDriver;

    public function has(string $slug): bool;
}

/**
 * نقطه‌ی ورود سطح‌بالا. هیچ کد دیگری (سفارش، صورتحساب، پنل) نباید مستقیم
 * با درایور کار کند — همه از این سرویس تسک می‌سازند.
 */
interface ProvisioningManager
{
    /**
     * تسک را می‌سازد و در صف می‌گذارد. اگر تسک انحصاری دیگری روی همین سرویس
     * در جریان باشد، به دلیل UNIQUE(lock_key) یک ConflictingTask پرتاب می‌شود.
     *
     * @param  array<string,mixed>  $payload
     * @throws ConflictingTask
     */
    public function dispatch(
        string $action,
        ?int $serviceId,
        array $payload = [],
        string $requestedByType = 'system',
        ?int $requestedById = null,
        ?string $requestIp = null,
    ): ProvisioningTask;

    /** اقدام روشن/خاموش از پنل مشتری — مجوز + محدودیت نرخ اینجا اعمال می‌شود */
    public function power(int $serviceId, string $action, Actor $actor): ProvisioningTask;
}
```

