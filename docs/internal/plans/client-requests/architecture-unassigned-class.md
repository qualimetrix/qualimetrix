# План: гейт «каждое моё объявление отнесено к слою» — канал `architecture.unassigned-class`

**Статус:** ИСПОЛНЕН
**Дата плана:** 2026-08-16
**Дата исполнения:** 2026-08-19 (после подложки идентичности канала, PR #14, `f9039a45`)

**Область:** `src/Analysis/Policy/Architecture/`, доки правил и справочника порогов

---

## 1. Предпосылки

Базовое утверждение архитектурного владельца — «каждый мой класс отнесён к слою» —
не было проверяемо как гейт: диагностика `architecture.coverage` смешивает два разных
факта и на дефолтном `fail_on: error` в режиме `coverage: error` валит сборку на чужом
коде.

Проверено по коду (состояние на момент исполнения):

- `LayerViolationRule::collectEdgeViolations()` идёт по всем рёбрам графа зависимостей
  и для каждого ребра, чей **target** не попал ни в один слой, инкрементит `targetEdges`
  и добавляет FQN цели в «uncovered». Цели рёбер — это в том числе vendor-классы
  (`Symfony\...`, `PHPUnit\...`), которых в `paths:` нет и классифицировать которые
  невозможно.
- `collectClassEvidence()` ходит только по `metrics->all(SymbolType::Class_)` — то есть
  по проанализированным объявлениям из `paths:`. Это source-половина, но она наполнялась
  только при `$coverageMode !== CoverageMode::Ignore` и затем **сливалась** с
  edge-концами (`$coverageState['classes'] += $uncoveredClasses` в `analyze()`).
- `buildCoverageDiagnostic()` сводит три числа (`unmatchedSourceEdges`,
  `unmatchedTargetEdges`, `uncoveredClasses`) в один `Violation` под кодом
  `architecture.coverage`, без различения «мой класс вне слоя» и «зависимость уходит в
  vendor», и без `metricValue`.

Итог наблюдения: строгий режим «каждый мой класс отнесён к слою» был физически
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
`potential-shadow` / `empty-template`). Добавление `architecture.unassigned-class` —
идиоматичный путь: один анализатор, независимые baseline/suppress/gate.

Ключевой факт, снимающий возражение B: **«source» не требует определения vendor.**
Source = «объявление было обнаружено и проанализировано» = «есть в `paths:`». Разница
между `metrics->all(SymbolType::Class_)` и концами рёбер графа — структурная, правило уже
ею располагает. Vendor = «конец ребра, которого нет в проанализированном множестве»;
никакого словаря не нужно.

## 3. Решение (в том виде, в котором приземлилось)

1. **Собственный режим-гейт `unassigned_class: ignore|warn|error`** (дефолт `ignore`),
   независимый от существующего `coverage`. Отдельный тип `UnassignedClassMode`, а не
   переиспользование `CoverageMode`: у двух ручек разные вопросы, и общий enum
   провоцировал бы общее значение.

2. **Ключ живёт в опциях правила, а не в секции `architecture:`.** Это отличие от
   исходного текста плана, и оно вынужденное: `#[CliAlias]` отображает CLI-флаг на **ключ
   опций правила**, а ключи секции `architecture:` (включая `coverage`) никакой CLI-
   поверхности не имеют вообще. Требование «CLI-алиас работает» выполнимо только на
   стороне опций. Прецедент там же: удалённые `unreachable_layer_severity`,
   `potential_shadow_severity`, `empty_template_severity` были ключами
   `LayerViolationOptions` с алиасами `--layer-violation-<канал>-severity`; `coverage`
   — исключение, а не правило.

3. **Сбор source-данных перестал зависеть от `coverage`.** `collectClassEvidence()`
   принимает булев `$materializeUncovered`, который в `analyze()` вычисляется как
   `coverage !== Ignore || unassigned_class !== Ignore`. Это критично: типовой
   пользователь, страдающий от vendor-шума, держит `coverage: ignore`, и без этого новый
   канал не получил бы данных вообще.

4. Диагностика `architecture.unassigned-class` — один сводный `Violation` за прогон по
   проанализированным объявлениям, не попавшим в слои. Vendor-концы рёбер не входят:
   множество берётся до слияния с edge-состоянием.

