# X19 / R3 — "порог замещает уровень молча" (ось B, complexity/coupling)

Дерево: `qualimetrix` @ `02a6ca66` (main). Все команды исполнялись из корня репозитория,
если не указано иное. Файлы задания:
- `docs/internal/generated/promise-effect/verdicts.tsv` (6290 строк, оси A/B/D)
- `docs/internal/plans/promise-effect/measurement/promise-ledger.tsv` (1308 строк данных + большая шапка)
- `docs/internal/plans/promise-effect/measurement/key-pairs.tsv` (465 строк, знаменатель оси B same-source)
- `docs/internal/plans/promise-effect/measurement/legitimate-configs.tsv` (31 документ)

---

## 1. Пересчёт строк реестра без выразимого coexistence

**Задание утверждало: 66 строк = 6 kind=pair + 60 coupling.\*.**

Число 159/54/38/28/19/11/9 (пункт 2) подтвердилось МАШИННО и ТОЧНО. Число 66 — НЕТ:
оно воспроизводится только при смешении двух разных единиц счёта внутри одного
и того же заявления, и ни при одной последовательной единице счёта не даёт 66.

| Единица счёта                                                                                                                                      | complexity (class-семья, ccn+cognitive+npath)                                             | coupling (cbo+instability, оба слота)                   | Итог   |
| -------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- | ------------------------------------------------------- | ------ |
| **физическая строка TSV** (каждая физическая строка = 1)                                                                                           | 9 (3 правила × (2 коллизионных копии `class.threshold×threshold` + 1 `class:×threshold`)) | 60                                                      | **69** |
| **логическая пара** (коллизионные копии `2-same-name-top-vs-level`/`4-cross-level` схлопнуты в одну, как это явно сделано в шапке реестра для «6») | 6 (3 правила × 2 формы: `class.threshold×threshold`, `class:×threshold`)                  | 48 (12 из 60 физических строк — коллизионные дубликаты) | **54** |

Шапка реестра (строки 290-374 файла) сама называет «6» логическими парами (явно:
«по две на правило: `class.threshold x threshold` и `class: x threshold`»), а «60» —
физическими строками того же класса дефекта («60 (coupling.cbo и
coupling.instability × оба слота)», без учёта, что 12 из них — коллизионные
дубликаты одной и той же пары). Это разные единицы счёта внутри одного и того же
абзаца. 66 = 6 + 60 воспроизводится, только если для complexity считать парами,
а для coupling — строками; ни «69» (строки/строки), ни «54» (пары/пары) не дают 66.

Дополнительно: в этой же популяции («сокращение × уровень», same-source) есть ЕЩЁ
15 логических пар (45 физических строк) семьи `callable` (`complexity.{ccn,cognitive,npath}`
× `callable.threshold`, `class.max-warning`, `class.max-error` — header называет это
отдельным «15-строчным» блоком, где C6c и C20 согласны, но словарь тоже не называет
победителя явно). Если считать, что они СТРАДАЮТ ТЕМ ЖЕ дефектом словаря (а по тексту
шапки — да, это тот же класс проблемы, просто отделённый в отдельный абзац), полный
охват дефекта — 15 (пары) / 21 (физ. строки) для complexity, что при сложении с
coupling даёт 63 (пары) или 81 (строки) — тоже не 66.

Собственная арифметика шапки реестра тоже не сходится сама с собой: строка 301
обещает «ЕЩЁ 84 СТРОКИ» и перечисляет 15+60+18 = 93 ≠ 84 (строки 301-310). Это
самостоятельная находка: шапка реестра, на которую опирается задание, не сходится
арифметически сама с собой ни в объявленном (84 vs факт. сумма 93), ни в способе,
которым посчитаны «6» против «60» (пары vs строки).

**Вывод пункта 1**: посылка «66 строк» не выдержала проверку. Реальный, машинно
воспроизводимый диапазон — 54 (пары, минимальная консистентная оценка) … 69
(строки, минимальная консистентная оценка) … 63/81 (если включать семью `callable`).
Все нужные строки перечислены поимённо в `ledger-rows-no-coexistence.tsv` (см. рядом).

---

## 2. Разбивка 159 MISCOMPOSED по видам (ось B, defect=yes)

