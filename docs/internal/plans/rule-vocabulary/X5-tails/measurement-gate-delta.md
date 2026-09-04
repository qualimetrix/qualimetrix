# M1 — гейт и намеренное изменение множества находок: измерение

Всё измерено на **клоне вне рабочего дерева**:
`…/scratchpad/x5-measure/clone`, `git clone --local --no-hardlinks` из WT на
`ab614111`, `vendor/` скопирован жёсткими ссылками (`cp -Rl`, не симлинк).
Рабочее дерево WT не тронуто: `git -C WT status --porcelain` пусто, HEAD
`ab614111` (проверено после всех прогонов).

Все сравнения — `--reference=HEAD` внутри клона: эталон = коммит `ab614111`,
кандидат = то же дерево с одной правкой. Это ровно «шаг, отличающийся одной
вещью», без переставления коммитов.

## Что опровергнуто / подтверждено в записи FOLLOWUPS

1. **ПОДТВЕРЖДЕНО буквально:** `--derive-declared-delta` при изменившемся
   составе находок отказывается писать вердикт по `finding-count-mismatch`.
   Мой прогон: `RED — 1 failure(s): finding-count-mismatch`,
   `The candidate reports 14 finding(s), the reference 15.`, `EXIT=5`
   (`run-A-derive.log`). У записи было «candidate 7 против 8» — другой кейс, тот
   же класс.
2. **ПОДТВЕРЖДЕНО:** «причины заполнены руками → RED, счёт плюс `delta-overreach`».
   Мой прогон: `RED — 33 failure(s): finding-count-mismatch, delta-overreach`
   (1 + 32), `EXIT=1` (`run-A2.log`). В записи 27 (1 + 26) — разница только в
   размере кейса.
3. **ОПРОВЕРГНУТО (важно): «правку подсказок нечем объявить» — неточно.**
   Правка `DirectiveNameHints` **не меняет состав находок вообще**:
   `finding-count-mismatch` не возникает, число записей одинаковое,
   `coverage`/`case-claim` не шелохнулись. Это движение **одного поля `message`
   внутри одной записи** (`annotation.unresolved-directive @
   file:src/Directives.php`, строка 35). Форма объявления для неё **есть и
   почти работает**: `DeclaredDelta` приняла 8 поверхностей из 9, и упёрлась
   ровно в один страж — `delta-overreach` на `format:json`, 2 отказа.
   То есть это не «нет формы», а «один guard написан так, что поле `message`
   не может быть залицензировано ничем». Подробности в п. 6.
4. **УТОЧНЕНО: «два отказа» — неверный счёт.** Классов, через которые проходит
   шаг, меняющий состав, при пустых картах — два (`finding-count-mismatch`,
   `delta-overreach`), но множество ДОСТИЖИМЫХ точек отказа больше и зависит от
   того, что именно исчезает: при потере последней записи канала добавляются
   `case-claim-mismatch` + `coverage-shortfall`, при потере единственного текста,
   который переводила строка карты, — `map-stale`/`normalization-stale`. См. п. 1.
5. **НОВОЕ, в записи нет: `--derive-declared-delta` пишет файлы даже когда
   говорит «nothing was written».** После прогона с `EXIT=5` и сообщением
   `The run this declaration would be derived from failed, so nothing was
   written` в клоне лежали 13 файлов `finding-gate/declared-delta/*.diff`
   (`ls -la`, mtime совпадает с концом прогона). По коду: `Gate::deriveDeclaredDelta()`
   (`scripts/finding-gate/Gate.php:196-203`) вызывает `DeclaredDelta::rewrite()`
   безусловно, и уже ПОСЛЕ этого `scripts/finding-gate.php:136-142` решает
   вернуть 5 и напечатать «nothing was written». `rewrite()`
   (`scripts/finding-gate/DeclaredDelta.php:160-181`) сначала сносит каталог,
   пишет файлы, потом пишет индекс — без единого условия, так что индекс
   переписан тоже. Уверенность: файлы — измерено; индекс — вывод из кода,
   высокая (я затёр улику собственным `git checkout -- .`).
   Побочный эффект: следующий обычный прогон судится против декларации,
   выведенной из сломанного прогона, если кто-то дописал `reason`.

