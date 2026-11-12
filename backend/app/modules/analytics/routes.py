"""API Analitik: taksonomi isu, konten, pencarian (S07, S08, S14, S15)."""

from __future__ import annotations

from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException, Query, Request, status
from pydantic import BaseModel, Field
from sqlalchemy import delete, func, or_, select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.classification import Role
from app.core.db import get_db
from app.core.security import client_ip, get_current_user, require_roles
from app.core.timeutil import utcnow
from app.modules.analytics.geo import PROVINCE_NAMES
from app.modules.analytics.models import ContentIssue, Issue
from app.modules.analytics.service import issue_metrics
from app.modules.identity.models import User
from app.modules.ingest.models import ContentItem

router = APIRouter(tags=["analitik"])


# ---------- isu ----------

class IssueIn(BaseModel):
    code: str = Field(pattern=r"^[A-Z0-9-]{3,32}$")
    name: str = Field(min_length=3, max_length=200)
    taxonomy_path: str = ""
    description: str = ""
    risk_level: str = Field(default="sedang", pattern="^(rendah|sedang|tinggi)$")
    keywords: list[str] = Field(default_factory=list)


class IssuePatch(BaseModel):
    name: str | None = None
    description: str | None = None
    risk_level: str | None = Field(default=None, pattern="^(rendah|sedang|tinggi)$")
    keywords: list[str] | None = None
    active: bool | None = None


class IssueOut(IssueIn):
    id: int
    active: bool


def _issue_out(i: Issue) -> IssueOut:
    return IssueOut(id=i.id, code=i.code, name=i.name, taxonomy_path=i.taxonomy_path, description=i.description,
                    risk_level=i.risk_level, keywords=list(i.keywords or []), active=i.active)


def _clean_keywords(kws: list[str]) -> list[str]:
    return sorted({k.strip().lower() for k in kws if k.strip()})


@router.get("/issues", response_model=list[IssueOut])
def list_issues(db: Session = Depends(get_db), _: User = Depends(get_current_user)) -> list[IssueOut]:
    return [_issue_out(i) for i in db.execute(select(Issue).order_by(Issue.code)).scalars()]


@router.post("/issues", response_model=IssueOut, status_code=201)
def create_issue(body: IssueIn, request: Request, db: Session = Depends(get_db),
                 user: User = Depends(require_roles(Role.ANALIS, Role.ADMIN))) -> IssueOut:
    if db.execute(select(Issue).where(Issue.code == body.code)).first():
        raise HTTPException(status.HTTP_409_CONFLICT, detail="Kode isu sudah ada")
    issue = Issue(**{**body.model_dump(), "keywords": _clean_keywords(body.keywords)})
    db.add(issue)
    db.flush()
    audit.record(db, actor=user, action="isu.buat", object_type="issue", object_id=issue.id, ip=client_ip(request),
                 detail={"code": issue.code})
    db.commit()
    return _issue_out(issue)


@router.patch("/issues/{issue_id}", response_model=IssueOut)
def patch_issue(issue_id: int, body: IssuePatch, request: Request, db: Session = Depends(get_db),
                user: User = Depends(require_roles(Role.ANALIS, Role.ADMIN))) -> IssueOut:
    issue = db.get(Issue, issue_id)
    if issue is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Isu tidak ditemukan")
    data = body.model_dump(exclude_none=True)
    if "keywords" in data:
        data["keywords"] = _clean_keywords(data["keywords"])
    for k, v in data.items():
        setattr(issue, k, v)
    audit.record(db, actor=user, action="isu.ubah", object_type="issue", object_id=issue.id, ip=client_ip(request),
                 detail=data)
    db.commit()
    return _issue_out(issue)


@router.get("/issues/{issue_id}/metrics")
def get_issue_metrics(issue_id: int, days: int = Query(default=14, ge=1, le=90), db: Session = Depends(get_db),
                      _: User = Depends(get_current_user)) -> dict:
    if db.get(Issue, issue_id) is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Isu tidak ditemukan")
    return issue_metrics(db, issue_id, days)


@router.get("/provinces")
def provinces(_: User = Depends(get_current_user)) -> list[dict]:
    return [{"code": c, "name": n} for c, n in PROVINCE_NAMES.items()]


# ---------- konten ----------

class ContentIssueOut(BaseModel):
    code: str
    name: str
    confidence: float
    assigned_by: str


class ContentOut(BaseModel):
    id: int
    source_id: int
    source_name: str
    source_reliability: str
    url: str
    title: str
    excerpt: str
    author: str
    published_at: datetime
    ingested_at: datetime
    sentiment_label: str
    sentiment_score: float
    province_code: str | None
    province_name: str | None
    provinces: list[str]
    duplicate_of: int | None
    issues: list[ContentIssueOut]


class ContentDetail(ContentOut):
    body: str


class ContentList(BaseModel):
    total: int
    items: list[ContentOut]


class IssueCorrection(BaseModel):
    issue_codes: list[str] = Field(default_factory=list)


def _issues_for(db: Session, ids: list[int]) -> dict[int, list[ContentIssueOut]]:
    out: dict[int, list[ContentIssueOut]] = {i: [] for i in ids}
    if not ids:
        return out
    rows = db.execute(
        select(ContentIssue, Issue.code, Issue.name).join(Issue, Issue.id == ContentIssue.issue_id)
        .where(ContentIssue.content_id.in_(ids))
    ).all()
    for ci, code, name in rows:
        out[ci.content_id].append(ContentIssueOut(code=code, name=name, confidence=ci.confidence,
                                                  assigned_by=ci.assigned_by))
    return out


