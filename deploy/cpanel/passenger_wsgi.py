"""Titik masuk Passenger untuk hosting cPanel (CloudLinux "Setup Python App").

Passenger memanggil objek WSGI `application`; FastAPI adalah ASGI sehingga dibungkus a2wsgi.
Variabel lingkungan (SISTRA_*) diatur di halaman "Setup Python App" cPanel.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(__file__))

from a2wsgi import ASGIMiddleware  # noqa: E402

from app.main import app  # noqa: E402

application = ASGIMiddleware(app)
