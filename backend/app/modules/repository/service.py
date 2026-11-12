"""Aturan akses ABAC dan pencarian repositori (S70, S72)."""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import and_, func, or_, select
from sqlalchemy.orm import Session

from app.core.classification import Level, Role
from app.core.timeutil import utcnow
from app.modules.identity.models import User
from app.modules.repository.models import AccessGrant, Document, DocumentVersion


def active_grant(db: Session, user: User, doc: Document, now: datetime | None = None) -> AccessGrant | None:
    now = now or utcnow()
    return db.execute(
        select(AccessGrant).where(
            AccessGrant.document_id == doc.id,
            AccessGrant.user_id == user.id,
            AccessGrant.revoked_at.is_(None),
            AccessGrant.valid_from <= now,
            AccessGrant.valid_until > now,
        ).limit(1)
    ).scalar_one_or_none()


def can_read(db: Session, user: User, doc: Document) -> bool:
    """Aturan baca (docs/analisis/04-model-data.md §3).

    - clearance pengguna ≥ klasifikasi dokumen (izin tidak pernah menaikkan clearance);
    - TERBUKA/TERBATAS: cukup clearance;
    - RAHASIA: unit pemilik, pembuat, atau izin aktif;
    - SANGAT_RAHASIA: pembuat atau izin eksplisit aktif.
    """
    level = Level.parse(doc.classification)
    if user.clearance_level < level:
        return False
    if level <= Level.TERBATAS:
        return True
    if doc.created_by == user.id:
        return True
    if level == Level.RAHASIA and doc.owner_unit_id == user.unit_id:
        return True
    return active_grant(db, user, doc) is not None


def can_write(db: Session, user: User, doc: Document) -> bool:
    """Check-out / check-in versi baru: pembaca yang berasal dari unit pemilik, pembuat, atau arsiparis."""
    if not can_read(db, user, doc):
        return False
    return doc.owner_unit_id == user.unit_id or doc.created_by == user.id or user.has_role(Role.ARSIPARIS)


def can_manage(db: Session, user: User, doc: Document) -> bool:
    """Memberi izin, reklasifikasi, melihat log unduhan."""
    if not can_read(db, user, doc):
        return False
    if user.has_role(Role.ARSIPARIS):
        return True
    return user.has_role(Role.PIMPINAN) and doc.owner_unit_id == user.unit_id


def _escape_like(term: str) -> str:
    return term.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")


def search_documents(
    db: Session,
    user: User,
    *,
    q: str | None = None,
    classification: str | None = None,
    unit_id: int | None = None,
    tag: str | None = None,
    status: str | None = None,
    max_candidates: int = 2000,
) -> list[tuple[Document, DocumentVersion]]:
    allowed_levels = [lvl.name for lvl in Level if lvl <= user.clearance_level]
    stmt = (
        select(Document, DocumentVersion)
        .join(
            DocumentVersion,
            and_(DocumentVersion.document_id == Document.id, DocumentVersion.version_no == Document.current_version_no),
        )
        .where(Document.classification.in_(allowed_levels))
    )
    if classification:
        stmt = stmt.where(Document.classification == Level.parse(classification).name)
    if unit_id:
        stmt = stmt.where(Document.owner_unit_id == unit_id)
    if status:
        stmt = stmt.where(Document.status == status)

    terms = [t for t in (q or "").split() if t]
    if terms:
        if db.bind is not None and db.bind.dialect.name == "postgresql":
            doc_text = func.concat_ws(" ", Document.title, Document.description, DocumentVersion.text_content)
            tsv = func.to_tsvector("simple", doc_text)
            query = func.plainto_tsquery("simple", " ".join(terms))
            stmt = stmt.where(tsv.op("@@")(query)).order_by(func.ts_rank(tsv, query).desc())
        else:
            for t in terms:
                pat = f"%{_escape_like(t)}%"
                stmt = stmt.where(
                    or_(
                        Document.title.ilike(pat, escape="\\"),
                        Document.description.ilike(pat, escape="\\"),
                        DocumentVersion.text_content.ilike(pat, escape="\\"),
                    )
                )
    stmt = stmt.order_by(Document.updated_at.desc()).limit(max_candidates)

    rows = db.execute(stmt).all()
    out = []
    for doc, ver in rows:
        if tag and tag not in (doc.tags or []):
            continue
        if can_read(db, user, doc):
            out.append((doc, ver))
    return out


def snippet(text: str, q: str | None, width: int = 110) -> str:
    if not text:
        return ""
    clean = " ".join(text.split())
    if not q:
        return clean[: width * 2]
    low = clean.casefold()
    positions = [low.find(t.casefold()) for t in q.split() if t]
    positions = [p for p in positions if p >= 0]
    if not positions:
        return clean[: width * 2]
    start = max(min(positions) - width, 0)
    end = min(start + width * 2, len(clean))
    return ("…" if start > 0 else "") + clean[start:end] + ("…" if end < len(clean) else "")