5. Существующий `architecture.coverage` остался как есть (полная, edge-инклюзивная —
   сценарий «vendor-only-слоя», например `ClickHouseDB\**`). Его поведение не менялось;
   единственная правка в его тестах — белоящичный вызов `collectClassEvidence()` через
   рефлексию, у которого сменился тип третьего аргумента.

6. **`metricValue` — абсолютное число неотнесённых объявлений, а не доля.** Канал
   объявлен `ChannelDeclaration::magnitude(WorseDirection::Higher)`. Процент уходит в
   текст `message` как справочная величина и в ратчете не участвует.

   **Почему не доля.** ADR 0017, риск №5: «шкала величины может измениться без изменения
   канала… нормализованный по проекту `coupling.class-rank` сознательно сделан
   occurrence-каналом». Доля нормализована по проекту и даёт два отказа. Проект растёт,
   неотнесённые объявления растут пропорционально — процент стоит, потолок не
   пробивается, абсолютное число вне слоёв растёт без ограничения. Зеркально: добавление
   покрытых объявлений опускает процент и ужесточает потолок само собой — это не защита,
   а артефакт знаменателя. Абсолютный счёт монотонен и ложится в идиому ратчета «не хуже
   N», что и позволяет въезжать постепенно.

7. **Множество называется точно.** Считаются **проанализированные class-like
   объявления**: `metrics->all(SymbolType::Class_)` перечисляет то, что записали
   коллекторы, и class-scope открывается на любом `ClassLike` — интерфейсы, трейты и
   енумы тоже. Слепая зона названа явно в докблоке `collectClassEvidence()`: объявление,
   для которого ни один коллектор не записал class-уровневых метрик, в множество не
   попадает и потому считается покрытым. При нуле неотнесённых объявлений (в том числе
   при нуле проанализированных) диагностика не эмитится.

8. **Принимаемость канала — `AcceptableAsDebt`** (дефолт `ChannelDeclaration`). Это
   сознательное отличие от четырёх диагностик, которые подложка перевела в
   `ConfigurationError`. Различие не в предикате, а в роли: `architecture.coverage`
   смешивает в себе vendor-концы рёбер, поэтому принимать его в ратчет значило бы
   фиксировать шум; `architecture.unassigned-class` по умолчанию выключен, это новый
   гейт, который пользователь включает осознанно, и ратчет с постепенным въездом —
   единственный способ посадить его на живую кодовую базу. Именно поэтому §3.6 выбирает
   абсолютный счёт: он монотонен и годится как потолок.

9. **Докблок `channelDeclarations()` переписан.** Прежний текст утверждал, что
   `occurrence` — «единственная форма, которую любая из них может принять», и что это
   «прямое следствие решения ADR 0017 о форме канала, а не суждение». Первое перестало
   быть верным рядом с magnitude-соседом; второе было переатрибуцией — ADR 0017 упоминает
   архитектурные каналы один раз, в риске №11, как наблюдение о последствиях их
   occurrence-формы, а не как решение о ней. Новый текст говорит: форма пяти каналов
   читается с их точек эмиссии (ни одна не передаёт `metricValue`), а форма шестого —
   именно суждение, с названной ценой альтернативы.

