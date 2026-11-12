"""Parser sumber: RSS 2.0, Atom, dan JSON (S01)."""

from __future__ import annotations

import html
import json
import re
from dataclasses import dataclass
from datetime import datetime
from email.utils import parsedate_to_datetime
from xml.etree import ElementTree as ET

from app.core.timeutil import as_utc


class ParseError(ValueError):
    pass


@dataclass
class RawItem:
    title: str
    body: str
    url: str = ""
    external_id: str = ""
    author: str = ""
    published_at: datetime | None = None


_TAG = re.compile(r"<[^>]+>")
_WS = re.compile(r"\s+")


def clean_text(value: str | None) -> str:
    """Hapus tag HTML, dekode entitas, rapikan spasi."""
    if not value:
        return ""
    text = _TAG.sub(" ", value)
    text = html.unescape(text)
    return _WS.sub(" ", text).strip()


def parse_datetime(value: str | None) -> datetime | None:
    if not value:
        return None
    value = value.strip()
    try:
        return as_utc(parsedate_to_datetime(value))
    except (TypeError, ValueError, IndexError):
        pass
    try:
        return as_utc(datetime.fromisoformat(value.replace("Z", "+00:00")))
    except ValueError:
        return None


def _local(tag: str) -> str:
    return tag.rsplit("}", 1)[-1].lower()


def _child_text(el: ET.Element, *names: str) -> str:
    for child in el:
        if _local(child.tag) in names:
            return (child.text or "").strip() or "".join(child.itertext()).strip()
    return ""


def parse_feed(data: bytes) -> list[RawItem]:
    head = data[:2048].lower()
    if b"<!doctype" in head or b"<!entity" in data.lower():
        raise ParseError("Feed dengan DOCTYPE/ENTITY ditolak (pencegahan XXE/entity expansion)")
    try:
        root = ET.fromstring(data)
    except ET.ParseError as exc:
        raise ParseError(f"XML tidak valid: {exc}") from exc

    items: list[RawItem] = []
    for el in root.iter():
        name = _local(el.tag)
        if name == "item":  # RSS 2.0 / RSS 1.0
            link = _child_text(el, "link")
            body = _child_text(el, "encoded") or _child_text(el, "description")
            items.append(RawItem(
                title=clean_text(_child_text(el, "title")),
                body=clean_text(body),
                url=link,
                external_id=_child_text(el, "guid") or link,
                author=clean_text(_child_text(el, "creator", "author")),
                published_at=parse_datetime(_child_text(el, "pubdate", "date")),
            ))
        elif name == "entry":  # Atom
            link = ""
            for child in el:
                if _local(child.tag) == "link" and child.get("rel", "alternate") == "alternate":
                    link = child.get("href", "")
                    break
            author = ""
            for child in el:
                if _local(child.tag) == "author":
                    author = _child_text(child, "name")
            items.append(RawItem(
                title=clean_text(_child_text(el, "title")),
                body=clean_text(_child_text(el, "content") or _child_text(el, "summary")),
                url=link,
                external_id=_child_text(el, "id") or link,
                author=clean_text(author),
                published_at=parse_datetime(_child_text(el, "published") or _child_text(el, "updated")),
            ))
    return items


def parse_json(data: bytes) -> list[RawItem]:
    try:
        payload = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ParseError(f"JSON tidak valid: {exc}") from exc
    if isinstance(payload, dict):
        payload = payload.get("items", [])
    if not isinstance(payload, list):
        raise ParseError("JSON harus berupa array objek atau {\"items\": [...]}")
    items = []
    for obj in payload:
        if not isinstance(obj, dict):
            continue
        items.append(RawItem(
            title=clean_text(str(obj.get("title", ""))),
            body=clean_text(str(obj.get("body") or obj.get("content") or obj.get("text") or "")),
            url=str(obj.get("url", "")),
            external_id=str(obj.get("external_id") or obj.get("id") or obj.get("url") or ""),
            author=clean_text(str(obj.get("author", ""))),
            published_at=parse_datetime(str(obj["published_at"])) if obj.get("published_at") else None,
        ))
    return items


def parse_any(data: bytes) -> list[RawItem]:
    stripped = data.lstrip()
    if stripped[:1] in (b"[", b"{"):
        return parse_json(data)
    return parse_feed(data)
