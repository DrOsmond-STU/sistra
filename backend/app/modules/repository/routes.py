"""API Repositori Pengetahuan — Modul 9 (S66–S68, S70–S72)."""

from __future__ import annotations

import mimetypes
import secrets
from datetime import date, datetime, timedelta
from pathlib import PurePath
from urllib.parse import quote

from fastapi import APIRouter, Depends, File, Form, HTTPException, Query, Request, Response, UploadFile, status
from pydantic import BaseModel, Field
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.classification import LEVEL_LABELS, Level
from app.core.config import get_settings
from app.core.db import get_db
from app.core.security import client_ip, get_current_user
from app.core.timeutil import as_utc, utcnow
from app.modules.identity.models import Unit, User
from app.modules.repository import storage, watermark
from app.modules.repository.models import AccessGrant, Document, DocumentVersion, DownloadLog
from app.modules.repository.service import (
    can_manage,
    can_read,
    can_write,
    search_documents,
    snippet,
)

router = APIRouter(prefix="/documents", tags=["repositori"])

MAX_GRANT_DAYS = 90


# ---------- skema ----------

class VersionOut(BaseModel):
    version_no: int
    filename: str
    mime: str
    size: int
    sha256: str
    note: str
    created_by: int
    created_at: datetime


class GrantOut(BaseModel):
    id: int
    user_id: int
    username: str
    reason: str
    valid_from: datetime
    valid_until: datetime
    revoked_at: datetime | None


class DocumentOut(BaseModel):
    id: int
    title: str
    description: str
    owner_unit_code: str
    owner_unit_name: str
    classification: str
    classification_label: str
    period_start: date
    period_end: date
    origin: str
    retention_class: str
    tags: list[str]
    status: str
    checked_out_by: int | None
    current_version: VersionOut
    created_by: int
    created_at: datetime
    updated_at: datetime
    snippet: str = ""
    permissions: dict[str, bool] = Field(default_factory=dict)


class DocumentDetail(DocumentOut):
    versions: list[VersionOut]
    grants: list[GrantOut] = Field(default_factory=list)


class DocumentList(BaseModel):
    total: int
    items: list[DocumentOut]


class GrantIn(BaseModel):
    username: str
    reason: str = Field(min_length=5, max_length=500)
    valid_days: int = Field(ge=1, le=MAX_GRANT_DAYS)


class MetadataPatch(BaseModel):
    title: str | None = Field(default=None, min_length=3, max_length=300)
    description: str | None = None
    classification: str | None = None
    tags: list[str] | None = None
    retention_class: str | None = None


class DownloadOut(BaseModel):
    id: int
    version_no: int
    username: str
    ip: str
    watermark_id: str
    at: datetime


# ---------- utilitas ----------

def _version_out(v: DocumentVersion) -> VersionOut:
    return VersionOut(
        version_no=v.version_no, filename=v.filename, mime=v.mime, size=v.size, sha256=v.sha256,
        note=v.note, created_by=v.created_by, created_at=v.created_at,
    )


def _doc_out(db: Session, user: User, doc: Document, ver: DocumentVersion, q: str | None = None) -> DocumentOut:
    lvl = Level.parse(doc.classification)
    return DocumentOut(
        id=doc.id,
        title=doc.title,
        description=doc.description,
        owner_unit_code=doc.owner_unit.code,
        owner_unit_name=doc.owner_unit.name,
        classification=lvl.name,
        classification_label=LEVEL_LABELS[lvl],
        period_start=doc.period_start,
        period_end=doc.period_end,
        origin=doc.origin,
        retention_class=doc.retention_class,
        tags=list(doc.tags or []),
        status=doc.status,
        checked_out_by=doc.checked_out_by,
        current_version=_version_out(ver),
        created_by=doc.created_by,
        created_at=doc.created_at,
        updated_at=doc.updated_at,
        snippet=snippet(ver.text_content or doc.description, q) if q else "",
        permissions={
            "write": can_write(db, user, doc),
            "manage": can_manage(db, user, doc),
        },
    )


def _load_readable(db: Session, user: User, doc_id: int, request: Request) -> Document:
    doc = db.get(Document, doc_id)
    if doc is None or not can_read(db, user, doc):
        if doc is not None:
            audit.record(db, actor=user, action="dokumen.akses_ditolak", object_type="document",
                         object_id=doc_id, ip=client_ip(request))
            db.commit()
        # 404 agar keberadaan dokumen berklasifikasi tidak bocor
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Dokumen tidak ditemukan")
    return doc