## 1. Точки отказа по коду: вопросы и их потребители

Последовательность прогона — `scripts/finding-gate/Gate.php:111-151`. Ниже —
все места, где шаг, намеренно меняющий состав находок, может получить отказ.

| #   | вопрос, на который отвечает страж                                       | потребитель / файл:строка                                                         | класс                                               | достижим при «убрали одну находку»?                                                 |
| --- | ----------------------------------------------------------------------- | --------------------------------------------------------------------------------- | --------------------------------------------------- | ----------------------------------------------------------------------------------- |
| 1   | «столько же ли записей публикуют обе стороны на этом кейсе?»            | `Gate::compareFindingCounts`, `Gate.php:1026-1048` (вызов — `Gate.php:602`)       | `finding-count-mismatch`                            | **да, всегда и первым**. Декларацию не консультирует ни одну                        |
| 2   | «совпал ли текст поверхности после карт и нормализации?»                | `Gate::compareSurfaces`, `Gate.php:665-676`                                       | `surface-mismatch`                                  | **да, по одной на каждую публикующую поверхность** (измерено: 13 на `case:design`)  |
| 3   | «объявленный дифф равен измеренному побайтно?»                          | `Gate::checkAgainstDeclaredDelta`, `Gate.php:762-777`                             | `delta-mismatch`                                    | достижим, но при честной деривации не срабатывает (измерено: 0)                     |
| 4   | «не блоб ли объявление?»                                                | там же, `Gate.php:737-747`                                                        | `delta-too-large`                                   | достижим при широком шаге; на моём — 0                                              |
| 5   | «не двигает ли строка диффа поле кортежа без объявленного расщепления?» | `Gate::overreachingLines`, `Gate.php:788-850`, вызов `Gate.php:750-758`           | `delta-overreach`                                   | **да, N раз** (измерено: 32)                                                        |
| 6   | «половина расщепления встретилась там, где строки нет?»                 | `Gate::checkSplitExplanation`, `Gate.php:986-1002`                                | `split-unmapped`                                    | нет: `if ($this->split->isEmpty()) return;`, а карты у такого шага пусты            |
| 7   | «кейс фиксирует те каналы, что объявил?»                                | `Gate::checkCaseClaim` через `checkCoverage`, `Gate.php:1096+`                    | `case-claim-mismatch`                               | **условно**: только если исчезла последняя запись канала в кейсе                    |
| 8   | «каждый объявленный канал@уровень кем-то наблюдается?»                  | `Gate::checkCoverage`, `Gate.php:1096+`                                           | `coverage-shortfall` / `-surplus` / `-multiplicity` | **условно**, тот же случай                                                          |
| 9   | «строка карты / правило нормализации что-нибудь перевело?»              | `Gate::checkStaleMaps` `Gate.php:1177`, `checkStaleNormalization` `Gate.php:1239` | `map-stale`, `normalization-stale`                  | **условно**: если исчезнувшая запись была единственным текстом, который они трогали |
| 10  | «объявленная дельта на совпавшей поверхности?»                          | `Gate::checkStaleDeclaredDelta`, `Gate.php:917-930`                               | `delta-stale`                                       | достижим при переобъявлении                                                         |
| 11  | «набор ключей публикуемой записи = кортежу?»                            | `checkTupleAgainstFindings`, `Gate.php:456`                                       | `finding-tuple-mismatch`                            | нет: удаление записи не меняет ключей                                               |
| 12  | «отпечаток сходится со своими же полями?»                               | `checkFingerprints`, `Gate.php:481-533`                                           | `fingerprint-mismatch` / `-opaque`                  | нет (проверка внутри одной стороны)                                                 |

