"""Synthetic delivery test for two dedicated Bale test bots in a private test chat."""

import argparse
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


def probe(call, sender, receiver, chat_id, timeout=30, clock=time.monotonic):
    if not sender or not receiver or sender == receiver or not chat_id:
        raise ProbeError("Two distinct dedicated test bots and a test chat are required")
    webhook = call(receiver, "getWebhookInfo", {})
    if not isinstance(webhook, dict) or webhook.get("url"):
        raise ProbeError("Receiver is not an isolated polling test bot")

    marker = "servernet-delivery-probe-" + secrets.token_hex(16)
    sent = call(sender, "sendMessage", {"chat_id": chat_id, "text": marker})
    if not isinstance(sent, dict) or not isinstance(sent.get("message_id"), int):
        raise ProbeError("No send receipt")
    deadline = clock() + timeout
    offset = None
    while clock() < deadline:
        args = {"timeout": 2, "limit": 100}
        if offset is not None:
            args["offset"] = offset
        updates = call(receiver, "getUpdates", args)
        if not isinstance(updates, list):
            raise ProbeError("Invalid receiver response")
        for update in updates:
            update_id = update.get("update_id")
            if isinstance(update_id, int):
                offset = max(offset or 0, update_id + 1)
            message = update.get("message") or {}
            if (message.get("text") == marker
                    and str((message.get("chat") or {}).get("id")) == str(chat_id)):
                return {"delivery_confirmed": True}
        time.sleep(0.5)
    return {"delivery_confirmed": False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--timeout", type=int, default=30, choices=range(1, 61))
    args = parser.parse_args()
    try:
        result = probe(
            bale_call, os.environ.get("BALE_PROBE_SENDER_TOKEN"),
            os.environ.get("BALE_PROBE_RECEIVER_TOKEN"),
            os.environ.get("BALE_PROBE_CHAT_ID"), args.timeout,
        )
        print(json.dumps(result))
        return 0 if result["delivery_confirmed"] else 1
    except ProbeError as exc:
        print(json.dumps({"delivery_confirmed": False, "error": str(exc)}))
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
