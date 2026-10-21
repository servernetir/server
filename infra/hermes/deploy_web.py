#!/usr/bin/env python3
"""Guarded Hermes Laravel release. Default is a read-only plan.

Example expected manifest (every selected target is required):
{"schema": 1, "files": {
  "app/Http/Controllers/HermesMiniAppMonitorController.php": {"before_sha256": "absent", "after_sha256": "..."},
  "app/Services/Hermes/NewService.php": {"before_sha256": "absent", "after_sha256": "..."},
  "resources/views/bale/hermes.blade.php": {"before_sha256": "..."},
  "routes/hermes-monitor.php": {"before_sha256": "absent"},
  "routes/web.php": {"before_sha256": "..."},
  "routes/hermes.php": {"before_sha256": "..."},
  "routes/console.php": {"before_sha256": "..."}
}}

Use --stage-dir DIR --expected-manifest FILE [--app-root DIR]
    [--backup-dir PRIVATE_NEW_DIR] [--lock-file PRIVATE_FILE] [--install].
Backups include manifest.json and rollback.py. Rollback also defaults to a plan:
    python3 PRIVATE_NEW_DIR/rollback.py [--install]

No .env, credential, database, cache, artisan, worker, or n8n operation is performed.
The parent release owns PHP validation and web OPcache/route activation separately.
"""
from __future__ import annotations

import argparse
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import tempfile

HEX = re.compile(r"[a-f0-9]{64}\Z")
WEB_REQUIRE = "require __DIR__.'/hermes-monitor.php';"
SCHEDULE = ("// Hermes owner support review; existing scheduler, bounded overlap lock.\n"
            "\\Illuminate\\Support\\Facades\\Schedule::command('hermes:support-review')\n"
            "    ->hourly()\n"
            "    ->withoutOverlapping(45)\n"
            "    ->name('hermes:support-review');\n")
GENERATED = {"routes/web.php", "routes/hermes.php", "routes/console.php"}
HERMES_PREFIX = "Route::prefix('bale/hermes')->middleware('throttle:90,1')->group"
HERMES_AUDITED_PREFIX = "Route::prefix('bale/hermes')->middleware([\\App\\Http\\Middleware\\HermesMiniAppAudit::class,'throttle:90,1'])->group"
VIEW = "resources/views/bale/hermes.blade.php"
PROTECTED = {"app/Http/Controllers/HermesMiniAppController.php",
             "app/Services/Bale/Admin/HermesMiniAppAuth.php",
             "app/Services/Bale/Admin/HermesMiniAppStore.php",
             "app/Services/Hermes/HermesMiniAppClient.php"}
