#!/usr/bin/env python3
"""Append browser chat snapshots to a local JSONL or Markdown demo log.

The input is JSON produced by scripts/demo-chat-browser-snapshot.js. The script
keeps a small sidecar state file beside the log so repeated snapshots append
only new messages while still recording page changes.
"""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
from pathlib import Path
import sys
from typing import Any


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--log",
        default=".demo-logs/latest-demo-chat.jsonl",
        help="Log path. Default: .demo-logs/latest-demo-chat.jsonl",
    )
    parser.add_argument(
        "--format",
        choices=["jsonl", "markdown"],
        default=None,
        help="Output format. Default: infer from --log suffix, falling back to jsonl.",
    )
    parser.add_argument(
        "--label",
        default="Drupal AI Guidance demo",
        help="Human-readable demo label used when creating a new log.",
    )
    parser.add_argument(
        "--init",
        action="store_true",
        help="Create or reset the log and sidecar state, then exit unless JSON is provided on stdin.",
    )
    return parser.parse_args()


def read_stdin_json() -> dict[str, Any] | None:
    if sys.stdin.isatty():
        return None
    raw = sys.stdin.read().strip()
    if not raw:
        return None
    try:
      data = json.loads(raw)
    except json.JSONDecodeError as exc:
      raise SystemExit(f"Input was not valid JSON: {exc}") from exc
    if not isinstance(data, dict):
      raise SystemExit("Input JSON must be an object.")
    return data


def load_state(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {"seen_messages": [], "last_page_key": ""}
    try:
        state = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {"seen_messages": [], "last_page_key": ""}
    return {
        "seen_messages": list(state.get("seen_messages", [])),
        "last_page_key": str(state.get("last_page_key", "")),
    }


def save_state(path: Path, state: dict[str, Any]) -> None:
    path.write_text(json.dumps(state, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def message_hash(message: dict[str, Any]) -> str:
    payload = {
        "role": str(message.get("role", "unknown")),
        "text": normalize_text(str(message.get("text", ""))),
    }
    return hashlib.sha256(json.dumps(payload, sort_keys=True).encode("utf-8")).hexdigest()


def normalize_text(text: str) -> str:
    lines = [line.rstrip() for line in text.replace("\r\n", "\n").replace("\r", "\n").split("\n")]
    while lines and not lines[0].strip():
        lines.pop(0)
    while lines and not lines[-1].strip():
        lines.pop()
    return "\n".join(lines)


def log_format(log_path: Path, requested: str | None) -> str:
    if requested:
        return requested
    return "markdown" if log_path.suffix.lower() in {".md", ".markdown"} else "jsonl"


def fenced(text: str) -> str:
    text = normalize_text(text)
    fence = "```"
    if fence in text:
        fence = "````"
    return f"{fence}\n{text}\n{fence}"


def init_log(log_path: Path, state_path: Path, label: str, output_format: str) -> None:
    log_path.parent.mkdir(parents=True, exist_ok=True)
    created = dt.datetime.now(dt.timezone.utc).astimezone().isoformat(timespec="seconds")
    if output_format == "markdown":
        log_path.write_text(
            f"# {label}\n\n"
            f"- Created: `{created}`\n"
            "- Scope: local demo debugging transcript from the visible browser chat.\n"
            "- Note: this file is ignored by git via `.demo-logs/`.\n\n",
            encoding="utf-8",
        )
    else:
        event = {
            "type": "session_start",
            "label": label,
            "created_at": created,
            "scope": "local demo debugging transcript from the visible browser chat",
        }
        log_path.write_text(json.dumps(event, ensure_ascii=False, sort_keys=True) + "\n", encoding="utf-8")
    save_state(state_path, {"seen_messages": [], "last_page_key": ""})


def append_snapshot(
    log_path: Path,
    state_path: Path,
    data: dict[str, Any],
    label: str,
    output_format: str,
) -> tuple[int, bool]:
    if not log_path.exists():
        init_log(log_path, state_path, label, output_format)

    state = load_state(state_path)
    seen = set(state["seen_messages"])

    captured_at = str(data.get("captured_at") or dt.datetime.now(dt.timezone.utc).isoformat())
    title = str(data.get("title") or "")
    url = str(data.get("url") or "")
    path = str(data.get("path") or "")
    headings = [str(value) for value in data.get("page_headings", []) if str(value).strip()]
    page_key = hashlib.sha256(f"{title}\n{url}".encode("utf-8")).hexdigest()
    page_changed = page_key != state.get("last_page_key")

    messages = data.get("messages", [])
    if not isinstance(messages, list):
        messages = []

    new_messages: list[dict[str, Any]] = []
    for message in messages:
        if not isinstance(message, dict):
            continue
        text = normalize_text(str(message.get("text", "")))
        if not text:
            continue
        digest = message_hash(message)
        if digest in seen:
            continue
        seen.add(digest)
        new_messages.append({
            "role": str(message.get("role", "unknown")),
            "text": text,
            "message_hash": digest,
        })

    if not page_changed and not new_messages:
        save_state(state_path, {"seen_messages": sorted(seen), "last_page_key": page_key})
        return 0, False

    page = {
        "url": url,
        "title": title,
        "path": path,
        "headings": headings[:8],
    }
    if output_format == "jsonl":
        event = {
            "type": "snapshot",
            "captured_at": captured_at,
            "page_changed": page_changed,
            "page": page,
            "deep_chat_present": bool(data.get("deep_chat_present")),
            "visible_message_count": int(data.get("message_count") or len(messages)),
            "new_messages": new_messages,
        }
        with log_path.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(event, ensure_ascii=False, sort_keys=True) + "\n")
        save_state(state_path, {"seen_messages": sorted(seen), "last_page_key": page_key})
        return len(new_messages), page_changed

    lines = [
        f"## Snapshot: {captured_at}",
        "",
        f"- URL: `{url}`",
        f"- Title: `{title}`",
    ]
    if path:
        lines.append(f"- Path: `{path}`")
    if headings:
        lines.append("- Page headings: " + ", ".join(f"`{heading}`" for heading in headings[:8]))
    lines.append("")

    if not new_messages:
        lines.append("_No new chat messages in this snapshot._")
        lines.append("")
    else:
        for message in new_messages:
            role = message["role"].title()
            lines.append(f"### {role}")
            lines.append("")
            lines.append(fenced(message["text"]))
            lines.append("")

    with log_path.open("a", encoding="utf-8") as handle:
      handle.write("\n".join(lines).rstrip() + "\n\n")

    save_state(state_path, {"seen_messages": sorted(seen), "last_page_key": page_key})
    return len(new_messages), page_changed


def main() -> int:
    args = parse_args()
    log_path = Path(args.log)
    state_path = log_path.with_suffix(log_path.suffix + ".state.json")
    output_format = log_format(log_path, args.format)
    data = read_stdin_json()

    if args.init:
        init_log(log_path, state_path, args.label, output_format)
        if data is None:
            print(f"Initialized {log_path}")
            return 0

    if data is None:
        raise SystemExit("No snapshot JSON provided on stdin. Use --init to create an empty log.")

    count, page_changed = append_snapshot(log_path, state_path, data, args.label, output_format)
    print(f"Appended {count} new message(s) to {log_path}" + ("; page changed" if page_changed else ""))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
