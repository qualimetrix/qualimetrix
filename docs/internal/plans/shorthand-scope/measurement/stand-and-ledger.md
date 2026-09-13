# Перемер стенда promise-effect и леджера — ФАКТ

**Сетка: 122 дефекта на 7218 ячейках — A 4, B 81, C 0, D 37, E 0. Пол: 27 строк = 1 стоящая + 21 вылеченная + 4 `pending:` + 1 `withdrawn`. Контроли: 42 кейса, 0 провалов. Дерево чистое (`git status --short` пуст).**

Дерево: репозиторий проекта, ветка `x24-shorthand-scope`, HEAD `c755fe00`. Ни один файл репозитория не правился.

---

## 1. Сетка — перемерена

Прогон: `composer promise-effect` -> **exit 1** (~200 с). Exit 1 — «красный исход на БЛОКИРУЮЩЕЙ оси», а не сбой (сбой был бы 2 или 3).

**Важно, и это идёт вразрез с ожидаемым: ось B НЕ блокирующая.** `promise-effect/run-declaration.tsv` объявляет
`axes = A,B,C,D,E`, `blocking-axes = **A,C,D,E**`, `before-commit = 02a6ca66`. То есть exit 1 дают ось A (4 дефекта) и ось D (37),
а 81 дефект оси B прогон НЕ краснит вовсе. Ось, вокруг которой крутится весь заход, сегодня не блокирует.

    composer promise-effect > /tmp/pe.out 2> /tmp/pe.err; echo "EXIT=$?"

Свод из stdout прогона:

| ось | вердикты                                            | дефектов |
| --- | --------------------------------------------------- | -------- |
| A   | NOT OBSERVABLE 1697, OK 793, REFUSES 2790           | **4**    |
| B   | COEXISTENCE_OK 384, MISCOMPOSED 81                  | **81**   |
| C   | COMPOSED_AS_PROMISED 18, NOT OBSERVABLE 3           | **0**    |
| D   | COLLAPSED 5, NOT OBSERVABLE 139, OK 47, REFUSES 353 | **37**   |
| E   | NOT OBSERVABLE 14, PRESENCE_NEUTRAL 894             | **0**    |
| —   | всего 7218 ячеек, NOT OBSERVABLE 1853 (25.7 %)      | **122**  |

Пересчёт по самому артефакту (независимо от печати стенда; `defect` — отдельная колонка, а не метка вердикта):

    awk -F'\t' 'NR>2{c[$1]++; if($6=="yes") d[$1]++} END{for(a in c) printf "axis %s: cells=%d defects=%d\n",a,c[a],d[a]+0}' \
      docs/internal/generated/promise-effect/verdicts.tsv | sort

-> `A 5280/4, B 465/81, C 21/0, D 544/37, E 908/0; TOTAL 7218/122`. Совпадает с печатью.

Метки дефектных ячеек: A — 4 `REFUSES`; B — 81 `MISCOMPOSED`; D — 5 `COLLAPSED` + 32 `REFUSES`.

**Расхождение с посылкой: нет.** Посылка «122 — A 4, B 81, C 0, D 37, E 0» подтверждена ячейка в ячейку.

Дерево после прогона: `git status --short` пуст — стенд переписал `docs/internal/generated/promise-effect/verdicts.tsv` и файл штампа идентичным содержимым, откатывать нечего (`git diff --stat` тоже пуст).

---

## 2. Пол дефектов — `promise-effect/floor.tsv`

    awk -F'\t' '!/^#/ && !/^[ \t]*$/ && $1!="row" {
      if ($4 ~ /^pending:/) p++; else if ($4!="") c++; else if ($5!="") w++; else s++; t++
    } END{printf "total=%d standing=%d cured=%d pending=%d withdrawn=%d\n",t,s,c,p,w}' promise-effect/floor.tsv

-> `total=27 standing=1 cured=21 pending=4 withdrawn=1`

Печать стенда говорит то же: `defect floor  1 row(s) still defective, 21 cured as declared, 4 pending`.

**Расхождение с посылкой: посылка неполна, а не неверна.** «1 стоящая, 21 вылеченная, 4 pending» верно, но умалчивает **27-ю строку, единственную `withdrawn`**: `form|yaml|computed_metrics.<name>.enabled|null`. `withdrawn` — утверждение о СТЕНДЕ, не о продукте; `Floor` держит колонки `cure`/`withdrawn` взаимоисключающими.