Измеренная реализация этого множества на «одна находка меньше»
(`size.property-count` возвращает `array_slice($findings, 1)`, кейс `design`,
`run-A.log`): **14 отказов — 1 `finding-count-mismatch` + 13 `surface-mismatch`**,
поверхности: `baseline-file`, `explain:declaration:class:…Order`, `format:{checkstyle,
github, gitlab, html, json, metrics, sarif, summary, text, text-verbose}`,
`show-suppressed`. `coverage`/`case-claim` не сработали, потому что канал
`size.property-count` в кейсе даёт и другие записи, — что и подтверждает
условность пунктов 7-9.

**Ключевой факт:** пункт 1 — единственный, который не консультирует никакую
декларацию (в `compareFindingCounts` нет ни одного обращения к
`$this->declaredDelta`), и он же делает деривацию невозможной, потому что
`deriveDeclaredDelta` дергает тот же `compare()`.

## 2. Что уже есть: `DeclaredDelta`

**Что она объявляет.** Ключ — *поверхность* (`case:design|format:json`), не
находка. Значение — точный unified diff этой поверхности целиком плюс рукописная
`reason` (`scripts/finding-gate/DeclaredDelta.php:41-100`). Четыре свойства:
дифф равен измеренному побайтно, объявление на совпавшей поверхности протухло,
дифф больше 200 изменённых строк — блоб, строка диффа не двигает поле кортежа без
объявленного расщепления. `reason` не переживает изменение диффа, под который
писалась (`DeclaredDelta::reasonFor`, `:191-199`).

**Почему не хватает — три разных «почему», и их надо различать.**

- **По построению (a):** `finding-count-mismatch` вычисляется до и мимо
  декларации (`Gate.php:1026-1048`), поэтому объявить изменение числа записей
  нечем в принципе. Хуже: этот отказ краснит и сам derive-прогон, поэтому
  декларацию **нельзя даже получить машинно** — `EXIT=5`, «nothing was written»
  (измерено). Дальше остаётся ровно то, что запись FOLLOWUPS и называет: писать
  дельту руками, чего README прямо запрещает («the diff files are produced by
  `--derive-declared-delta`, never typed», `finding-gate/README.md:547-551`).
- **По построению (b):** ключ — поверхность. Исчезновение одной записи — это
  13 объявлений на 13 поверхностях, и ни в одном нет места, где сказано «уходит
  вот эта идентичность». Проверить «объявленное совпало с измеренным» можно
  только как «дифф совпал», то есть косвенно.
- **Из-за того, как написан ОДИН guard (c):** `delta-overreach` разрешает
  движение сравниваемого поля только через `ChannelSplit::allowsMove`
  (`Gate.php:833-835`), а `ChannelSplit::allowMove` заполняет разрешения только
  для `FIELDS = ['channel','rule','code']` (`ChannelSplit.php:44`, `:230-241`).
  Значит `message`, `techDebtMinutes`, `file`, `line`, `subject` **не может
  залицензировать ни одно объявление, которое сегодня существует**. Это не
  свойство предмета, это свойство одного массива из трёх строк.

Измеренная раскладка 32 `delta-overreach` (`run-A2.log`) — и она показывает (c)
наглядно: отказы только там, где поверхность публикует синтаксис `"поле": значение`:
`format:json` 18, `format:html` 11, `baseline-file` 1, `format:gitlab` 1,
`format:sarif` 1. Восемь объявленных поверхностей из тринадцати
(`text`, `text-verbose`, `summary`, `checkstyle`, `github`, `metrics`,
`show-suppressed`, `explain:…`) **прошли по декларации без единого отказа**.
Типовые тексты:
`Hunk line 5 changes the compared field "techDebtMinutes" ("430" -> "415"), a move no declared split explains.`
`Hunk line 7 publishes 0 value(s) of the compared field "file" where the reference publishes 1, so the change is not a rename a declared split could explain.`

## 3. Воспроизведение на реальном прогоне

Три прогона, все в клоне, все с явным кодом возврата.

