"""Mesin status persetujuan komando berjenjang (S36).

draf → ditinjau → disetujui → tayang → ditarik
           ↘ draf (dikembalikan)      disetujui → ditarik (dibatalkan)

Aturan:
- Hanya penulis yang mengajukan naskahnya; penulis tidak boleh mengembalikan/menyetujui naskahnya sendiri.
- Persetujuan oleh pimpinan disertai tanda tangan atas (id, versi, hash isi) — pengganti HSM pada MVP.
- Penayangan memverifikasi bahwa isi tidak berubah sejak ditandatangani.
- Penyuntingan hanya pada status draf; tidak ada jalur publikasi yang melewati persetujuan manusia (S33).
"""

from __future__ import annotations

import hashlib
from dataclasses import dataclass

from app.core.classification import Role
from app.core.security import sign, verify_signature
from app.modules.identity.models import User
from app.modules.workflow.models import Draft

AUTHOR_ROLES = (Role.REDAKTUR, Role.PRODUSER, Role.ANALIS)

AI_LABEL = "Konten ini disusun dengan bantuan AI dan telah ditinjau oleh redaksi."


@dataclass(frozen=True)
class Rule:
    roles: tuple[str, ...]
    author_only: bool = False
    not_author: bool = False
    comment_required: bool = False
    sign: bool = False
    verify_signature: bool = False


RULES: dict[tuple[str, str], Rule] = {
    ("draf", "ditinjau"): Rule(roles=AUTHOR_ROLES, author_only=True),
    ("ditinjau", "draf"): Rule(roles=(Role.REDAKTUR, Role.PIMPINAN), not_author=True, comment_required=True),
    ("ditinjau", "disetujui"): Rule(roles=(Role.PIMPINAN,), not_author=True, sign=True),
    ("disetujui", "tayang"): Rule(roles=(Role.OPERATOR,), verify_signature=True),
    ("disetujui", "ditarik"): Rule(roles=(Role.PIMPINAN,), comment_required=True),
    ("tayang", "ditarik"): Rule(roles=(Role.PIMPINAN, Role.REDAKTUR), comment_required=True),
}


class TransitionError(Exception):
    def __init__(self, message: str, code: int = 409):
        super().__init__(message)
        self.code = code


def content_hash(title: str, body: str) -> str:
    return hashlib.sha256(f"{title}\n\n{body}".encode()).hexdigest()


def signing_message(draft: Draft, approver_id: int) -> str:
    return f"sistra-draft|{draft.id}|v{draft.version}|{draft.content_hash}|{approver_id}"


def allowed_actions(draft: Draft, user: User) -> list[str]:
    out = []
    for (src, dst), rule in RULES.items():
        if src != draft.status or not user.has_role(*rule.roles):
            continue
        if rule.author_only and draft.created_by != user.id:
            continue
        if rule.not_author and draft.created_by == user.id:
            continue
        out.append(dst)
    return out


def check_transition(draft: Draft, to_status: str, user: User, comment: str) -> Rule:
    rule = RULES.get((draft.status, to_status))
    if rule is None:
        raise TransitionError(f"Transisi '{draft.status}' → '{to_status}' tidak diizinkan")
    if not user.has_role(*rule.roles):
        raise TransitionError(f"Transisi ini memerlukan peran: {', '.join(rule.roles)}", 403)
    if rule.author_only and draft.created_by != user.id:
        raise TransitionError("Hanya penulis yang dapat mengajukan naskah ini", 403)
    if rule.not_author and draft.created_by == user.id:
        raise TransitionError("Penulis tidak boleh meninjau/menyetujui naskahnya sendiri", 403)
    if rule.comment_required and not comment.strip():
        raise TransitionError("Catatan wajib diisi untuk transisi ini", 422)
    if rule.verify_signature:
        if draft.approved_by is None or not draft.approval_signature:
            raise TransitionError("Naskah belum memiliki tanda tangan persetujuan")
        if content_hash(draft.title, draft.body) != draft.content_hash or not verify_signature(
            signing_message(draft, draft.approved_by), draft.approval_signature
        ):
            raise TransitionError("Verifikasi tanda tangan gagal: isi naskah berubah sejak disetujui", 409)
    return rule


def approval_signature(draft: Draft, approver: User) -> str:
    return sign(signing_message(draft, approver.id))