Стоящая строка (одна):
`pair|complexity.ccn|class:|threshold|same-source|3-shorthand-vs-level-block`, ожидаемый вердикт `MISCOMPOSED`, источник «M11 position 66: a top-level shorthand discards the written `class` block».

### Четыре строки `pending:`

Все четыре — `composition|composition-triple|complexity.ccn|callable@warning|…|optionsObject`, вердикт `LOST_SIBLING`, различаются только тройкой слоёв:

| #   | адрес строки                                      |
| --- | ------------------------------------------------- |
| 1   | `…|preset#1/preset#2/cli-bucket|T2|optionsObject` |
| 2   | `…|preset#1/preset#2/preset#3|T3|optionsObject`   |
| 3   | `…|preset#1/preset#2/qmx.yaml|T1|optionsObject`   |
| 4   | `…|preset#1/qmx.yaml/cli-bucket|T4|optionsObject` |

Текст после `pending: ` у всех четырёх одинаков: «X20 stage 2 unfolds the threshold shorthand in every layer before merging (`RuleOptionThresholdShorthand::unfold`), so the layer that only rewrote half the band no longer loses the other half to the constructor default».

Смысл сентинела (`Floor.php:75`, `:91-99`, `:218-232`): «ещё дефект на замороженной половине, уже не дефект на живой сетке».

**Что требуется для конвертации в `062cb5c3`.** Самостоятельной конвертации НЕ существует, и это прямо написано в шапке `floor.tsv`: «Re-taking the snapshot is NOT the fix» и «converting them to a plain `cure` is that retake's own first step». Пока пересъёмка замороженной половины не понадобилась по СВОЕЙ причине (02 §10 требует `--reason`), четыре строки честны ровно в том виде, в каком стоят, и трогать их нечем: простой `cure` значит одно и то же на обеих половинах, а на замороженной эти строки всё ещё дефект.

Когда такая пересъёмка будет обоснована независимо, конвертация — её первый шаг, и порядок принудительный:

1. В `promise-effect/floor.tsv` заменить в колонке `cure` префикс `pending: ` на коммит, т.е. привести каждую из четырёх строк к форме
   `062cb5c3 (A layer's value survives the layers above it): X20 stage 2 unfolds the threshold shorthand…`
   (та же форма «хеш (субъект): эффект», что у 21 вылеченной строки; `Floor` текст `cure` не парсит, кроме префикса `pending: ` — это документация, а не проверяемый факт).
2. Затем сама пересъёмка: `composer promise-effect -- --freeze-before --reason='…'`.
   Обратный порядок невозможен: `--freeze-before` ОТКАЗЫВАЕТСЯ работать, пока стоит хоть одна `pending` (`promise-effect.php:453-460`).

**Команды не исполнял. Пересъёмка сама по себе заданием запрещена, и по шапке floor.tsv она и не является лечением.**

Побочный факт, опровергающий общее предостережение шапки `floor.tsv` (что хеши в `cure` не являются предками main из-за squash-мержа): для `062cb5c3` это НЕ так.

    git log -1 --oneline 062cb5c3        # 062cb5c3 A layer's value survives the layers above it (#65)
    git merge-base --is-ancestor 062cb5c3 origin/main && echo ancestor

-> `062cb5c3` — предок и HEAD, и `origin/main`. Для этих четырёх строк ссылка будет проверяемой, в отличие от трёх старых (`7fe879f6`, `b9fd87d3`, `f20bd708`).

---

## 3. Контроли

    composer promise-effect:controls > /tmp/pec.out 2>&1; echo "EXIT=$?"

-> **exit 0**, последняя строка: `Ran 42 control cases, 0 failures.`

**Расхождения с посылкой нет.** Дерево после контролей чистое.

---

## 4. Леджер — `docs/internal/plans/promise-effect/measurement/promise-ledger.tsv`

Файл полиморфный: колонка 1 — `kind`, колонки 2-6 значат разное на разных kind. Колонка 6 — `equivalences` для `form`, **`coexistence` для `pair`**, `promised_winner` для `composition-path`, `promised_outcome` для `composition-bucket`, `promised_survival` для `composition-triple`. Всего 1322 строки данных: `form` 728, `pair` 573, `deferred` 7, `composition-path` 7, `composition-triple` 4, `composition-bucket` 3.