**Стенд доказан пустым прогоном.** Немутированный клон,
`php scripts/finding-gate.php --reference=HEAD --cases=annotations --incomplete-corpus`
→ `PARTIAL — no equivalence is claimed`, `EXIT=2`, ноль отказов, 17 секунд
(`run-b0.log`). Без `--incomplete-corpus` тот же прогон даёт
`RED — 1 failure(s): coverage-shortfall`, `EXIT=1` — **`--cases` в одиночку
даёт красный, а не PARTIAL**; сужение требует обоих флагов (`run-baseline.log`).

**A. Состав меняется на одну запись.** Мутация:
`src/Analysis/Evidence/Size/PropertyCountRule.php:97`,
`return $findings;` → `return \array_slice($findings, 1);`
(та же, что у контроля `changed-finding-count`, `Controls.php:170-183`).
`--cases=design --incomplete-corpus` →
`RED — 14 failure(s): finding-count-mismatch, surface-mismatch`, `EXIT=1`.
Буквально:
```
FAIL [finding-count-mismatch] case:design
  The candidate reports 14 finding(s), the reference 15.
    - only in reference: size.property-count @ declaration:class:Corpus\Design\Model\Order@src/Model/Order.php
```

**A-derive.** Та же мутация, `--derive-declared-delta` (полный корпус,
229-350 с) →
```
RED — 1 failure(s): finding-count-mismatch
The run this declaration would be derived from failed, so nothing was written: …
EXIT=5
```
(и всё-таки написал 13 `.diff` — см. пункт 5 шапки).

**A2. Дельта объявлена руками.** Диффы, которые derive всё же оставил, подложены
обратно, индекс `declared-delta.tsv` заполнен 13 строками с причинами →
`RED — 33 failure(s): finding-count-mismatch, delta-overreach`, `EXIT=1`.
Ни `delta-mismatch`, ни `delta-too-large` не сработали.

**B. Правка подсказок (предмет п. 6).** Мутация в
`src/Analysis/Policy/Inline/Directive/DirectiveNameHints.php:120-124`:
кандидаты `nearestChannels()` фильтруются `DirectiveChannelBan::covers()`.
`--cases=annotations --incomplete-corpus` →
`RED — 9 failure(s): surface-mismatch`, `EXIT=1`. **`finding-count-mismatch`
не возник.** Затем полный `--derive-declared-delta` (229 с) → `EXIT=4`,
9 поверхностей; причины заполнены; повторный узкий прогон →
`RED — 2 failure(s): delta-overreach`, `EXIT=1`, обе на `case:annotations|format:json`:
```
Hunk line 1 changes the compared field "message" ("… annotation.unresolved-directive, annotation.unused-directive. …" -> "… annotation.unresolved-directive. …"), a move no declared split explains.
```

## 4. Цена закрытия — измерено

### `composer gate:controls`

- **17 контролей** (`Controls::all()`, `scripts/finding-gate-controls/Controls.php:49-73`).
- **Интерфейс сужения есть:** `--only=<a,b>`, а также `--jobs=<n>`,
  `--report-dir=`, `--force-expect=<id>:<class>`, `--detached`
  (`scripts/finding-gate-controls/Harness.php:70-92`, usage `:162-175`).
  Потолок параллелизма 8 (`Harness::JOB_CEILING`), по умолчанию четверть ядер —
  на этой машине 14 ядер → **3 одновременно**.
- **Полный прогон замерен:** `php scripts/finding-gate-controls.php --reference=HEAD --detached`
  в клоне: `T0=1788461105`, `T1=1788462589` → **1484 с = 24 мин 44 с**,
  `PASS — 17 of 17 control(s) behaved as declared`, `EXIT=0`.
  Каждый контроль ~4 мин 03 с — то есть время ≈ (17/jobs)×4 мин, при `--jobs=8`
  ожидаемо ~10-12 мин (не мерил).
