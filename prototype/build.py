"""Gabungkan purwarupa menjadi satu berkas HTML mandiri: dist/prototipe.html"""
from pathlib import Path

root = Path(__file__).parent
src = root / "src"
html = (src / "shell.html").read_text()
css = (src / "style.css").read_text()
js = "\n".join((src / f).read_text() for f in ("data.js", "charts.js", "app.js"))
out = html.replace("/*CSS*/", css).replace("/*JS*/", js.replace("</script", "<\\/script"))
(root / "dist").mkdir(exist_ok=True)
(root / "dist" / "prototipe.html").write_text(out)
print(f"dist/prototipe.html {len(out) / 1024:.0f} KB")