MONITOR_ANCHOR = '<p class="note" data-i18n="infra_note"></p></section>'
SHOW_ANCHOR = "if(view==='status'&&!overviewLoaded)overview()"
SHOW_MONITOR = "if(view==='status'){if(!overviewLoaded)overview();monitor()}"
REFRESH_ANCHOR = "$('refresh').onclick=overview;"
MONITOR_HTML = '''<section aria-labelledby="monitorTitle" style="margin-top:28px;border-top:1px solid var(--line);padding-top:24px"><div class="section-head"><div><h2 id="monitorTitle"></h2><p id="monitorAutomationState" role="status"></p></div></div><div class="metrics"><div class="metric"><span id="monitorEnabledLabel"></span><strong id="monitorEnabled" style="font:inherit;margin-top:15px">—</strong></div><div class="metric"><span id="monitorLastLabel"></span><strong id="monitorLast" style="font:inherit;margin-top:15px">—</strong></div><div class="metric"><span id="monitorSentLabel"></span><strong id="monitorSent">—</strong></div></div><h3 id="monitorErrorsTitle" style="font-size:14px;margin-top:26px"></h3><p id="monitorErrorsState" class="note" role="status"></p><div id="monitorErrors" class="memory-list" style="margin-top:12px"></div></section>'''
MONITOR_SCRIPT = r'''// Hermes monitoring panel (read-only).
const monitorText=({fa:{title:'پایش و گزارش خطا',hourly:'بررسی تیکت‌ها هر ساعت',last:'آخرین بررسی',sent:'پاسخ‌های ارسال‌شده',errors:'۵ خطای اخیر',enabled:'فعال',disabled:'غیرفعال',loading:'در حال دریافت وضعیت…',unavailable:'دریافت وضعیت ممکن نشد؛ دوباره تازه‌سازی کنید.',empty:'خطایی ثبت نشده است.',count:'تعداد',request:'شناسه پیگیری'},en:{title:'Monitoring and error log',hourly:'Hourly ticket review',last:'Last review',sent:'Replies sent',errors:'5 recent errors',enabled:'Enabled',disabled:'Disabled',loading:'Loading status…',unavailable:'Status unavailable. Refresh to retry.',empty:'No errors recorded.',count:'Count',request:'Request ID'},tr:{title:'İzleme ve hata kaydı',hourly:'Saatlik talep kontrolü',last:'Son kontrol',sent:'Gönderilen yanıtlar',errors:'Son 5 hata',enabled:'Etkin',disabled:'Devre dışı',loading:'Durum yükleniyor…',unavailable:'Durum alınamadı. Yenileyerek tekrar deneyin.',empty:'Kaydedilmiş hata yok.',count:'Sayı',request:'İstek kimliği'}})[lang]||{title:'Monitoring',hourly:'Hourly review',last:'Last review',sent:'Replies sent',errors:'Recent errors',enabled:'Enabled',disabled:'Disabled',loading:'Loading…',unavailable:'Status unavailable.',empty:'No errors recorded.',count:'Count',request:'Request ID'};
let monitorBusy=false;
for(const[id,key]of Object.entries({monitorTitle:'title',monitorEnabledLabel:'hourly',monitorLastLabel:'last',monitorSentLabel:'sent',monitorErrorsTitle:'errors'}))$(id).textContent=monitorText[key];
function monitorDate(value){const n=typeof value==='number'?value:typeof value==='string'?Date.parse(value)/1000:NaN;const at=new Date(n*1000);return Number.isFinite(n)&&n>0&&!Number.isNaN(at.getTime())?at.toLocaleString(lang==='fa'?'fa-IR':lang):'—'}
function monitorCount(value,fallback=0){return Number.isSafeInteger(value)&&value>=0?value:fallback}
function monitorAutomation(result){if(result.status!=='fulfilled'||result.value?.ok!==true||typeof result.value.automation?.enabled!=='boolean'){$('monitorAutomationState').textContent=monitorText.unavailable;for(const id of ['monitorEnabled','monitorLast','monitorSent'])$(id).textContent='—';return}const a=result.value.automation;$('monitorAutomationState').textContent='';$('monitorEnabled').textContent=monitorText[a.enabled?'enabled':'disabled'];$('monitorLast').textContent=monitorDate(a.last_run_at);$('monitorSent').textContent=number(monitorCount(a.sent_replies))}
function monitorErrors(result){const list=$('monitorErrors');list.replaceChildren();if(result.status!=='fulfilled'||result.value?.ok!==true||!Array.isArray(result.value.errors)){$('monitorErrorsState').textContent=monitorText.unavailable;return}const rows=result.value.errors.slice(0,5);$('monitorErrorsState').textContent=rows.length?'':monitorText.empty;for(const item of rows){const row=item&&typeof item==='object'?item:{},card=document.createElement('article'),line=document.createElement('p'),ref=document.createElement('p');const code=typeof row.code==='string'&&/^[A-Za-z0-9_-]{1,80}$/.test(row.code)?row.code:'unknown';const id=typeof row.request_id==='string'&&/^[A-Za-z0-9_-]{8,80}$/.test(row.request_id)?row.request_id:'—';card.className='memory-card';line.dir='auto';line.textContent=code+' · '+monitorDate(row.utc)+' · '+monitorText.count+': '+number(monitorCount(row.count,1));ref.className='note';ref.dir='auto';ref.textContent=monitorText.request+': '+id;card.append(line,ref);list.append(card)}}
async function monitor(){if(!authorized||monitorBusy)return;monitorBusy=true;$('monitorAutomationState').textContent=monitorText.loading;$('monitorErrorsState').textContent=monitorText.loading;try{const results=await Promise.allSettled([api('automation'),api('errors')]);monitorAutomation(results[0]);monitorErrors(results[1])}finally{monitorBusy=false}}
// End Hermes monitoring panel.
$('refresh').onclick=()=>{overview();monitor()};'''


