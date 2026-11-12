"""Titik masuk Passenger untuk hosting cPanel (CloudLinux "Setup Python App").

- Konfigurasi dibaca dari berkas `sistra.env` (KUNCI=nilai, izin 0600) di folder aplikasi, bila ada;
  variabel yang sudah diatur di halaman Python App tetap diutamakan.
- Passenger memanggil objek WSGI `application`; FastAPI (ASGI) dibungkus a2wsgi. a2wsgi tidak
  menjalankan event lifespan, jadi inisialisasi basis data dilakukan di sini.
"""

import os
import sys

ROOT = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, ROOT)

_env_file = os.path.join(ROOT, "sistra.env")
if os.path.exists(_env_file):
    with open(_env_file) as fh:
        for line in fh:
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                key, value = line.split("=", 1)
                os.environ.setdefault(key.strip(), value.strip())

from a2wsgi import ASGIMiddleware  # noqa: E402

from app.core.db import init_db  # noqa: E402
from app.main import app  # noqa: E402

if os.getenv("SISTRA_AUTO_SEED") == "1":
    from app.seed import auto_seed  # noqa: E402

    auto_seed()
else:
    init_db()

application = ASGIMiddleware(app)
