# X19 — перечисление фактов по девяти хвостам X18

Дерево: `qualimetrix`, ветка `main`, коммит `02a6ca66` (снят в начале сессии). Весь режим —
READ-ONLY по репозиторию; все прогоны — в `fixture/` внутри каталога этого захода, с
явным `--cache-dir` на каждый прогон.

## ЧЕМ ПОЛУЧЕНО (общее для всех разделов)

- Статический разбор: `Read`/`grep`/`awk` по `src/`, `docs/internal/plans/promise-effect/`,
  `docs/internal/generated/promise-effect/verdicts.tsv`, `scripts/promise-effect/`,
  `qmx.yaml`, `qmx-baseline.json`.
- Наблюдение: `bin/qmx check --config=<fixture>.yaml --format=json --workers=0
  --cache-dir=<уникальный каталог, удалён перед прогоном>` из
  `.../scratchpad/x19/r4/fixture/`, где лежат два минимальных файла с `eval()`
  (`fixture/2024/Foo.php`, `fixture/other/Bar.php`) — гарантированная, легко считаемая
  находка на файл.
- Обход ловушки `--no-cache`: флаг не передавался вовсе в прогонах, где нужен «чистый»
  кеш — вместо этого каждый прогон получал СВОЙ `--cache-dir=.../cachedirs/<имя>`,
  каталог удалялся (`rm -rf`) непосредственно перед прогоном, наличие подтверждалось
  `ls` после. Отдельно, для самого пункта 6, `--no-cache` проверялся КАК ПОДОЗРЕВАЕМЫЙ
  ОБЪЕКТ (а не как средство очистки) — специально без `--cache-dir`, чтобы увидеть его
  реальный эффект на `.qmx-cache` в рабочем каталоге.
- Код возврата снимался с процесса напрямую (`echo $?` сразу после команды, без пайпа).

## ЧЕГО ЭТОТ СПОСОБ НЕ ВИДИТ (общее)

- Фикстура — два файла с одним `eval()`; поведение НЕ проверялось на реальных
  масштабах `src/` (production coupling/maintainability числа в п.9 взяты из
  `qmx-baseline.json`/`qmx.yaml` как есть, не пересчитаны заново).
- Прогоны — только `--format=json`, `--workers=0`; поведение других форматтеров и
  параллельного режима не проверялось.
- Для пп. 7-8 (grid) измерение — по уже сгенерированному `verdicts.tsv`, не по
  повторному прогону стенда `composer promise-effect` (запрещено заданием).

---

## 1. `default-value-present-key` — пятое значение `null_means`

**РАЗМЕР.** 9 строк в `docs/internal/plans/promise-effect/measurement/promise-ledger.tsv`
(кол-во проверено `awk` по колонке 5 `null_means` для `kind=form`):
`rules.complexity.cognitive.threshold`, `rules.complexity.ccn.threshold`,
`rules.complexity.npath.threshold`, `rules.coupling.cbo.error`,
`rules.coupling.cbo.threshold`, `rules.coupling.cbo.warning`,
`rules.coupling.instability.max-error`, `rules.coupling.instability.max-warning`,
`rules.coupling.instability.threshold` — все девять, дверь `yaml`.

**МЕСТО.**
- Реестр: `promise-ledger.tsv` (заголовок строка 459), 9 строк со значением
  `default-value-present-key` (напр. строка 562 — `rules.complexity.ccn.threshold`).