class Refused(RuntimeError):
    pass


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def allowed(relative: str) -> bool:
    if not isinstance(relative, str):
        return False
    p = PurePosixPath(relative)
    if (p.is_absolute() or ".." in p.parts or not p.parts or "\\" in relative
            or relative != p.as_posix() or any(part.startswith(".") for part in p.parts)):
        return False
    return relative not in PROTECTED and ((p.parts[0] == "app" and len(p.parts) > 1 and p.suffix == ".php")
            or relative in {"routes/hermes-monitor.php", VIEW}
            or relative in GENERATED)


def no_symlink_ancestors(path: Path) -> None:
    for current in (path, *path.parents):
        if current.is_symlink():
            raise Refused("symlink path or ancestor refused: " + str(path))


def safe_path(root: Path, relative: str) -> Path:
    if not allowed(relative):
        raise Refused("target outside the Hermes web allowlist: " + relative)
    target = root / relative
    current = root
    no_symlink_ancestors(root)
    for part in PurePosixPath(relative).parts:
        current = current / part
        if current.is_symlink():
            raise Refused("symlink target or parent refused: " + relative)
    if target.exists() and not target.is_file():
        raise Refused("target is not a regular file: " + relative)
    return target


def current_hash(path: Path) -> str:
    return digest(path.read_bytes()) if path.exists() else "absent"


def check_hash(value: str, *, absent: bool = True) -> bool:
    return isinstance(value, str) and ((absent and value == "absent") or HEX.fullmatch(value) is not None)


def expected_files(data: dict) -> dict:
    if not isinstance(data, dict) or not isinstance(data.get("files"), dict):
        raise Refused("expected manifest must contain a files mapping")
    result = {}
    for relative, entry in data["files"].items():
        if not allowed(relative):
            raise Refused("unexpected manifest target: " + relative)
        entry = {"before_sha256": entry} if isinstance(entry, str) else entry
        if not isinstance(entry, dict) or not check_hash(entry.get("before_sha256")):
            raise Refused("missing/invalid before_sha256: " + relative)
        after = entry.get("after_sha256")
        if after is not None and not check_hash(after, absent=False):
            raise Refused("invalid after_sha256: " + relative)
        result[relative] = entry
    return result


def source_files(stage: Path, selected: set[str]) -> dict[str, bytes]:
    result = {}
    for relative in sorted(selected):
        path = safe_path(stage, relative)
        if not path.is_file() or relative in GENERATED:
            raise Refused("selected stage file missing or unsupported: " + relative)
        result[relative] = path.read_bytes()
    if "routes/hermes-monitor.php" not in result or VIEW not in result:
        raise Refused("manifest requires the dedicated monitor route and additive miniapp view")
    return result


