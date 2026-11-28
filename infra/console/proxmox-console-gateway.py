"""Private two-VM VNC relay. No Proxmox credentials or upstream URLs reach the browser."""
import asyncio
import json
import re
import secrets
import socket
import ssl
import time
from pathlib import Path

import aiohttp
from aiohttp import web
from aiohttp.abc import AbstractResolver

ALLOWED_VMS = {"116", "130"}
ALLOWED_ORIGINS = {"https://console.servernet.cloud", "https://servernet.cloud"}
PUBLIC = "pve-console-ir.servernet.cloud"
CLAIM = "https://console.servernet.cloud/agent/proxmox-console/claim"
PVE = "https://pve.servernet.cloud:8006/api2/json/nodes/ir/qemu/"
HEX = re.compile(r"^[a-f0-9]{64}$")
TICKET = re.compile(r"^PVEVNC:[A-Za-z0-9+/=:@_.-]+$")
PASSWORD = re.compile(r"^[!-~]{8}$")
SESSIONS = {}
BUSY = set()
PREPARE_LIMIT = asyncio.Semaphore(4)


class PveResolver(AbstractResolver):
    async def resolve(self, host, port=0, family=socket.AF_INET):
        if host != "pve.servernet.cloud":
            raise OSError("unexpected upstream")
        return [{"hostname": host, "host": "10.10.10.1", "port": port,
                 "family": socket.AF_INET, "proto": 0, "flags": 0}]

    async def close(self):
        pass


def result(body, status=200):
    return web.json_response(body, status=status, headers={
        "Cache-Control": "no-store, private", "Referrer-Policy": "no-referrer",
        "X-Content-Type-Options": "nosniff"})


async def discard(sid):
    record = SESSIONS.pop(sid, None)
    if record:
        BUSY.discard(record["vm"])
        await record["upstream"].close()


async def expiry(sid):
    await asyncio.sleep(35)
    await discard(sid)


async def health(_):
    return result({"ok": True, "service": "console-gateway"})


async def prepare(request):
    if PREPARE_LIMIT.locked():
        return result({"ok": False}, 429)
    vm = None
    upstream = None
    reserved = False
    async with PREPARE_LIMIT:
        try:
            body = await request.json()
            grant = body.get("grant") if isinstance(body, dict) else None
            if not isinstance(grant, str) or not HEX.fullmatch(grant):
                return result({"ok": False}, 400)
            async with request.app["claim_http"].post(
                CLAIM, json={"grant": grant}, allow_redirects=False
            ) as response:
                if response.status != 200 or response.content_length and response.content_length > 4096:
                    return result({"ok": False}, 403)
                raw = await response.content.read(4097)
                if len(raw) > 4096:
                    return result({"ok": False}, 403)
                claim = json.loads(raw)
            vm = claim.get("vm")
            if claim.get("ok") is not True or vm not in ALLOWED_VMS:
                return result({"ok": False}, 403)
            if vm in BUSY:
                return result({"ok": False}, 409)
            BUSY.add(vm)
            reserved = True
            base = PVE + vm
            async with request.app["pve_http"].post(
                base + "/vncproxy", data={"websocket": "1"}, allow_redirects=False
            ) as response:
                if response.status != 200:
                    raise ValueError("grant failed")
                raw = await response.content.read(32769)
                if len(raw) > 32768:
                    raise ValueError("invalid grant")
                data = json.loads(raw).get("data", {})
            port = data.get("port")
            if isinstance(port, str) and port.isdecimal():
                port = int(port)
            ticket, password = data.get("ticket"), data.get("password")
            if not isinstance(ticket, str) or len(ticket) > 4096:
                raise ValueError("invalid ticket")
            if ":" in ticket and not ticket.startswith("PVEVNC:"):
                prefix, ticket = ticket.split(":", 1)
                if password != prefix:
                    raise ValueError("challenge mismatch")
            if (not isinstance(port, int) or not 5900 <= port <= 5999
                    or not TICKET.fullmatch(ticket)
                    or not isinstance(password, str) or not PASSWORD.fullmatch(password)):
                raise ValueError("invalid grant")
            # Establish the upstream now, before the temporary PVE proxy expires.
            upstream = await request.app["pve_http"].ws_connect(
                base + "/vncwebsocket", params={"port": str(port), "vncticket": ticket},
                protocols=("binary",), max_msg_size=8 * 1024 * 1024, heartbeat=20)
            sid = secrets.token_hex(32)
            SESSIONS[sid] = {"vm": vm, "upstream": upstream, "expires": time.monotonic() + 35}
            asyncio.create_task(expiry(sid))
            reserved = False
            upstream = None
            return result({"url": f"wss://{PUBLIC}/ws/{sid}", "password": password})
        except (Exception, asyncio.CancelledError):
            return result({"ok": False}, 502)
        finally:
            if upstream is not None:
                await upstream.close()
            if reserved:
                BUSY.discard(vm)


async def pipe(src, dst):
    async for message in src:
        if message.type == aiohttp.WSMsgType.BINARY:
            await dst.send_bytes(message.data)
        elif message.type == aiohttp.WSMsgType.TEXT:
            # The RFB protocol must be binary; never relay arbitrary text.
            break
        elif message.type in (aiohttp.WSMsgType.ERROR, aiohttp.WSMsgType.CLOSE):
            break


async def websocket(request):
    sid = request.match_info["sid"]
    if request.headers.get("Origin") not in ALLOWED_ORIGINS or not HEX.fullmatch(sid):
        return result({"ok": False}, 403)
    record = SESSIONS.pop(sid, None)  # single event loop: claim exactly once
    if not record:
        return result({"ok": False}, 410)
    upstream = record["upstream"]
    downstream = web.WebSocketResponse(protocols=("binary",), max_msg_size=8 * 1024 * 1024, heartbeat=20)
    tasks = []
    try:
        if record["expires"] < time.monotonic() or upstream.closed:
            return result({"ok": False}, 410)
        await downstream.prepare(request)
        tasks = [asyncio.create_task(pipe(upstream, downstream)),
                 asyncio.create_task(pipe(downstream, upstream))]
        await asyncio.wait(tasks, timeout=25*60, return_when=asyncio.FIRST_COMPLETED)
        return downstream
    except Exception:
        return downstream
    finally:
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        await upstream.close()
        if downstream.prepared:
            await downstream.close()
        BUSY.discard(record["vm"])


async def clients(app):
    ca = ssl.create_default_context()
    authorization = Path("/etc/servernet-console/pve-auth").read_text().strip()
    if not authorization.startswith("PVEAPIToken="):
        raise RuntimeError("missing console authorization")
    timeout = aiohttp.ClientTimeout(total=12, connect=4)
    app["pve_http"] = aiohttp.ClientSession(
        connector=aiohttp.TCPConnector(resolver=PveResolver(), ssl=ca),
        headers={"Authorization": authorization}, timeout=timeout)
    app["claim_http"] = aiohttp.ClientSession(timeout=timeout)
    yield
    for sid in list(SESSIONS):
        await discard(sid)
    await app["pve_http"].close()
    await app["claim_http"].close()


def make_app():
    app = web.Application(client_max_size=4096)
    app.cleanup_ctx.append(clients)
    app.router.add_get("/health", health)
    app.router.add_post("/prepare", prepare)
    app.router.add_get("/ws/{sid}", websocket)
    return app


if __name__ == "__main__":
    web.run_app(make_app(), host="10.10.10.30", port=8787, access_log=None, print=None)
