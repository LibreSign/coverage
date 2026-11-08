# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Insert the interactive, accessible trend into generated site HTML."""
from pathlib import Path
import argparse
import shutil

MARKER = '<!-- LibreSign trend chart -->'
WIDGET = '''<section id="coverage-trend" aria-labelledby="trend-title">
  <h2 id="trend-title">Evolução do coverage total</h2>
  <div class="coverage-controls">
    <label>Fonte
      <select id="trend-source">
        <option value="legacy_coveralls">Coveralls — histórico</option>
        <option value="octocov_main">Octocov — main</option>
      </select>
    </label>
    <label>Período
      <select id="trend-period">
        <option value="all">Todo o histórico</option>
        <option value="year">Últimos 12 meses de dados</option>
        <option value="quarter">Últimos 90 dias de dados</option>
      </select>
    </label>
  </div>
  <p id="trend-status" aria-live="polite">Carregando medições históricas…</p>
  <svg class="coverage-chart" aria-label="Evolução do coverage total"></svg>
  <div id="trend-hover-tooltip" role="tooltip" hidden></div>
  <a id="trend-point-details" href="#" hidden rel="noopener noreferrer"></a>
  <p><small>Coveralls e Octocov usam métricas distintas. As séries não são conectadas.
  <span class="coverage-legend">Linha contínua: entre medições próximas. Linha tracejada: último valor conhecido, não nova medição.</span>
  Nos intervalos sem dados, passe o mouse sobre o traço para consultar o percentual e a data original.
  Mudanças de escopo acima de 30% também são indicadas por trecho tracejado.
  Alterações no percentual não representam necessariamente mudanças na qualidade do código.</small></p>
  <details id="trend-table-details"><summary>Consultar todas as medições deste período</summary>
    <table id="trend-data"><thead><tr><th>Data</th><th>Coverage</th><th>Cobertura</th><th>Commit</th><th>Origem</th></tr></thead><tbody></tbody></table>
  </details>
</section>'''


def prepare(public: Path):
    root=public / "index.html"
    data=public / "assets/coverage-history.json"
    if not root.is_file() or not data.is_file():
        raise FileNotFoundError("Generated index or history data not found")
    destination=public / "assets/coverage-theme"
    destination.mkdir(parents=True, exist_ok=True)
    source=Path(__file__).resolve().parents[1] / "site-theme"
    for name in ("trend.js","trend.css"):
        shutil.copyfile(source / name, destination / name)
    html=root.read_text()
    if MARKER in html:
        return
    target='<h2 id="current-repositories">'
    if target not in html:
        raise ValueError("Generated Octocov index lacks the repository section")
    markup = (MARKER + '\n' + WIDGET + '\n'
              + '<link rel="stylesheet" href="assets/coverage-theme/trend.css">\n'
              + '<script src="assets/coverage-theme/trend.js" defer></script>\n')
    root.write_text(html.replace(target,markup+target,1))


if __name__=="__main__":
    p=argparse.ArgumentParser()
    p.add_argument("--public",type=Path,required=True)
    prepare(p.parse_args().public)
