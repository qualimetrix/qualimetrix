#!/usr/bin/env python3
"""Freeze the carrier ranges the promise ledger cites, and prove the freeze covers all of them.

The ledger (docs/internal/plans/promise-effect/measurement/promise-ledger.tsv) states what the
product promises, read from external carriers: website pages and contract docblocks. Its companion
promise-ledger-frozen-ranges.tsv pins the exact line ranges those citations point at, so that a
later edit to a carrier cannot change a promise silently.

The companion file used to be produced by a throwaway script that was not kept, and the run that
produced it missed one cited range. A missing range is the worst failure this file can have: the
one carrier nobody watched is exactly the one that was rewritten. So this script asserts coverage
in both directions — every cited range is frozen, and every frozen range is still cited — in
addition to comparing the hashes.

    python3 scripts/promise-ledger-freeze.py            # rewrite the frozen file from the tree
    python3 scripts/promise-ledger-freeze.py --check    # assert it matches the tree; 0 ok, 1 drift

Exit codes: 0 agreed, 1 a range drifted or coverage is incomplete, 2 the input cannot be read.
"""

from __future__ import annotations

import hashlib
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MEASUREMENT = ROOT / "docs/internal/plans/promise-effect/measurement"
LEDGER = MEASUREMENT / "promise-ledger.tsv"
FROZEN = MEASUREMENT / "promise-ledger-frozen-ranges.tsv"

# A citation is `<path>:<line>` or `<path>:<line>-<line>`. Only paths inside the two trees the
# ledger declares as carriers are citations; anything else in a note is prose.
CITATION = re.compile(r"((?:website/docs|src)/[A-Za-z0-9_./-]+\.(?:md|php)):(\d+)(?:-(\d+))?")

COLUMNS = "file\tfirst_line\tlast_line\tsha256_16\towner_package\tfirst_line_text"

HEADER = """\
# ЗАМОРОЖЕННЫЕ ДИАПАЗОНЫ НОСИТЕЛЕЙ — спутник promise-ledger.tsv (этап 01, пакет L; перевывод L2).
# Дерево {tree}. Дата: {date}.
#
# ЗАЧЕМ: единица непересечения с пакетом P1 — не файл, а ДИАПАЗОН СТРОК (01-promise.md §«Как
# проверяется непересечение, не убивая носителей»). Здесь перечислен каждый диапазон, который
# процитирован хотя бы одной строкой реестра, с sha256 его текста.
#
# ЧЕМ ПОЛУЧЕНО: scripts/promise-ledger-freeze.py — собирает все ссылки вида `путь:строка[-строка]`
#   из тела promise-ledger.tsv (включая шапку и колонку note), читает ровно эти строки файла на
#   текущем дереве и считает sha256 (первые 16 знаков) от строк, склеенных через \\n БЕЗ
#   завершающего перевода строки. Колонка owner_package выведена из пути: src/** — P1, website/** — P6.
#
# ПОЧЕМУ СКРИПТ ВООБЩЕ ЕСТЬ. Прошлый снимок делал одноразовый скрипт, который не сохранили, и его
#   прогон пропустил ровно один процитированный диапазон — configuration.md:749-750, тот самый,
#   который пакет документации потом и переписал. Сторож, не видящий носителя, молчит именно там,
#   где нужен. Поэтому `--check` проверяет ПОКРЫТИЕ В ОБЕ СТОРОНЫ (каждая цитата заморожена, каждая
#   заморозка ещё цитируется), а не только совпадение sha.
#
# ЧЕГО ЭТОТ СПОСОБ НЕ ВИДИТ: он проверяет ТЕКСТ диапазона, а не его смысл. Правка соседней
#   строки, меняющая смысл абзаца, sha диапазона не тронет; и наоборот, сдвиг нумерации
#   (вставка строки выше) покрасит диапазон, ничего в нём не изменив, — это осознанно
#   консервативная сторона.
#
# ПРОВЕРКА DoD-2, в доступной пакету части. Продуктовый набор P1 (03-cure.md) — семь поимённых
#   файлов плюс классы опций. Из замороженных диапазонов В НАБОР P1 ПОПАДАЮТ РОВНО ТРИ:
#     src/Analysis/Finding/Contract/Rule/RuleOptionKeySet.php:23-27   (три состояния ключа;
#         носитель законности адресного ответа класса — C17)
#     src/Analysis/Finding/Contract/Rule/RuleOptionKeySet.php:28-34   (канонические написания)
#     src/Analysis/Finding/Contract/Rule/LevelOptionsInterface.php:33-39
#   Все три — докблоки, все три вне сигнатур и тел. На дереве {tree_short} P1 изменил
#   RuleOptionKeySet.php, но НЕ эти строки: все три sha совпали с прежним снимком побайтно.
#   Остальные лежат в website/docs/**, который принадлежит P6, а не P1.
#   Ни один класс опций (динамическая половина набора P1) носителем не является: реестр их не читал.
#
# ОЖИДАЕМЫЙ СЛУЧАЙ, А НЕ ПРОВАЛ (03-cure.md §P1.7): пакет меняет контракт или страницу, и носитель
#   после него либо лжёт, либо правится. Порядок — возврат к оркестратору, перевывод затронутых
#   строк реестра, обновление этого файла. Провал — МОЛЧАЛИВАЯ правка.
"""


