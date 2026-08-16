# План: `@qmx-threshold` по точному имени правила (адресуемой единице override) + громкий отказ на ошибке аннотации + унификация матчеров

**Статус:** предложение, перед ревью
**Дата:** 2026-08-16
**Область:** `src/Analysis/Run/Pipeline/AnalysisPipeline.php`, `src/Analysis/Finding/Contract/Rule/`, `src/Analysis/Policy/Inline/`, `src/Core/Util/`, доки

---

## 1. Предпосылки

Две директивы матчатся по-разному, и это порождает два реальных дефекта.

**Словари рассинхронизированы:**

- `@qmx-ignore` матчится по **violationCode** (`SuppressionFilter.php:78` →
  `Suppression::matches($violationCode)` → `RuleMatcher`, prefix). Значит
  `@qmx-ignore coupling.cbo.class` работает точечно, а `@qmx-ignore coupling.cbo`
  префиксом покрывает `.class` и `.namespace`.
- `@qmx-threshold` матчится по **имени правила** (`AbstractRule::getEffectiveOptions()`
  (`AbstractRule.php:58`) → `$this->getName()` → `ThresholdOverride::matches()`
  (`ThresholdOverride.php:45-52`) → `RuleMatcher`, prefix).

Следствие — **дефект 1 (подсказка врёт):** отчёт печатает `coupling.cbo.class`, агент/человек
копирует это в `@qmx-threshold coupling.cbo.class`, а принимается `coupling.cbo`. Reverse-prefix
не срабатывает, директива уходит в тихий no-op.