**Имя ключа повторяет короткое имя канала, а не `coverage_source`**: §2 отверг вариант B
именно за то, что он прячет два разных утверждения внутри одного канала, и ключ
`coverage_source` втащил бы эту рамку обратно через конфигурацию; существующая конвенция
уже отображает ручку на короткое имя канала. Имя канала `architecture.unassigned-class`
зафиксировано — оно **не** точечный потомок `architecture.coverage`, а после отмены
префиксного матчинга каналов (PR #14) селекторы вида `architecture.coverage` вообще не
могут его задеть. Source-скоуп дефолтом гейта не становится — дефолт `ignore`.

**CLI-алиас — `--layer-violation-unassigned-class`, без хвоста `-severity`.**
CLI_CONVENTIONS задаёт формат `{rule-short-name}[-{level}]-{option}`; удалённый
`--layer-violation-unreachable-layer-severity` нёс хвост `-severity` потому, что его
опция называлась `unreachable_layer_severity`. Наша опция называется `unassigned_class`,
и ключ, и значение — режим, а не severity, поэтому хвост был бы ложью о том, что ставит
флаг.

## 4. Что затронуто фактически

- **Код:**
  - `LayerViolation/UnassignedClassMode.php` (новый enum, без собственного
    `fromString` — парсинг живёт в опциях, иначе `duplication.code-duplication`
    справедливо указывал на копию `CoverageMode::fromString`);
  - `LayerViolation/OutsideLayerSummary.php` (новый класс) — обе сводные
    диагностики за прогон, `architecture.coverage` и
    `architecture.unassigned-class`, плюс объявление magnitude-канала и общее
    форматирование выборки FQN. Вынесены из правила: у них общий предмет («что
    осталось вне объявленных слоёв») и общее форматирование, а правило иначе
    выходит за собственные потолки CBO и WMC;
  - `LayerViolation/LayerViolationOptions.php` — поле `unassignedClass`, парсинг,
    отказ на неизвестном значении и предикат `collectsOutsideLayerEvidence()`;
  - `LayerViolation/LayerViolationRule.php` — константа имени канала,
    `#[CliAlias('layer-violation-unassigned-class', 'unassigned_class')]`,
    `channelDeclarations()` и её докблок, `collectClassEvidence()` (принимает
    `ArchitectureConfiguration` вместо реестра, сам спрашивает опции о
    материализации, возвращает счётчик проанализированных объявлений);
  - `Contract/LayerPolicyPreparationInterface.php` — константа имени канала и
    запись в `PROJECT_SCOPED_CHANNELS` (политика слоёв — утверждение о проекте,
    файловые исключения к ней не применяются);
  - `Analysis/Policy/Architecture/README.md`.
- **Governance:** записи в `docs/internal/modular-architecture-manifest.json` для
  двух новых типов и consumer-записи `Analysis.Policy.Architecture →
  Core\Observation\WorseDirection`, `Finding\Contract\{ChannelDeclaration,
  Location, Severity, Violation}`, `Core\Symbol\{MetricSubject, SymbolPath}`;
  перегенерация `qmx.yaml` и `docs/internal/generated/modular-architecture/**`
  командой `composer architecture:generate`.
- **Доки:** `website/docs/rules/architecture.{md,ru.md}` (раздел гейта, строка в
  таблице опций, правка абзаца о подавлении: каналов стало шесть),
  `website/docs/reference/default-thresholds.{md,ru.md}`,
  `website/docs/usage/cli-options.{md,ru.md}`,
  `src/Analysis/Configuration/README.md` (канонический внутренний список
  CLI-алиасов, который проверяет `DocumentationConsistencyTest`).
- **Тесты:** новый `tests/Analysis/Policy/Architecture/Unit/UnassignedClassDiagnosticsTest.php`;
  дополнения в `LayerViolationOptionsTest`; ожидание алиасов в
  `LayerViolationRuleTest`; фикстура-оракул
  `tests/Analysis/Finding/Fixtures/Channels/declared.txt` и счётчик в
  `ChannelUniverseCoverageTest`; перечисление P4-объявлений в
  `ArchitectureInternalTopologyTest`; один белоящичный вызов в
  `CoverageDiagnosticsTest`.

## 5. Открытое

- **`qmx-baseline.json` требует перегенерации, и это не выбор.** Идентичность
  записи v11 включает байтовое смещение объявления в файле
  (`...LayerViolationRule.php:5154`), поэтому ЛЮБАЯ правка этого файла
  расключает его записи: принятые ранее `coupling.cbo.class` (24) и
  `complexity.cyclomatic.callable` на `collectEdgeViolations` (10) всплывают
  заново с теми же значениями. Само свойство задокументировано в докблоке
  константы `DIAGNOSTIC_SEVERITY` того же класса.
- **`Core\Observation\WorseDirection` растёт на +1 по CBO (27 → 28)** и это
  неустранимо в границах пакета: magnitude-канал обязан назвать
  `WorseDirection::Higher`, а CBO этого enum растёт с каждым легитимным
  потребителем. Либо принять 28 в ратчет, либо поставить `@qmx-threshold` в
  `src/Core/Observation/WorseDirection.php` — файл вне границ этого пакета.
- `architecture.coverage` по-прежнему печатает счётчики в `message` и не ставит
  `metricValue`. Если гейт покрытия (см. соседний план diff-mode) потребует
  `metricValue`, добавление поля делается там же.