def _current_version(doc: Document) -> DocumentVersion:
    return next(v for v in doc.versions if v.version_no == doc.current_version_no)


async def _read_upload(file: UploadFile) -> tuple[str, str, bytes]:
    limit = get_settings().max_upload_mb * 1024 * 1024
    data = await file.read(limit + 1)
    if len(data) > limit:
        raise HTTPException(status.HTTP_413_REQUEST_ENTITY_TOO_LARGE, detail=f"Berkas melebihi {get_settings().max_upload_mb} MB")
    if not data:
        raise HTTPException(422, detail="Berkas kosong")
    filename = PurePath((file.filename or "berkas").replace("\\", "/")).name[:255] or "berkas"
    mime = file.content_type or ""
    if not mime or mime == "application/octet-stream":
        mime = mimetypes.guess_type(filename)[0] or "application/octet-stream"
    return filename, mime, data


def _parse_tags(raw: str | None) -> list[str]:
    return sorted({t.strip().lower() for t in (raw or "").split(",") if t.strip()})[:20]


# ---------- endpoint ----------

@router.get("", response_model=DocumentList)
def list_documents(
    q: str | None = None,
    classification: str | None = None,
    unit_code: str | None = None,
    tag: str | None = None,
    status_: str | None = Query(default=None, alias="status"),
    limit: int = Query(default=50, ge=1, le=200),
    offset: int = Query(default=0, ge=0),
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
) -> DocumentList:
    unit_id = None
    if unit_code:
        unit = db.execute(select(Unit).where(Unit.code == unit_code)).scalar_one_or_none()
        if unit is None:
            return DocumentList(total=0, items=[])
        unit_id = unit.id
    try:
        rows = search_documents(db, user, q=q, classification=classification, unit_id=unit_id, tag=tag, status=status_)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc))
    page = rows[offset: offset + limit]
    return DocumentList(total=len(rows), items=[_doc_out(db, user, d, v, q) for d, v in page])


@router.post("", response_model=DocumentDetail, status_code=201)
async def upload_document(
    request: Request,
    file: UploadFile = File(...),
    title: str | None = Form(default=None),
    classification: str | None = Form(default=None),
    owner_unit_code: str | None = Form(default=None),
    period_start: str | None = Form(default=None),
    period_end: str | None = Form(default=None),
    origin: str | None = Form(default=None),
    description: str = Form(default=""),
    retention_class: str = Form(default="UMUM"),
    tags: str = Form(default=""),
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
) -> DocumentDetail:
    # S68 — metadata wajib: tolak unggahan yang tidak lengkap
    required = {
        "title": title, "classification": classification, "owner_unit_code": owner_unit_code,
        "period_start": period_start, "period_end": period_end, "origin": origin,
    }
    missing = [k for k, v in required.items() if not (v and v.strip())]
    if missing:
        raise HTTPException(422,
                            detail={"message": "Metadata wajib belum lengkap", "missing": missing})
    try:
        level = Level.parse(classification)  # type: ignore[arg-type]
        p_start = date.fromisoformat(period_start)  # type: ignore[arg-type]
        p_end = date.fromisoformat(period_end)  # type: ignore[arg-type]
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc))
    if p_end < p_start:
        raise HTTPException(422, detail="period_end lebih awal dari period_start")
    if level > user.clearance_level:
        raise HTTPException(status.HTTP_403_FORBIDDEN, detail="Tidak dapat membuat dokumen di atas clearance Anda")
    unit = db.execute(select(Unit).where(Unit.code == owner_unit_code)).scalar_one_or_none()
    if unit is None:
        raise HTTPException(422, detail=f"Unit tidak ditemukan: {owner_unit_code}")
    if unit.id != user.unit_id and not user.has_role("arsiparis"):
        raise HTTPException(status.HTTP_403_FORBIDDEN, detail="Hanya dapat mengunggah atas nama unit sendiri")

    filename, mime, data = await _read_upload(file)
    sha, existed = storage.put(data)
    now = utcnow()
    doc = Document(
        title=title.strip(), description=description.strip(), owner_unit_id=unit.id,  # type: ignore[union-attr]
        classification=level.name, period_start=p_start, period_end=p_end, origin=origin.strip(),  # type: ignore[union-attr]
        retention_class=retention_class.strip() or "UMUM", tags=_parse_tags(tags), created_by=user.id,
        created_at=now, updated_at=now, current_version_no=1,
    )
    doc.versions.append(DocumentVersion(
        version_no=1, filename=filename, mime=mime, size=len(data), sha256=sha,
        text_content=storage.extract_text(filename, mime, data), note="Versi awal", created_by=user.id, created_at=now,
    ))
    db.add(doc)
    db.flush()
    audit.record(db, actor=user, action="dokumen.unggah", object_type="document", object_id=doc.id,
                 ip=client_ip(request),
                 detail={"title": doc.title, "classification": doc.classification, "sha256": sha, "dedup": existed})
    db.commit()
    return _detail(db, user, doc)


