"""Synthetic delivery test for two dedicated Bale test bots in a private test chat."""

import argparse
import hmac
import json
import os
import re
import secrets
import time
import urllib.request


class ProbeError(RuntimeError):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def bale_call(token, method, data):
    if not re.fullmatch(r"[0-9]+:[A-Za-z0-9_-]+", token):
        raise ProbeError("Invalid test bot token format")
    request = urllib.request.Request(
        f"https://tapi.bale.ai/bot{token}/{method}",
        data=json.dumps(data).encode(),
        headers={"Content-Type": "application/json"},
    )
    try:
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=15) as response:
            result = json.load(response)
    except Exception:
        raise ProbeError(f"{method}: request failed") from None
    if not isinstance(result, dict) or result.get("ok") is not True:
        raise ProbeError(f"{method}: API rejected request")
    return result.get("result")


def bot_id(call, token):
    me = call(token, "getMe", {})
    if not isinstance(me, dict) or type(me.get("id")) is not int or me["id"] <= 0:
        raise ProbeError("Cannot establish test bot identity")
    return me["id"]


def require_polling_bot(call, token):
    webhook = call(token, "getWebhookInfo", {})
    if not isinstance(webhook, dict) or webhook.get("url") != "":
        raise ProbeError("Configured bot is not an isolated polling test bot")


def send(call, token, chat_id, marker):
    sent = call(token, "sendMessage", {"chat_id": chat_id, "text": marker})
    if not isinstance(sent, dict) or not isinstance(sent.get("message_id"), int):
        raise ProbeError("No send receipt")


def wait_for(call, token, chat_id, peer_id, accept, timeout, clock=time.monotonic):
    deadline = clock() + timeout
    offset = None
    while clock() < deadline:
        args = {"timeout": 2, "limit": 100}
        if offset is not None:
            args["offset"] = offset
        updates = call(token, "getUpdates", args)
        if not isinstance(updates, list):
            raise ProbeError("Invalid receiver response")
        for update in updates:
            if not isinstance(update, dict):
                continue
            update_id = update.get("update_id")
            if isinstance(update_id, int):
                offset = max(offset or 0, update_id + 1)
            message = update.get("message") or {}
            if not isinstance(message, dict):
                continue
            chat = message.get("chat")
            source = message.get("from")
            if (isinstance(chat, dict) and isinstance(source, dict)
                    and str(chat.get("id")) == str(chat_id) and source.get("id") == peer_id):
                value = accept(message.get("text"))
                if value is not None:
                    return value if clock() <= deadline else None
        time.sleep(0.5)
    return None


def probe(call, sender, receiver, chat_id, timeout=30, clock=time.monotonic):
    if not sender or not receiver or sender == receiver or not chat_id:
        raise ProbeError("Two distinct dedicated test bots and a test chat are required")
    require_polling_bot(call, sender)
    require_polling_bot(call, receiver)
    sender_id, receiver_id = bot_id(call, sender), bot_id(call, receiver)
    if sender_id == receiver_id:
        raise ProbeError("Test bots must have distinct identities")
    marker = "servernet-delivery-probe-" + secrets.token_hex(16)
    send(call, sender, chat_id, marker)
    got = wait_for(call, receiver, chat_id, sender_id,
                   lambda text: True if text == marker else None, timeout, clock)
    return {"delivery_confirmed": got is True}


# Two-host mode: a fixed, synthetic ping/pong only. No operational payloads.
PREFIX = "servernet-link-pilot-v2"
MAX_AGE = 120


def signed_marker(key, kind, nonce, timestamp, source, dest, chat):
    body = f"{PREFIX}:{kind}:{nonce}:{timestamp}:{source}:{dest}:{chat}"
    return body + ":" + hmac.digest(key, body.encode(), "sha256").hex()