- **Сколько из 17 адресуют затрагиваемую семантику:** пять по объявленному
  классу — `changed-finding-count`, `delta-mismatch`, `delta-stale`,
  `delta-overreach`, `delta-too-large`; плюс `positive` краснеет от любой правки
  компаратора; плюс `removed-fixture` и `lost-level-fixture` держат толерации на
  `surface-mismatch`. Итого 6-8 из 17 придётся пересматривать, остальные 9-11
  просто перепрогнать.

### `SelfTest.php`

1825 строк, **196 ассертов** (`this->assert(` + `this->same(`), 29 методов.

- **Сравнение состава находок и счётчик находок не покрыты вовсе.** `grep -n
  "findingCount\|FINDING_COUNT\|findingIdentities\|compareFindingCounts"
  scripts/finding-gate/SelfTest.php` — **ноль совпадений**. `compareFindingCounts`
  — приватный метод `Gate`, и его единственное свидетельство — контроль
  `changed-finding-count`, то есть 4 минуты полного прогона.
- Что придётся переписать вместе с семантикой: `declaredDelta()`
  (`SelfTest.php:1425`, **9 ассертов** — механика загрузки, `?`, пустой файл,
  дубль поверхности), `multiHunkDiff()` (`:1373`, **8** — парность строк, которую
  читает `delta-overreach`), `htmlPayloadVocabulary()` (`:1346`, **2** — алиасы
  полей payload), `collapsedIdentityNeedsNoDelta()` (`:1703`, **3**).
  **Итого ≈22 ассерта из 196** прямо привязаны к дельте; остальное про карты,
  нормализацию, отпечатки, покрытие.

### Прочие артефакты на текущей семантике

`grep -rn "finding-count-mismatch\|delta-overreach\|delta-mismatch\|delta-stale\|delta-too-large"`
вне `docs/internal/plans`:
- `scripts/finding-gate/FailureClass.php:29,80,83,86,89` — сам словарь
  (докблок класса: строки — контракт, переименование ломает ассерты контролей).
- `scripts/finding-gate/{Gate,DeclaredDelta,ExactDiff,SelfTest}.php` — докблоки
  и код.
- `scripts/finding-gate-controls/Controls.php:16,24,293,310,319,346` — контроли.
- `finding-gate/README.md:295,543,558-562,593,715` — раздел «What a declared
  delta declares» и список классов.
- **Продуктовый тест:** `tests/Analysis/Finding/Integration/ChannelSuggestionTieTest.php:85-102`
  — докблок прямо опирается на текущее правило: «`message` is a compared field a
  declared delta may not cover (`delta-overreach`)», и на этом основании фикстура
  тай-брейка то заводится, то снимается. Смена семантики делает это рассуждение
  неверным, а не просто устаревшим.
- `finding-gate/declared-delta.tsv` — сегодня только заголовок
  `surface\tfile\treason`, ноль строк.

## 5. Минимальная форма объявления

Предмет распадается на **две независимые дырки**, и им нужны разные формы.
Закрывать надо обе: п. 6 требует только вторую.

### Форма I — дельта СОСТАВА (закрывает п. 1 и `finding-count-mismatch`)

Отдельный индекс, потому что ключ у него принципиально другой — идентичность
находки, а не поверхность.

```
finding-gate/declared-finding-delta.tsv
case        direction   identity                                     reason
design      removed     size.property-count @ declaration:class:…    <почему запись уходит>
annotations added       annotation.unused-directive @ file:src/…     <почему появляется>
```

`identity` — ровно та строка, которую гейт уже печатает в диффе счётчика:
`sprintf('%s @ %s', channel, subject)` (`Gate::findingIdentities`,
`Gate.php:1312-1326`). Ничего нового изобретать не надо, и она уже
нормализована и отсортирована.

Псевдокод проверки, на месте нынешнего `compareFindingCounts`:

```
для каждого кейса:
    ref  := findingIdentities(эталон, после forward-перевода картами)
    cand := findingIdentities(кандидат)
    declaredRemoved := строки(case, 'removed')
    declaredAdded   := строки(case, 'added')

    # ТОЧНОЕ равенство мультимножеств, не подмножество
    expected := (ref \ declaredRemoved) ∪ declaredAdded
    если expected ≠ cand:
        FAIL finding-set-mismatch  # печатает обе разности целиком
    # ... implementation details

    # стальная запись
    если declaredRemoved ⊄ ref:      FAIL finding-delta-stale (объявлено удаление того, чего в эталоне нет)
    если declaredAdded   ⊄ cand:     FAIL finding-delta-stale (объявлено добавление, которого нет у кандидата)
    если declaredRemoved ∩ cand ≠ ∅: FAIL finding-delta-stale (запись объявлена ушедшей и осталась)
```

Три вещи, которые обязаны быть названы явно, иначе форма станет резиновой:

1. **Равенство, а не подмножество.** `expected` сравнивается с `cand` целиком,
   в обе стороны. `Outcome::asDeclared()` соседнего харнесса уже дал дефект
   ровно на сверке подмножеством — повторять нельзя. Мультимножество, а не
   множество: две записи с одной идентичностью существуют
   (`findingIdentities` их не схлопывает, там `sort`, а не `unique`).
2. **Стальная запись — три разных вида, и все три красные.** Объявленное
   удаление, которого в эталоне не было; объявленное добавление, которого нет у
   кандидата; объявленное удаление, которое не случилось. Это зеркало
   `map-stale`/`delta-stale`/`normalization-stale`, и оно должно быть в том же
   словаре классов.
3. **Что форма НЕ объявляет:** она не даёт послабления `delta-overreach`
   автоматически. Иначе одна строка «эта запись ушла» разрешит любое движение
   любого поля на всех тринадцати поверхностях. Послабление — узкое: строка
   диффа, все *исчезнувшие* значения полей которой принадлежат записи,
   объявленной `removed` (и симметрично для `added`), из проверки reach
   исключается; строка, где сравниваемое поле **переехало** между двумя
   оставшимися записями, — по-прежнему отказ.
   Отдельно придётся решить про агрегаты: `techDebtMinutes` итоговой строки
   меняется как СЛЕДСТВИЕ (измерено: `430 -> 415`), и он не принадлежит ни одной
   исчезнувшей записи. Либо это отдельная объявляемая величина, либо
   нормализация. Не решать молча.

### Форма II — движение поля ВНУТРИ записи (закрывает п. 6)

Здесь новый файл не нужен: `DeclaredDelta` уже ловит всё, кроме одного стража.
Минимальная правка — дать `delta-overreach` второй источник разрешений, кроме
`ChannelSplit`, и оформить его как объявление:

```
finding-gate/declared-field-moves.tsv
surface                          field    from                       to                        reason
case:annotations|format:json     message  <точный старый текст>      <точный новый текст>      <почему>
```

```
overreachingLines():
    ... как сейчас ...
    если split.allowsMove(field, from, to):        пропустить
    если declaredMoves.allows(surface, field, from, to):
        declaredMoves.credit(...)                  # для проверки на стальную строку
        пропустить
    иначе FAIL delta-overreach
```

- **Ключ:** (surface, field, from, to). Значения полные и точные, не префикс, не
  regex — иначе строка станет `normalization` через заднюю дверь.
- **Точность = равенство:** строка срабатывает только на паре, буквально равной
  объявленной. Пара, которую никакая строка диффа не произвела, → новый класс
  `field-move-stale`, той же природы, что `map-stale`.
- **Что мешает злоупотреблению:** объявление всё ещё висит внутри
  `DeclaredDelta`, то есть дифф поверхности по-прежнему сверяется побайтно
  (`delta-mismatch`) и по размеру (`delta-too-large`). Форма II снимает
  единственную оставшуюся стену, а не открывает шлюз.
- Для правки подсказок понадобится **одна строка** — измерено: движется одно
  поле одной записи, и `delta-overreach` сработал только на `format:json`
  (2 раза, одна и та же пара значений на строках 1 и 2 ханка).

## 6. Правка «did you mean»: какая именно дельта

Измерено (мутация B, `run-B.log`, `run-B2.log`, `declared-delta/…json.diff`).

