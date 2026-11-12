"""Autentikasi (hash kata sandi, JWT) dan dependensi otorisasi berbasis peran.

MVP memakai JWT lokal. Pada F1 diganti OIDC (Keycloak) + MFA FIDO2; antarmuka
`get_current_user` dan `require_roles` dipertahankan agar modul tidak berubah.
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import secrets
from collections.abc import Callable
from datetime import timedelta

import jwt
from fastapi import Depends, HTTPException, Request, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from sqlalchemy.orm import Session

from app.core.config import get_settings
from app.core.db import get_db
from app.core.timeutil import utcnow
from app.modules.identity.models import User

_SCRYPT_N, _SCRYPT_R, _SCRYPT_P = 2**14, 8, 1
_JWT_ALG = "HS256"

bearer = HTTPBearer(auto_error=False)


def hash_password(password: str) -> str:
    salt = secrets.token_bytes(16)
    digest = hashlib.scrypt(password.encode(), salt=salt, n=_SCRYPT_N, r=_SCRYPT_R, p=_SCRYPT_P, dklen=32)
    return "scrypt${}${}${}${}${}".format(
        _SCRYPT_N, _SCRYPT_R, _SCRYPT_P, base64.b64encode(salt).decode(), base64.b64encode(digest).decode()
    )


def verify_password(password: str, encoded: str) -> bool:
    try:
        scheme, n, r, p, salt_b64, hash_b64 = encoded.split("$")
        if scheme != "scrypt":
            return False
        expected = base64.b64decode(hash_b64)
        digest = hashlib.scrypt(
            password.encode(), salt=base64.b64decode(salt_b64), n=int(n), r=int(r), p=int(p), dklen=len(expected)
        )
        return hmac.compare_digest(digest, expected)
    except (ValueError, TypeError):
        return False


def create_access_token(user: User) -> str:
    settings = get_settings()
    now = utcnow()
    payload = {
        "sub": str(user.id),
        "username": user.username,
        "iat": int(now.timestamp()),
        "exp": int((now + timedelta(minutes=settings.token_ttl_minutes)).timestamp()),
    }
    return jwt.encode(payload, settings.secret_key, algorithm=_JWT_ALG)


def _unauthorized(detail: str = "Tidak terautentikasi") -> HTTPException:
    return HTTPException(status.HTTP_401_UNAUTHORIZED, detail=detail, headers={"WWW-Authenticate": "Bearer"})


def get_current_user(
    creds: HTTPAuthorizationCredentials | None = Depends(bearer),
    db: Session = Depends(get_db),
) -> User:
    if creds is None:
        raise _unauthorized()
    try:
        payload = jwt.decode(creds.credentials, get_settings().secret_key, algorithms=[_JWT_ALG])
        user_id = int(payload["sub"])
    except (jwt.PyJWTError, KeyError, ValueError):
        raise _unauthorized("Token tidak valid atau kedaluwarsa")
    user = db.get(User, user_id)
    if user is None or not user.active:
        raise _unauthorized("Pengguna tidak aktif")
    return user


def require_roles(*roles: str) -> Callable[..., User]:
    """Dependensi: pengguna wajib memiliki salah satu peran."""

    def _dep(user: User = Depends(get_current_user)) -> User:
        if not user.has_role(*roles):
            raise HTTPException(status.HTTP_403_FORBIDDEN, detail=f"Memerlukan peran: {', '.join(roles)}")
        return user

    return _dep


def client_ip(request: Request) -> str:
    return request.client.host if request.client else "-"


def sign(message: str) -> str:
    """Tanda tangan HMAC-SHA256 (pengganti HSM pada MVP)."""
    return hmac.new(get_settings().signing_key.encode(), message.encode(), hashlib.sha256).hexdigest()


def verify_signature(message: str, signature: str) -> bool:
    return hmac.compare_digest(sign(message), signature or "")