`kind` пары лежит НЕ в отдельной колонке, а внутри примечания (колонка 9) как `kind=…;`. Мой разбор регуляркой совпадает с продуктовым `Ledger::pairKind()` (`Ledger.php:376-386`, `/\bkind=([^;|]+)/`). Второй свидетель: `grep -c '3-shorthand-vs-level-block' …/key-pairs.tsv` = 18 (18 пар x 2 координаты = 36 строк, как предписывает `promise-effect/pair-kind-scope.tsv`).

### 4.1 Строки `kind=3-shorthand-vs-level-block` — 36, поимённо

    grep -v '^#' docs/internal/plans/promise-effect/measurement/promise-ledger.tsv | tail -n +2 | awk -F'\t' '
     {k=""; if(match($9,/kind=[^;]*/)) k=substr($9,RSTART+5,RLENGTH-5);
      if(k=="3-shorthand-vs-level-block") printf "%s\t%s\t%s\t%s\t%s\t%s\n",$2,$3,$4,$5,($6==""?"<EMPTY>":$6),$8}'

Свод: **36 = 18 `same-source` (7 `refuse` + 11 `compose`, все `DECIDED`) + 18 `cross-source` (пустая `coexistence`, все `DEFERRED`)**. Путь у всех `path=(top)|<level>`.

`same-source`, 18:

| #   | правило              | key_a         | key_b         | coexistence |
| --- | -------------------- | ------------- | ------------- | ----------- |
| 1   | complexity.ccn       | `callable:`   | `threshold`   | **refuse**  |
| 2   | complexity.ccn       | `class:`      | `threshold`   | compose     |
| 3   | complexity.cognitive | `callable:`   | `threshold`   | **refuse**  |
| 4   | complexity.cognitive | `class:`      | `threshold`   | compose     |
| 5   | complexity.npath     | `callable:`   | `threshold`   | **refuse**  |
| 6   | complexity.npath     | `class:`      | `threshold`   | compose     |
| 7   | coupling.cbo         | `class:`      | `error`       | compose     |
| 8   | coupling.cbo         | `class:`      | `threshold`   | **refuse**  |
| 9   | coupling.cbo         | `class:`      | `warning`     | compose     |
| 10  | coupling.cbo         | `error`       | `namespace:`  | compose     |
| 11  | coupling.cbo         | `namespace:`  | `threshold`   | **refuse**  |
| 12  | coupling.cbo         | `namespace:`  | `warning`     | compose     |
| 13  | coupling.instability | `class:`      | `max-error`   | compose     |
| 14  | coupling.instability | `class:`      | `max-warning` | compose     |
| 15  | coupling.instability | `class:`      | `threshold`   | **refuse**  |
| 16  | coupling.instability | `max-error`   | `namespace:`  | compose     |
| 17  | coupling.instability | `max-warning` | `namespace:`  | compose     |
| 18  | coupling.instability | `namespace:`  | `threshold`   | **refuse**  |

`cross-source`, 18 — те же 18 пар ключей под второй координатой, у всех `coexistence` пуста и статус `DEFERRED` (пустая `coexistence` разрешена Ledger-ом ТОЛЬКО на `DEFERRED`).

**Утверждение предыдущего ревью подтверждено счётом полностью: 7 / 11 / 18-DEFERRED.**

Дополнительно: **все 18 `same-source` строк этого kind — дефекты на живой сетке**, и разбиение ровно по `coexistence`: 7 строк с `refuse` -> «composed where the ledger promised a refusal: accepted»; 11 строк с `compose` -> «the effect of A (или B) is absent when both are written».

### 4.2 Пять правил `complexity.ccn / cognitive / npath`, `coupling.cbo / instability`

Строк, где колонка 2 — одно из пяти правил: **314** = 307 `pair` + 3 `composition-bucket` (`promised_outcome=refuse`) + 4 `composition-triple` (`promised_survival=survives`). Перечисление полное: у 7 строк `composition-path` колонка 2 несёт путь, а не правило, и единственное её значение — `rules.size.method-count.warning`, ни одного из пяти правил там нет.

Свод по `coexistence` по 307 pair-строкам:

| правило              | compose | refuse | пусто  | итого   |
| -------------------- | ------- | ------ | ------ | ------- |
| complexity.ccn       | 29      | 7      | 8      | 44      |
| complexity.cognitive | 29      | 7      | 8      | 44      |
| complexity.npath     | 29      | 7      | 8      | 44      |
| coupling.cbo         | 60      | 12     | 15     | 87      |
| coupling.instability | 61      | 12     | 15     | 88      |
| **всего**            | **208** | **45** | **54** | **307** |