def font_patch(view: bytes) -> bytes:
    old = 'body{font:14px/1.9 Tahoma,"Segoe UI",Arial,sans-serif;'
    new = 'body{font:14px/1.9 IRANSans,Tahoma,"Segoe UI",Arial,sans-serif;'
    text = view.decode("utf-8")
    if text.count(old) != 1 or text.count("<style>") != 1 or "@font-face" in text:
        raise Refused("miniapp font baseline changed; reconcile exact font-only patch")
    newline = "\r\n" if "\r\n" in text else "\n"
    fonts = "".join("@font-face{font-family:IRANSans;src:url('/bale/hermes/assets/fonts/IRANSans-"
                    + name + ".woff2') format('woff2');font-style:normal;font-weight:"
                    + str(weight) + ";font-display:swap}" + newline
                    for name, weight in (("regular", 400), ("medium", 500), ("bold", 700)))
    return text.replace("<style>", "<style>" + newline + fonts, 1).replace(old, new, 1).encode("utf-8")


def miniapp_patch(view: bytes) -> bytes:
    text = font_patch(view).decode("utf-8")
    for anchor in (MONITOR_ANCHOR, SHOW_ANCHOR, REFRESH_ANCHOR):
        if text.count(anchor) != 1:
            raise Refused("miniapp monitoring anchor changed; reconcile exact additive patch")
    return (text.replace(MONITOR_ANCHOR, MONITOR_ANCHOR[:-10] + MONITOR_HTML + "</section>", 1)
            .replace(SHOW_ANCHOR, SHOW_MONITOR, 1)
            .replace(REFRESH_ANCHOR, MONITOR_SCRIPT, 1).encode("utf-8"))


def append_php(text: str, addition: str, label: str) -> str:
    if not text.lstrip().startswith("<?php"):
        raise Refused(label + " routes are not a PHP source file")
    newline = "\r\n" if "\r\n" in text else "\n"
    append = newline * 2 + addition.replace("\n", newline)
    if text.rstrip().endswith("?>"):
        closing = text.rfind("?>")
        return text[:closing] + append + text[closing:]
    return text + append


def generated_routes(web: bytes, console: bytes, hermes: bytes) -> dict[str, bytes]:
    web_text = web.decode("utf-8")
    if "hermes-monitor.php" in web_text:
        raise Refused("monitor route is already mentioned/registered; reconcile manually")
    changed_web = append_php(web_text, WEB_REQUIRE + "\n", "web").encode("utf-8")
    console_text = console.decode("utf-8")
    if "hermes:support-review" in console_text:
        raise Refused("support-review is already mentioned/registered; reconcile manually")
    changed_console = append_php(console_text, SCHEDULE, "console")
    hermes_text = hermes.decode("utf-8")
    if hermes_text.count(HERMES_PREFIX) != 1 or "HermesMiniAppAudit" in hermes_text:
        raise Refused("Hermes signed route prefix changed or audit middleware already present")
    return {"routes/web.php": changed_web, "routes/console.php": changed_console.encode("utf-8"),
            "routes/hermes.php": hermes_text.replace(HERMES_PREFIX, HERMES_AUDITED_PREFIX, 1).encode("utf-8")}


@dataclass
class Item:
    relative: str
    source: str
    data: bytes
    before: str
    after: str
    operation: str
    mode: int
    uid: int
    gid: int

    def record(self) -> dict:
        return {"target": self.relative, "source": self.source,
                "before_sha256": self.before, "after_sha256": self.after,
                "operation": self.operation, "mode": self.mode,
                "uid": self.uid, "gid": self.gid,
                "backup": "before/" + self.relative if self.before != "absent" else None}


def priority(item: Item) -> tuple[int, str]:
    if item.relative == "routes/console.php":
        return 60, item.relative
    if item.relative == "resources/views/bale/hermes.blade.php":
        return 50, item.relative
    if item.relative == "routes/web.php":
        return 40, item.relative
    if item.relative == "routes/hermes-monitor.php":
        return 20, item.relative
    if item.relative == "routes/hermes.php":
        return 35, item.relative
    return (10 if item.before == "absent" else 30), item.relative