Источник: `docs/internal/generated/promise-effect/verdicts.tsv`, поле `row` для строк
`axis=B` кодирует `pair|<rule>|<key_a>|<key_b>|<source_scope>|<kind>` — последний
сегмент даёт `kind` напрямую, без обращения к key-pairs.tsv.

```
awk -F'\t' '$1=="B" && $5=="MISCOMPOSED" && $6=="yes"{n=split($2,a,"|"); print a[n]}' \
  docs/internal/generated/promise-effect/verdicts.tsv | sort | uniq -c | sort -rn
```

| kind                       | задание | факт                                                                                               | совпало |
| -------------------------- | ------- | -------------------------------------------------------------------------------------------------- | ------- |
| 4-cross-level              | 54      | **54**                                                                                             | да      |
| 6-gate                     | 38      | **38**                                                                                             | да      |
| 6-subject-filter           | 28      | **28**                                                                                             | да      |
| 2-same-name-top-vs-level   | 19      | **19**                                                                                             | да      |
| 3-shorthand-vs-level-block | 11      | **11**                                                                                             | да      |
| прочее                     | 9       | **9** (6-measurement-input 6, 6-subject-vs-filter 1, 6-precedence-fill-in 1, 6-gate-vs-severity 1) | да      |
| **Итого**                  | 159     | **159**                                                                                            | да      |

Разбивка задания подтвердилась ТОЧНО. Это единственная часть задания, прошедшая
проверку без оговорок.

### Механизм по видам (файл:строка) + КРИТИЧЕСКАЯ ОГОВОРКА

Механизм каждого вида по доминирующему `why` (`docs/internal/generated/promise-effect/verdicts.tsv`, столбец 9):