def _detail(db: Session, user: User, doc: Document) -> DocumentDetail:
    base = _doc_out(db, user, doc, _current_version(doc))
    grants: list[GrantOut] = []
    if can_manage(db, user, doc):
        rows = db.execute(
            select(AccessGrant, User.username).join(User, User.id == AccessGrant.user_id)
            .where(AccessGrant.document_id == doc.id).order_by(AccessGrant.created_at.desc())
        ).all()
        grants = [GrantOut(id=g.id, user_id=g.user_id, username=un, reason=g.reason, valid_from=g.valid_from,
                           valid_until=g.valid_until, revoked_at=g.revoked_at) for g, un in rows]
    return DocumentDetail(**base.model_dump(), versions=[_version_out(v) for v in doc.versions], grants=grants)


@router.get("/{doc_id}", response_model=DocumentDetail)
def get_document(doc_id: int, request: Request, db: Session = Depends(get_db),
                 user: User = Depends(get_current_user)) -> DocumentDetail:
    doc = _load_readable(db, user, doc_id, request)
    audit.record(db, actor=user, action="dokumen.lihat", object_type="document", object_id=doc.id, ip=client_ip(request))
    db.commit()
    return _detail(db, user, doc)


@router.patch("/{doc_id}", response_model=DocumentDetail)
def patch_metadata(doc_id: int, body: MetadataPatch, request: Request, db: Session = Depends(get_db),
                   user: User = Depends(get_current_user)) -> DocumentDetail:
    doc = _load_readable(db, user, doc_id, request)
    if not can_write(db, user, doc):
        raise HTTPException(status.HTTP_403_FORBIDDEN, detail="Tidak berwenang mengubah dokumen ini")
    changes: dict = {}
    if body.classification is not None:
        try:
            new_level = Level.parse(body.classification)
        except ValueError as exc:
            raise HTTPException(422, detail=str(exc))
        if new_level.name != doc.classification:
            if not can_manage(db, user, doc):
                raise HTTPException(status.HTTP_403_FORBIDDEN, detail="Reklasifikasi hanya oleh pimpinan unit/arsiparis")
            if new_level > user.clearance_level:
                raise HTTPException(status.HTTP_403_FORBIDDEN, detail="Tidak dapat menaikkan di atas clearance Anda")
            changes["classification"] = [doc.classification, new_level.name]
            doc.classification = new_level.name
    for field in ("title", "description", "retention_class"):
        val = getattr(body, field)
        if val is not None and val != getattr(doc, field):
            changes[field] = [getattr(doc, field), val]
            setattr(doc, field, val)
    if body.tags is not None:
        new_tags = _parse_tags(",".join(body.tags))
        changes["tags"] = [doc.tags, new_tags]
        doc.tags = new_tags
    if changes:
        doc.updated_at = utcnow()
        audit.record(db, actor=user, action="dokumen.ubah_metadata", object_type="document", object_id=doc.id,
                     ip=client_ip(request), detail=changes)
        db.commit()
    return _detail(db, user, doc)