def _content_out(c: ContentItem, issues: list[ContentIssueOut], detail: bool = False) -> ContentOut:
    body = c.body or ""
    base = dict(
        id=c.id, source_id=c.source_id, source_name=c.source.name, source_reliability=c.source.reliability,
        url=c.url, title=c.title, excerpt=body[:280] + ("…" if len(body) > 280 else ""), author=c.author,
        published_at=c.published_at, ingested_at=c.ingested_at, sentiment_label=c.sentiment_label,
        sentiment_score=c.sentiment_score, province_code=c.province_code,
        province_name=PROVINCE_NAMES.get(c.province_code or ""), provinces=list(c.provinces or []),
        duplicate_of=c.duplicate_of, issues=sorted(issues, key=lambda i: -i.confidence),
    )
    return ContentDetail(**base, body=body) if detail else ContentOut(**base)


def _escape_like(term: str) -> str:
    return term.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")


@router.get("/content", response_model=ContentList)
def list_content(
    q: str | None = None,
    issue: str | None = None,
    province: str | None = None,
    sentiment: str | None = Query(default=None, pattern="^(positif|negatif|netral)$"),
    source_id: int | None = None,
    since: datetime | None = None,
    until: datetime | None = None,
    include_duplicates: bool = False,
    limit: int = Query(default=50, ge=1, le=200),
    offset: int = Query(default=0, ge=0),
    db: Session = Depends(get_db),
    _: User = Depends(get_current_user),
) -> ContentList:
    stmt = select(ContentItem)
    if not include_duplicates:
        stmt = stmt.where(ContentItem.duplicate_of.is_(None))
    if issue:
        stmt = stmt.join(ContentIssue, ContentIssue.content_id == ContentItem.id).join(
            Issue, Issue.id == ContentIssue.issue_id).where(Issue.code == issue)
    if province:
        stmt = stmt.where(ContentItem.province_code == province)
    if sentiment:
        stmt = stmt.where(ContentItem.sentiment_label == sentiment)
    if source_id:
        stmt = stmt.where(ContentItem.source_id == source_id)
    if since:
        stmt = stmt.where(ContentItem.published_at >= since)
    if until:
        stmt = stmt.where(ContentItem.published_at < until)
    terms = [t for t in (q or "").split() if t]
    if terms:
        if db.bind is not None and db.bind.dialect.name == "postgresql":
            tsv = func.to_tsvector("simple", func.concat_ws(" ", ContentItem.title, ContentItem.body))
            stmt = stmt.where(tsv.op("@@")(func.plainto_tsquery("simple", " ".join(terms))))
        else:
            for t in terms:
                pat = f"%{_escape_like(t)}%"
                stmt = stmt.where(or_(ContentItem.title.ilike(pat, escape="\\"), ContentItem.body.ilike(pat, escape="\\")))
    total = db.execute(select(func.count()).select_from(stmt.subquery())).scalar_one()
    rows = db.execute(stmt.order_by(ContentItem.published_at.desc(), ContentItem.id.desc())
                      .limit(limit).offset(offset)).unique().scalars().all()
    issues = _issues_for(db, [c.id for c in rows])
    return ContentList(total=total, items=[_content_out(c, issues[c.id]) for c in rows])


@router.get("/content/{content_id}", response_model=ContentDetail)
def get_content(content_id: int, db: Session = Depends(get_db), _: User = Depends(get_current_user)) -> ContentDetail:
    c = db.get(ContentItem, content_id)
    if c is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Konten tidak ditemukan")
    return _content_out(c, _issues_for(db, [c.id])[c.id], detail=True)  # type: ignore[return-value]


@router.put("/content/{content_id}/issues", response_model=ContentDetail)
def correct_issues(content_id: int, body: IssueCorrection, request: Request, db: Session = Depends(get_db),
                   user: User = Depends(require_roles(Role.ANALIS))) -> ContentDetail:
    """Koreksi klasifikasi isu oleh analis; tersimpan sebagai label latih (S14)."""
    c = db.get(ContentItem, content_id)
    if c is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Konten tidak ditemukan")
    codes = sorted(set(body.issue_codes))
    issues = db.execute(select(Issue).where(Issue.code.in_(codes))).scalars().all() if codes else []
    unknown = set(codes) - {i.code for i in issues}
    if unknown:
        raise HTTPException(422, detail=f"Isu tidak dikenal: {', '.join(sorted(unknown))}")
    before = [i.code for i in _issues_for(db, [c.id])[c.id]]
    db.execute(delete(ContentIssue).where(ContentIssue.content_id == c.id))
    now = utcnow()
    for i in issues:
        db.add(ContentIssue(content_id=c.id, issue_id=i.id, confidence=1.0, assigned_by="analyst",
                            corrected_by=user.id, created_at=now))
    audit.record(db, actor=user, action="konten.koreksi_isu", object_type="content", object_id=c.id,
                 ip=client_ip(request), detail={"before": sorted(before), "after": codes})
    db.commit()
    return _content_out(c, _issues_for(db, [c.id])[c.id], detail=True)  # type: ignore[return-value]