**Правил с `violationCode ≠ ruleName` — шесть** (пять иерархических + одно
не-иерархическое). Получено grep'ом `self::NAME . '` по `violationCode`/`channelDeclarations()`
(2026-08-16); таблица — обязательный перечислительный пин, не растёт без правки этого плана.

| Правило                 | Коды                             | Множество                | Механизм override                                                             |
| ----------------------- | -------------------------------- | ------------------------ | ----------------------------------------------------------------------------- |
| `coupling.cbo`          | `.class`, `.namespace`           | уровни (Class+Namespace) | `getEffectiveOptions` → `withOverride` на уровне, матчится subject'ом         |
| `coupling.instability`  | `.class`, `.namespace`           | уровни                   | то же                                                                         |
| `complexity.cyclomatic` | `.callable`, `.class`            | уровни (Callable+Class)  | то же                                                                         |
| `complexity.cognitive`  | `.callable`, `.class`            | уровни                   | то же                                                                         |
| `complexity.npath`      | `.callable`, `.class`            | уровни                   | то же                                                                         |
| `design.type-coverage`  | `.param`, `.return`, `.property` | измерения (НЕ уровни)    | `withOverride` uniform по трём измерениям (`TypeCoverageOptions.php:140-151`) |

Контрпример, ломающий «exact по violationCode»: `design.type-coverage` — неиерархическое
правило с тремя кодами, а `withOverride` ослабляет все три измерения единообразно. Значит
`.param`/`.return`/`.property` не являются отдельными единицами override (per-channel плумбинга
нет), а сегодня работающий `@qmx-threshold design.type-coverage 90` под правилом «exact по
violationCode» стал бы ошибкой.

Уровень, однако, различается subject'ом, а не суффиксом: `getEffectiveOptions` берёт оверрайд
по имени правила и применяет `withOverride` к тем уровне-опциям, чей subject совпал
(`AnalysisContext.php:41`). Аннотация на методе ослабляет callable-уровень, на классе —
class-уровень. Namespace-уровень инлайн недостижим: subject — `MetricSubject::aggregate(namespace)`.

**Prefix у threshold — футган.** `ThresholdOverride::matches()` даёт prefix-семантику, поэтому
`@qmx-threshold coupling 15` молча переставляет пороги **всем** coupling-правилам; `*`
спецкасится как «матч всего» (`ThresholdOverride.php:47-49`), а
`buildUnsupportedOverrideViolations()` (`AnalysisPipeline.php:338`) пропускает `*` мимо
проверки — то есть `@qmx-threshold * 15` молча переставляет пороги всему проекту.

**Ошибки аннотаций тонут.** Диагностики `annotation.unsupported-threshold` и
`annotation.invalid-threshold` эмитятся с жёстко зашитым `Severity::Warning`
(`AnalysisPipeline.php:353` и `:465`). При дефолтном `fail_on: error` опечатка в директиве —
тихий no-op: директива стоит в докблоке, числится в реестре подавлений, выглядит живой и не
делает ничего. Это ровно тот случай, ради которого заведён `qmx-suppressions.txt`
(директива, которая врёт о том, что жива), и он нарушает §6.8 PRODUCT_VISION
(«конфигурационная ошибка ≠ долг кода, сигналится иначе»).

**Матчеры неконсистентны и продублированы:**

- `NamespaceMatcher`/`PathMatcher` (`NamespaceMatcher.php:85-96`, `PathMatcher.php:61-68`) —
  boundary-aware prefix + `fnmatch` на glob-символах.
- `RuleMatcher` (`RuleMatcher.php:24-31`) — только dot-prefix, без glob-режима.
- Четыре inline-копии namespace-prefix: `ViolationFilter.php:88`, `DistanceRule.php:229`,
  `WorstOffenderBuilder.php:121`, `HealthScoreDrillDown.php:236`.

## 2. Аргументы

**Разделение «suppression=фильтр, threshold=конфигурация» — по намерению директивы, а не
по единому правилу.**

- `@qmx-ignore xxx` — операция «убери из вывода». «Игнорируй `complexity`» естественно
  значит «игнорируй семью `complexity.*`»: prefix логичен и безопасен (никакой числовой
  порог не трогается).
- `@qmx-threshold xxx 15` — конфигурация конкретного правила. `xxx` обязано именовать ровно
  одно правило; prefix/`*` — футган, а не фича.

Это разрешает кажущееся противоречие «для неймспейса prefix естественен, для правила нет»:
семантика вложенности неймспейсов (A\B *является* A) — это дерево; у правила вложенности нет,
есть конвенция именования (`coupling.cbo` → `.class`/`.namespace`). Поэтому suppression может
жить на prefix по violationCode, а threshold обязан быть exact **по имени правила** (адресуемой
единице override). Словари остаются разными — это структурно, а не баг: override работает на
гранулярности правила, suppression — на гранулярности канала. Мост между ними даёт «did you
mean», а не выравнивание словарей.

**«Падать на ошибках» — да, но как Error-severity, не как аборт.** Конфигурационную ошибку
нельзя «принять как долг» (baseline/suppress) — ADR 0017 уже исключает `annotation.*` из
этого. Error-severity по умолчанию валит дефолтный `fail_on: error`, сохраняет единую модель
(findings идут через общий pipeline/форматеры), а §6.8 достигается тем, что это отдельный
канал с error, негейтируемый/небазлайнимый.

**Унификация — двух видов, а не «всё в один prefix»:**

1. Примитив матчинга единый: `RuleMatcher` добирает glob-режим до конвенции
   `NamespaceMatcher`/`PathMatcher`; четыре inline `str_starts_with` уходят в
   `NamespaceMatcher::matchesSingle()`.
2. Режим выбирает директива: `@qmx-ignore` → prefix, `@qmx-threshold` → exact.

## 3. Решение

1. **`@qmx-threshold` — exact по имени правила.** Директива именует правило целиком
   (`coupling.cbo`, `design.type-coverage`, `size.loc`). Prefix-паттерн и `*` не считаются
   матчем. Эффект по subject'у: аннотация на методе ослабляет callable-уровень, на классе —
   class-уровень; `design.type-coverage` ослабляет три измерения единообразно. ViolationCode —
   не единица override: `@qmx-threshold coupling.cbo.class` → ошибка «did you mean
   `@qmx-threshold coupling.cbo`?» (закрывает дефект 1). Источник «did you mean» — **оба**
   реестра: `allRules()` (перечислить producers) + `RuleChannelRegistryInterface`
   (для каждого producer сопоставить pattern с его каналами; интерфейс умеет только
   `channelsProducedBy(name)` и не перечисляет producers — см. §4).
2. **Громкий отказ.** `annotation.unsupported-threshold` и `annotation.invalid-threshold`
   эмитятся с `Severity::Error` по умолчанию. В сообщение/`recommendation` добавляется
   принятая форма — «did you mean `@qmx-threshold coupling.cbo`?».
3. **`@qmx-ignore` остаётся на prefix** по violationCode (семья через bare-имя). Glob в общий
   `RuleMatcher` **не добавляется**: он обслуживает и `only_rules`/`disabled_rules`/
   `RuleSelector`/категории/исключения каналов, и `*`/`?`/`[` как glob изменили бы публичную
   семантику всех селекторов без миграции. Prefix уже покрывает семью — glob избыточен и
   рискован.
4. **Унификация матчеров (без glob):** inline namespace-prefix в
   `ViolationFilter`/`DistanceRule`/`WorstOffenderBuilder`/`HealthScoreDrillDown` заменяются
   на `NamespaceMatcher::matchesSingle()`. `RuleMatcher` не трогается (остаётся prefix-only).
5. **Суффиксы уровня — не адресуемые единицы.** `@qmx-threshold coupling.cbo.namespace`,
   `coupling.cbo.class`, `complexity.cyclomatic.callable` → «did you mean `coupling.cbo`?».
   Namespace-уровень к тому же инлайн недостижим (subject — агрегат namespace), настраивается
   в `qmx.yaml`. Per-level адресация суффиксом — отдельная возможность, в этот план не входит.

## 4. Последствия

- Код: `AnalysisPipeline::buildUnsupportedOverrideViolations()` и
  `buildDiagnosticViolations()` (severity Error + did-you-mean), `ThresholdOverride::matches()`
  (exact по имени правила, без prefix/`*`), источник «did you mean» — `allRules()` +
  `RuleChannelRegistryInterface` (новая DI-зависимость pipeline: описать manifest-edge и
  тестовый seam), четыре inline matcher-вызова → `NamespaceMatcher` (`RuleMatcher` без glob).
- Миграция (потребитель): проекты, где `@qmx-threshold coupling 15` / `* 15` /
  `foo.bar.class` сегодня тихо работают или тихо no-op'ят, начнут валить сборку на Error.
  Раздел миграции обязателен: диагностика Error + подсказка «did you mean» должна позволить
  исправить механически, а не вручную.
- Доки: `website/docs/rules/*.md` (семантика `@qmx-threshold` — имя правила), inline-README
  (`src/Analysis/Policy/Inline/README.md`), CHANGELOG (`Breaking` для громкости `annotation.*`
  и для exact-семантики threshold).
- Тесты: `ChannelCoverageTest` (severity `annotation.*`), `ThresholdOverrideIntegrationTest`,
  unit на exact-матч имени правила и «did you mean» (включая `design.type-coverage.param` →
  «did you mean `design.type-coverage`»), `RuleMatcherTest` (glob-режим).
- Dogfooding/ратчет: подъём `annotation.*` до Error проходит по `composer selfcheck`
  (`--fail-on=warning`) и по `qmx-baseline.json`. Если в собственном коде qmx есть сейчас тихо
  no-op'ящие директивы (неизвестное правило/опечатка), после фикса они валят selfcheck. Перед
  мержем — прогон selfcheck и разбор новых `annotation.*`-нарушений; ратчет
  (`qmx-baseline.json`) эти ошибки принимать не должен (ADR 0017 исключает `annotation.*` из
  базлайна) — их чинят, а не принимают.
- Замечание: словарь violationCode не замкнут кодовой базой — `computed.*` берёт
  `violationCode` из пользовательского конфига (`ComputedMetricFindingBuilder.php:38`), а
  `annotation.invalid-threshold.<code>` — суффикс вне `channelDeclarations()`
  (`AnalysisPipeline.php:456`). Поэтому «did you mean» строится от реестра правил/каналов, а
  не от «множества всех кодов».