- Схема reестра (перечисляет допустимые значения `null_means`):
  `docs/internal/plans/promise-effect/01-promise.md:56` —
  `` null_means` ∈ `default` | `refuse` | `unpromised` | `addressed-answer` `` —
  **`default-value-present-key` в этот список НЕ входит**, это пятое,
  недокументированное там значение.
- Классификатор: `scripts/promise-effect/Classifier.php`, метод `nullForm()`
  (строки 149-186) — явные ветки только для `unpromised` (156), `addressed-answer`
  (160), `refuse` (166); всё остальное, включая `default-value-present-key`,
  проваливается в ветку `default` (комментарий на строке 174).
  `grep -c default-value-present-key scripts/promise-effect/*.php` = 0 — значение
  нигде в коде стенда не упомянуто по имени.

**ЧТО ИМЕННО СЛОМАНО/НЕ СУДИТСЯ.** Посылка задания («9 строк не судятся, а не
зелены») **ОПРОВЕРГНУТА измерением** `docs/internal/generated/promise-effect/verdicts.tsv`
(строки `form|yaml|<key>|null`):
- 7 из 9 получили вердикт `OK` (`` `~` behaved as an omitted key ``) —
  фактически ЗЕЛЕНЫ, не «не осуждены».
- 2 из 9 (`cognitive.threshold`, `ccn.threshold`) получили `COLLAPSED`
  (`` `~` did something other than defaulting ``), `defect=yes` — уже КРАСНЫ.
- Ни одна строка не получила `NOT_OBSERVABLE`.

Настоящий (не наивный) пробел — другой: смысл `default-value-present-key`
(зафиксирован в примечании самой ledger-строки, `promise-ledger.tsv` рядом со
строкой 460) — это ДВЕ независимые половины обещания: (а) значение ключа берёт
дефолт (это и проверяет `nullForm()` через сравнение текста) и (б) ПРИСУТСТВИЕ
ключа с `~` всё равно выбирает плоскую форму правила и подавляет чтение соседних
блоков `callable:`/`class:`/`namespace:` — ровно то, чем это значение отличается
от обычного `default`. Половина (б) **не проверяется классификатором вообще** —
`nullForm()` сравнивает только текстовые эффекты значения, не факт «прочитан ли
соседний блок». Значит утверждение X18 верно не в форме «9 строк не судятся», а
точечно: измеримая (а)-половина осуждена (и даже частично красная), а
определяющая эту координату (б)-половина не имеет пробника нигде в стенде.

**ЦЕНА ЛЕЧЕНИЯ.** Нужен новый пробник в `Stand.php`/`Classifier.php`: писать
`threshold: ~` РЯДОМ с блоком уровня (`callable:`/`class:`/`namespace:`) и
проверять, что блок НЕ был прочитан — это вторая ось (уже не «значение», а
«присутствие ключа против соседнего блока»), которой в текущей сетке нет вовсе
(сетка организована по `kind=form`, а не по «ключ + сосед»). Требуется новый
`kind` строки реестра либо расширение `pair`-механизма (`Classifier::pair()`) на
несимметричную пару «пороговый ключ vs блок уровня».

**СЦЕПЛЕННОСТЬ.** Независим от остальных восьми — свой файл, свой механизм.

---

## 2. Два обещания «C19»

Оба зафиксированы в `promise-ledger.tsv` (строки ~68-76, блок `C19`,
раздел website/docs/getting-started/configuration.md:775-836).

### (I) «Любой источник судится, а не только победивший»

Носитель: `website/docs/getting-started/configuration.md:784-787`.

Наблюдение (fixture): YAML `format: [json]` (список — заведомо неверная форма)
+ `--format=json` на CLI (валидная, ПОБЕЖДАЮЩАЯ форма):

```
$ bin/qmx check --config=qmx-c19.yaml --format=json ...
exit=3
{"error":"Configuration error: Invalid value for \"format\": expected the name
of an output format, got array.","exit_code":3}
```

Обещание **ПОДТВЕРЖДЕНО прогоном**: проигравший (перекрытый) источник всё равно
судится и отказывает документ. Сетка `verdicts.tsv` этого не видит по построению
— она даёт строки вида `(door × path × form)`, а не `(door_A × door_B × path)`;
межзаходного/межисточникового измерения в артефакте нет ни одной строки.

### (II) Расхождение по числовому имени каталога

Носитель: `website/docs/getting-started/configuration.md:825-832`.

**Таблица наблюдений** (фикстура: `fixture/2024/Foo.php` с `eval()`,
`fixture/other/Bar.php` с `eval()`; каждый прогон — свой `--cache-dir`):

| Дверь                               | Значение            | Наблюдение                                                                                                                                                 | Место решения (файл:строка)                                                                                                                                                                                                                                     |
| ----------------------------------- | ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| root `suppress_paths`               | `["2024"]` (строка) | `violationCount=2` (2024 подавлен)                                                                                                                         | —                                                                                                                                                                                                                                                               |
| root `suppress_paths`               | `[2024]` (число)    | `violationCount=2`, **идентично строке** — ПРИНЯТО, int→string                                                                                             | `ConfiguredFindingExclusionsResolver.php:36-38` (`suppressedPaths()`)                                                                                                                                                                                           |
| per-rule `rules.<r>.suppress_paths` | `["2024"]`          | принято, exit=2                                                                                                                                            | —                                                                                                                                                                                                                                                               |
| per-rule `rules.<r>.suppress_paths` | `[2024]`            | **ОТКАЗ, exit=3**: `"Option \"suppressPaths\" of rule \"code-smell.eval\" must be a non-empty string or a list of non-empty strings or null, got a list."` | `RuleOptionKeyRecognition.php:71-90` (`refuseMalformedFrameworkKeys()`, форма `either(nonEmptyText(), listOf(nonEmptyText()))`) — отказывает РАНЬШЕ, чем код `RuleOptionsFactory.php:157-172` (`array_filter(is_string)`, тихое отбрасывание) вообще исполнится |
| `paths:`                            | `["2024"]`          | `filesAnalyzed=1` (2024 просканирован)                                                                                                                     | —                                                                                                                                                                                                                                                               |
| `paths:`                            | `[2024]`            | **МОЛЧА ОТБРОШЕНО → пустой список путей → `filesAnalyzed=0`, exit=0 (\"чисто\")**                                                                          | `RunConfigurationResolver.php:78-88` (`lastStringList()`, `array_filter(is_string)` строка 83)                                                                                                                                                                  |
| `exclude:`                          | `["2024"]`          | 2024 исключена, `filesAnalyzed=1`                                                                                                                          | —                                                                                                                                                                                                                                                               |
| `exclude:`                          | `[2024]`            | **МОЛЧА ОТБРОШЕНО → 2024 НЕ исключена, `filesAnalyzed=2`** (как будто exclude не писали)                                                                   | `RunConfigurationResolver.php:96-106` (`accumulatedStrings()`, строка 101)                                                                                                                                                                                      |

Наблюдение **шире**, чем в постановке задания: реально это **три различных
поведения**, не два (accept-vs-drop): ACCEPT-с-приведением (root suppress_paths),
REFUSE (per-rule suppress_paths — из-за отдельного, более раннего шейп-чекера,
а не из-за кода в самом `RuleOptionsFactory`), SILENT-DROP (paths:/exclude:).
Причём `paths: [2024]` — не просто «неверная область», а **полностью пустой
скан с exit=0**, если это единственный путь: самый тяжёлый случай из
наблюдавшихся.

**ЦЕНА ЛЕЧЕНИЯ.** Разные слои: (root) уже безопасен; (per-rule) уже безопасен
(отказывает); опасны ровно `paths:`/`exclude:` — унифицировать с root-конвенцией
(int/float → string) в `RunConfigurationResolver::lastStringList()` и
`::accumulatedStrings()` — 2 функции, один файл.

**СЦЕПЛЕННОСТЬ.** (I) и (II) независимы друг от друга и от прочих хвостов.

---

## 3. Форма ЭЛЕМЕНТА списка (`~` внутри списка)

**Полный список list-корней по `ConfigSchema::listKeys()`** (источник истины,
`src/Analysis/Configuration/ConfigSchema.php:341-355`) — **7 корней**, не 4:
`paths`, `exclude`, `disabledRules`(`disabled_rules`), `onlyRules`(`only_rules`),
`suppressPaths`(`suppress_paths`), `suppressNamespaces`(`suppress_namespaces`),
`excludeHealth`(`exclude_health`) — последний добавлен отдельной строкой
(`$lists[self::EXCLUDE_HEALTH] = true;`, строка 352), не через `ENTRIES`.

**Таблица наблюдений** (`~` — единственный или один из элементов списка):

| Корень                | Конфиг        | exit  | Поведение                                                                                                                                                                                                                                                  | Место решения                                                         |
| --------------------- | ------------- | ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| `paths`               | `[~]`         | 0     | ПРИНЯТО молча, `filesAnalyzed=0` (пустой список путей, „чисто“)                                                                                                                                                                                            | `RunConfigurationResolver.php:83`                                     |
| `exclude`             | `[vendor, ~]` | 2     | ПРИНЯТО молча, `~` отброшен, `vendor` работает                                                                                                                                                                                                             | `RunConfigurationResolver.php:101`                                    |
| `disabled_rules`      | `[~]`         | 2     | ПРИНЯТО молча, ни одно правило не отключено (4 находки, как в базовом прогоне)                                                                                                                                                                             | `FindingConfigurationResolver.php:107`                                |
| `only_rules`          | `[~]`         | 2     | ПРИНЯТО молча, `~` отброшен → пустой список → ТРАКТУЕТСЯ как «без ограничения» (4 находки — как без `only_rules` вовсе); **проверено отдельно**: `only_rules: []` (без `~`) даёт тот же результат — это не специфика `~`, а общая семантика пустого списка | `FindingConfigurationResolver.php:90`                                 |
| `suppress_paths`      | `[~]`         | **3** | ОТКАЗ: `"Invalid entry in \"suppress_paths\": every entry must be a string, got null."`                                                                                                                                                                    | `ConfiguredFindingExclusionsResolver.php:88-102` (`acceptedString()`) |
| `suppress_namespaces` | `[~]`         | **3** | ОТКАЗ: `"Invalid entry in \"suppress_namespaces\": every entry must be a string, got null."`                                                                                                                                                               | тот же файл, `suppressedNamespaces()`                                 |
| `exclude_health`      | `[~]`         | **3** | ОТКАЗ: `"exclude_health entries must be strings."`                                                                                                                                                                                                         | `ComputedMetricContributionReader.php:64-70`                          |

**Посылка задания опровергнута числом**: не «один корень из четырёх» отказывает,
а **3 из 7** отказывают (`suppress_paths`, `suppress_namespaces`, `exclude_health`),
и 4 из 7 молча принимают (`paths`, `exclude`, `disabled_rules`, `only_rules`).
Разлом идёт не по «списки против одного особого случая», а по границе
«фильтрующие / информирующие `Run`-и-`Finding` конфигурации» (молча режут) против
«подавляющие находки конфигурации» (`suppress_*`, `exclude_health` — отказывают).

**ЦЕНА ЛЕЧЕНИЯ.** Унифицировать 4 «молчаливых» корня с поведением 3
«отказывающих» — правка в двух файлах-резолверах (`RunConfigurationResolver`,
`FindingConfigurationResolver`), обе функции `lastStringList`/`accumulatedStrings`
уже дублируются почти дословно между двумя файлами (см. также п.2) — одна правка
общего хелпера закрыла бы оба хвоста (2 и 3) разом.

**СЦЕПЛЕННОСТЬ.** Сцеплен с хвостом 2(II) — тот же код (`array_filter(is_string)`
в тех же двух функциях тех же двух файлов), лечится ОДНИМ пакетом работ.

---

## 4. 12 форм `ComputedMetricEntryKeys`, объявленных и не потребляемых

**РАЗМЕР.** 12 деклараций форм = 9 (глубина 1, `acceptedEntryKeys()`:
`description, enabled, error, formula, formulas, inverted, levels, threshold,
warning`) + 3 (глубина 2 под `formulas:`, `acceptedFormulaKeys()`: `class,
namespace, project`).
`src/Analysis/Evidence/ComputedMetrics/Configuration/ComputedMetricEntryKeys.php:54-77`.
Докблок на строках 45-52 прямо говорит: «The forms below are stated, not
consumed here» — `knows()` (`RuleOptionKeySet.php:120`) спрашивает только имя.

**8 отказов `ComputedMetricOverrideReader`** (файл того же имени,
`src/Analysis/Evidence/ComputedMetrics/ComputedMetricOverrideReader.php`) —
собственные вызовы форм-отказов для 8 из 9 ключей глубины 1 (все кроме
`enabled` — см. хвост 8) плюс общая проверка для 3 ключей `formulas.*`:

1. `formula` (строка 117) — `mustBeAString`
2. `formulas.<level>` — значение (строка 136-142) — `formulaValueMustBeAString`
   (покрывает все 3 формы `class/namespace/project` ОДНИМ вызовом на уровень)
3. `levels` контейнер (строка 172-176) — `levelListNotAList`
4. `description` (строка 196-201) — `mustBeAString`
5. `inverted` (строка 221-226) — `mustBeABoolean`
6. `threshold` (через общую `threshold()`, строка 395) — `mustBeANumber`
7. `warning` (тот же вызов, другой `$key`)
8. `error` (тот же вызов, другой `$key`)

**Дословные тексты (шаблоны из
`ComputedMetricShapeRefusalWording.php:62-99`, подтверждены прогоном ниже):**

1. `Option "formula" of computed metric "%s" must be a string, got %s.`
2. `The "formulas.%s" value of computed metric "%s" must be a string, got %s.`
3. `"levels" of computed metric "%s" must be a list of level words, got %s.`
4. `Option "description" of computed metric "%s" must be a string, got %s.`
5. `Option "inverted" of computed metric "%s" must be a boolean, got %s.`
6. `Option "threshold" of computed metric "%s" must be a number or null, got %s.`
7. `Option "warning" of computed metric "%s" must be a number or null, got %s.`
8. `Option "error" of computed metric "%s" must be a number or null, got %s.`

**Совпадение со словарём форм?** НЕТ — измерено прогоном:

```
computed_metrics: {computed.my-metric: {error: true}}
→ "...must be a number or null, got bool."
computed_metrics: {computed.my-metric: {inverted: 5}}
→ "...must be a boolean, got int."
rules: {code-smell.eval: {suppressPaths: 5}}   # общий словарь RuleOptionShape
→ "...must be a non-empty string or a list of non-empty strings or null, got a whole number."
```

`ComputedMetricShapeRefusalWording` печатает СЫРОЙ `get_debug_type()` (`bool`,
`int`), тогда как общий словарь `RuleOptionValueForm::describeWritten()`
(`src/Analysis/Finding/Contract/Rule/RuleOptionValueForm.php:78-95`) печатает
очеловеченные формы («a boolean», «a whole number», «null»). Два разных
словаря формы в одном продукте, подтверждено прогоном, не только чтением кода.

**ЦЕНА ЛЕЧЕНИЯ.** Переписать 8 сайтов в `ComputedMetricOverrideReader` и тексты в
`ComputedMetricShapeRefusalWording` на общий `RuleOptionShape`/
`RuleOptionValueForm` словарь — контролируемый рефакторинг в границах одного
файла + вордингового класса; поведенческого изменения (кроме текста сообщения)
нет.

**СЦЕПЛЕННОСТЬ.** Независим от прочих; частично соприкасается с хвостом 8
(тот же класс `ComputedMetricsConfigResolver`/`ComputedMetricOverrideReader`,
но другой ключ — `enabled` не входит в 8 отказов).

---

## 5. `RuleOptionsFactory` и литерал `'coupling'`

**ОПРОВЕРГНУТАЯ ПОСЫЛКА:** в `RuleOptionsFactory.php`
(`src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php`) нет ни одного
упоминания `'coupling'` или `ConfigSchema::COUPLING` — `grep -c` = 0 по обоим.
Задание указало не тот класс.

Реальное расхождение — **внутри `src/Analysis/Evidence/Coupling/CouplingAnalysis.php`**:
- строка 32: `$document->contributions('coupling')` — литерал
- строки 58-59: `ConfigurationRefusal::aboutResolvedInput('...' . ConfigSchema::COUPLING . '...', ConfigSchema::COUPLING)` — константа

**Расходятся ли сегодня?** НЕТ: `ConfigSchema::COUPLING = 'coupling'`
(`ConfigSchema.php:44`) — значения идентичны, поведения не расходятся.

**Что сломается при замене литерала на константу?** Ничего — значения совпадают
буквально, замена `'coupling'` → `ConfigSchema::COUPLING` в строке 32
поведенчески нейтральна; риск сегодняшний нулевой, риск — только на будущее
(если кто-то переименует секцию `coupling`, документ и код разойдутся молча).

**ЦЕНА ЛЕЧЕНИЯ.** Одна правка одной строки, без последствий.
**СЦЕПЛЕННОСТЬ.** Независим.

---

## 6. Сообщение `cache.dir` советует нерабочий `--no-cache`

**Место (2 сайта в одном файле):**
`src/Infrastructure/Cache/CacheConfigurationResolver.php:64` —
`'Invalid value for "%s": a directory path cannot be empty. Omit the key to use
the default, or disable the cache with --no-cache.'`
`src/Infrastructure/Cache/CacheConfigurationResolver.php:92` —
`'Cache directory "%s" is not writable. Point cache.dir (or --cache-dir) at a
writable path, or disable the cache with --no-cache.'`

**Наблюдение (в чём именно не держит `--no-cache`):**

```
$ rm -rf .qmx-cache
$ bin/qmx check --no-cache --format=json --workers=0
exit=2
$ ls .qmx-cache
.serializer  86/  dc/        # каталог СОЗДАН несмотря на --no-cache
```

**Корневая причина** — `--no-cache` доходит до `cache.enabled=false`
(`ConfigurationInputAdapter.php:56-58`) и до `CacheConfiguration->enabled`
корректно, но `enabled` **больше нигде не читается**:
`grep -rn "enabled" src/Infrastructure/Cache/*.php src/Infrastructure/Console/RuntimeConfigurator.php
src/Infrastructure/Console/CheckResolvedConfiguration.php` — единственное
чтение `->enabled` за пределами конструктора — в самом
`CacheConfigurationResolver::resolve()` (пропуск проверки записываемости).
`CacheFactory::create()` (`CacheFactory.php:30-37`) **безусловно** строит
`FileCache` из `directory`, не проверяя `enabled` вовсе — нет ветки
`NullCache`/`enabled ? ... : ...`. `FileCache` создаёт каталог при первой
записи (`FileCache.php:72,109,162`) независимо от того, что флаг был передан.

`--no-cache` реально отключает ТОЛЬКО проверку «каталог должен быть
доступен для записи» — само кеширование продолжает работать и писать на диск.

**ЦЕНА ЛЕЧЕНИЯ.** Нужна ветка в `CacheFactory::create()` (или выше, в
`RuntimeConfigurator`) — `NullCache`/no-op реализация при `enabled=false`; это
новый класс + правка точки создания, не косметика. Пока это не сделано,
сообщения (п.6) советуют нерабочее средство — цена лечения самого хвоста 6
(текста) тривиальна, но честный текст обязан либо не советовать `--no-cache`,
либо чинить сам флаг (что дороже).

**СЦЕПЛЕННОСТЬ.** Текстовая правка (сообщения) независима; РЕАЛЬНАЯ починка
`--no-cache` — самостоятельный, более дорогой хвост, который этот текст лишь
маскирует.

---

## 7. 1283 ячейки bool-слепоты / 162 строки

**Посылка задания НЕ ВОСПРОИЗВЕДЕНА измерением.** Числа «1283» и «162» не
встречаются нигде в дереве в этом контексте (`grep -rn "1283"` по `docs/internal/`
— 0 релевантных совпадений; «162» встречается ровно один раз в
`promise-ledger.tsv:144`, но про СОВЕРШЕННО ДРУГОЙ предмет — «162 строки
framework × продюсер», не про bool-слепоту).

**Что измерено вместо этого**, прямым запросом к
`docs/internal/generated/promise-effect/verdicts.tsv`:
- строк с `probe=bool` и `decided_by` начинающимся на `omitted == equivalent`
  (сигнатура слепоты — Classifier.php:118-120, ранняя проверка
  чувствительности) в axis `A`: **159**;
- то же в axis `D`: **7**; итого **166** across двух осей, а не 162.

**Механизм подтверждён по коду, не по домыслу**: `promise-effect/forms.tsv`
(строка `bool`) даёт **один универсальный литерал `true`** как канонический
`bool`-пробник для ЛЮБОГО ключа во всей сетке (в отличие от числовых форм,
где нарочно взято 7331 — «a canonical write that happens to equal a rule's own
default makes the sensitivity probe blind, and every threshold default in this
tree is far below it», дословно из комментария в файле). Для bool это
компромисс не сделан: любой ключ, чей реальный дефолт `true` (частый случай —
`enabled: true` у большинства правил), структурно слеп.

**«Сколько из этих строк имеют дефолт `false`»: 0, и это не эмпирика, а
следствие построения.** Слепота (`omitted == equivalent`) в принципе может
сработать только когда канонический write (всегда `true`) ТЕКСТУАЛЬНО совпал с
дефолтом — то есть дефолт обязан быть `true`. Строка с дефолтом `false`
физически не может попасть в этот блокирующий вердикт (запись `true` тогда
отличима от умолчания `false`). Значит **весь класс слепоты на 100% лечится
контр-дефолтным пробником**: для ключей с дефолтом `true` тестировать канонической
записью `false` (а не только `true`) — тогда наблюдение вновь различает «ключ
подействовал» от «ключ проигнорирован». Это не гипотеза — это прямое следствие
условия входа в вердикт, проверенное по коду `Classifier.php:118-120` и по
самому `forms.tsv`.

**ЦЕНА ЛЕЧЕНИЯ.** Не тривиальна: сейчас `forms.tsv` даёт РОВНО ОДНУ запись на
форму для всей сетки (докблок: «forms are eight and they are listed by
name»). Контрдефолтный `bool` потребовал бы знать дефолт КАЖДОГО ключа заранее
(а дефолты как раз то, что стенд не должен подглядывать в исходники правил) —
то есть лечение требует новой процедуры выбора канонического значения
ПО КЛЮЧУ, а не общего словаря на 8 форм, и это меняет архитектуру `Declarations`/
`Stand.php`, а не одну строку.

**СЦЕПЛЕННОСТЬ.** Независим от прочих (свой механизм в `forms.tsv`), но
методологически близок хвосту 8 (тоже про `bool`), хотя причина другая: здесь
— совпадение канонического значения с дефолтом; там — отсутствие ветки вовсе.

---

## 8. `computed_metrics.<name>.enabled` слепа на всех формах

**Причина по коду** — не совпадение (как в п.7), а **отсутствие ветки**:
`ComputedMetricsConfigResolver::applyEntry()`
(`src/Analysis/Evidence/ComputedMetrics/ComputedMetricsConfigResolver.php:111-129`)
имеет РОВНО одно условие на `enabled` — строка 118:
`if (isset($overrides['enabled']) && $overrides['enabled'] === false) { ...
disable...; return; }`. Ветки на `=== true` нет вообще: и `enabled: true`, и
отсутствие ключа приводят к ОДНОМУ И ТОМУ ЖЕ коду (шаг 5, `merge()`/`create()`).
Слепота не зависит от того, КАКОЕ конкретно значение записано (в отличие от
хвоста 7) — сравнение текста здесь даже не нужно, потому что код принципиально
не читает значение `true`.

**Какое ДРУГОЕ наблюдаемое сделало бы строку зрячей** (не другое написание
значения — другое НАБЛЮДАЕМОЕ): единственный работающий канал — `enabled:
false`, который переводит метрику в отключённое состояние. Чтобы увидеть эффект
`enabled: true`, нужно наблюдать не «значение против отсутствия ключа» (ось A,
одна запись), а **«значение против УЖЕ ОТКЛЮЧЁННОГО состояния»** — т.е. пара
источников (ось B, `pair`): сначала запись, где метрика выключена (через
`enabled: false` в одном источнике или через дефолтное выключение), затем
дописать `enabled: true` во втором и проверить, что метрика ПОЯВИЛАСЬ в выводе
(присутствие/отсутствие канала в отчёте, а не текст значения ключа). Это другое
наблюдаемое — «метрика присутствует в выводе check» вместо «текст записанного
значения», и оно требует coexistence-пробника, а не form-пробника.

**ЦЕНА ЛЕЧЕНИЯ.** Нужен новый `pair`-кейс в реестре и в `Classifier::pair()`
специально под координату «disabled-then-enabled», плюс наблюдаемое
«присутствие метрики в отчёте» (сейчас `Stand.php` не читает список метрик из
отчёта как отдельный сигнал в этой части сетки — нужно проверить отдельно, не
проверялось в этом заходе, ЦЕНА измерена не полностью).

**СЦЕПЛЕННОСТЬ.** Связан с хвостом 4: и `enabled`, и остальные 9 ключей —
одна декларация (`ComputedMetricEntryKeys`), но `enabled` физически обрабатывается
в ДРУГОМ классе (`ComputedMetricsConfigResolver`, не `ComputedMetricOverrideReader`)
— лечится отдельным пакетом.

---

## 9. Подавление CBO: конфиг-хаб / `Reporting\Formatter` vs `Evidence\Maintainability`

**qmx.yaml**, `coupling.cbo:namespace` под `suppress_namespace_channels`
(`qmx.yaml:1405-1462`):
- `'[Q]ualimetrix\Analysis\Configuration'` (строка 1432) — «configuration hub»,
  комментарий строк 1416-1431: «union 16 against an inclusive 16»; ПОСТОЯННОЕ
  подавление канала `coupling.cbo:namespace` — комментарий прямо говорит «Exact
  channel scope keeps declaration-level class CBO... measured», т.е. только
  ЭТОТ конкретный (namespace-level) канал гасится НАВСЕГДА, без порога/ратчета.
- `'[Q]ualimetrix\Reporting\Formatter'` (строка 1447) — тот же механизм,
  тот же комментарий-паттерн («union 16 against an inclusive 16», «No
  refactoring is offered here for the same reason as the entry above»).

**qmx-baseline.json** (v13, 148 записей), запись:
```json
"ns:Qualimetrix\\Analysis\\Evidence\\Maintainability": [
  {"channel": "coupling.cbo", "magnitudes": [16]}
]
```
— это НЕ suppress-запись из `qmx.yaml`, это отдельная РАТЧЕТ-запись в
baseline: находка ИЗМЕРЯЕТСЯ каждый прогон, и принимается ровно до потолка 16;
превышение красит сборку, а уменьшение возможности регенерации ratchет вниз.
Проверено: `ns:Qualimetrix\Analysis\Configuration` и `ns:Qualimetrix\Reporting\Formatter`
(без вложенных под-неймспейсов) **отсутствуют** в `qmx-baseline.json` вообще —
только их под-пространства (`Configuration\Pipeline\Stage`, `Formatter\Health`,
`Formatter\Html`, `Formatter\Json`) присутствуют, и не по каналу `coupling.cbo`
namespace-уровня, а по другим каналам/классам.

**В чём расхождение обращения:**
- Config-хаб и `Reporting\Formatter`: канал CBO для namespace **выключен
  насовсем** через `qmx.yaml` — не измеряется, не виден даже как «подавлено N»
  в обычном смысле ratchet (это `suppress_namespace_channels`, отдельный от
  baseline механизм подавления), нет потолка, нет пути к «стало лучше —
  ratchet вниз».
- `Evidence\Maintainability`: канал **измеряется каждый раз**, принят до
  фиксированного потолка (16) через `qmx-baseline.json` — тот же по форме
  дефект (namespace CBO из-за широкого набора клиентов), но здесь это
  ВРЕМЕННО принятый долг с явной цифрой, а не постоянно выключенный сигнал.

Задание упоминает «конфиг-хаб с 80 зависимыми» — эта конкретная цифра «80» не
найдена нигде в `qmx.yaml`/`qmx-baseline.json`/тексте рядом с записью (в
комментариях фигурируют 14→15, 16, но не 80); пересчитать её напрямую по коду
в рамках этого захода не пытался (потребовал бы прогона `bin/qmx check src/`
на реальном дереве, не на фикстуре — вне разрешённого объёма коротких прогонов
на собственных фикстурах).

**ЦЕНА ЛЕЧЕНИЯ.** Два разных решения по устранению расхождения:
(а) снять постоянное подавление конфиг-хаба/Formatter и перевести на baseline-
ратчет (как Maintainability) — правка `qmx.yaml` (удаление 2 строк списка) +
`qmx-baseline.json` (добавление 2 записей с измеренным потолком);
(б) обратное — если Maintainability тоже «дизайн, а не дефект» (по аналогии с
комментариями у config-хаба/Formatter — «Core value objects are coupling
magnets by design»), перевести её тоже в постоянное подавление. Дороже —
согласование, какая трактовка верна по существу (это открытый архитектурный
вопрос, не техническая правка).

**СЦЕПЛЕННОСТЬ.** Независим от 1-8, но решение требует разбора вопроса
"почему одинаковая форма дефекта получила два разных режима governance" — то
есть не техническое лечение, а решение по существу (какое из двух — норма).