def verify_marker(text, key, kind, source, dest, chat, now, expected_nonce=None):
    if not isinstance(text, str) or len(text) > 300:
        return None
    fields = text.split(":")
    if len(fields) != 8:
        return None
    prefix, received_kind, nonce, timestamp, sender_id, dest_id, chat_id, signature = fields
    if (prefix != PREFIX or received_kind != kind or sender_id != str(source)
            or dest_id != str(dest) or chat_id != str(chat)
            or not re.fullmatch(r"[0-9a-f]{32}", nonce)
            or not re.fullmatch(r"[0-9]{1,12}", timestamp)
            or not re.fullmatch(r"[0-9a-f]{64}", signature)
            or not -5 <= now - int(timestamp) <= MAX_AGE
            or (expected_nonce is not None and nonce != expected_nonce)):
        return None
    expected = signed_marker(key, kind, nonce, int(timestamp), source, dest, chat)
    return nonce if hmac.compare_digest(text, expected) else None


def roundtrip(call, role, token, peer_id, chat_id, key, timeout=30,
              clock=time.monotonic, wall=time.time):
    if (role not in ("initiator", "responder") or not token
            or type(peer_id) is not int or peer_id <= 0
            or not re.fullmatch(r"-?[0-9]{1,20}", str(chat_id))
            or not isinstance(key, bytes) or len(key) != 32):
        raise ProbeError("Invalid isolated two-host pilot settings")
    require_polling_bot(call, token)
    local = bot_id(call, token)
    if local == peer_id:
        raise ProbeError("Test bots must have distinct identities")
    if role == "initiator":
        nonce = secrets.token_hex(16)
        started = clock()
        send(call, token, chat_id, signed_marker(key, "ping", nonce, int(wall()), local, peer_id, chat_id))
        got = wait_for(call, token, chat_id, peer_id,
                       lambda text: verify_marker(text, key, "pong", peer_id, local, chat_id, int(wall()), nonce),
                       timeout, clock)
        result = {"role": role, "roundtrip_confirmed": got == nonce}
        if got == nonce:
            result["roundtrip_ms"] = round((clock() - started) * 1000)
        return result

    got = wait_for(call, token, chat_id, peer_id,
                   lambda text: verify_marker(text, key, "ping", peer_id, local, chat_id, int(wall())),
                   timeout, clock)
    if got is not None:
        send(call, token, chat_id, signed_marker(key, "pong", got, int(wall()), local, peer_id, chat_id))
    # Only the initiator can assert that the pong was actually received.
    return {"role": role, "peer_ping_received": got is not None, "ack_submitted": got is not None,
            "roundtrip_confirmed": False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--timeout", type=int, default=30, choices=range(1, 61))
    parser.add_argument("--role", choices=("one-way", "initiator", "responder"), default="one-way")
    args = parser.parse_args()
    try:
        if args.role == "one-way":
            result = probe(
                bale_call, os.environ.get("BALE_PROBE_SENDER_TOKEN"),
                os.environ.get("BALE_PROBE_RECEIVER_TOKEN"),
                os.environ.get("BALE_PROBE_CHAT_ID"), args.timeout,
            )
        else:
            secret = os.environ.get("BALE_PROBE_TEST_KEY", "")
            peer = os.environ.get("BALE_PROBE_PEER_ID", "")
            if not re.fullmatch(r"[0-9a-fA-F]{64}", secret) or not re.fullmatch(r"[0-9]{1,20}", peer):
                raise ProbeError("Dedicated 32-byte pilot key and peer bot ID are required")
            result = roundtrip(bale_call, args.role, os.environ.get("BALE_PROBE_LOCAL_TOKEN"),
                               int(peer), os.environ.get("BALE_PROBE_CHAT_ID"), bytes.fromhex(secret), args.timeout)
        print(json.dumps(result))
        return 0 if result.get("delivery_confirmed") or result.get("roundtrip_confirmed") or result.get("ack_submitted") else 1
    except ProbeError as exc:
        print(json.dumps({"delivery_confirmed": False, "error": str(exc)}))
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
