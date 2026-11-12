"""Titik masuk aplikasi SISTRA (MVP, modular monolith)."""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles

from app.core.config import get_settings
from app.core.db import init_db
from app.modules.alerts.routes import router as alerts_router
from app.modules.analytics.routes import router as analytics_router
from app.modules.dashboard.routes import router as dashboard_router
from app.modules.identity.routes import router as identity_router
from app.modules.ingest.routes import router as ingest_router
from app.modules.repository.routes import router as repository_router
from app.modules.workflow.routes import router as workflow_router

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")

VERSION = "0.1.0-mvp"


@asynccontextmanager
async def lifespan(_: FastAPI):
    init_db()
    yield


def create_app() -> FastAPI:
    settings = get_settings()
    app = FastAPI(
        title="SISTRA API",
        description="Sistem Informasi dan Aksi Strategis — MVP (Modul 1, 2, 5, 8, 9).",
        version=VERSION,
        lifespan=lifespan,
        docs_url="/api/docs",
        openapi_url="/api/openapi.json",
    )
    app.add_middleware(
        CORSMiddleware,
        allow_origins=settings.cors_origins,
        allow_credentials=False,
        allow_methods=["*"],
        allow_headers=["Authorization", "Content-Type"],
        expose_headers=["Content-Disposition", "X-SISTRA-Watermark", "X-SISTRA-Watermark-Embedded"],
    )

    @app.middleware("http")
    async def security_headers(request, call_next):  # noqa: ANN001, ANN202
        response = await call_next(request)
        response.headers.setdefault("X-Content-Type-Options", "nosniff")
        response.headers.setdefault("X-Frame-Options", "DENY")
        response.headers.setdefault("Referrer-Policy", "no-referrer")
        response.headers.setdefault(
            "Content-Security-Policy",
            "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; connect-src 'self'; "
            "frame-ancestors 'none'",
        )
        return response

    for router in (identity_router, dashboard_router, ingest_router, analytics_router, alerts_router,
                   workflow_router, repository_router):
        app.include_router(router, prefix="/api")

    @app.get("/api/health", tags=["sistem"])
    def health() -> dict:
        return {"status": "ok", "version": VERSION}

    if settings.static_dir and (settings.static_dir / "index.html").is_file():
        _mount_frontend(app, settings.static_dir)

    return app


def _mount_frontend(app: FastAPI, root: Path) -> None:
    """Mode satu-aplikasi (mis. hosting cPanel): sajikan build frontend dari backend, dengan fallback SPA."""
    app.mount("/assets", StaticFiles(directory=root / "assets"), name="assets")
    index = root / "index.html"

    @app.get("/{path:path}", include_in_schema=False)
    def spa(path: str) -> FileResponse:
        if path.startswith("api/"):
            raise HTTPException(status_code=404, detail="Tidak ditemukan")
        candidate = (root / path).resolve()
        if path and candidate.is_file() and candidate.is_relative_to(root):
            return FileResponse(candidate)
        return FileResponse(index, headers={"Cache-Control": "no-cache"})


app = create_app()
