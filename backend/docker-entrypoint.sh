#!/bin/sh
set -e
# Buat skema; isi data dasar (+ data contoh bila SISTRA_SEED_DEMO=1) hanya jika basis data masih kosong.
if [ "${SISTRA_SEED_DEMO:-0}" = "1" ]; then
  python -m app.seed
else
  python -m app.seed --no-demo
fi
exec "$@"
