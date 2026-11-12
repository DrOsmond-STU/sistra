"""Alur penyerapan: normalisasi → dedup → simpan → pengayaan → evaluasi peringatan (S01, S03)."""

from __future__ import annotations

import ipaddress
import socket
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from urllib.parse import urlparse

import httpx
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.timeutil import as_utc, utcnow
from app.modules.alerts import service as alert_service
from app.modules.analytics.service import enrich, load_classifier
from app.modules.ingest import dedup
from app.modules.ingest.models import ContentItem, Source
from app.modules.ingest.parsers import RawItem, parse_any

MAX_FEED_BYTES = 5 * 1024 * 1024
NEAR_DUP_WINDOW = timedelta(days=3)


@dataclass
class IngestResult:
    received: int = 0
    inserted: int = 0
    exact_duplicates: int = 0
    near_duplicates: int = 0
    skipped: int = 0
    alerts_created: list[int] = field(default_factory=list)
    alerts_escalated: list[int] = field(default_factory=list)


def ingest_items(
    db: Session,
    source: Source,
    items: list[RawItem],
    *,
    actor=None,  # noqa: ANN001
    ip: str = "-",
    evaluate_alerts: bool = True,
    now: datetime | None = None,
) -> IngestResult:
    now = as_utc(now) or utcnow()
    result = IngestResult(received=len(items))
    classifier, issue_ids = load_classifier(db)

    recent = db.execute(
        select(ContentItem.id, ContentItem.simhash, ContentItem.published_at, ContentItem.title, ContentItem.body)
        .where(ContentItem.published_at >= now - timedelta(days=10), ContentItem.duplicate_of.is_(None))
        .order_by(ContentItem.id.desc()).limit(5000)
    ).all()
    candidates: list[tuple[int, int, datetime, frozenset[int]]] = [
        (r[0], r[1], as_utc(r[2]), dedup.shingles(dedup.normalize(r[3], r[4]))) for r in recent
    ]
    seen_hashes: set[str] = set()

    for raw in items:
        title = (raw.title or "").strip()[:500]
        body = (raw.body or "").strip()
        if not title and not body:
            result.skipped += 1
            continue
        if not title:
            title = body[:120]
        chash = dedup.content_hash(title, body)
        if chash in seen_hashes or db.execute(
            select(ContentItem.id).where(ContentItem.content_hash == chash)
        ).first():
            result.exact_duplicates += 1
            continue
        ext_id = (raw.external_id or raw.url or chash)[:300]
        if db.execute(select(ContentItem.id).where(ContentItem.source_id == source.id,
                                                   ContentItem.external_id == ext_id)).first():
            result.exact_duplicates += 1
            continue
        seen_hashes.add(chash)

        published = as_utc(raw.published_at) or now
        if published > now + timedelta(minutes=5):  # cap waktu masa depan dianggap tidak valid
            published = now
        norm = dedup.normalize(title, body)
        sh = dedup.simhash(norm)
        sh_set = dedup.shingles(norm)
        dup_of = next(
            (cid for cid, csh, cpub, cset in candidates
             if abs((cpub - published).total_seconds()) <= NEAR_DUP_WINDOW.total_seconds()
             and dedup.hamming(sh, csh) <= dedup.SIMHASH_PREFILTER
             and dedup.jaccard(sh_set, cset) >= dedup.JACCARD_THRESHOLD),
            None,
        )

        item = ContentItem(
            source_id=source.id, external_id=ext_id, url=(raw.url or "")[:1000], title=title, body=body,
            author=(raw.author or "")[:200], published_at=published, ingested_at=now, content_hash=chash,
            simhash=sh, duplicate_of=dup_of,
        )
        item.source = source
        db.add(item)
        db.flush()
        enrich(db, item, classifier, issue_ids)
        if dup_of is None:
            candidates.append((item.id, sh, published, sh_set))
            result.inserted += 1
        else:
            result.near_duplicates += 1

    source.last_fetched_at = now
    db.flush()
    if evaluate_alerts and (result.inserted or result.near_duplicates):
        ev = alert_service.evaluate(db, now=now, actor=actor)
        result.alerts_created, result.alerts_escalated = ev["created"], ev["escalated"]
    audit.record(db, actor=actor, action="penyerapan.batch", object_type="source", object_id=source.id, ip=ip,
                 detail={k: v for k, v in result.__dict__.items() if not k.startswith("alerts")})
    return result


def _assert_public_host(url: str) -> None:
    """Cegah SSRF: tolak host yang mengarah ke jaringan internal/loopback."""
    host = urlparse(url).hostname
    if not host:
        raise ValueError("URL sumber tidak valid")
    try:
        infos = socket.getaddrinfo(host, None)
    except socket.gaierror as exc:
        raise ValueError(f"Host tidak dapat di-resolve: {host}") from exc
    for info in infos:
        ip = ipaddress.ip_address(info[4][0])
        if ip.is_private or ip.is_loopback or ip.is_link_local or ip.is_reserved or ip.is_multicast:
            raise ValueError("URL sumber mengarah ke alamat internal dan ditolak")


def fetch_feed(url: str) -> list[RawItem]:
    if not url.startswith(("http://", "https://")):
        raise ValueError("URL sumber harus http(s)")
    _assert_public_host(url)
    with httpx.Client(timeout=15.0, follow_redirects=False, headers={"User-Agent": "SISTRA-Ingest/0.1"}) as client:
        with client.stream("GET", url) as resp:
            resp.raise_for_status()
            chunks, size = [], 0
            for chunk in resp.iter_bytes():
                size += len(chunk)
                if size > MAX_FEED_BYTES:
                    raise ValueError("Feed melebihi batas ukuran 5 MB")
                chunks.append(chunk)
    return parse_any(b"".join(chunks))
