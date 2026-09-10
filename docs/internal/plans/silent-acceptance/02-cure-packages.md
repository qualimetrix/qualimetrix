# Этап 02 — пакеты работ

Решения, формы сигнала, маршруты данных и контракты — в `02-cure.md`. Здесь:
наборы файлов, общий DoD и пакеты P1–P5 (§1–2, продолжение §2 — пакеты P6–P11 —
в `02-cure-packages-producers.md`). Тестовый план, порядок исполнения,
методология перечисления, матрица «место × команда» и остатки раунда — в
`02-cure-packages-verification.md` (§3–8).

## 1. Таблица владения файлами

Один файл — один пакет. Каталог в строке означает «все производственные файлы
каталога, кроме поимённо отданных другому пакету». Набор каждого пакета выведен
из колонки «маршрут» `02-cure.md` §2: файл, который маршрут удлиняет, обязан
быть в наборе. Этой проверкой сюда попали `CheckCommand.php`,
`FindingFilterOrchestrator.php` и `LayerViolationRule.php` в первом раунде и
`OutputConfigurator.php`, `PreparedRun.php`, `RuleProducerPreparation.php`,
`LayerEvidence.php` — во втором, когда каждое звено было прочитано по коду.

| пакет   | набор файлов (производственный)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **P1**  | `src/Infrastructure/Console/RuleInputValidator.php` · `src/Infrastructure/Console/RuntimeLoggerConfigurator.php` · `src/Infrastructure/Cache/CacheConfigurationResolver.php` · `src/Infrastructure/Cache/Contract/CacheConfiguration.php`                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| **P2**  | `src/Reporting/Formatter/FormatOptionKeysInterface.php` (новый) · `FormatterRegistry.php` · `FormatterRegistryInterface.php` · `Json/JsonFormatter.php` · `Summary/SummaryFormatter.php` · `Html/HtmlFormatter.php` · `Health/HealthTextFormatter.php` · `src/Infrastructure/Console/FormatterContextFactory.php` (пересечение с P10) · **`src/Infrastructure/DependencyInjection/Configurator/OutputConfigurator.php`** (`:333`, пересечение с P7)                                                                                                                                                                                                                          |
| **P3**  | `src/Infrastructure/Console/ResultPresenter.php` (пересечение с P10) · `src/Infrastructure/Console/DrillDownBinding.php` (новый)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| **P4**  | `src/Reporting/GraphProjection/DotExporter.php` · `JsonGraphExporter.php` · `DependencyGraphProjector.php` · `GraphProjection/Contract/**` · общий матчер неймспейсов `GraphProjection` (новый) · `src/Infrastructure/Console/Command/GraphExportCommand.php`                                                                                                                                                                                                                                                                                                                                                                                                                |
| **P5**  | `src/Infrastructure/Console/LayerAssignmentResolver.php` · `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| **P6**  | `src/Analysis/Run/Contract/Configuration/RunConfiguration.php` · `src/Analysis/Run/Configuration/RunConfigurationResolver.php` · `src/Analysis/Run/Discovery/**` (включая новый счётчик привязки exclude) · `src/Analysis/Run/Pipeline/AnalysisPipeline.php` · **`src/Analysis/Run/Pipeline/PreparedRun.php`** · **`src/Analysis/Run/RuleProducerPreparation.php`** · **`src/Infrastructure/Console/Command/CheckCommand.php`** · новый продюсер `discovery.configuration` под `src/Analysis/Run/` · `src/Infrastructure/DependencyInjection/Configurator/AnalysisConfigurator.php` · `finding-gate/cases/<discovery>/**` · `website/docs/rules/*.md` (строки своего канала) |
| **P7**  | новый продюсер `suppression.configuration` (класс правила + Options + коллаборатор) под `src/Analysis/Finding/` · **`src/Infrastructure/Console/FindingFilterOrchestrator.php`** · **`src/Infrastructure/DependencyInjection/Configurator/OutputConfigurator.php`** (`:355`, пересечение с P2) · `src/Infrastructure/DependencyInjection/Configurator/FindingConfigurator.php` · `finding-gate/cases/<suppression>/**` · `website/docs/rules/*.md` (строки своих каналов)                                                                                                                                                                                                    |
| **P8**  | `src/Analysis/Policy/Architecture/Layer/MembershipResult.php` · `LayerDefinition.php` · `LayerRegistry.php` · `src/Analysis/Policy/Architecture/LayerViolation/LayerEvidenceCollector.php` · **`LayerEvidence.php`** · **`LayerViolationRule.php`** · `LayerViolationOptions.php` · `DeclaredLayerReachability.php` · `LayerDeclarationValidator.php` · `src/Analysis/Policy/Architecture/Contract/LayerPolicyPreparationInterface.php` · `finding-gate/cases/<architecture>/**` · `website/docs/rules/architecture*.md`                                                                                                                                                     |
| **P9**  | `src/Analysis/Evidence/Coupling/CouplingAnalysis.php` · `src/Analysis/Evidence/Coupling/*Rule.php` и его Options · `Coupling/Contract/**` · `src/Infrastructure/DependencyInjection/Configurator/CouplingConfigurator.php` · `finding-gate/cases/<coupling>/**` · `website/docs/rules/coupling*.md`                                                                                                                                                                                                                                                                                                                                                                          |
| **P10** | `src/Infrastructure/Git/ReportingGitScopeQuery.php` · `src/Reporting/FindingProjection/Contract/GitScopeResult.php` · `src/Reporting/FindingProjection/FindingProjector.php` · `FindingProjectionResult.php` · `src/Reporting/FormatterContext.php` · `src/Reporting/Formatter/Summary/HintRenderer.php` · плюс два файла в общем владении (см. ниже)                                                                                                                                                                                                                                                                                                                        |
| **P11** | `docs/internal/architecture-manifest*` и всё, что генерируют `composer architecture:generate` / `check:artifacts` · `scripts/generate-modular-architecture-production-inventory.php` · `qmx.yaml` · `qmx-baseline.json` · `mkdocs.yml` · `src/Infrastructure/Console/CheckCommandDefinition.php` · `finding-gate/declared-delta.tsv` · `finding-gate/declared-delta/**` · `CHANGELOG.md`                                                                                                                                                                                                                                                                                     |

### Поимённые пересечения

Непоимённое пересечение — дефект, а не третий случай. Их ровно семь, и каждое
**упорядочено**: одновременная правка одного файла двумя ветками — это и есть
непоимённое пересечение, поэтому у общего файла всегда есть первый и второй.

| файл                                                        | пакеты                                                                               | причина, названная в обоих                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CheckCommandDefinition.php`                                | **P11** единолично                                                                   | Тексты `--help` пяти дверей меняются одним изменением; пакеты формулируют строку помощи в отчёте, P11 вносит                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `DependencyInjection/Configurator/OutputConfigurator.php`   | P2 (`:333`), затем **P7** (`:355`)                                                   | Оба добавляют аргумент в существующую регистрацию: P2 даёт `FormatterContextFactory` реестр форматтеров (сегодня она зарегистрирована **без аргументов**), P7 — оркестратору продюсера. Без этой строки ни одна из двух новых зависимостей до объекта не доезжает (`02-cure.md` §4.2, §4.3). Отсюда **P7 идёт после P2**; цена — P7 покидает тир P6–P9. **Отвергнуто:** «правки в разных блоках одного файла можно вести параллельно» — прецедента в этой таблице нет, а разрешение сделало бы порядок необязательным для всех восьми строк |
| `Infrastructure/Console/FormatterContextFactory.php`        | P2, затем **P10**                                                                    | P2 добавляет отказ по реестру ключей, P10 проводит через тот же `create()` число привязок git-области (`02-cure.md` §2, маршрут Б13). Файл один, поэтому **P10 идёт после P2**                                                                                                                                                                                                                                                                                                                                                              |
| `Infrastructure/Console/ResultPresenter.php`                | P3, затем **P10**                                                                    | P3 сажает отказ Б7/Б8 до построения отчёта, P10 добавляет одну передачу числа в фабрику контекста. **P10 идёт после P3**. `DrillDownBinding` — сравнитель без состояния, конструируется presenter'ом на месте: строки DI он не требует, и P3 в `OutputConfigurator.php` не заходит                                                                                                                                                                                                                                                          |
| `DependencyInjection/Configurator/*.php` (прочие)           | P6 (`AnalysisConfigurator`), P7 (`FindingConfigurator`), P9 (`CouplingConfigurator`) | Разные файлы одного каталога; каталог целиком никому не принадлежит                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| `website/docs/rules/*.md`, `CHANGELOG.md`                   | P6–P9 пишут только строки своего канала; сведение и вычитка — **P11**                | Документация одного канала — часть его пакета; общая структура и changelog — сведение                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `mkdocs.yml`, `qmx.yaml`, `finding-gate/declared-delta.tsv` | **P11** единолично                                                                   | Новая группа правил требует страницы в nav, иначе `docs:check --strict` красный; новый класс требует строки в своём слое, иначе `architecture.coverage-gap` красит `check:self`; строка декларации требует рукописной причины. Пять пакетов, правящих три общих файла, — то самое непоимённое пересечение; пакеты отдают строки в отчёте                                                                                                                                                                                                    |

**Прежней зависимости P7 → P6 больше нет.** Она держалась на общем
`AnalysisPipeline.php`; после разбора маршрутов (`02-cure.md` §4.3) P7 работает
на шве `FindingFilterOrchestrator`, а `AnalysisPipeline.php` целиком принадлежит
P6. Появилась другая — **P7 после P2**, по строке DI (см. таблицу выше).

### Тестовые наборы

Тест следует своему предмету (ADR 0022). Каждый пакет владеет
`tests/**`-зеркалом своего производственного набора. Свип по чужим фикстурам,
которые лечение может покрасить, распределён поимённо:

| пакет | свип                                                   | знаменатель                                                                      |
| ----- | ------------------------------------------------------ | -------------------------------------------------------------------------------- |
| P7    | фикстуры с `suppress_paths`/`suppress_namespaces`      | 28 файлов                                                                        |
| P8    | тесты `LayerRegistry`/`LayerEvidence`/назначения слоёв | снимается пакетом тем же `grep -rln`; знаменатель прошлой редакцией не измерялся |
Знаменатель снят `grep -rln` по `tests/`; это число файлов к просмотру, а не
число падений. 16 файлов у P6 — тестовые вызовы `new RunConfiguration(`: поле
добавляется в конструктор, и позиционные вызовы в тестах ломаются по арности.
Это ожидаемое красное, а не регрессия.

### Гейт и `declared-delta.tsv` — DoD пакетов P6–P10

`finding-gate/README.md`: новый канал требует фикстуры в кейсе своего семейства
**и** строки в `channels` этого кейса; структурное изменение сравниваемой
поверхности требует строки в `declared-delta.tsv` (`surface`, `file`, `reason`)
плюс точного диффа в `declared-delta/`, иначе гейт даёт `delta-mismatch`. Причина
рукописная — `--derive-declared-delta` переносит существующие и пишет `?` для
новой, а загрузка `?` отвергает.

Прежняя редакция DoD принимала «красный гейт, объяснённый прозой». Это заменено
машинной проверкой, согласованной с приёмкой раунда (`03-acceptance.md`:
«красное объясняется целиком и только объявленным»):

1. **Первым действием пакета** — дешёвый пробник: фикстура плюс строка
   `channels`, прогон `composer gate -- --reference=<коммит начала пакета>`,
   класс отказа прочитан. Это измерение, а не предположение.
2. Пакет **отдаёт в отчёте строки `declared-delta.tsv`**: поверхность, файл и
   причину — по одной на каждое расхождение, которое он породил. Вносит их P11;
   до этого гейт красный, и это ожидаемое состояние, названное здесь.
3. Расхождение на поверхности, которую пакет не объявил, — **провал DoD**, а не
   повод дописать причину задним числом.
4. `PARTIAL` не засчитывается ни в каком случае.

P10 попадает в этот же режим, хотя канала не вводит: он меняет текст подсказки
`format:summary`, а это сравниваемая поверхность. Прежний DoD «`composer gate`
зелёный» для него недостижим по той же причине, что и для P6–P9.

## 2. Пакеты

Общий DoD, обязательный для всех: `composer check:code` зелёный на своём наборе;
прогоны с `--fail-on=none` и кодом возврата без пайпа; отчёт называет дельту
манифеста, строки `declared-delta.tsv` и фактическое число новых находок.

**Красное дерево — общая строка пяти пакетов.** Новый production-класс вводят
**P2, P3, P4, P6, P7**; P8 и P9 нового класса не вводят (канал живёт на
существующем продюсере, severity — в существующих Options), поэтому их эта строка
не касается и N для них равно нулю. До P11 у каждого из пяти дерево красное по
`composer architecture:check`, `check:artifacts` и `check:self`, потому что класс
не внесён ни в манифест, ни в поимённые паттерны слоёв `qmx.yaml`, а
`architecture.coverage-gap` объявлен конфигурационной ошибкой и даёт **exit 2
безусловно, даже под `--fail-on=none`** (`02-cure.md` §7). Отсюда два следствия,
обязательных для DoD каждого из пяти:

- проверка «`--fail-on=none` даёт exit 0, а не 2» ставится **на фикстуре**, а не
  на `src/`;
- на `src/` пакет гоняет `bin/qmx check src/ --baseline=qmx-baseline.json
  --fail-on=warning`, а не `composer selfcheck` (первая половина которого —
  `architecture:check` — заведомо красная и до второй не доходит), и называет
  результат так: **ровно N находок `architecture.coverage-gap`, называющих новые
  классы пакета и его предшественников, и ничего больше**. **N — не только свои
  классы:** пакет, ветвящийся от уже слитого предшественника, несёт и его
  непокрытые классы, потому что `qmx.yaml` правит только P11. Сегодня это P7
  (свои классы + классы P2) и P10 (классы P2 и P3; своих у него нет).
  Формулировка «N = число новых классов пакета» для них невыполнима по чужой
  причине — ровно ловушка «пакет корректен по своему DoD, а дерево хуже».
  «Ноль» на красном по манифесту дереве недостижимо и критерием не является.

### P1 — три отказа в консольном адаптере (Б2, Н1, Н2)

Зависимости: этап 01. Идёт первым, ни с чем не конфликтует.

- Б2: `RuleInputValidator::validateOptionOwners()` отвергает значение без `=`
  тем же сообщением `Invalid --rule-opt "%s". Expected RULE:OPTION=VALUE.`;
  второй словарь не заводится.
- Н1: `RuntimeLoggerConfigurator::configure()` вместо подмены на `info` бросает
  `ConfigurationRefusal::aboutInput` с перечислением четырёх уровней. Вызов идёт
  из `RuntimeConfigurator::configure():91`, внутри `doExecute`.
- Н2: проверка записываемости каталога кеша **только при
  `CacheConfiguration::$enabled === true`**; отсутствующий каталог создаётся.

**DoD:** три пары «промах/попадание» прогонами; `--no-cache` и
`cache.enabled: false` с незаписываемым путём дают exit 0; Н2 проверен **на всех
четырёх командах**, доходящих до резолвера — `check` (`CheckConfigurationResolvers`),
`baseline:*` (`BaselineRun`), `directives` и `debug:layer-assignment` (оба через
`AnalysisPreflight:62`). Это единственная дверь раунда, закрывающаяся сразу на
нескольких командах.
**Некомпенсировано:** тексты `--help` трёх дверей (P11). Ратчет, гейт и дерево не
двигаются — отказ не порождает находки и нового класса.

### P2 — реестр ключей `--format-opt` (Б1)

Зависимости: P1 (форма отказа проверена). Параллелен P3–P5. **Вводит класс.**
Первый пакет, правящий `OutputConfigurator.php`; P7 идёт после него.

Контракт — `02-cure.md` §4.2. Четыре форматтера объявляют шесть ключей;
`FormatterContextFactory` получает реестр и отвергает ключ, неизвестный всем.
**Звено, которого не было:** фабрика зарегистрирована **без аргументов**
(`OutputConfigurator.php:333`) — без строки DI новая зависимость до неё не
доезжает, и отказ молча не работал бы на `bin/qmx check`.

**DoD:** тест-перечислитель — все вызовы `getOption(`/`options[` в
`src/Reporting/` (без `Template/node_modules`) покрыты объединением объявлений;
чужой-но-настоящий ключ не отказывает; `--all` не отказывает на своём
`violations`; **отказ проверен полным прогоном `bin/qmx check --format-opt=zzz=1`,
а не только юнит-тестом фабрики** — иначе пропуск строки DI невидим; свип
3 тестовых файлов; общая строка красного дерева.
**Некомпенсировано:** значения ключей по-прежнему не проверяются (позиция 107,
`00-overview.md` останавливается на ключах осознанно); дерево красное по
`architecture:check`, `check:artifacts` и `check:self` до P11.

### P3 — привязка `--namespace` / `--class` в `check` (Б7, Б8)

Зависимости: P1. Параллелен P2, P4, P5. **Вводит класс.**

Универсум — декларации прогона, доступные `ResultPresenter` через
`$analysisResult->metrics` и `$analysisResult->namespaceTree`; сравнение — тем же
`NamespaceMatcher::matchesSingle`, каким фильтрует `FindingFilter`, чтобы
проверка и фильтрация не разъехались. Отказ бросается **до** построения отчёта.
`DrillDownBinding` — сравнитель без состояния, presenter конструирует его на
месте: строки DI пакет не добавляет и в `OutputConfigurator.php` не заходит.
**Отвергнуто:** инъекция через конструктор `ResultPresenter`
(`OutputConfigurator.php:342`) — она сделала бы этот файл трёхсторонним и
поставила бы P3 в очередь с P2 и P7 без выигрыша.

**DoD:** «нет такого» и «есть и чист» перестали быть одной строкой;
`FindingFilter` не изменён (фильтр — не место проверки существования); glob-форма
`--namespace` проверяется тем же матчером; отказ проверен полным прогоном
`bin/qmx check --namespace=...`; общая строка красного дерева.
**Некомпенсировано:** `--help` (P11); дерево красное до P11 — новый
`DrillDownBinding` не внесён ни в манифест, ни в слой `qmx.yaml`.

### P4 — привязка `graph:export --namespace` (Б9)

Зависимости: P1. Параллелен P2, P3, P5. **Вводит класс.**

`namespaceMatches()` приватно дублирован в `DotExporter:265` и
`JsonGraphExporter:157`. Проверка в команде стала бы третьей копией и поехала бы
от них молча, поэтому сравнение выносится в один тип `GraphProjection`, оба
экспортёра зовут его, проектор отдаёт число привязок, `GraphExportCommand`
превращает ноль в `ConfigurationRefusal` — конверт `catch` у команды уже стоит
(`:118-127`). **Отвергнуто:** проверка в команде своим сравнением.

**DoD:** обе копии сравнения удалены, тест ловит расхождение отрисовки и
проверки; несуществующий неймспейс → exit 3; существующий → прежний граф
байт-в-байт; общая строка красного дерева.
**Некомпенсировано:** `--exclude-namespace` (Б10) осознанно не лечится; дерево
красное до P11 — новый матчер не внесён ни в манифест, ни в слой `qmx.yaml`.

### P5 — `debug:layer-assignment <FQCN>` (Н3)

Зависимости: P1. Параллелен P2–P4. Нового класса не вводит.

`LayerAssignmentResolver` уже строит множество разобранных классов; отсутствие
аргумента в нём даёт `ConfigurationRefusal`. Реальный класс вне слоёв сохраняет
прежний вывод `(no layer)` + совет — команда перестаёт склеивать два ответа.

**DoD:** пара «несуществующий FQCN → 3 / реальный вне слоёв → 0» прогонами.
**Некомпенсировано:** промахнувшийся `exclude:` в общем `qmx.yaml` под этой
командой **молчит и после раунда** — одна из четырёх клеток остатка покрытия
(`02-cure.md` §5). Это не дефект пакета: у команды нет отчёта о находках.