Сверх того у этих пяти правил **175 `form`-строк** (`yaml` 85, `rule-opt` 70, `cli-alias` 20) — у них колонка 6 несёт `equivalences`, а не `coexistence`, поэтому в свод не входят.

### 4.3 Пара «верхний `enabled` x `class.enabled`»

В леджере это **пять строк** (по одной на правило), а не одна. Все пять идентичны: `scope=same-source`, **`coexistence=compose`**, `status=DECIDED`, `carrier` ПУСТ, `kind=2-same-name-top-vs-level`, `path=(top)|class`.

Примечание (одинаковое у всех пяти):
«the carrier (`website/docs/getting-started/configuration.md:185-217`) speaks only about the bare `threshold` shorthand and says nothing about this key at either depth. **ADR 0052 row 3**: nothing is missed when a top-level key and its namesake inside a slot are both written -> both apply at their own depth».

То есть `compose` здесь — решение раунда по ADR 0052 при молчащем носителе (`carrier` пуст именно поэтому), а не показание документации.

Живая сетка по этим пяти плюс пяти сестринским (`callable.enabled` / `namespace.enabled`): **девять из десяти `COEXISTENCE_OK`, одна — дефект**: `pair|complexity.npath|class.enabled|enabled|same-source|2-same-name-top-vs-level` -> `MISCOMPOSED`, «the effect of A is absent when both are written». Эта одна ячейка объясняет вторую устарелость `axis-b-mechanisms.tsv` — см. §5.

### 4.4 Общий свод по леджеру

- **По `pair`-строкам (573): compose 380, refuse 85, пусто 108.** <- посылка подтверждена точно.
- По ВСЕМУ файлу колонка 6: `compose` 380, `refuse` **88**, пусто **271**, плюс `high` 7, `survives` 4 и 572 строки текста-носителя (`equivalences` у form).
  - 88 refuse = 85 pair + 3 composition-bucket;
  - 271 пусто = 108 pair + 156 form + 7 deferred.

**Уточнение:** «380 / 85 / 108» верно ровно для популяции `pair`. Прочитанное как «по всему леджеру» — неверно по двум числам из трёх.

---

## 5. Разбивка оси B — `docs/internal/plans/layer-value-survival/measurement/axis-b-mechanisms.tsv`

    awk -F'\t' 'NR>1{print $1"\t"$2; s+=$2} END{print "SUM\t"s}' \
      docs/internal/plans/layer-value-survival/measurement/axis-b-mechanisms.tsv

-> `M1 20, M2 25, M3 36, M4 1, SUM 82`.

Арифметика посылки верна: **20 + 25 + 36 = 81**. Но в файле **четыре** механизма, и его собственная сумма — **82**. Посылка молча роняет M4.

- **M1 `enabled-disable` (20 ячеек, kind `6-gate`)** — ветка «явный верхний `enabled: false` гасит все уровни» в `fromArray()` возвращает объект с выключенными уровнями ДО чтения соседнего/вложенного ключа, поэтому эффект соседа стирается; ветка скопирована в 5 классов опций.
- **M2 `shorthand-disables-other-level` (25, только complexity, kinds 2/3/4)** — верхний плоский shorthand собирает `callable` из верхних threshold/warning/error и жёстко выключает `class`, выбрасывая написанный рядом блок.
- **M3 `shorthand-applies-uniformly` (36, только coupling, kinds 2/3/4)** — тот же shorthand применяется ОДНОРОДНО к обоим уровням (`class` и `namespace`, ни один не выключается), а вложенный блок рядом всё равно выбрасывается целиком.
- **M4 `removed-severity-refusal` (1, `architecture.layer-violation`, `enabled` x `severity`, kind `6-gate`)** — сам файл помечает её «NOT VERIFIED»: вероятно артефакт выбранной величины эффекта (невалидная строка enum), а не дефект композиции.

**Живая проверка счётом.** Дефекты оси B по kind: `6-gate` 20, `2-same-name-top-vs-level` 11, `3-shorthand-vs-level-block` 18, `4-cross-level` 32 -> 81. По правилам: ccn 11, cognitive 11, npath 12, cbo 24, instability 23 -> 81; это ровно M1 (9 complexity + 11 coupling) + M2 (25 complexity) + M3 (36 coupling).

    awk -F'\t' 'NR>2 && $1=="B" && $6=="yes"{n=split($2,a,"|"); print a[n]}' \
      docs/internal/generated/promise-effect/verdicts.tsv | sort | uniq -c

