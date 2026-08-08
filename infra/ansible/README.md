# ServerNet IaC — Ansible

Infrastructure-as-Code that codifies the entire ServerNet control-plane on the
single-node Proxmox VE 9.2 server: least-privilege bootstrap, the six Linux
cloud-init templates, the Windows turnkey mechanism, the Laravel control panel,
the sing-box exit, Module 2 (multi-country pool), the port-forward agent, and
safe provision/destroy runtimes.

> **خلاصهٔ فارسی:** این مخزن تمامِ کارهایی که تا الان دستی روی سرور ساخته‌ایم را
> به‌صورتِ Ansible «کدنویسی» می‌کند تا برگشت‌پذیر، قابل‌بازتولید و امن باشد.
> **پیش‌فرض امن است:** نقش‌هایی که به کانتینرها/فایروال دست می‌زنند فقط فایل‌ها را
> در `/root/.servernet/...` *آماده* می‌کنند و تا وقتی فلگِ `*_apply=true` را ندهی
> چیزی روی سیستمِ زنده تغییر نمی‌کند. **VM 108 خطِ‌قرمز است** و در سه لایه محافظت
> شده. هرگز `--limit all` نزن. اول با `--check` اجرا کن.

---

## 1. Safety model (read first)

This runs against a **live** node, so every destructive edge is fenced:

- **VM 108 is a hard red line.** It — plus infra (113/115), the templates
  (9000–9005, 9012), and the pre-existing guests (100–114) — are protected in
  `inventory/group_vars/all/vars.yml` (`protected_vmids`, `protected_vmid_ranges`)
  and re-checked by an assert in `provision.yml` and `destroy.yml`. The guards
  **fail closed**: any doubt ⇒ refuse.
- **Stage-by-default.** The roles that touch live containers or host firewall
  (`control_panel`, `exit_node`, `exit_multicountry`, `pf_agent`) only *render*
  their output to a host staging dir. They change nothing until you pass the
  role's apply flag (`panel_apply`, `exit_apply`, `mc_apply`, `pf_apply`).
- **Least privilege, never root.** `proxmox_host` creates a pool-scoped API
  token (`svc-controller@pve!provisioner`); the panel authenticates with that,
  never the root password. Root is used only for the one-time host bootstrap.
- **Additive & reversible.** New objects (pool, token, snippet, DNAT chain,
  systemd units) sit beside existing config; the pf-agent uses a dedicated
  `SERVERNET-PF` iptables chain so flushing that one chain undoes everything.
- **Secrets via Vault.** Real values live only in `vault.yml` (git-ignored).
- **Never** run with `--limit all`; never add the pre-existing guests to the
  inventory.

Always dry-run first:

```bash
ansible-playbook site.yml --check --diff
```

## 2. Prerequisites

- **ansible-core ≥ 2.17** on the controller (community.proxmox needs it).
- Collections + roles: `ansible-galaxy install -r requirements.yml`
  (also `ansible-galaxy collection install -r requirements.yml`).
- The controller must be able to `ssh root@` the Proxmox host. All container
  work is done from the host via `pct`, so **containers need no SSH surface**.
  Running Ansible *from the Proxmox host itself* is the simplest setup.
- On the host (installed by `proxmox_host`): `python3-proxmoxer`,
  `python3-requests` — only needed if you switch `provision.yml` to the
  community.proxmox module path (the shipped version uses `qm`).

## 3. Configure

1. `cp inventory/group_vars/vault.example.yml inventory/group_vars/all/vault.yml`
   (secrets must live in `group_vars/all/` to load; the example sits outside it
   on purpose so it never loads).
2. Fill real secrets, then `ansible-vault encrypt inventory/group_vars/all/vault.yml`
3. Edit `inventory/hosts.yml` — set the host's SSH address.
4. Review `inventory/group_vars/all/vars.yml` and set **`pve_node`** to your real
   node name (`pvesh get /nodes --output-format json`). Verify `vm_storage`,
   `iso_storage`, IP ranges, and the protected lists.

## 4. Run

**Turnkey (recommended first run).** Copy the repo onto the Proxmox host and run:

```bash
bash bootstrap-apply.sh
```

It installs ansible, fills in the node name + a local Module-2 secret,
syntax-checks, then applies the safe idempotent parts (bootstrap + templates)
and deploys Module 2 — leaving the working panel/exit/pf-agent untouched. For
manual, staged control instead:

