#!/usr/bin/env bash
# Bangun paket satu-aplikasi untuk hosting cPanel: backend + build frontend dalam satu folder.
# Hasil: dist/sistra-bundle.tar.gz  → unggah & ekstrak ke folder aplikasi Python di cPanel.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist/sistra-bundle"
rm -rf "$OUT" && mkdir -p "$OUT"

(cd "$ROOT/frontend" && npm ci --no-audit --no-fund && npm run build)

cp -r "$ROOT/backend/app" "$OUT/app"
cp -r "$ROOT/backend/sample_data" "$OUT/sample_data"
cp -r "$ROOT/frontend/dist" "$OUT/web"
cp "$ROOT/backend/requirements.txt" "$OUT/requirements-app.txt"
cp "$ROOT/deploy/cpanel/requirements.txt" "$OUT/requirements.txt"
cp "$ROOT/deploy/cpanel/passenger_wsgi.py" "$OUT/passenger_wsgi.py"
find "$OUT" -name __pycache__ -type d -prune -exec rm -rf {} +

tar -C "$ROOT/dist" -czf "$ROOT/dist/sistra-bundle.tar.gz" sistra-bundle
echo "Paket siap: $ROOT/dist/sistra-bundle.tar.gz"