**Файл устарел в ДВУХ местах, а не в одном.**

*Первое — M4 считает ячейку, которой больше нет.* Её строка сегодня читается
`pair|architecture.layer-violation|enabled|severity|same-source|6-gate -> COEXISTENCE_OK, defect=no, «both effects present; A wrote the alternate magnitude»`,
то есть механизм M4 больше не наблюдается (величина эффекта для `severity` перевыбрана; `promise-effect/effect-magnitudes.tsv` датирован тем же днём, что и floor). Живая B = 81; заявленные в файле 82 включают ячейку, которой больше нет. Посылка «B 81» верна для продукта, но сходится с файлом лишь потому, что обе стороны роняют M4 по разным причинам.

*Второе — M2 поглощает ячейку, которую сам не описывает.* Дефекты kind `2-same-name-top-vs-level` по правилам: ccn **2**, cognitive **2**, npath **3**, cbo 2, instability 2 (итого 11). M1 даёт по 3 ячейки на каждое complexity-правило, поэтому на долю M2 остаётся ccn 8, cognitive 8, **npath 9** — ровно те числа, что M2 у себя и написал. Но девятая ячейка npath — это `pair|complexity.npath|class.enabled|enabled` из §4.3, а текст M2 говорит исключительно про ветку плоского верхнего `threshold`-shorthand и про `enabled` не говорит НИЧЕГО. То есть M2 корректен по счёту и неверен по содержанию на одну ячейку: она попала в его число, потому что число выводили вычитанием, а не перечислением. Ни продукта, ни леджера это не касается — это дефект самого разбора.

Команда:

    awk -F'\t' 'NR>2 && $1=="B" && $6=="yes" && $2 ~ /2-same-name-top-vs-level$/{split($2,a,"|"); print a[2]}' \
      docs/internal/generated/promise-effect/verdicts.tsv | sort | uniq -c

---

## 6. Словарь, закрытый в PR #68 — цена нового значения

Оба словаря — приватные константы в `scripts/promise-effect/Ledger.php`:

| словарь                                                                            | файл:строка                                                    | значения                                                                                       |
| ---------------------------------------------------------------------------------- | -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `promised_survival` / `promised_winner` / `promised_outcome` (все `composition-*`) | `scripts/promise-effect/Ledger.php:282` (`PROMISE_VALUES`)     | `high`, `low`, `refuse`, `lost`, `survives`                                                    |
| `coexistence` (`pair`)                                                             | `scripts/promise-effect/Ledger.php:303` (`COEXISTENCE_VALUES`) | `compose`, `refuse` + префиксная форма `one-wins:<key>` + пустое значение ТОЛЬКО на `DEFERRED` |

Присуждающие ветви (`scripts/promise-effect/Classifier.php`):

| значение                       | ветвь                                                                                      |
| ------------------------------ | ------------------------------------------------------------------------------------------ |
| `coexistence` `one-wins:<key>` | `Classifier::pair()`, `:284-298`                                                           |
| `coexistence` `refuse`         | `:301-305` — ждёт `REFUSED_FRAMED`, иначе `MISCOMPOSED`                                    |
| `coexistence` `compose`        | провал в хвост `:307+` — единственное значение без проверки по имени, «всё остальное»      |
| `promised_*` `refuse`          | `:436` — отказ = `COMPOSED_AS_PROMISED`                                                    |
| `promised_*` `lost`            | `:455` — `LOST_SIBLING` без флага дефекта                                                  |
| `promised_*` `survives`        | `:469-482` — требует наблюдённый `middle`, иначе `FRANKENSTEIN`; сверка через `survives()` |
| `promised_*` `high` / `low`    | `:491-499` — «победила обещанная сторона» vs `MISLAYERED`                                  |
| (`unpromised`)                 | синтезируется стендом на `:433`; в файле леджера запрещено намеренно                       |

**Цена нового значения** = константа (`Ledger.php:282` или `:303`) + именованная ветвь в `Classifier::pair()` / `Classifier::composition()` + кейс в `tests/Unit/PromiseEffect/LedgerVocabularyTest.php`.