```bash
# Everything, dry:
ansible-playbook site.yml --check --diff

# One-time host bootstrap (pool, token, role, ACL, snippet storage):
ansible-playbook site.yml --tags bootstrap

# Build/verify the six Linux templates (skips any that already exist):
ansible-playbook site.yml --tags templates

# Stage the panel config, review it, then apply:
ansible-playbook site.yml --tags panel                      # stage only
less /root/.servernet/panel/.env                            # review on the host
ansible-playbook site.yml --tags panel  -e panel_apply=true # apply + backup

# Exit node, Module 2, pf-agent — same pattern:
ansible-playbook site.yml --tags exit    -e exit_apply=true
ansible-playbook site.yml --tags module2 -e mc_apply=true
ansible-playbook site.yml --tags pf      -e pf_apply=true
```

Per-role wrappers also live in `playbooks/` (e.g.
`ansible-playbook playbooks/40-exit-node.yml -e exit_apply=true`).

## 5. Runtime: sell & destroy

```bash
# Provision a customer VM (mirrors Provisioner::sell):
ansible-playbook provision.yml -e '{
  "os_key":"ubuntu2404","new_vmid":123,"cust_name":"s123",
  "assigned_ip":"10.10.10.62","initial_password":"REDACTED",
  "plan_cores":2,"plan_memory":2048,"plan_disk_gb":40 }'

# Destroy is dry by default and triple-guarded:
ansible-playbook destroy.yml -e target_vmid=123                    # dry-run
ansible-playbook destroy.yml -e target_vmid=123 -e i_understand=yes
```

`destroy.yml` refuses 108, infra, templates, and 100–114 no matter what you
pass. The password in `provision.yml` is never printed (`no_log`).

## 6. Architecture & roles

Everything targets the `proxmox` host; container work goes through `pct`.

| Role | What it codifies |
|------|------------------|
| `proxmox_host` | Snippet storage, `ServerNetProvisioner` role, `svc-controller@pve` user + privsep token, `customers` pool, pool-scoped ACL. Token secret → root-only file, never stdout. |
| `cloudinit_snippet` | `sn-linux.yaml` vendor snippet (the Linux password-login fix). |
| `linux_templates` | Downloads cloud images; builds templates 9000–9005 idempotently; attaches the cicustom snippet; adds to the pool. |
| `windows_template` | Stages `SetupComplete.cmd` + `sn-firstboot.ps1` + fixed `cloudbase-init.conf`; optional offline ntfs3 bake (`win_bake_offline`). |
| `control_panel` | LXC 115 Laravel: packages, app from artifact archive, `.env` overlay, migrate, ownership, nginx. **Includes the country-selection feature** (`exit_country` column, sellable-country catalog + sale-time dropdown, `/agent/countryroutes` desired-state API, and the HOST `servernet-country-agent` timer that applies per-VM split-routing). Stage/apply (`panel_apply`; feature toggle `panel_country_feature`). |
| `exit_node` | LXC 113 sing-box: pinned binary, config (baseline template or your exported `config.json`), systemd. Stage/apply. |
| `exit_relay` | **Module 2b**: self-healing swappable relay pool — `servernet-relay` (SSH `-D` clean uplink SOCKS at `127.0.0.1:1080` from `/etc/servernet/relay.conf`) + `servernet-relay-monitor` (auto-rotates the pool on egress loss) + host `servernet-relay-set` helper. Stage/apply (`relay_apply`); optional guarded migration off `de-ssh-socks`. |
| `exit_multicountry` | **Module 2b**: 25-country mihomo pool — `UPLINK` dialer-proxy through the relay, per-country providers + url-test failover, per-country SOCKS listeners, `MATCH,REJECT` kill-switch base; multi-source sync (SoliSpirit + ConfigForge, dedup/cap) + local httpd + 15-min timer. Preserves the on-box secret. Stage/apply (`mc_apply`). |
| `mihomo_killswitch` | **Module 2b**: `servernet-killswitch.service` — a cgroup iptables rule restricting `mihomo.service` to loopback-only egress, so an empty/dead country is CUT, never leaks the Iran IP. Idempotent, persisted. Stage/apply (`killswitch_apply`). |
| `exit_country_routing` | **Module 2b Phase B**: per-country `servernet-country@<cc>` tun2socks services + policy-routing tables with a fail-closed blackhole default + `servernet-vm-country[-del]` customer-assignment helpers. The host helper does **connmark split-routing** (VM gateway stays `10.10.10.1`, inbound SSH/RDP preserved; only new-outbound is marked → table 50 → LXC 113 → tun`<cc>`), plus host prereqs (`send_redirects=0`, `ip_forward=1`, table 50 → LXC 113 + blackhole). Generated from the same `exit_countries` list. Stage/apply (`country_routing_apply`). |
| `exit_dedicated` | **Module 2b**: attach YOUR OWN foreign servers as GUARANTEED per-country exits so MOST countries become sellable with a stable IP. `servernet-exit-set <cc> --ssh\|--socks\|--link` repoints that country's tun (via the `UPSTREAM` var) at a dedicated self-healing SOCKS (`ssh -D` uplink, a SOCKS you run, or a share-link converter), and auto-syncs the panel catalog. `servernet-exit-del <cc>` reverts to the free pool. Stage/apply (`dedicated_apply`); declarative `dedicated_exits` list for full IaC. |
| `pf_agent` | Host DNAT agent in the dedicated `SERVERNET-PF` chain + systemd timer. Stage/apply. |