| kind                       | доминирующий `why` (N строк)                                                            | механизм, файл:строка                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| -------------------------- | --------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 4-cross-level              | «effect of A absent» 38, «effect of B absent» 9, «composed where refusal promised» 7    | Бинарная ветка `fromArray()`: `array_key_exists('threshold', $config)` возвращает РАНЬШЕ, чем читается ключ `class`/`callable`/`namespace` — блок молча теряется. `src/Analysis/Evidence/Complexity/NpathComplexityOptions.php:44-54` (то же в `ComplexityOptions.php:44-53`, `CognitiveComplexityOptions.php:44-53`); для coupling — `src/Analysis/Evidence/Coupling/CboOptions.php:42-76` (тот же паттерн, но применяет сокращение к ОБОИМ уровням, а не выключает один) |
| 2-same-name-top-vs-level   | смешанный: 8 «A absent», 7 «composed where refusal promised», 3 «B absent», 1 «refused» | тот же код, что и 4-cross-level — это тот же механизм, наблюдаемый на паре «одноимённый ключ верхнего уровня vs тот же ключ внутри слота»                                                                                                                                                                                                                                                                                                                                  |
| 3-shorthand-vs-level-block | «refused where composition promised» 11                                                 | верхний бинарный ключ (`error`/`warning`/`threshold`) vs ПУСТОЙ блок уровня (`class:`) — здесь стенд НАМЕРЕННО пишет блок без `threshold` (см. `scripts/promise-effect/Stand.php:807-812`, комментарий «66 wrote `class: {max_warning, max_error}»), поэтому для ЭТОГО вида «refused» ближе к подлинному прочтению C20, а не к артефакту зонда                                                                                                                             |
| 6-gate                     | «refused where composition promised» 29, «B absent» 9                                   | **ПРОВЕРЕНО ЭМПИРИЧЕСКИ: ЭТО АРТЕФАКТ ЗОНДА, НЕ ПРОДУКТА** (см. ниже)                                                                                                                                                                                                                                                                                                                                                                                                      |
| 6-subject-filter           | «refused where composition promised» 28 (все)                                           | **ПРОВЕРЕНО ЭМПИРИЧЕСКИ: ЭТО АРТЕФАКТ ЗОНДА, НЕ ПРОДУКТА** (см. ниже)                                                                                                                                                                                                                                                                                                                                                                                                      |
| прочее (9)                 | «refused where composition promised» 9                                                  | та же генерическая причина, что у 6-gate/6-subject-filter (не проверялось поштучно)                                                                                                                                                                                                                                                                                                                                                                                        |

**Находка, меняющая интерпретацию ~половины из 159 строк.** Генератор пары
(`scripts/promise-effect/Stand.php`, метод `pairValue()`, строки ≈788-816) пишет
значение ключа как СЫРОЕ ЦЕЛОЕ число (`seed`), и только ключ, СТРОГО оканчивающийся
на `enabled`, получает специальную обработку (`bool false`). Любой другой ключ
булевой или строковой формы (`exclude-readonly`, `exclude-tests`,
`exclude-data-classes`, `exclude-promoted-only`, `exclude-exceptions`,
`unused-directive-severity`, `exclude-methods`, `include-namespaces` и т.п.) получает
целое число — и тогда рефьюзит не «пара двух ключей», а C19 (форма значения):
«must be a boolean/string, got a whole number». Подтверждено запуском на двух
КОНКРЕТНЫХ строках из фактической выдачи verdicts.tsv:

```
$ bin/qmx check <fixture> --only-rule=design.god-class \
    --config=<{exclude-readonly: 1, wmc-threshold: 2}>
{"error": "Configuration error: Option \"excludeReadonly\" of rule \"design.god-class\"
  must be a boolean or null, got a whole number.", "exit_code": 3}

$ bin/qmx check <fixture> --only-rule=annotation.directive \
    --config=<{enabled: false, unused-directive-severity: 2}>
{"error": "Configuration error: Option \"unusedDirectiveSeverity\" of rule
  \"annotation.directive\" must be a string or null, got a whole number.",
  "exit_code": 3}
```

Обе пары — буквально те же строки, что в verdicts.tsv:
`design.god-class|exclude-readonly|wmc-threshold` (6-subject-filter) и
`annotation.directive|enabled|unused-directive-severity` (6-gate). Отказ в обоих
случаях — форма значения (C19), а не заявленная семантика вида («gate» / «subject
vs filter»). Сам `Stand.php` уже один раз обжёгся на этом классе артефакта
(комментарий на строке ~807: «a probe refused on its own write would read
MISCOMPOSED for the stand's reason, not the product's») и обошёл его ТОЛЬКО для
пары `threshold` vs `max_warning`/`max_error` внутри одного блока — но не для
булевых/строковых ключей семейства `exclude-*` и `*-severity`, которые составляют
почти весь состав `6-gate` (38) и `6-subject-filter` (28).

**Проверено только 2 из 66 потенциально затронутых строк** — экстраполяция на весь
объём 6-gate+6-subject-filter (66 строк) не подтверждена поштучно, но механизм
(один и тот же код зонда, одна и та же категория ключей) делает её вероятной для
большинства. Это не опровергает число 159 (оно машинно точное), но подрывает
доверие к тому, ЧТО ИМЕННО эти ~66 строк измеряют: не столкновение двух ключей
правила, а форму значения, которую сам зонд пишет неправильно.

---

## 3. Что говорит носитель (EN-половина сайта)

`website/docs/getting-started/configuration.md` на ТЕКУЩЕМ дереве (02a6ca66 — это
именно тот коммит, который последним трогал этот файл, PR #63 «A recognised
configuration key does what its name promised»). Номера строк совпадают с тем,
что цитирует «ПЕРЕВЫВОД-3» реестра (рабочее дерево на момент реестра = текущий HEAD).

- `website/docs/getting-started/configuration.md:185` — «**A bare `threshold` at a
  hierarchical rule's own top level replaces the level blocks, it does not add to
  them.**» + «`complexity.ccn`, `complexity.cognitive` and `complexity.npath` apply
  it to the **callable** level and **switch the class level off**» (строка 187) +
  «`coupling.cbo` and `coupling.instability` apply it uniformly to **both** levels
  at once» (строка ~213-217).
- `website/docs/getting-started/configuration.md:855-880` — «Inside `rules:` that is
  where the equivalence stops» — С ССЫЛКОЙ на раздел выше («selects the threshold
  shorthand described under **Rules** above»), не повторяет утверждение.
- `website/docs/getting-started/configuration.md:882-891` — «`threshold:` and
  `warning:`/`error:` are still two modes» → `ConfigurationRefusal`, «Cannot mix».

Носитель СЕГОДНЯ говорит РОВНО то, что задание утверждает (заявление о НАХОДКАХ,
не о том, чьё значение победило): бланк `threshold` выключает уровень `class` у
complexity-семьи. Пункт 6 подтверждает это прогоном.

---

## 4. Цена отказа («Cannot mix» по образцу)

Существующий образец: `src/Analysis/Finding/Contract/Rule/ThresholdParser.php:72-77`
(бросает `ConfigurationRefusal` с сообщением из `mixedModesMessage()`,
строки 157-168) — но это про `threshold` vs `warning`/`error` В ОДНОМ И ТОМ ЖЕ
источнике на одном уровне, НЕ про `threshold` vs `class:`/`callable:`/`namespace:`
блок. Второй случай (`Cannot mix "threshold" with "warning"/"error"` при `threshold: ~`)
докуметирован на `website/docs/getting-started/configuration.md:887-888`.

**Корпус легитимных конфигураций (31 документ, `legitimate-configs.tsv`)**: НИ ОДИН
не содержит опасного соседства («голый threshold/warning/error/max_warning/max_error
у complexity.{ccn,cognitive,npath} или coupling.{cbo,instability} рядом с блоком
уровня того же правила в том же документе»). Проверено grep'ом всех 28
отслеживаемых `qmx.yaml` + 3 встроенных пресетов по именам этих 5 правил:

```
for f in $(git ls-files '*qmx.yaml' finding-gate/... tests/... input-doors/...); do
  grep -n 'complexity\.\(ccn\|cognitive\|npath\)\|coupling\.\(cbo\|instability\)' "$f"
done
```
→ только 6 файлов вообще упоминают эти правила, и ни в одном нет блока `class:`/
`callable:`/`namespace:` РЯДОМ с голым threshold/warning/error того же правила
(репозиторный `qmx.yaml` использует только `suppress_paths`/`suppress_namespaces`
для этих правил; `tests/.../BaselineV10/cbo/qmx.yaml` использует ТОЛЬКО блочную
форму, без голого ключа — безопасно).

**Собственный `qmx.yaml` репозитория**: безопасен (см. выше).

**Встроенные пресеты** (`src/Analysis/Configuration/Preset/{strict,legacy,ci}.yaml`):
`ci.yaml` вообще не упоминает эти правила; `strict.yaml` и `legacy.yaml` используют
ИСКЛЮЧИТЕЛЬНО блочную форму (`callable:`/`class:`/`namespace:`), без голого
`threshold`/`warning`/`error` — тоже безопасны.

**Цена отказа по корпусу = 0 из 31.** Отказ по этому соседству не сломает НИ ОДИН
известный легитимный документ этого дерева.

**Тесты, закрепляющие текущее молчаливое поведение** (пришлось бы переписать при
переходе на отказ):
- `tests/Analysis/Evidence/Complexity/Unit/ComplexityOptionsTest.php:66`
  `itLetsTheBareThresholdDiscardTheClassBlockAndSilenceTheClassLevel` (единственный
  прямой тест для complexity.ccn)
- `tests/Analysis/Evidence/Coupling/Unit/CboOptionsTest.php:76`
  `itLetsTheFlatThresholdWinOverAPreExistingNestedClassAndNamespaceConfigInTheSameArray`
- `tests/Analysis/Evidence/Coupling/Unit/InstabilityOptionsTest.php:67`
  `itLetsTheFlatThresholdWinOverAPreExistingNestedClassAndNamespaceConfigInTheSameArray`

**Находка (пробел покрытия, не по заданию, но релевантна цене):**
`complexity.cognitive` и `complexity.npath` НЕ имеют аналогичного прямого теста
на «блок рядом с голым threshold отбрасывается» — `NpathComplexityOptionsTest.php`
и `CognitiveComplexityOptionsTest.php` содержат только 2 теста каждый (32 строки,
только `enabled:false`); есть более широкий тест на уровне правила
(`NpathComplexityRuleTest.php:384-397`,
`itNpathComplexityOptionsFromFlatThresholdShorthand` — и аналог в
`CognitiveComplexityRuleTest.php:331`), но ни один из них не пишет `class:` блок
РЯДОМ с `threshold` в одном вызове — то есть регрессионного теста именно на
«блок отброшен, даже если написан» для этих двух правил нет вовсе.

---

## 5. Второй вариант (принимать, но не молчать)

Канал для несмертельного предупреждения В ПРОДУКТЕ УЖЕ ЕСТЬ, но не там, где нужно:
`ArchitectureConfigurationWarning`
(`src/Analysis/Policy/Architecture/Contract/ArchitectureConfigurationWarning.php`)
— список `list<ArchitectureConfigurationWarning>`, собираемый
`AllowValidator`/`WildcardSelfAllowDetector` (`src/Analysis/Policy/Architecture/Configuration/*.php`)
и печатаемый как `Warning: %s` в
`src/Infrastructure/Console/Command/CheckCommand.php:242`
(тем же путём — `$resolvedScope->warnings` — что и предупреждение про
непокрытые autoload-пути, которое я наблюдал в каждом своём прогоне).

**Цена варианта «предупреждение» выше цены «отказ»**: `ThresholdParser::parse()`
и все пять `fromArray()` — ЧИСТЫЕ статические фабрики, возвращающие только
Options-объект, без побочного канала. У `ArchitectureConfigurationWarning`
предупреждение собирается ПО ССЫЛКЕ (`&$warnings`) на уровне, где вызывающий код
уже это ожидает (Architecture-конфигуратор). Чтобы завести предупреждение для
`fromArray()` семейства, пришлось бы менять сигнатуру `fromArray()` (или заводить
отдельный сборщик предупреждений и прокидывать его через все точки вызова вплоть
до `CheckCommand`) — на 5 классов Options, а не на 1 вызов `throw` (как при отказе).
Отказ дешевле реализовать (одна проверка + `throw`, по образцу уже существующего
`ThresholdParser::parse()`), варианту «варнинг» требуется новая инфраструктура
проброса предупреждений через чистые статические фабрики.

---

## 6. Таблица наблюдений — утверждение о НАХОДКАХ (не о том, чьё значение победило)

Фикстура: `fixtures/src/Sample.php` — один метод CCN=14 (подтверждено прогоном
без порогов). Три конфигурации `complexity.ccn`:

| #   | конфигурация                                              | class-level threshold эффективно    | находка на уровне class?                                | вывод                                                                          |
| --- | --------------------------------------------------------- | ----------------------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------ |
| (а) | `threshold: 100` (только сокращение)                      | недостижим — уровень class ВЫКЛЮЧЕН | **НЕТ** (0 находок)                                     | class полностью выключен                                                       |
| (б) | `threshold: 100` + `class: {max_warning: 1}`              | блок `class:` НЕ ЧИТАЕТСЯ           | **НЕТ** (0 находок), идентично (а)                      | блок молча отброшен — не просто «его значение проигрывает», а «класс выключен» |
| (в) | только `class: {max_warning: 1}` (без верхнего threshold) | 1 (из блока)                        | **ДА** (2 находки: callable по дефолту 10 + class по 1) | без сокращения блок работает нормально                                         |

```
bin/qmx check <fixture>/src --only-rule=complexity.ccn --config=a-threshold-only.yaml   → 0 нарушений
bin/qmx check <fixture>/src --only-rule=complexity.ccn --config=b-threshold-plus-class.yaml → 0 нарушений (идентично (а))
bin/qmx check <fixture>/src --only-rule=complexity.ccn --config=c-class-only.yaml       → 2 нарушения (callable + class)
```

(а) и (б) дают БИТ-В-БИТ идентичный результат (0 находок) — подтверждает, что
дело не в «чьё значение победило» (в (б) `class.max_warning=1` мог бы легко дать
находку, если бы читался), а в том, что уровень `class` полностью и молча
выключается самим фактом присутствия ключа `threshold`. Это ровно то утверждение
о НАХОДКАХ, которое реестр называет невыразимым в словаре `coexistence`
(`compose`/`refuse` описывают то, ЧЬЁ значение побеждает; здесь побеждающего
значения нет вовсе — есть выключенный уровень).

---

## ЧЕМ ПОЛУЧЕНО

```bash
# Пункт 1 — подсчёт строк реестра
awk -F'\t' 'NR>459' docs/internal/plans/promise-effect/measurement/promise-ledger.tsv > /tmp/x19_data_rows.tsv
awk -F'\t' '$1=="pair" && ($2=="coupling.cbo"||$2=="coupling.instability") && $5=="same-source" \
  && ($9 ~ /coexistence column has no value|no value for|has no value meaning|COLLISION/)' /tmp/x19_data_rows.tsv | wc -l   # 60
awk -F'\t' '$1=="pair" && ($2=="complexity.ccn"||$2=="complexity.cognitive"||$2=="complexity.npath") \
  && ($3=="class.threshold"||$3=="class:") && $4=="threshold" && $5=="same-source"' /tmp/x19_data_rows.tsv | wc -l   # 9 физ. строк / 6 пар

# Пункт 2 — разбивка MISCOMPOSED
awk -F'\t' '$1=="B" && $5=="MISCOMPOSED" && $6=="yes"{n=split($2,a,"|"); print a[n]}' \
  docs/internal/generated/promise-effect/verdicts.tsv | sort | uniq -c | sort -rn
awk -F'\t' '$1=="B" && $5=="MISCOMPOSED"{n=split($2,a,"|"); print a[n]"\t"$NF}' \
  docs/internal/generated/promise-effect/verdicts.tsv | sort | uniq -c

# Пункт 3 — носитель
grep -n "A bare \`threshold\` at a hierarchical rule" website/docs/getting-started/configuration.md
sed -n '855,891p' website/docs/getting-started/configuration.md

# Пункт 4 — цена
grep -n "Cannot mix" src/Analysis/Finding/Contract/Rule/ThresholdParser.php
grep -c "complexity\.\(ccn\|cognitive\|npath\)\|coupling\.\(cbo\|instability\)" <28 файлов legitimate-configs.tsv>
grep -n "public function it" tests/Analysis/Evidence/Complexity/Unit/ComplexityOptionsTest.php
grep -n "public function it" tests/Analysis/Evidence/Coupling/Unit/CboOptionsTest.php tests/Analysis/Evidence/Coupling/Unit/InstabilityOptionsTest.php

# Пункт 5 — канал предупреждения
grep -rn "class ConfigurationWarning\|ConfigurationWarning\b" src/
grep -rn "writeWarning" src/Infrastructure/Console/Command/CheckCommand.php

# Пункт 6 — прогоны на своей фикстуре (workers=0, --only-rule, свой --config)
bin/qmx check <scratch>/fixtures/src --only-rule=complexity.ccn \
  --config=<scratch>/fixtures/configs/a-threshold-only.yaml --format=json --workers=0
# (аналогично для b-threshold-plus-class.yaml и c-class-only.yaml)

# Находка про артефакт зонда (доп. проверка, не по прямому пункту задания)
bin/qmx check <scratch>/fixtures/src --only-rule=design.god-class \
  --config=<{exclude-readonly: 1, wmc-threshold: 2}> --format=json --workers=0
bin/qmx check <scratch>/fixtures/src --only-rule=annotation.directive \
  --config=<{enabled: false, unused-directive-severity: 2}> --format=json --workers=0
```

## ЧЕГО ЭТОТ СПОСОБ НЕ ВИДИТ

- Подсчёт пункта 1 опирается на текст `note` (регулярка по четырём фразам-маркерам);
  строка без явной фразы, но фактически страдающая тем же дефектом словаря, будет
  пропущена — оценка «54…69…63…81» снизу ограничена этим же способом извлечения,
  а не независимым переисполнением стенда.
- Гипотеза «артефакт зонда» в пункте 2 подтверждена на 2 конкретных строках из 66
  потенциально затронутых (`design.god-class×wmc-threshold`,
  `annotation.directive×unused-directive-severity`); остальные 64 не прогонялись
  поштучно — экстраполяция на весь `6-gate`+`6-subject-filter` правдоподобна
  (общий код зонда, общий класс ключей — булевы/строковые не-`enabled`), но не
  доказана поштучно.
- RU-половина сайта не проверялась (по заданию — только EN).
- Прогон пункта 6 — один правило (`complexity.ccn`), один фикстурный класс; не
  проверялись `complexity.cognitive`/`complexity.npath` (код идентичен по
  структуре, но не запускался) и не проверялся `coupling.cbo`/`instability`
  (там иной механизм — «уравнивает оба уровня», а не «выключает один», см. код).
- `git ls-files` для проверки корпуса legitimate-configs.tsv запускался руками по
  списку из самого файла (31 путь), а не через `composer promise-effect:corpus`
  (сам раннер не запускался — превысил бы разрешённый бюджет времени).