- **Состав находок не меняется.** `finding-count-mismatch` не возник, число
  записей то же, `coverage`/`case-claim`/witness — тишина. Постановка «шаг,
  меняющий множество находок» к этой правке **не относится**.
- **Сколько записей:** одна. `annotation.unresolved-directive` на
  `src/Directives.php:35` (директива с опечаткой `annotation.unressed-directive`).
  Второй тай-брейк того же кейса (`design.type-coverage.propurn`,
  `src/Directives.php:48`) **не затронут** — забаненный канал не входил в его
  пятёрку.
- **Какие поля:** одно — `message` (в SARIF оно же публикуется как
  `message.text`, в text/summary — внутри строки отчёта). Разница:
  из списка подсказок исчезает `, annotation.unused-directive`.
- **Сколько поверхностей:** 9 из 12 плюс `show-suppressed`:
  `summary, text, text-verbose, json, checkstyle, sarif, gitlab, github,
  show-suppressed`. Не затронуты `metrics`, `health`, **`html`** и файлы
  baseline/explain. (Что `html` не сдвинулся — измерено деривацией, объяснения
  у меня нет; гипотеза средней уверенности: payload публикует `message` в
  форме, которую нормализация или ReportPayload-редукция снимает.)
- **Под какую форму попадает:** под **уже существующую `DeclaredDelta`** —
  и она её почти принимает. Полная деривация прошла (`EXIT=4`, 9 файлов,
  суммарно 48 строк), `delta-mismatch` не сработал, `delta-too-large` не
  сработал (по 2 изменённых строки на поверхность при пределе 200),
  8 поверхностей из 9 приняты молча. Отказал ровно `delta-overreach` на
  `format:json`, 2 раза, потому что только там читается синтаксис
  `"message": "…"`.
- **Вывод, расходящийся с записью FOLLOWUPS:** «правку нечем объявить» —
  неточно. Объявить её есть чем; нельзя *залицензировать движение поля
  `message`*, потому что `ChannelSplit::allowMove` заводит разрешения только
  для `channel`/`rule`/`code` (`ChannelSplit.php:44`). Цена закрытия этого —
  форма II из п. 5 плюс перепрогон `gate:controls` (25 минут), а не форма
  дельты состава. **Шаг с подсказками можно провести, не трогая
  `finding-count-mismatch` вообще.**

## Чего я не измерил и почему

- **Не мерил `--jobs=8` для `gate:controls`.** Один полный прогон уже стоил
  25 минут; вторая точка не меняла бы вывод о порядке цены.
- **Не воспроизводил дословно строку FOLLOWUPS «27 отказов»** на их фикстуре
  (`38ad58e9` + корпусная фикстура, которой в дереве нет). Вместо этого
  воспроизвёл тот же механизм на своей мутации: 33 отказа той же пары классов.
  Считаю это эквивалентным свидетельством о механизме, но не о числе 27.
- **Не подтвердил измерением, что failing derive переписывает
  `declared-delta.tsv`** (только `*.diff`): я затёр улику `git checkout -- .`
  до того, как посмотрел. Вывод сделан из кода `DeclaredDelta::rewrite()`
  — там нет ветвления, — уверенность высокая, но это вывод, а не измерение.
  Перепроверяется одним прогоном на 4 минуты.
- **Не проверял, что html-поверхность действительно не двигается** при правке
  подсказок, ничем кроме отсутствия файла в деривации. Гипотеза о причине
  (редукция payload) не проверена.
- **Не проектировал взаимодействие формы I с `ChannelCoverage`/`case.json`
  channels**: если шаг убирает ПОСЛЕДНЮЮ запись канала, объявления состава мало,
  нужно ещё снять канал из `case.json` и из витнесса. Это отдельная развилка,
  на моей мутации она не активировалась (канал остался живым), поэтому я её не
  мерил и не закладывал в п. 5.
- **Не запускал `composer check`, тесты и PHPStan** — задача измерительная,
  дерево не трогалось.
