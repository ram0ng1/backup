#!/usr/bin/env python3
"""Remove do SARIF os achados já suprimidos in-source (`nosemgrep`).

Por que isto existe
-------------------
Um comentário `nosemgrep` na linha tira o achado do exit code do semgrep —
o gate bloqueante do PR nunca reprova por causa dele. Mas o semgrep ainda
emite o achado no SARIF, marcado com `"suppressions": [{"kind":
"inSource"}]`. O GitHub code scanning entende esse campo, porém o bot de
review (`github-advanced-security`) posta comentário de PR mesmo assim.
O resultado é uma enxurrada de alerta em linha que já carrega
justificativa escrita ao lado — ruído que treina o revisor a ignorar
alerta de segurança, que é exatamente o oposto do que a camada serve.

Filtramos apenas a cópia que vai para o code scanning. O SARIF íntegro,
com os suprimidos, continua sendo publicado como artefato do job (ver
`.github/workflows/security.yml`), então o histórico de "o que foi
suprimido e onde" não se perde.

Falha aberta de propósito: se o arquivo não existir, não for JSON válido
ou tiver formato inesperado, copiamos a entrada para a saída sem filtrar.
Perder o upload de segurança inteiro por causa de um erro de parsing
seria pior do que publicar achados a mais.

Uso:
    python3 strip-suppressed.py entrada.sarif saida.sarif
"""

from __future__ import annotations

import json
import shutil
import sys


def strip_suppressed(src: str, dst: str) -> int:
    try:
        with open(src, encoding="utf-8") as handle:
            document = json.load(handle)
    except (OSError, ValueError) as exc:
        print(f"{src}: ilegível ({exc}) — copiado sem filtrar", file=sys.stderr)
        try:
            shutil.copyfile(src, dst)
        except OSError:
            pass
        return 0

    runs = document.get("runs")
    if not isinstance(runs, list):
        print(f"{src}: sem `runs` — copiado sem filtrar", file=sys.stderr)
        shutil.copyfile(src, dst)
        return 0

    removed = 0
    for run in runs:
        if not isinstance(run, dict):
            continue
        results = run.get("results")
        if not isinstance(results, list):
            continue
        kept = [r for r in results if not (isinstance(r, dict) and r.get("suppressions"))]
        removed += len(results) - len(kept)
        run["results"] = kept

    with open(dst, "w", encoding="utf-8") as handle:
        json.dump(document, handle)

    print(f"{src}: {removed} achado(s) suprimido(s) removido(s) do upload")
    return removed


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        print(__doc__, file=sys.stderr)
        return 2
    strip_suppressed(argv[1], argv[2])
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