### 6b. Module 2b — multi-country exit (relay → mihomo → kill-switch → routing)

Module 2b is the proven multi-country exit chain, all inside LXC 113 and driven
from the host via `pct`. Four roles, applied **in order**, each stage-by-default:

1. **`exit_relay`** brings up the swappable clean uplink SOCKS at
   `127.0.0.1:1080` and a self-heal monitor that rotates the relay pool on egress
   loss. Everything downstream is relay-agnostic.
2. **`exit_multicountry`** rebuilds mihomo: every free per-country node is
   **dialed through `UPLINK`** (the relay), so nodes work despite Iran DPI; each
   country is a `url-test` group (same-country failover) with a dedicated SOCKS
   listener (`127.0.0.1:4200X`); rules end in **`MATCH,REJECT`**.
3. **`mihomo_killswitch`** locks the mihomo cgroup to loopback-only egress, so a
   country with no live nodes is **cut, never leaked** (network-layer guarantee
   on top of `MATCH,REJECT`).
4. **`exit_country_routing`** (Phase B) gives each country its own `tun<cc>`
   (`tun2socks` → that country's listener) and a policy-routing table with a
   **fail-closed blackhole default**; customers are attached with
   `servernet-vm-country <vm-ip> <cc>`, which applies **connmark split-routing**
   so the VM keeps inbound management via its Iran IP:port while only new
   outbound exits via the country.

```bash
# Stage everything (no live change), review under /root/.servernet/*, then apply:
ansible-playbook site.yml --tags relay      -e relay_apply=true
ansible-playbook site.yml --tags module2    -e mc_apply=true
ansible-playbook site.yml --tags killswitch -e killswitch_apply=true
ansible-playbook site.yml --tags routing    -e country_routing_apply=true
```

**Customer VMs keep the normal gateway `gw=10.10.10.1`** (the host) — inbound
SSH/RDP via the Iran IP:port keeps working. `servernet-vm-country <vm-ip> <cc>`
then applies connmark split-routing so only *new outbound* exits via the country;
`servernet-vm-country-del <vm-ip>` removes it on destroy. This is fully automated
by the panel: pick a country at sale time and the host `servernet-country-agent`
applies the split within ~30 s (see the `control_panel` country feature). The
relay pool is managed at runtime with `servernet-relay-set add|use|list`.

The 25 countries, listener ports, routing tables and tun IPs are one Jinja list
(`exit_countries` in `roles/exit_multicountry/defaults/main.yml`) that drives the
mihomo config, the sync script, and the Phase-B env files. **Table numbering:**
`table = 140 + country_index` and `tunip = 10.(table-100).0.1/24` — DE/NL/FR/FI
resolve to `141/142/143/147` exactly as in the proven scripts; the remaining
countries follow the same rule (GB=144 … LV=165). Every role documents its
one-line rollback in the header of its `tasks/main.yml`.

### 6c. Dedicated own-server exits — sell MOST countries (`exit_dedicated`)

The free pool only reliably covers ~4 countries and runs on stranger nodes
(never for paid customers). To sell **any** country with a stable, guaranteed IP,
attach a server **you** control as that country's dedicated exit. Install the
mechanism once (`--tags dedicated -e dedicated_apply=true`), then at runtime:

```bash
# a server you have SSH to (most common) — opens a self-healing ssh -D SOCKS:
servernet-exit-set jp --ssh root@203.0.113.9 --key /root/jp_exit_key
# a SOCKS you already run, or a bought share-link:
servernet-exit-set tr --socks 127.0.0.1:1085
servernet-exit-set ae --link "vless://…#my-ae"
servernet-exit-list          # show them
servernet-exit-del jp        # revert jp to the free pool
```

Under the hood it points that country's `servernet-country@<cc>` tun at the
dedicated SOCKS (the `UPSTREAM` var — mihomo and the free pool are untouched),
keeps it alive with `Restart=always`, and regenerates
`config/countries_dedicated.php` so the country appears in the panel's sale-time
dropdown flagged **dedicated**. From there it's identical to any country VPS: the
customer keeps `gw=10.10.10.1` (inbound preserved) and the country-agent applies
connmark split-routing so the VM egresses from your server's IP. The dedicated
SOCKS port is `listener_port + 1000` (43001–43025). For full IaC, declare exits
in the `dedicated_exits` list (SSH keys via vault) instead of running the command.

## 7. Reconcile with the live system

Some things are environment-specific or hand-built; the roles are written to be
correct from scratch, so for the *existing* box confirm/provide:

- **`pve_node`** — real node name (the brief used `ir`).
- **`php_version`** — the panel container's PHP (`php_fpm_sock` follows it).
- **App code** — export once so the panel role can deploy it:
  `pct exec 115 -- tar czf - -C /opt control > roles/control_panel/files/control-app.tar.gz`
  (git-ignored). Without it, only `.env`/nginx are managed.
- **Live exit config** — to keep the exact working sing-box config, drop it in
  `roles/exit_node/files/config.json`; it is used verbatim over the baseline.
- **`pf_public_iface`** — interface holding the public IP
  (`ip -o addr show | grep 85.9.108.118`).

## 8. Module 2 honesty note

The free per-country pool routes through **public nodes run by strangers**.
That is fine for a **free / trial / fallback tier**, and mihomo gives real
same-country automatic failover (it health-checks and always uses the best
alive node per country). **Never route paid customers through it** — for paid,
point the same mihomo groups at your own servers. The `mc_free_tier_only` flag
documents this intent. Country file names in `exit_countries` must match the
SoliSpirit `Countries/` directory exactly (URL-encode spaces).

## 9. Validation done

`ansible-core` couldn't be installed in the build sandbox (restricted network),
so this repo was validated by: YAML well-formedness on all YAML files; Jinja
syntax on all templates; **rendering** each template with representative vars
and checking the output is valid JSON (sing-box), YAML (mihomo), shell
(`bash -n`), and PHP (`php -l`); a structural lint (one module per task, every
play has hosts + roles/tasks); and a truth-table test of the protected-VMID
guard. Run `ansible-playbook site.yml --syntax-check` and `ansible-lint` in
your own environment as a final gate before applying.

### Changelog — 2026-08-07
- **`control_panel` now codifies the panel country-selection feature** (was only
  deployed live via `mod2b/17-panel-deploy.sh`): the 7 app/config/view/migration
  files (byte-identical to the live deploy, verified by decoding the deploy's
  base64), the `/agent/countryroutes` route, and the HOST `servernet-country-agent`
  timer. Toggle `panel_country_feature`; applies with `panel_apply=true`.
- **`exit_country_routing` reconciled to the live connmark split** (was the older
  `gw=10.10.10.20` design): the host `servernet-vm-country[-del]` now do CONNMARK
  marking + `fwmark`→table 50→LXC 113 so the customer keeps `gw=10.10.10.1` and
  inbound SSH/RDP via the Iran IP:port, with only new-outbound routed to the
  country. Added the host prereqs (`send_redirects=0`, `ip_forward=1`, table 50 →
  LXC 113 + blackhole). The country tun now reads an `UPSTREAM` var (free pool by
  default) via a new `servernet-country-run` wrapper, so a dedicated exit can
  repoint it without touching mihomo.
- **New `exit_dedicated` role** — attach your own foreign servers as guaranteed
  per-country exits (`servernet-exit-set <cc> --ssh|--socks|--link`), so most
  countries become sellable with a stable IP. Self-healing, panel-catalog synced,
  reversible. The whole platform now reproduces from `site.yml` alone (11 roles).
