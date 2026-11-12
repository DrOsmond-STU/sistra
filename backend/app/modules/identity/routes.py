from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Request, status
from pydantic import BaseModel, Field, field_validator
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.classification import LEVEL_LABELS, Level, Role
from app.core.db import get_db
from app.core.security import (
    client_ip,
    create_access_token,
    get_current_user,
    hash_password,
    require_roles,
    verify_password,
)
from app.modules.identity.models import Unit, User

router = APIRouter(tags=["identitas"])


class UnitOut(BaseModel):
    id: int
    code: str
    name: str
    parent_id: int | None


class UserOut(BaseModel):
    id: int
    username: str
    full_name: str
    nip: str
    unit: UnitOut
    roles: list[str]
    clearance: str
    clearance_label: str
    active: bool

    @classmethod
    def of(cls, u: User) -> "UserOut":
        return cls(
            id=u.id,
            username=u.username,
            full_name=u.full_name,
            nip=u.nip,
            unit=UnitOut(id=u.unit.id, code=u.unit.code, name=u.unit.name, parent_id=u.unit.parent_id),
            roles=list(u.roles or []),
            clearance=u.clearance,
            clearance_label=LEVEL_LABELS[u.clearance_level],
            active=u.active,
        )


class LoginIn(BaseModel):
    username: str
    password: str


class TokenOut(BaseModel):
    access_token: str
    token_type: str = "bearer"
    user: UserOut


def _validate_roles(v: list[str]) -> list[str]:
    unknown = set(v) - Role.ALL
    if unknown:
        raise ValueError(f"Peran tidak dikenal: {', '.join(sorted(unknown))}")
    return sorted(set(v))


def _validate_clearance(v: str) -> str:
    return Level.parse(v).name


class UserCreate(BaseModel):
    username: str = Field(min_length=3, max_length=64, pattern=r"^[a-z0-9._-]+$")
    full_name: str = Field(min_length=1, max_length=200)
    nip: str = ""
    unit_code: str
    roles: list[str] = Field(min_length=1)
    clearance: str = Level.TERBUKA.name
    password: str = Field(min_length=8)

    _r = field_validator("roles")(_validate_roles)
    _c = field_validator("clearance")(_validate_clearance)


class UserUpdate(BaseModel):
    roles: list[str] | None = None
    clearance: str | None = None
    active: bool | None = None
    unit_code: str | None = None

    @field_validator("roles")
    @classmethod
    def _roles(cls, v: list[str] | None) -> list[str] | None:
        return None if v is None else _validate_roles(v)

    @field_validator("clearance")
    @classmethod
    def _clr(cls, v: str | None) -> str | None:
        return None if v is None else _validate_clearance(v)


@router.post("/auth/login", response_model=TokenOut)
def login(body: LoginIn, request: Request, db: Session = Depends(get_db)) -> TokenOut:
    user = db.execute(select(User).where(User.username == body.username)).scalar_one_or_none()
    if user is None or not user.active or not verify_password(body.password, user.password_hash):
        audit.record(
            db, actor=None, actor_username=body.username[:64], action="auth.login_gagal",
            object_type="user", object_id=body.username[:64], ip=client_ip(request),
        )
        db.commit()
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, detail="Nama pengguna atau kata sandi salah")
    audit.record(db, actor=user, action="auth.login", object_type="user", object_id=user.id, ip=client_ip(request))
    db.commit()
    return TokenOut(access_token=create_access_token(user), user=UserOut.of(user))


@router.get("/auth/me", response_model=UserOut)
def me(user: User = Depends(get_current_user)) -> UserOut:
    return UserOut.of(user)


@router.get("/units", response_model=list[UnitOut])
def list_units(db: Session = Depends(get_db), _: User = Depends(get_current_user)) -> list[UnitOut]:
    units = db.execute(select(Unit).order_by(Unit.code)).scalars().all()
    return [UnitOut(id=u.id, code=u.code, name=u.name, parent_id=u.parent_id) for u in units]


@router.get("/users", response_model=list[UserOut])
def list_users(db: Session = Depends(get_db), _: User = Depends(require_roles(Role.ADMIN))) -> list[UserOut]:
    return [UserOut.of(u) for u in db.execute(select(User).order_by(User.username)).scalars().all()]


def _unit_by_code(db: Session, code: str) -> Unit:
    unit = db.execute(select(Unit).where(Unit.code == code)).scalar_one_or_none()
    if unit is None:
        raise HTTPException(422, detail=f"Unit tidak ditemukan: {code}")
    return unit


@router.post("/users", response_model=UserOut, status_code=201)
def create_user(
    body: UserCreate,
    request: Request,
    db: Session = Depends(get_db),
    admin: User = Depends(require_roles(Role.ADMIN)),
) -> UserOut:
    if db.execute(select(User).where(User.username == body.username)).scalar_one_or_none():
        raise HTTPException(status.HTTP_409_CONFLICT, detail="Nama pengguna sudah dipakai")
    user = User(
        username=body.username,
        full_name=body.full_name,
        nip=body.nip,
        unit_id=_unit_by_code(db, body.unit_code).id,
        roles=body.roles,
        clearance=body.clearance,
        password_hash=hash_password(body.password),
    )
    db.add(user)
    db.flush()
    audit.record(
        db, actor=admin, action="user.buat", object_type="user", object_id=user.id, ip=client_ip(request),
        detail={"username": user.username, "roles": user.roles, "clearance": user.clearance},
    )
    db.commit()
    db.refresh(user)
    return UserOut.of(user)


@router.patch("/users/{user_id}", response_model=UserOut)
def update_user(
    user_id: int,
    body: UserUpdate,
    request: Request,
    db: Session = Depends(get_db),
    admin: User = Depends(require_roles(Role.ADMIN)),
) -> UserOut:
    user = db.get(User, user_id)
    if user is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Pengguna tidak ditemukan")
    if user.id == admin.id and (body.active is False or (body.roles is not None and Role.ADMIN not in body.roles)):
        raise HTTPException(status.HTTP_400_BAD_REQUEST, detail="Admin tidak dapat menonaktifkan/mencabut perannya sendiri")
    changes: dict = {}
    if body.roles is not None:
        changes["roles"] = [user.roles, body.roles]
        user.roles = body.roles
    if body.clearance is not None:
        changes["clearance"] = [user.clearance, body.clearance]
        user.clearance = body.clearance
    if body.active is not None:
        changes["active"] = [user.active, body.active]
        user.active = body.active
    if body.unit_code is not None:
        unit = _unit_by_code(db, body.unit_code)
        changes["unit"] = [user.unit.code, unit.code]
        user.unit_id = unit.id
    audit.record(db, actor=admin, action="user.ubah", object_type="user", object_id=user.id,
                 ip=client_ip(request), detail=changes)
    db.commit()
    db.refresh(user)
    return UserOut.of(user)
