"""API Sumber & Penyerapan — Modul 1 (S01, S03)."""

from __future__ import annotations

from datetime import datetime

import httpx
from fastapi import APIRouter, Depends, File, Form, HTTPException, Request, UploadFile, status
from pydantic import BaseModel, Field
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.classification import Role
from app.core.db import get_db
from app.core.security import client_ip, get_current_user, require_roles
from app.modules.analytics.geo import PROVINCE_NAMES
from app.modules.identity.models import User
from app.modules.ingest import service
from app.modules.ingest.models import ContentItem, Source
from app.modules.ingest.parsers import ParseError, parse_any

router = APIRouter(tags=["penyerapan"])


class SourceIn(BaseModel):
    type: str = Field(pattern="^(news|social|tv|radio|print|report)$")
    name: str = Field(min_length=2, max_length=200)
    url: str = Field(default="", max_length=500)
    platform: str = ""
    province_code: str | None = None
    reliability: str = Field(default="C", pattern="^[A-F]$")
    access_basis: str = Field(default="Sumber terbuka/publik", min_length=3, max_length=300)


class SourceOut(SourceIn):
    id: int
    active: bool
    last_fetched_at: datetime | None
    item_count: int = 0


class IngestOut(BaseModel):
    received: int
    inserted: int
    exact_duplicates: int
    near_duplicates: int
    skipped: int
    alerts_created: list[int]
    alerts_escalated: list[int]


def _source_out(s: Source, count: int = 0) -> SourceOut:
    return SourceOut(id=s.id, type=s.type, name=s.name, url=s.url, platform=s.platform, province_code=s.province_code,
                     reliability=s.reliability, access_basis=s.access_basis, active=s.active,
                     last_fetched_at=s.last_fetched_at, item_count=count)


@router.get("/sources", response_model=list[SourceOut])
def list_sources(db: Session = Depends(get_db), _: User = Depends(get_current_user)) -> list[SourceOut]:
    counts = dict(db.execute(select(ContentItem.source_id, func.count()).group_by(ContentItem.source_id)).all())
    return [_source_out(s, counts.get(s.id, 0)) for s in db.execute(select(Source).order_by(Source.name)).scalars()]


@router.post("/sources", response_model=SourceOut, status_code=201)
def create_source(body: SourceIn, request: Request, db: Session = Depends(get_db),
                  user: User = Depends(require_roles(Role.ANALIS, Role.ADMIN))) -> SourceOut:
    if body.province_code and body.province_code not in PROVINCE_NAMES:
        raise HTTPException(422, detail="Kode provinsi tidak dikenal")
    src = Source(**body.model_dump())
    db.add(src)
    db.flush()
    audit.record(db, actor=user, action="sumber.buat", object_type="source", object_id=src.id, ip=client_ip(request),
                 detail=body.model_dump())
    db.commit()
    return _source_out(src)


def _get_source(db: Session, source_id: int) -> Source:
    src = db.get(Source, source_id)
    if src is None or not src.active:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Sumber tidak ditemukan/aktif")
    return src


@router.post("/ingest/upload", response_model=IngestOut)
async def ingest_upload(
    request: Request,
    source_id: int = Form(...),
    file: UploadFile = File(...),
    db: Session = Depends(get_db),
    user: User = Depends(require_roles(Role.ANALIS, Role.ADMIN)),
) -> IngestOut:
    """Impor berkas RSS/Atom/JSON ke sumber tertentu."""
    src = _get_source(db, source_id)
    data = await file.read(service.MAX_FEED_BYTES + 1)
    if len(data) > service.MAX_FEED_BYTES:
        raise HTTPException(status.HTTP_413_REQUEST_ENTITY_TOO_LARGE, detail="Berkas melebihi 5 MB")
    try:
        items = parse_any(data)
    except ParseError as exc:
        raise HTTPException(422, detail=str(exc))
    result = service.ingest_items(db, src, items, actor=user, ip=client_ip(request))
    db.commit()
    return IngestOut(**result.__dict__)


@router.post("/ingest/fetch/{source_id}", response_model=IngestOut)
def ingest_fetch(source_id: int, request: Request, db: Session = Depends(get_db),
                 user: User = Depends(require_roles(Role.ANALIS, Role.ADMIN))) -> IngestOut:
    """Ambil feed RSS/Atom dari URL sumber."""
    src = _get_source(db, source_id)
    if not src.url:
        raise HTTPException(422, detail="Sumber tidak memiliki URL")
    try:
        items = service.fetch_feed(src.url)
    except (ParseError, ValueError) as exc:
        raise HTTPException(422, detail=str(exc))
    except httpx.HTTPError as exc:
        raise HTTPException(status.HTTP_502_BAD_GATEWAY, detail=f"Gagal mengambil feed: {exc.__class__.__name__}")
    result = service.ingest_items(db, src, items, actor=user, ip=client_ip(request))
    db.commit()
    return IngestOut(**result.__dict__)
