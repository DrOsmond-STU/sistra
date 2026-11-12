"""Isi basis data dengan data awal & contoh sintetis.

Penggunaan:
    python -m app.seed            # isi bila kosong
    python -m app.seed --reset    # hapus semua lalu isi ulang (HANYA pengembangan)
"""

from __future__ import annotations

import argparse
import logging
import os
import secrets
from datetime import date, timedelta
from pathlib import Path

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.db import SessionLocal, init_db, reset_db
from app.core.security import hash_password
from app.core.timeutil import utcnow
from app.modules.analytics.geo import PROVINCE_NAMES
from app.modules.analytics.issues import DEFAULT_ISSUES
from app.modules.analytics.models import Issue
from app.modules.identity.models import Unit, User
from app.modules.ingest.models import Source
from app.modules.ingest.service import ingest_items
from app.modules.repository import storage
from app.modules.repository.models import Document, DocumentVersion
from app.modules.workflow import service as wf
from app.modules.workflow.models import ApprovalStep, CalendarEntry, Channel, Draft, DraftRevision
from app.seed_data import SAMPLE_DOCUMENTS, generate

log = logging.getLogger("sistra.seed")

UNITS = [
    ("DEP7", "Deputi Bidang Komunikasi dan Informasi", None),
    ("DIT-ANL", "Direktorat Analisis Media", "DEP7"),
    ("DIT-PRD", "Direktorat Produksi & Komunikasi Strategis", "DEP7"),
    ("DIT-KNL", "Direktorat Kanal & Distribusi", "DEP7"),
    ("BAG-ARS", "Bagian Arsip & Data", "DEP7"),
    ("BAG-TI", "Bagian Teknologi Informasi", "DEP7"),
]

# username, nama, unit, peran, clearance
USERS = [
    ("admin", "Administrator Sistem", "BAG-TI", ["admin"], "TERBATAS"),
    ("auditor", "Auditor Internal", "DEP7", ["auditor"], "RAHASIA"),
    ("pimpinan", "Pimpinan Deputi (contoh)", "DEP7", ["pimpinan"], "SANGAT_RAHASIA"),
    ("pimpinan.anl", "Direktur Analisis (contoh)", "DIT-ANL", ["pimpinan"], "RAHASIA"),
    ("analis", "Analis Media (contoh)", "DIT-ANL", ["analis"], "RAHASIA"),
    ("analis2", "Analis Produksi (contoh)", "DIT-PRD", ["analis"], "RAHASIA"),
    ("redaktur", "Redaktur (contoh)", "DIT-PRD", ["redaktur"], "TERBATAS"),
    ("produser", "Produser Multimedia (contoh)", "DIT-PRD", ["produser"], "TERBATAS"),
    ("operator", "Operator Kanal (contoh)", "DIT-KNL", ["operator"], "TERBATAS"),
    ("arsiparis", "Arsiparis (contoh)", "BAG-ARS", ["arsiparis"], "RAHASIA"),
    ("pelapor", "Petugas Pelapor Unit (contoh)", "DIT-KNL", ["pelapor"], "TERBUKA"),
]


def seed_base(db: Session, password: str, per_user: dict[str, str] | None = None) -> dict[str, User]:
    units: dict[str, Unit] = {}
    for code, name, parent in UNITS:
        u = db.execute(select(Unit).where(Unit.code == code)).scalar_one_or_none()
        if u is None:
            u = Unit(code=code, name=name, parent_id=units[parent].id if parent else None)
            db.add(u)
            db.flush()
        units[code] = u
    users: dict[str, User] = {}
    for i, (username, full_name, unit, roles, clearance) in enumerate(USERS, start=1):
        u = db.execute(select(User).where(User.username == username)).scalar_one_or_none()
        if u is None:
            u = User(username=username, full_name=full_name, nip=f"19900101202701{i:04d}", unit_id=units[unit].id,
                     roles=roles, clearance=clearance,
                     password_hash=hash_password((per_user or {}).get(username, password)))
            db.add(u)
            db.flush()
        users[username] = u
    for spec in DEFAULT_ISSUES:
        if db.execute(select(Issue).where(Issue.code == spec["code"])).first() is None:
            db.add(Issue(**spec))
    if db.execute(select(Channel)).first() is None:
        db.add(Channel(code="NAS", name="Kanal Nasional", type="nasional"))
        for code, name in PROVINCE_NAMES.items():
            db.add(Channel(code=f"PROV-{code}", name=f"Kanal {name}", type="provinsi", province_code=code))
        db.add(Channel(code="RESMI-IG", name="Akun Resmi Instagram (contoh)", type="medsos_resmi"))
        db.add(Channel(code="RESMI-X", name="Akun Resmi X (contoh)", type="medsos_resmi"))
    db.flush()
    return users