def build_plan(stage: Path, root: Path, expected: dict) -> list[Item]:
    no_symlink_ancestors(root)
    no_symlink_ancestors(stage)
    if not root.is_dir() or root.is_symlink() or root == root.parent:
        raise Refused("app root must be an existing regular directory")
    if not stage.is_dir() or stage.is_symlink():
        raise Refused("stage root must be an existing regular directory")
    entries = expected_files(expected)
    if not GENERATED <= set(entries):
        raise Refused("manifest must include all three generated route files")
    source = source_files(stage, set(entries) - GENERATED)
    targets = set(entries)
    # Validate EVERY live and staged precondition before preparing any write.
    for relative in sorted(targets):
        target = safe_path(root, relative)
        if current_hash(target) != entries[relative]["before_sha256"]:
            raise Refused("live hash changed: " + relative)
    for relative in GENERATED:
        if entries[relative]["before_sha256"] == "absent":
            raise Refused("existing route file required: " + relative)
    if source[VIEW] != miniapp_patch((root / VIEW).read_bytes()):
        raise Refused("staged view must equal the exact additive miniapp patch of current live view")
    source.update(generated_routes((root / "routes/web.php").read_bytes(),
                                   (root / "routes/console.php").read_bytes(),
                                   (root / "routes/hermes.php").read_bytes()))
    app_info = root.stat()
    items = []
    groups = set(os.getgroups()) | {os.getegid()}
    for relative, data in source.items():
        entry = entries[relative]
        after = digest(data)
        if entry.get("after_sha256") not in (None, after):
            raise Refused("staged/generated source hash changed: " + relative)
        target = safe_path(root, relative)
        info = target.stat() if target.exists() else app_info
        uid, gid = info.st_uid, info.st_gid
        if os.geteuid() != 0 and (uid != os.geteuid() or gid not in groups):
            raise Refused("cannot preserve target ownership: " + relative)
        before = entry["before_sha256"]
        operation = "noop" if before == after else "create" if before == "absent" else "replace"
        items.append(Item(relative, "generated" if relative in GENERATED else str(stage / relative),
                          data, before, after, operation,
                          stat.S_IMODE(info.st_mode) if target.exists() else 0o644, uid, gid))
    return sorted(items, key=priority)


def route_caches(root: Path) -> list[str]:
    directory = root / "bootstrap/cache"
    no_symlink_ancestors(directory)
    return sorted(path.name for path in directory.glob("routes*.php") if path.is_file())


@contextmanager
def release_lock(path: Path):
    no_symlink_ancestors(path)
    if not path.parent.is_dir() or path.parent.is_symlink():
        raise Refused("release lock parent must already exist")
    flags = os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0)
    descriptor = os.open(path, flags, 0o600)
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) & 0o077:
            raise Refused("release lock must be a private regular file")
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    except BlockingIOError as exc:
        raise Refused("another web release holds the lock") from exc
    finally:
        os.close(descriptor)


def atomic_write(path: Path, data: bytes, mode: int, uid: int, gid: int) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix="." + path.name + ".hermes-", dir=path.parent)
    try:
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(data)
            handle.flush()
            info = os.fstat(handle.fileno())
            if (info.st_uid, info.st_gid) != (uid, gid):
                os.fchown(handle.fileno(), uid, gid)
            os.fchmod(handle.fileno(), mode)
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def private_json(path: Path, data: dict) -> None:
    atomic_write(path, (json.dumps(data, ensure_ascii=True, indent=2, sort_keys=True) + "\n").encode(),
                 0o600, os.geteuid(), os.getegid())


def guarded_backup_dir(backup: Path, root: Path, stage: Path) -> None:
    no_symlink_ancestors(backup)
    for forbidden in (root, stage, root.parent / "public_html"):
        if backup == forbidden or forbidden in backup.parents:
            raise Refused("backup must be outside app, stage and public roots")
    if backup.exists() or backup.is_symlink():
        raise Refused("backup directory must be new")


