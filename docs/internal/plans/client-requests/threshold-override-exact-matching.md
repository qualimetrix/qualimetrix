# План: `@qmx-threshold` по точному имени правила + громкий отказ + унификация матчеров

**Статус:** исполнен 2026-08-19
**Дата написания:** 2026-08-16
**Порядок:** исполнялся после `channel-identity-substrate.md`

> **Как читать этот документ.** Он переписан 2026-08-19 по факту. Подложка
> `channel-identity-substrate.md` (PR #14, `f9039a45`) приземлилась раньше и
> закрыла бо́льшую часть замысла — включая то, что план числил за собой.
> Ниже разделено: что закрыла подложка, что осталось этому пакету, и что было
> отвергнуто. Утверждения о коде пересняты из дерева, а не унаследованы из
> редакции 2026-08-16.

**Область по факту:** `src/Analysis/Finding/Contract/Threshold/ThresholdOverride.php`,
`src/Analysis/Policy/Inline/`, `src/Core/Util/NamespaceMatcher.php`, четыре потребителя
inline-матчера, доки.

---

## 1. Предпосылки

Две директивы адресуют разные сущности, и до подложки это порождало два дефекта.

- `@qmx-ignore` адресует **канал** (violationCode).
- `@qmx-threshold` адресует **правило** (имя правила).

**Дефект 1 (подсказка врала).** Отчёт печатает `coupling.cbo.class`, автор копирует
это в `@qmx-threshold coupling.cbo.class`, а prefix-матчер принимал `coupling.cbo`.
Обратный префикс не срабатывал — директива уходила в тихий no-op.

**Дефект 2 (prefix и `*` — футган).** Prefix-семантика означала, что
`@qmx-threshold coupling 15` молча переставляет пороги всем coupling-правилам,
а `@qmx-threshold * 15` — всему проекту.

### Перечислительный пин: правила с `violationCode ≠ ruleName`

Таблица **переснята из кода 2026-08-19** скриптом: обход всех конкретных классов
`src/`, вызов `channelDeclarations()`, отбор ключей `ruleName#violationCode`, где
половины различаются. Проверено 41 конкретное правило-объявитель. Результат
совпал с редакцией 2026-08-16 построчно — шесть правил.

| Правило                 | Коды                             | Множество                | Механизм override                                     |
| ----------------------- | -------------------------------- | ------------------------ | ----------------------------------------------------- |
| `coupling.cbo`          | `.class`, `.namespace`           | уровни (Class+Namespace) | `withOverride` на уровне, уровень различается subject |
| `coupling.instability`  | `.class`, `.namespace`           | уровни                   | то же                                                 |
| `complexity.cyclomatic` | `.callable`, `.class`            | уровни (Callable+Class)  | то же                                                 |
| `complexity.cognitive`  | `.callable`, `.class`            | уровни                   | то же                                                 |
| `complexity.npath`      | `.callable`, `.class`            | уровни                   | то же                                                 |
| `design.type-coverage`  | `.param`, `.return`, `.property` | измерения (НЕ уровни)    | `withOverride` uniform по трём измерениям             |

**Контрпример, на котором стоит всё обоснование.** `design.type-coverage` —
неиерархическое правило с тремя кодами, а `withOverride` ослабляет все три
измерения единообразно: per-channel плумбинга нет. Значит `.param`/`.return`/`.property`
не являются отдельными единицами override, и правило «exact по violationCode»
сломало бы сегодня работающий `@qmx-threshold design.type-coverage 90`. Отсюда:
exact **по имени правила**, а не по violationCode.

Уровень различается subject'ом, а не суффиксом: оверрайд берётся по имени правила
и применяется к тем уровне-опциям, чей subject совпал. Аннотация на методе
ослабляет callable-уровень, на классе — class-уровень. Namespace-уровень инлайн
недостижим (subject — агрегат неймспейса) и настраивается в `qmx.yaml`.

## 2. Аргументы

**Разделение по намерению директивы, а не по единому правилу.**
`@qmx-ignore X` — операция «убери из вывода»: групповая адресация осмысленна.
`@qmx-threshold X 15` — конфигурация конкретного правила: `X` обязано именовать
ровно одно правило, потому что порог принадлежит единственному объекту опций.
«Сбрось порог всему, что под этим именем» никогда не было директивой, которая
может значить одно.

Словари остаются разными — это структурно: override работает на гранулярности
правила, suppression — на гранулярности канала. Мост между ними даёт «did you
mean», а не выравнивание словарей.

**Конфигурационная ошибка — не долг кода.** Её нельзя принять в baseline;
ADR 0017 исключает `annotation.*`. Отсюда `ChannelAcceptability::ConfigurationError`
и `Severity::Error`, негейтируемые `fail_on`.

## 3. Что закрыла подложка (не этим пакетом)

1. **Exact по имени правила.** `ThresholdOverride::matches()` — равенство строк;
   ни `X.*`, ни голый префикс, ни одинокая `*` матчем не считаются.
2. **Громкий отказ.** `annotation.unresolved-directive`,
   `annotation.unsupported-threshold`, `annotation.invalid-threshold` объявлены
   `ChannelAcceptability::ConfigurationError` и эмитятся с `Severity::Error`.
3. **«Did you mean».** Реализовано в `DirectiveNameHints` обратным запросом к
   `ChannelIdentityInterface`, а не отрезанием суффикса от введённой строки.
   Для threshold: `producerOf($name)` отвечает «`X` — канал правила `Y`;
   threshold адресует правило», иначе — ближайшие по Левенштейну имена правил.
4. **`@qmx-ignore`.** Прежняя редакция плана оставляла подавление на prefix.
   Подложка отменила и решение, и обоснование: подавление требует полного имени
   канала, групповая адресация выражается явной звездой `X.*`. Пункт исполнен
   подложкой; из этого плана удалён, чтобы два документа не предписывали разное.
5. **Реестр.** План предполагал `allRules()` + `RuleChannelRegistryInterface` и
   новый DI-edge. Подложка ввела единый `ChannelIdentityInterface`
   (`ruleNames()`, `hasRule()`, `channels()`, `producerOf()`,
   `supportsThresholdOverride()`, `expand()`), который отвечает на оба вопроса.
   Отдельный реестр каналов и новый edge не понадобились.
6. **Место кода.** `AnalysisPipeline::buildUnsupportedOverrideViolations()` /
   `buildDiagnosticViolations()` и `src/Core/Util/RuleMatcher.php` больше не
   существуют. Диагностики живут в `Analysis\Policy\Inline\Directive\`
   (`InlineDirectiveRule` эмитит, `DirectiveAddressability` решает,
   `DirectiveNameHints` подсказывает).

## 4. Что сделал этот пакет

1. **Унификация namespace-матчеров.** Четыре inline-копии boundary-aware
   prefix заменены на `NamespaceMatcher::matchesSingle()`:
   `Reporting\Filter\ViolationFilter`,
   `Analysis\Evidence\Coupling\DistanceRule`,
   `…\ComputedMetrics\Health\Offender\WorstOffenderBuilder`,
   `…\ComputedMetrics\Health\Contract\DrillDown\HealthScoreDrillDown`.
2. **Манифест.** Четыре новых exact-импорта `Core\Util\NamespaceMatcher`
   заведены в `docs/internal/modular-architecture-manifest.json`; выбывший
   импорт `Core\Symbol\SymbolInfo` из `WorstOffenderBuilder` снят. Генерируемые
   артефакты перегенерированы.
3. **Тесты DoD.** Добавлены пины на контрольные кейсы: `design.type-coverage`
   принимается, `design.type-coverage.param` даёт did-you-mean на правило,
   голое `coupling` отвергается; в `ViolationFilterTest` — на два поведенческих
   расхождения замены матчеров.

**Поведенческие расхождения замены (не рефакторинг):**

- **Glob.** `matchesSingle()` включает glob-режим (`*`, `?`, `[` → `fnmatch`),
  которого у inline-копий не было. `--namespace='App\*\Order'` раньше был
  литеральным префиксом и не матчил ничего; теперь это паттерн.
- **Пустой селектор.** Пустая строка раньше матчила глобальный неймспейс
  (`'' === ''`), теперь не матчит ничего. Селектор, не именующий неймспейс,
  ничего не выбирает.

`DistanceRule` сохраняет `rtrim($prefix, '\\')` на месте вызова: `matchesSingle()`
нормализацию не делает и оставляет её вызывающему.

## 5. Отвергнуто

- **Exact по violationCode** — ломает `design.type-coverage` (см. §1).
- **Per-level адресация суффиксом** (`coupling.cbo.namespace`,
  `complexity.cyclomatic.callable`) — отдельная возможность, в этот план не
  входит; суффиксы уровня дают did-you-mean на родительское имя правила.
- **Glob-режим для идентичности канала** — отвергнут подложкой; groups
  выражаются явной `X.*`.

## 6. Замечание, остающееся в силе

Словарь violationCode не замкнут кодовой базой: `computed.*` берёт код из
пользовательского конфига. Поэтому «did you mean» строится от реестра
правил/каналов резолвленной конфигурации, а не от «множества всех кодов» —
и проверка идёт **после** резолва конфигурации.