@router.post("/{doc_id}/checkout", response_model=DocumentDetail)
def checkout(doc_id: int, request: Request, db: Session = Depends(get_db),
             user: User = Depends(get_current_user)) -> DocumentDetail:
    doc = _load_readable(db, user, doc_id, request)
    if not can_write(db, user, doc):
        raise HTTPException(status.HTTP_403_FORBIDDEN, detail="Tidak berwenang melakukan check-out")
    if doc.status == "checked_out":
        raise HTTPException(status.HTTP_409_CONFLICT, detail="Dokumen sedang di-check-out pengguna lain")
    if doc.status != "active":
        raise HTTPException(status.HTTP_409_CONFLICT, detail=f"Status dokumen {doc.status} tidak dapat di-check-out")
    doc.status, doc.checked_out_by, doc.updated_at = "checked_out", user.id, utcnow()
    audit.record(db, actor=user, action="dokumen.checkout", object_type="document", object_id=doc.id, ip=client_ip(request))
    db.commit()
    return _detail(db, user, doc)


@router.post("/{doc_id}/cancel-checkout", response_model=DocumentDetail)
def cancel_checkout(doc_id: int, request: Request, db: Session = Depends(get_db),
                    user: User = Depends(get_current_user)) -> DocumentDetail:
    doc = _load_readable(db, user, doc_id, request)
    if doc.status != "checked_out" or (doc.checked_out_by != user.id and not can_manage(db, user, doc)):
        raise HTTPException(status.HTTP_409_CONFLICT, detail="Tidak ada check-out milik Anda pada dokumen ini")
    doc.status, doc.checked_out_by, doc.updated_at = "active", None, utcnow()
    audit.record(db, actor=user, action="dokumen.batal_checkout", object_type="document", object_id=doc.id,
                 ip=client_ip(request))
    db.commit()
    return _detail(db, user, doc)


@router.post("/{doc_id}/versions", response_model=DocumentDetail, status_code=201)
async def checkin(doc_id: int, request: Request, file: UploadFile = File(...), note: str = Form(default=""),
                  db: Session = Depends(get_db), user: User = Depends(get_current_user)) -> DocumentDetail:
    doc = _load_readable(db, user, doc_id, request)
    if doc.status != "checked_out" or doc.checked_out_by != user.id:
        raise HTTPException(status.HTTP_409_CONFLICT, detail="Lakukan check-out terlebih dahulu sebelum check-in versi baru")
    filename, mime, data = await _read_upload(file)
    sha, existed = storage.put(data)
    if sha == _current_version(doc).sha256:
        raise HTTPException(status.HTTP_409_CONFLICT, detail="Isi berkas identik dengan versi terakhir")
    now = utcnow()
    new_no = doc.current_version_no + 1
    doc.versions.append(DocumentVersion(
        version_no=new_no, filename=filename, mime=mime, size=len(data), sha256=sha,
        text_content=storage.extract_text(filename, mime, data), note=note.strip()[:500], created_by=user.id,
        created_at=now,
    ))
    doc.current_version_no = new_no
    doc.status, doc.checked_out_by, doc.updated_at = "active", None, now
    audit.record(db, actor=user, action="dokumen.checkin", object_type="document", object_id=doc.id,
                 ip=client_ip(request), detail={"version": new_no, "sha256": sha, "dedup": existed})
    db.commit()
    return _detail(db, user, doc)


@router.get("/{doc_id}/versions/{version_no}/download")
def download(doc_id: int, version_no: int, request: Request, db: Session = Depends(get_db),
             user: User = Depends(get_current_user)) -> Response:
    doc = _load_readable(db, user, doc_id, request)
    ver = next((v for v in doc.versions if v.version_no == version_no), None)
    if ver is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Versi tidak ditemukan")
    ip = client_ip(request)
    try:
        data = storage.get(ver.sha256)
    except (storage.IntegrityError, FileNotFoundError) as exc:
        audit.record(db, actor=user, action="dokumen.integritas_gagal", object_type="document", object_id=doc.id,
                     ip=ip, detail={"version": version_no, "error": str(exc)})
        db.commit()
        raise HTTPException(status.HTTP_500_INTERNAL_SERVER_ERROR, detail="Verifikasi integritas berkas gagal")

    wm_id = secrets.token_hex(8)
    now = utcnow()
    mark = (f"{Level.parse(doc.classification).name} | {user.full_name} | NIP {user.nip or '-'} | IP {ip} | "
            f"{now.strftime('%Y-%m-%d %H:%M:%S')} UTC | WM-{wm_id}")
    payload, embedded = watermark.apply(ver.filename, ver.mime, data, mark)
    db.add(DownloadLog(version_id=ver.id, user_id=user.id, ip=ip, watermark_id=wm_id, at=now))
    audit.record(db, actor=user, action="dokumen.unduh", object_type="document", object_id=doc.id, ip=ip,
                 detail={"version": version_no, "watermark_id": wm_id, "embedded": embedded})
    db.commit()
    return Response(
        content=payload,
        media_type=ver.mime,
        headers={
            "Content-Disposition": f"attachment; filename*=UTF-8''{quote(ver.filename)}",
            "X-SISTRA-Watermark": f"WM-{wm_id}",
            "X-SISTRA-Watermark-Embedded": "1" if embedded else "0",
            "X-SISTRA-Source-SHA256": ver.sha256,
            "Cache-Control": "no-store",
        },
    )