def install(stage: Path, root: Path, expected: dict, backup: Path, lock_path: Path) -> dict:
    guarded_backup_dir(backup, root, stage)
    with release_lock(lock_path):
        items = build_plan(stage, root, expected)
        caches = route_caches(root)
        if caches:
            raise Refused("cached routes present; validated activation strategy required: " + ",".join(caches))
        backup.mkdir(mode=0o700, parents=True, exist_ok=False)
        os.chmod(backup, 0o700)
        manifest = {"schema": 1, "utc": datetime.now(timezone.utc).isoformat(),
                    "status": "preparing", "app_root": str(root), "stage_dir": str(stage),
                    "lock_file": str(lock_path), "route_caches": caches,
                    "activation_performed": False, "items": [item.record() for item in items]}
        # Backup all existing non-noop targets and verify all backups before any target write.
        for item in items:
            if item.operation == "noop" or item.before == "absent":
                continue
            target = safe_path(root, item.relative)
            if current_hash(target) != item.before:
                raise Refused("live file changed before backup: " + item.relative)
            destination = backup / "before" / item.relative
            destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            atomic_write(destination, target.read_bytes(), 0o600, os.geteuid(), os.getegid())
            if current_hash(destination) != item.before:
                raise Refused("backup hash mismatch: " + item.relative)
        release_copy = backup / "release.py"
        atomic_write(release_copy, Path(__file__).read_bytes(), 0o600, os.geteuid(), os.getegid())
        rollback_source = ("#!/usr/bin/env python3\nimport pathlib,subprocess,sys\n"
                           "here=pathlib.Path(__file__).resolve().parent\n"
                           "raise SystemExit(subprocess.call([sys.executable,str(here/'release.py'),"
                           "'--rollback-manifest',str(here/'manifest.json'),*sys.argv[1:]]))\n")
        atomic_write(backup / "rollback.py", rollback_source.encode(), 0o700, os.geteuid(), os.getegid())
        private_json(backup / "manifest.json", manifest)
        # Every destination is checked once more after backups and before the first write.
        for item in items:
            if current_hash(safe_path(root, item.relative)) != item.before:
                raise Refused("live file changed before release: " + item.relative)
        written = []
        try:
            for item in items:
                if item.operation == "noop":
                    continue
                target = safe_path(root, item.relative)
                if current_hash(target) != item.before:
                    raise Refused("live file changed during release: " + item.relative)
                atomic_write(target, item.data, item.mode, item.uid, item.gid)
                written.append(item)
                if current_hash(target) != item.after:
                    raise Refused("installed hash mismatch: " + item.relative)
            manifest["status"] = "installed_only"
        except Exception:
            reverted, conflicts = [], []
            for item in reversed(written):
                try:
                    target = safe_path(root, item.relative)
                    if current_hash(target) != item.after:
                        conflicts.append(item.relative)
                        continue
                    if item.before == "absent":
                        target.unlink()
                    else:
                        saved = backup / "before" / item.relative
                        no_symlink_ancestors(saved)
                        if current_hash(saved) != item.before:
                            raise Refused("automatic rollback backup changed")
                        atomic_write(target, saved.read_bytes(), item.mode, item.uid, item.gid)
                    reverted.append(item.relative)
                except Exception:
                    conflicts.append(item.relative)
            manifest.update(status="install_failed", reverted=reverted, rollback_conflicts=conflicts)
            private_json(backup / "manifest.json", manifest)
            raise
        private_json(backup / "manifest.json", manifest)
        return manifest