def sha16(lines: list[str]) -> str:
    return hashlib.sha256("\n".join(lines).encode("utf-8")).hexdigest()[:16]


def owner(path: str) -> str:
    return "P1 (03-cure.md ownership table)" if path.startswith("src/") else "P6 (website)"


def collect() -> list[tuple[str, int, int, str, str, str]]:
    """Every range cited anywhere in the ledger body, hashed against the working tree."""
    text = LEDGER.read_text(encoding="utf-8")
    cited: set[tuple[str, int, int]] = set()
    for path, first, last in CITATION.findall(text):
        cited.add((path, int(first), int(last or first)))

    rows = []
    cache: dict[str, list[str]] = {}
    for path, first, last in sorted(cited):
        target = ROOT / path
        if not target.exists():
            raise SystemExit(f"cited carrier does not exist: {path}")
        if path not in cache:
            cache[path] = target.read_text(encoding="utf-8").split("\n")
        body = cache[path]
        if last > len(body):
            raise SystemExit(f"cited range runs past the end of {path}: {first}-{last}")
        window = body[first - 1 : last]
        # first_line_text is a human signpost only; stripped and clipped to 70 characters,
        # the convention the first snapshot used. The sha is what actually binds.
        rows.append((path, first, last, sha16(window), owner(path), window[0].strip()[:70]))
    return rows


def render(rows) -> str:
    tree = subprocess.run(
        ["git", "-C", str(ROOT), "rev-parse", "HEAD"],
        capture_output=True, text=True, check=True,
    ).stdout.strip()
    date = subprocess.run(
        ["git", "-C", str(ROOT), "log", "-1", "--format=%cs"],
        capture_output=True, text=True, check=True,
    ).stdout.strip()
    head = HEADER.format(tree=tree, tree_short=tree[:8], date=date)
    body = "\n".join(
        "\t".join([f, str(a), str(b), s, o, t]) for f, a, b, s, o, t in rows
    )
    return head + COLUMNS + "\n" + body + "\n"


def parse_frozen() -> dict[tuple[str, int, int], str]:
    out = {}
    for line in FROZEN.read_text(encoding="utf-8").split("\n"):
        if not line or line.startswith("#") or line.startswith("file\t"):
            continue
        f = line.split("\t")
        out[(f[0], int(f[1]), int(f[2]))] = f[3]
    return out


def main() -> int:
    if not LEDGER.exists():
        print(f"cannot read {LEDGER}", file=sys.stderr)
        return 2
    rows = collect()
    if "--check" not in sys.argv:
        FROZEN.write_text(render(rows), encoding="utf-8")
        print(f"wrote {FROZEN.relative_to(ROOT)}: {len(rows)} ranges")
        return 0

    frozen = parse_frozen()
    cited = {(f, a, b): s for f, a, b, s, _, _ in rows}
    missing = sorted(set(cited) - set(frozen))
    stale = sorted(set(frozen) - set(cited))
    drifted = sorted(k for k in set(cited) & set(frozen) if cited[k] != frozen[k])

    for k in missing:
        print(f"NOT FROZEN (cited by the ledger, absent here): {k[0]}:{k[1]}-{k[2]}")
    for k in stale:
        print(f"STALE (frozen here, no longer cited): {k[0]}:{k[1]}-{k[2]}")
    for k in drifted:
        print(f"DRIFTED (text changed): {k[0]}:{k[1]}-{k[2]} {frozen[k]} -> {cited[k]}")

    if missing or stale or drifted:
        print(f"\n{len(cited)} cited, {len(frozen)} frozen: the freeze does not cover the ledger.")
        return 1
    print(f"{len(cited)} cited ranges, all frozen and unchanged.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