@router.post("/{doc_id}/grants", response_model=DocumentDetail, status_code=201)
def create_grant(doc_id: int, body: GrantIn, request: Request, db: Session = Depends(get_db),
                 user: User = Depends(get_current_user)) -> DocumentDetail:
    doc = _load_readable(db, user, doc_id, request)
    if not can_manage(db, user, doc):
        raise HTTPException(status.HTTP_403_FORBIDDEN, detail="Hanya pimpinan unit pemilik/arsiparis yang dapat memberi izin")
    grantee = db.execute(select(User).where(User.username == body.username)).scalar_one_or_none()
    if grantee is None or not grantee.active:
        raise HTTPException(422, detail="Pengguna penerima tidak ditemukan")
    if grantee.clearance_level < Level.parse(doc.classification):
        raise HTTPException(422,
                            detail="Clearance penerima lebih rendah dari klasifikasi dokumen; izin tidak dapat diberikan")
    now = utcnow()
    grant = AccessGrant(document_id=doc.id, user_id=grantee.id, reason=body.reason.strip(), valid_from=now,
                        valid_until=now + timedelta(days=body.valid_days), granted_by=user.id, created_at=now)
    db.add(grant)
    db.flush()
    audit.record(db, actor=user, action="dokumen.izin_beri", object_type="document", object_id=doc.id,
                 ip=client_ip(request),
                 detail={"grant_id": grant.id, "to": grantee.username, "days": body.valid_days, "reason": grant.reason})
    db.commit()
    return _detail(db, user, doc)


@router.delete("/{doc_id}/grants/{grant_id}", response_model=DocumentDetail)
def revoke_grant(doc_id: int, grant_id: int, request: Request, db: Session = Depends(get_db),
                 user: User = Depends(get_current_user)) -> DocumentDetail:
    doc = _load_readable(db, user, doc_id, request)
    if not can_manage(db, user, doc):
        raise HTTPException(status.HTTP_403_FORBIDDEN, detail="Tidak berwenang mencabut izin")
    grant = db.get(AccessGrant, grant_id)
    if grant is None or grant.document_id != doc.id:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Izin tidak ditemukan")
    if grant.revoked_at is None:
        grant.revoked_at = utcnow()
        audit.record(db, actor=user, action="dokumen.izin_cabut", object_type="document", object_id=doc.id,
                     ip=client_ip(request), detail={"grant_id": grant.id})
        db.commit()
    return _detail(db, user, doc)


@router.get("/{doc_id}/downloads", response_model=list[DownloadOut])
def download_log(doc_id: int, request: Request, db: Session = Depends(get_db),
                 user: User = Depends(get_current_user)) -> list[DownloadOut]:
    doc = _load_readable(db, user, doc_id, request)
    if not can_manage(db, user, doc):
        raise HTTPException(status.HTTP_403_FORBIDDEN, detail="Tidak berwenang melihat log unduhan")
    rows = db.execute(
        select(DownloadLog, DocumentVersion.version_no, User.username)
        .join(DocumentVersion, DocumentVersion.id == DownloadLog.version_id)
        .join(User, User.id == DownloadLog.user_id)
        .where(DocumentVersion.document_id == doc.id)
        .order_by(DownloadLog.at.desc())
        .limit(500)
    ).all()
    return [DownloadOut(id=d.id, version_no=vn, username=un, ip=d.ip, watermark_id=d.watermark_id, at=as_utc(d.at))
            for d, vn, un in rows]
