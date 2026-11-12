"""Middleware ASGI untuk penempatan di belakang reverse proxy (mis. proxy PHP di hosting lain).

- `SISTRA_TRUSTED_PROXIES`: daftar IP proxy tepercaya. Hanya dari IP ini header X-Forwarded-For dipercaya,
  sehingga IP asli pengguna tercatat di audit dan watermark.
- `SISTRA_PROXY_ONLY=1`: tolak (403) semua koneksi yang tidak datang dari proxy tepercaya, sehingga
  server origin tidak dapat diakses langsung dari internet.
"""

from __future__ import annotations

import ipaddress
import json


def _norm(ip: str) -> str:
    try:
        addr = ipaddress.ip_address(ip.strip().strip("[]"))
        return str(addr.ipv4_mapped or addr) if isinstance(addr, ipaddress.IPv6Address) else str(addr)
    except ValueError:
        return ip.strip()


class TrustedProxyMiddleware:
    def __init__(self, app, trusted: frozenset[str], proxy_only: bool) -> None:  # noqa: ANN001
        self.app = app
        self.trusted = frozenset(_norm(ip) for ip in trusted)
        self.proxy_only = proxy_only

    async def __call__(self, scope, receive, send):  # noqa: ANN001
        if scope["type"] not in ("http", "websocket"):
            return await self.app(scope, receive, send)
        peer = _norm(scope["client"][0]) if scope.get("client") else ""
        from_proxy = peer in self.trusted
        if self.proxy_only and not from_proxy:
            body = json.dumps({"detail": "Akses langsung ke server origin tidak diizinkan"}).encode()
            await send({"type": "http.response.start", "status": 403,
                        "headers": [(b"content-type", b"application/json"), (b"content-length", str(len(body)).encode())]})
            await send({"type": "http.response.body", "body": body})
            return
        if from_proxy:
            headers = dict(scope.get("headers") or [])
            xff = headers.get(b"x-forwarded-for", b"").decode("latin-1")
            if xff:
                scope = dict(scope)
                scope["client"] = (_norm(xff.split(",")[0]), 0)
            proto = headers.get(b"x-forwarded-proto", b"").decode("latin-1")
            if proto in ("http", "https"):
                scope = dict(scope)
                scope["scheme"] = proto
        return await self.app(scope, receive, send)