**Подтверждено: новое значение `coexistence` без ветви классификатора отвергается ПРИ ЗАГРУЗКЕ.** Механизм — `Ledger::assertKnownCoexistence()` (`Ledger.php:318-344`, вызов из разбора pair-строки на `:239`), бросает `LedgerError`: «…promises coexistence "X", which the classifier does not recognise and **would read as "compose"**…». Симметрично для composition: `Ledger::assertKnownPromise()` (`:305-316`, вызов `:194`) — «…which no classifier branch awards». Тесты-сторожа: `tests/Unit/PromiseEffect/LedgerVocabularyTest.php:60`, `:72`, `:84`, `:96`.

**Дыра в этой закрытости — и она хуже, чем просто «цена».** Форма `one-wins:` принимается по ПРЕФИКСУ, суффикс не проверяется (`Ledger.php:320-322`). Текст ошибки самого Ledger обещает форму `one-wins:<key>` — то есть ИМЯ КЛЮЧА. Классификатор же сравнивает суффикс с литералом `'a'` и всё прочее читает как «победил B» (`Classifier.php:285-286`). Словарь и классификатор расходятся: любая запись `one-wins:<реальное имя ключа>` молча означает «победил B», независимо от того, какой ключ назван.

Тест `LedgerVocabularyTest.php:96-105` эту дыру не закрывает и мимо неё проходит: он подставляет в леджер именно `one-wins:$2`, где `$2` — настоящее имя `key_a`, и проверяет ТОЛЬКО что загрузчик строку сохранил (`assertCount(1, $winners)`). Что классификатор прочитает её как победу противоположной стороны, он не проверяет.

Практических последствий сегодня нет: строк с `one-wins:` в леджере **0** — но цена добавления первой такой строки выше, чем «константа + ветвь + тест»: сначала придётся решить, в каких терминах записывается победитель.

---

## Посылки захода, которые измерение ОПРОВЕРГЛО

1. **«Пол: 1 стоящая, 21 вылеченная, 4 pending»** — неполно. Строк 27, и 27-я — `withdrawn` (`form|yaml|computed_metrics.<name>.enabled|null`), отдельная диспозиция, взаимоисключающая с `cure`.
2. **«Общая сводка по всему леджеру: 380 / 85 / 108»** — верно только для популяции `pair` (573 строки). По всему файлу колонка 6 даёт 380 / **88** / **271**.
3. **«81 = 20 (M1) + 25 (M2) + 36 (M3)»** — арифметика верна, но в `axis-b-mechanisms.tsv` четыре механизма и сумма 82; файл устарел на ячейку M4, которая на живой сетке уже `COEXISTENCE_OK`.
4. **(частично) «пара верхний `enabled` x `class.enabled`»** — в единственном числе её нет: пять строк, по одной на правило; и на сетке они не однородны (девять из десяти namesake-ячеек зелены, `complexity.npath|class.enabled|enabled` — дефект).

5. **(не названная, но подразумеваемая) «ось B блокирует прогон»** — нет. `run-declaration.tsv`: `blocking-axes = A,C,D,E`. Exit 1 этого прогона даёт A (4) и D (37); 81 дефект оси B прогон не краснит.

### Посылки, ПОДТВЕРЖДЁННЫЕ точно

- сетка «122 — A 4, B 81, C 0, D 37, E 0» — ячейка в ячейку;
- контроли «42 кейса, 0 провалов»;
- «18 same-source строк kind-3: СЕМЬ `refuse`, одиннадцать `compose`; cross-source половина — пустая `coexistence` + DEFERRED» — 7/11/18.

---

## Чистота дерева

`git status --short` после обоих прогонов — **пуст**, `git diff --stat` — пуст. Откат не понадобился: `composer promise-effect` перезаписал `docs/internal/generated/promise-effect/verdicts.tsv` и файл штампа байт в байт тем же содержимым (сетка на этом дереве воспроизводима), а контроли убрали за собой посаженные поломки. Режимы записи (`--freeze-before`, `promise-ledger:freeze`, пересъёмка) не запускались.

## Артефакты в каталоге

`promise-effect.stdout.txt`, `promise-effect.exit.txt`, `controls.stdout.txt`, `controls.exit.txt`,
`grid-axis-totals.txt`, `grid-defect-labels.txt`, `axis-b-defect-cells.tsv`, `axis-b-kind3.tsv`,
`five-rules-all.tsv`, `floor-standing.tsv`, `floor-pending.tsv`, `floor-withdrawn.tsv`,
`git-status-after-stand.txt`, `git-diff-stat-after-stand.txt`.
