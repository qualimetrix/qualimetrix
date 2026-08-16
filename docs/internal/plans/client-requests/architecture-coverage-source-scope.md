# План: source-scope архитектурного покрытия — отдельный канал `architecture.coverage.source`

**Статус:** предложение, перед ревью
**Дата:** 2026-08-16
**Область:** `src/Analysis/Policy/Architecture/LayerViolation/`, конфигурация архитектуры, доки

---

## 1. Предпосылки

Базовое утверждение архитектурного владельца — «каждый мой класс отнесён к слою» —
сегодня не проверяемо как гейт: диагностика `architecture.coverage` смешивает два разных
факта и на дефолтном `fail_on: error` в режиме `coverage: error` валит сборку на чужом
коде.

Проверено по коду:

- `LayerViolationRule::collectEdgeViolations()` (`LayerViolationRule.php:387-439`) идёт по
  всем рёбрам графа зависимостей и для каждого ребра, чей **target** не попал ни в один
  слой, инкрементит `targetEdges` и добавляет FQN цели в «uncovered». Цели рёбер — это в
  том числе vendor-классы (`Symfony\...`, `PHPUnit\...`), которых в `paths:` нет и
  классифицировать которые невозможно.
- `collectClassEvidence()` (`:322-365`) ходит только по `metrics->all(SymbolType::Class_)`
  — то есть **по проанализированным классам из `paths:`**. Это source-половина, но: она
  наполняется только при `$coverageMode !== CoverageMode::Ignore` (`:339`) и затем
  **сливается** с edge-концами (`$coverageState['classes'] += $uncoveredClasses`, `:217`).
  Разделение на выходе есть, но оно не готово как независимый источник.
- `buildCoverageDiagnostic()` (`:604-660`) сводит три числа (`unmatchedSourceEdges`,
  `unmatchedTargetEdges`, `uncoveredClasses`) в один `Violation` под кодом
  `architecture.coverage`, без различения «мой класс вне слоя» и «зависимость уходит в
  vendor».

Итог наблюдения: строгий режим «каждый мой класс отнесён к слою» сегодня физически
недоступен, потому что сводная диагностика тонет в vendor-концах рёбер.

## 2. Аргументы

Рассмотрены три пути.

**A. `exclude_namespaces`-механика — отвергнута.** Исключение убирает неймспейсы из
анализа/репортинга, а vendor-классы и так не анализируются (их нет в `paths:`). Проблема
в том, что диагностика *считает концы рёбер*, которые не попали в слой; `exclude_namespaces`
на это не влияет по построению.

**B. Флаг `coverage_scope: source|full` на существующем канале — отвергнута.** Требует
определять «что есть vendor», хотя вендор-определение не нужно (см. ниже), и прячет два
разных утверждения внутри одного канала, что мешает независимо их гейтить/базлайнить.

**C. Отдельный код диагностики (канал) — принята.** Продукт уже мультиплексирует каналы на
одном правиле (`layer-violation` / `coverage` / `unreachable-layer` /
`potential-shadow` / `empty-template`). Добавление `architecture.coverage.source` —
идиоматичный путь: один анализатор, два канала, независимые baseline/suppress/gate.

Ключевой факт, снимающий возражение B: **«source» не требует определения vendor.**
Source = «класс был обнаружен и проанализирован» = «есть в `paths:`». Разница между
`metrics->all(SymbolType::Class_)` и концами рёбер графа — структурная, правило уже ею
располагает. Vendor = «конец ребра, которого нет в проанализированном множестве»; никакого
словаря не нужно.

## 3. Решение

1. **Собственный режим-гейт `coverage_source: ignore|warn|error`** (дефолт `ignore`),
   независимый от существующего `coverage`. Независимость из §2C означает именно отдельный
   ключ, а не переиспользование `CoverageMode`.
2. **Сбор source-данных перестаёт зависеть от `coverage`.** `$uncoveredClasses` собирается
   всегда, когда `coverage_source != ignore`, а не под `$coverageMode !== Ignore`
   (`LayerViolationRule.php:339`). Это критично: типовой пользователь, страдающий от
   vendor-шума, держит `coverage: ignore`, и без этого новый канал не получил бы данных
   вообще.
3. Диагностика `architecture.coverage.source` — один сводный `Violation` за прогон по
   классам из `paths:`, не попавшим в слои. Vendor-концы рёбер не входят.
4. Существующий `architecture.coverage` остаётся как есть (полная, edge-инклюзивная —
   сценарий «vendor-only-слоя», например `ClickHouseDB\**`).
5. `metricValue` нового канала — доля классов из `paths:`, **не попавших** в слои (0–100,
   один знак после запятой). Канал объявляется `ChannelDeclaration::magnitude(
   WorseDirection::Higher)` (выше = хуже), в отличие от остальных каналов `LayerViolationRule`,
   которые все `occurrence`. Знаменатель — число классов в `paths:`; при нуле классов
   диагностика не эмитится. Откладывание поля = откладывание решения о ратчете (ADR 0017),
   поэтому фиксируется здесь.

Ключ `coverage_source` живёт в конфиге архитектуры рядом с `coverage` (тот же носитель
`ArchitectureConfiguration`), severity прокидывается как у `coverage`. Имя канала
`architecture.coverage.source` зафиксировано. Source-скоуп дефолтом гейта не становится —
дефолт `ignore`.

## 4. Последствия

- Код: `LayerViolationRule` (сбор source-классов вне условия `coverage`, эмиссия нового
  канала с `metricValue`), `LayerViolationOptions`/конфиг архитектуры (ключ `coverage_source`),
  `channelDeclarations()`.
- Доки: `website/docs/rules/architecture.{md,ru.md}`, строка в
  `website/docs/reference/default-thresholds.{md,ru.md}`.
- Тесты: unit на правило (source-канал не считает vendor-концы; данные собираются при
  `coverage: ignore` + `coverage_source: warn`), интеграция на синтетическом корпусе с
  vendor-зависимостью, parity с `debug:layer-assignment`.
- Замечание: существующий `architecture.coverage` печатает счётчики в `message` и не ставит
  `metricValue` (`LayerViolationRule:650-659`). Если гейт покрытия (см. соседний план
  diff-mode) потребует `metricValue`, добавление поля делается здесь же.