def seed_demo(db: Session, users: dict[str, User]) -> None:
    now = utcnow()
    src = Source(type="news", name="Portal Berita Contoh (sintetis)", reliability="B",
                 access_basis="Data sintetis untuk demonstrasi")
    db.add(src)
    db.add(Source(type="news", name="Feed RSS (isi URL feed publik)", url="", reliability="C"))
    db.flush()
    result = ingest_items(db, src, generate(now), actor=users["analis"], now=now)
    log.info("Konten contoh: %s", result)

    for spec in SAMPLE_DOCUMENTS:
        owner = users[spec["owner"]]
        data = spec["text"].encode()
        sha, _ = storage.put(data)
        mime = "text/csv" if spec["filename"].endswith(".csv") else "text/markdown" if spec["filename"].endswith(".md") else "text/plain"
        unit_id = db.execute(select(Unit.id).where(Unit.code == spec["unit"])).scalar_one()
        doc = Document(title=spec["title"], owner_unit_id=unit_id, classification=spec["classification"],
                       period_start=date.today() - timedelta(days=7), period_end=date.today(), origin=spec["origin"],
                       tags=spec["tags"].split(","), created_by=owner.id, created_at=now, updated_at=now)
        doc.versions.append(DocumentVersion(version_no=1, filename=spec["filename"], mime=mime, size=len(data),
                                            sha256=sha, text_content=spec["text"], note="Versi awal",
                                            created_by=owner.id, created_at=now))
        db.add(doc)
        db.flush()
        audit.record(db, actor=owner, action="dokumen.unggah", object_type="document", object_id=doc.id,
                     detail={"title": doc.title, "classification": doc.classification, "sha256": sha, "seed": True})

    issue_id = db.execute(select(Issue.id).where(Issue.code == "EKO-PANGAN")).scalar_one()

    def make(author: User, kind: str, title: str, body: str, ai: bool = False, prov: str | None = None) -> Draft:
        d = Draft(kind=kind, title=title, body=body, ai_generated=ai, issue_id=issue_id, province_code=prov,
                  status="draf", version=1, created_by=author.id, created_at=now, updated_at=now,
                  content_hash=wf.content_hash(title, body))
        d.revisions.append(DraftRevision(version=1, title=title, body=body, edited_by=author.id, at=now))
        db.add(d)
        db.flush()
        audit.record(db, actor=author, action="naskah.buat", object_type="draft", object_id=d.id,
                     detail={"kind": kind, "ai_generated": ai, "seed": True})
        return d

    def step(d: Draft, actor: User, to: str, role: str, comment: str = "") -> None:
        sig = None
        if to == "disetujui":
            sig = wf.approval_signature(d, actor)
            d.approved_by, d.approved_at, d.approval_signature = actor.id, now, sig
        d.steps.append(ApprovalStep(from_status=d.status, to_status=to, actor_id=actor.id, actor_role=role,
                                    comment=comment, version=d.version, content_hash=d.content_hash, signature=sig,
                                    at=now))
        audit.record(db, actor=actor, action="naskah.transisi", object_type="draft", object_id=d.id,
                     detail={"from": d.status, "to": to, "seed": True})
        d.status = to

    make(users["redaktur"], "rilis", "Pemerintah Pastikan Stok Beras Nasional Aman (contoh)",
         "Stok beras nasional dalam kondisi aman dan operasi pasar diperluas ke sejumlah provinsi. "
         "Masyarakat diimbau membeli sesuai kebutuhan. (Naskah contoh sintetis.)")
    d2 = make(users["produser"], "briefing", "Klarifikasi Hoaks Bantuan Sosial (contoh)",
              "Informasi yang menyebut penghentian bantuan sosial tidak benar. Penyaluran berjalan sesuai jadwal "
              "dan masyarakat dapat memeriksa melalui kanal resmi. (Naskah contoh sintetis.)", ai=True)
    step(d2, users["produser"], "ditinjau", "produser")
    d3 = make(users["redaktur"], "rilis", "Operasi Pasar Beras Diperluas di Jawa Timur (contoh)",
              "Operasi pasar beras diperluas ke sepuluh kabupaten/kota di Jawa Timur mulai pekan ini untuk menjaga "
              "keterjangkauan harga. (Naskah contoh sintetis.)", prov="35")
    step(d3, users["redaktur"], "ditinjau", "redaktur")
    step(d3, users["pimpinan"], "disetujui", "pimpinan", "Setuju untuk ditayangkan.")
    for code in ("NAS", "PROV-35"):
        ch = db.execute(select(Channel).where(Channel.code == code)).scalar_one()
        db.add(CalendarEntry(draft_id=d3.id, channel_id=ch.id, planned_at=now + timedelta(hours=3),
                             created_by=users["redaktur"].id))


def _write_credentials(path: Path, creds: dict[str, str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as fh:
        fh.write("# Kredensial akun SISTRA — simpan di tempat aman lalu hapus berkas ini.\n")
        for username, pw in creds.items():
            fh.write(f"{username}\t{pw}\n")


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    parser = argparse.ArgumentParser(description="Seed data SISTRA")
    parser.add_argument("--reset", action="store_true", help="hapus semua data lalu isi ulang")
    parser.add_argument("--no-demo", action="store_true", help="hanya data dasar (unit, pengguna, isu, kanal)")
    parser.add_argument("--random-passwords", metavar="BERKAS",
                        help="buat kata sandi acak per akun dan tulis ke BERKAS (izin 0600), bukan kata sandi bersama")
    args = parser.parse_args()
    password = os.getenv("SISTRA_SEED_PASSWORD", "sistra-demo-2027")
    per_user = ({u[0]: secrets.token_urlsafe(12) for u in USERS} if args.random_passwords else None)

    if args.reset:
        reset_db()
    else:
        init_db()
    with SessionLocal() as db:
        if db.execute(select(User)).first() is not None and not args.reset:
            log.info("Basis data sudah berisi pengguna; seed dilewati (gunakan --reset untuk mengisi ulang).")
            return
        users = seed_base(db, password, per_user)
        if not args.no_demo:
            seed_demo(db, users)
        db.commit()
    if per_user:
        _write_credentials(Path(args.random_passwords), per_user)
        log.info("Kata sandi acak per akun ditulis ke %s (izin 0600).", args.random_passwords)
    else:
        log.warning("Pengguna contoh dibuat dengan kata sandi '%s' — ganti sebelum dipakai selain demo.", password)


if __name__ == "__main__":
    main()
