"""Titik masuk aplikasi SISTRA (MVP, modular monolith)."""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

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
        return response

    for router in (identity_router, dashboard_router, ingest_router, analytics_router, alerts_router,
                   workflow_router, repository_router):
        app.include_router(router, prefix="/api")

    @app.get("/api/health", tags=["sistem"])
    def health() -> dict:
        return {"status": "ok", "version": VERSION}

    return app


app = create_app()