def rollback(manifest_path: Path, execute: bool = False) -> dict:
    no_symlink_ancestors(manifest_path)
    manifest = json.loads(manifest_path.read_text())
    root = Path(manifest["app_root"])
    lock_path = Path(manifest["lock_file"])
    backup = manifest_path.parent
    items = manifest["items"]
    status = manifest.get("status")
    if status not in ("installed_only", "preparing", "install_failed", "rolling_back"):
        raise Refused("only a known installed/interrupted release may be rolled back automatically")
    def validate():
        for item in items:
            target = safe_path(root, item["target"])
            if not check_hash(item["before_sha256"]) or not check_hash(item["after_sha256"], absent=False):
                raise Refused("invalid rollback hash")
            known = {item["after_sha256"]}
            if status != "installed_only":
                known.add(item["before_sha256"])
            if current_hash(target) not in known:
                raise Refused("newer live file blocks rollback: " + item["target"])
            if item["operation"] not in ("create", "replace", "noop"):
                raise Refused("invalid rollback operation")
            if ((item["operation"] == "create" and item["before_sha256"] != "absent")
                    or (item["operation"] == "replace" and item["before_sha256"] == "absent")
                    or (item["operation"] == "noop" and item["before_sha256"] != item["after_sha256"])):
                raise Refused("rollback operation/hash mismatch")
            if item["operation"] == "replace":
                expected_backup = "before/" + item["target"]
                no_symlink_ancestors(backup / expected_backup)
                if item.get("backup") != expected_backup or current_hash(backup / expected_backup) != item["before_sha256"]:
                    raise Refused("rollback backup is missing or changed: " + item["target"])
    validate()
    report = {"status": "rollback_plan", "app_root": str(root),
              "targets": [item["target"] for item in items if item["operation"] != "noop"
                          and current_hash(safe_path(root, item["target"])) == item["after_sha256"]],
              "activation_performed": False}
    if not execute:
        return report
    with release_lock(lock_path):
        validate()
        manifest["status"] = "rolling_back"
        private_json(manifest_path, manifest)
        for item in reversed(items):
            if item["operation"] == "noop":
                continue
            target = safe_path(root, item["target"])
            actual = current_hash(target)
            if status != "installed_only" and actual == item["before_sha256"]:
                continue
            if actual != item["after_sha256"]:
                raise Refused("live file changed during rollback: " + item["target"])
            if item["operation"] == "create":
                target.unlink()
            else:
                atomic_write(target, (backup / item["backup"]).read_bytes(),
                             item["mode"], item["uid"], item["gid"])
        manifest["status"] = "rolled_back_files_only"
        private_json(manifest_path, manifest)
    return {**report, "status": "rolled_back_files_only"}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--stage-dir", type=Path)
    parser.add_argument("--app-root", type=Path, default=Path("/home/servernetcloud/servernet_app"))
    parser.add_argument("--expected-manifest", type=Path)
    parser.add_argument("--backup-dir", type=Path)
    parser.add_argument("--lock-file", type=Path)
    parser.add_argument("--rollback-manifest", type=Path)
    parser.add_argument("--install", action="store_true", help="actually install/rollback; otherwise plan only")
    args = parser.parse_args()
    if args.rollback_manifest:
        result = rollback(args.rollback_manifest.absolute(), args.install)
    else:
        if not args.stage_dir or not args.expected_manifest:
            parser.error("--stage-dir and --expected-manifest are required")
        stage, root = args.stage_dir.absolute(), args.app_root.absolute()
        expected = json.loads(args.expected_manifest.read_text())
        if args.install:
            if not args.backup_dir:
                parser.error("--backup-dir is required with --install")
            lock_path = args.lock_file.absolute() if args.lock_file else root.parent / ".servernet-web-release.lock"
            result = install(stage, root, expected, args.backup_dir.absolute(), lock_path)
        else:
            items = build_plan(stage, root, expected)
            result = {"status": "plan", "app_root": str(root), "stage_dir": str(stage),
                      "route_caches": route_caches(root), "activation_performed": False,
                      "items": [item.record() for item in items]}
    print(json.dumps(result, ensure_ascii=True, indent=2, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except (Refused, OSError, ValueError, KeyError, UnicodeError) as exc:
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=True), file=sys.stderr)
        raise SystemExit(1)
