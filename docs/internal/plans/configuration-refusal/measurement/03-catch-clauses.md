# 03/P1. Оракул полноты класса 1: все `catch`-клаузы `src/` и `bin/`

Дерево: `01688709` (после `docs(plans): plan the configuration-refusal round`), состав
`src/` и `bin/` не менялся этим пакетом. Продолжение `03-normalization-verdicts.md` §6 —
здесь исполнены оба его артефакта: этот файл (оракул класса 1) и
`03-throw-sites.md` (места броска моего набора).

## Команда

```
grep -rn 'catch (' src bin --include='*.php' | grep -Ev '^[^:]+:[0-9]+: *(\*|//)'
```

Даёт **76** строк на снятом дереве. Второй `grep` вычитает докблок-строки вида
`Shared by ... to catch (...)`, которые упоминают `catch (` в прозе, а не в коде; без него
команда даёт 77.

**Чем получено и чего этот способ не видит.** Текстовый грep по токену `catch (`:

- не видит `catch` без открывающей скобки на той же строке (в дереве таких нет — весь код
  проекта пишет `} catch (X) {` на одной строке, но способ этого не проверяет, а
  предполагает по стилю);
- не отличает клаузу в мёртвой ветке (недостижимый код после `return`/`throw` внутри своего
  же `try`) от достижимой — в дереве такой нет ни одной, проверено чтением всех 76 контекстов
  ниже, но это ручная, а не машинная гарантия;
- не видит клауз внутри `vendor/` — вне популяции по определению (внешний код, не продукт);
- не считает `finally`-блоки — они не ловят исключение, поэтому не являются кандидатами
  класса 1 и не входят ни в оракул, ни в его пропуск.

## Число «до» (владелец: 03/P1)

`scripts/enumerate-refusal-fallback.php` считает **статически перечислимые броски
`InvalidArgumentException` вне носителя** (`throw new InvalidArgumentException`, `throw new`
подкласса и `throw $var`, где `$var` присвоен такому исключению в той же области), по AST.

**Популяция**: `src/` и `bin/` целиком, **исключая** `tests/`, `scripts/`, `vendor/`.

**Команда**: `php scripts/enumerate-refusal-fallback.php`

**Число «до»**: **178**

(снято на дереве `01688709`, до исполнения любого пакета раунда; полная разбивка по файлам —
в собственной шапке вывода скрипта, см. §6.1 `03-normalization-verdicts.md`: скрипт печатает
и число, и слепые пятна в одном прогоне, чтобы число нельзя было процитировать отдельно от
границ его смысла)

Число «после» этот пакет **не снимает и не обещает** — оно условие приёмки раунда
(`00-overview.md`, «Полнота и остаток»), владелец строки — оркестратор, на переставшем
меняться дереве.

## Таблица

Колонки: `путь:строка` · ловимая семья · что делает с пойманным. Отдельная пометка `[код N]`
— там, где нисходящий поток клаузы даёт код 2 или 4 (их раунд не трогает, `00-overview.md`
«Что раунд обязан не сломать»). Ни одна клауза этого дерева не даёт код 2 напрямую — код 2
(`EXIT_INERT_FOUND` у `directives`) вычисляется из вердиктов после успешного разбора, а не из
пойманного исключения; это отмечено отдельной строкой после таблицы, а не пропущено.

| путь:строка | ловит | что делает |
| --- | --- | --- |
| `src/Analysis/Configuration/Loader/YamlConfigLoader.php:30` | `ParseException` | перезаворачивает в `ConfigLoadException::parseError()`, бросает |
| `src/Analysis/Evidence/ComputedMetrics/ComputedMetricFormulaValidator.php:62` | `SyntaxError` | перезаворачивает в `ComputedMetricConfigurationException`, бросает (вердикт — набор 02) |
| `src/Analysis/Evidence/ComputedMetrics/Contract/Evaluation/ComputedMetricEvaluator.php:83` | `Throwable` | логирует warning, `continue` — глотает, наружу ничего не идёт |
| `src/Analysis/Evidence/ComputedMetrics/Contract/Evaluation/ComputedMetricExpression.php:101` | `SyntaxError` | глотает, `return true` |
| `src/Analysis/Evidence/ComputedMetrics/Contract/Evaluation/ComputedMetricExpression.php:217` | `SyntaxError` | глотает, `return []` |
| `src/Analysis/Evidence/ComputedMetrics/Health/Configuration/WeightedHealthFormula.php:40` | `SyntaxError` | глотает, `return null` |
| `src/Analysis/Evidence/Design/Inheritance/DitGlobalCollector.php:198` | `ReflectionException` | глотает, `return 0` |
| `src/Analysis/Evidence/Design/Inheritance/InheritanceDepthCollector.php:180` | `ReflectionException` | глотает, `return 0` (комментарий: «assume root») |
| `src/Analysis/Finding/Contract/Rule/ChannelDeclarationReader.php:116` | `Throwable` | перезаворачивает в `LogicException`, бросает — программная ошибка правила, не отказ конфигурации |
| `src/Analysis/Finding/Contract/Rule/ChannelDeclarationReader.php:154` | `InvalidArgumentException` | перезаворачивает в `LogicException`, бросает |
| `src/Analysis/Policy/Architecture/Configuration/AllowValidator.php:425` | `InvalidSelectorException` | перезаворачивает в `ArchitectureConfigurationException`, бросает |
| `src/Analysis/Policy/Architecture/Configuration/ArchitectureConfigurationFactory.php:204` | `InvalidArgumentException` | перезаворачивает в `ArchitectureConfigurationException`, бросает |
| `src/Analysis/Policy/Architecture/Configuration/CoverageValidator.php:38` | `InvalidArgumentException` | перезаворачивает в `ArchitectureConfigurationException`, бросает |
| `src/Analysis/Policy/Architecture/Configuration/ExcludeBlockValidator.php:117` | `InvalidArgumentException` | перезаворачивает в `ArchitectureConfigurationException`, бросает |
| `src/Analysis/Policy/Architecture/Configuration/LayersValidator.php:177` | `InvalidArgumentException` | перезаворачивает в `ArchitectureConfigurationException`, бросает |
| `src/Analysis/Policy/Architecture/Configuration/LayersValidator.php:225` | `InvalidLayerDefinitionException \| InvalidArgumentException` | перезаворачивает в `ArchitectureConfigurationException`, бросает (§4.1 — исключение из правила 1, разобрано отдельно) |
| `src/Analysis/Policy/Architecture/Layer/Expansion/LayerInstantiator.php:68` | `InvalidLayerDefinitionException` | перезаворачивает в `ArchitecturePreparationException`, бросает |
| `src/Analysis/Policy/Architecture/Layer/TemplateLayerDefinition.php:141` | `InvalidArgumentException` | перезаворачивает в новый `InvalidArgumentException` с более подробным сообщением, бросает |
| `src/Analysis/Policy/Baseline/BaselineChannelRenamer.php:187` | `JsonException` | перезаворачивает в `ChannelRenameRefusal`, бросает |
| `src/Analysis/Policy/Baseline/BaselineChannelRenamer.php:236` | `BaselineLoadException` | перезаворачивает в `ChannelRenameRefusal`, бросает |
| `src/Analysis/Policy/Baseline/BaselineEntryParser.php:102` | `InvalidArgumentException` | перезаворачивает в `BaselineEntryRejection`, бросает |
| `src/Analysis/Policy/Baseline/BaselineEntryParser.php:172` | `InvalidArgumentException` | перезаворачивает в `BaselineEntryRejection`, бросает |
| `src/Analysis/Policy/Baseline/BaselineEntryParser.php:201` | `InvalidArgumentException` | перезаворачивает в `BaselineEntryRejection`, бросает |
| `src/Analysis/Policy/Baseline/BaselineEntryParser.php:54` | `BaselineEntryRejection` | глотает, строит `InertBaselineEntry::forRaw()` — не выходит наружу |
| `src/Analysis/Policy/Baseline/BaselineEntryParser.php:68` | `BaselineEntryRejection` | глотает, строит `InertBaselineEntry::forIdentity()` — не выходит наружу |
| `src/Analysis/Policy/Baseline/BaselineEntryPayload.php:182` | `InvalidArgumentException` | глотает, `return null` |
| `src/Analysis/Policy/Baseline/BaselineLoader.php:102` | `JsonException` | перезаворачивает в `BaselineLoadException`, бросает |
| `src/Analysis/Policy/Baseline/BaselineUpdater.php:272` | `InvalidArgumentException` | глотает, `return null` (комментарий: NaN/infinity — не граница) |
| `src/Analysis/Policy/Baseline/BoundaryExplanationService.php:217` | `InvalidArgumentException` | глотает, `continue` (не конечное число — не граница) |
| `src/Analysis/Policy/Baseline/CanonicalBaselineReader.php:105` | `RuntimeException` | глотает, `return null` |
| `src/Analysis/Policy/Baseline/CanonicalBaselineReader.php:362` | `JsonException` | глотает, `return self::UNDECODABLE` |
| `src/Analysis/Policy/Baseline/ChannelRenameMap.php:204` | `InvalidArgumentException` | перезаворачивает в `ChannelRenameRefusal`, бросает |
| `src/Analysis/Policy/Baseline/Filter/BaselineCeilingStage.php:333` | `InvalidArgumentException` | глотает, `return null` (NaN/infinity) |
| `src/Analysis/Policy/Baseline/V5BaselineReader.php:101` | `RuntimeException` | глотает, `return false` |
| `src/Analysis/Policy/Baseline/V5BaselineReader.php:134` | `JsonException` | перезаворачивает в `RuntimeException`, бросает |
| `src/Analysis/Policy/Baseline/V5BaselineReader.php:75` | `RuntimeException` | условно: если `!$force` — рестрасс (`throw $e`), иначе глотает и строит пустой `V5Baseline` |
| `src/Analysis/Run/Collection/CollectionOrchestrator.php:151` | `Throwable` | глотает, строит `FileProcessingResult::failure(..., Processing)` — питает `AnalysisCoverage::isComplete()` | `[код 4]` |
| `src/Analysis/Run/Collection/FileProcessor.php:96` | `ParseException` | глотает, строит `FileProcessingResult::failure(..., Parse)` — питает `AnalysisCoverage::isComplete()` | `[код 4]` |
| `src/Analysis/Run/Pipeline/DependencyGraphAnalyzer.php:72` | `ParseException` | глотает, добавляет `AnalysisFailure(..., Parse)` в `$failures`, продолжает цикл | `[код 4 — питает AnalysisCoverage у graph:export]` |
| `src/Analysis/Run/Pipeline/DependencyGraphAnalyzer.php:74` | `Throwable` | глотает, добавляет `AnalysisFailure(..., Processing)` в `$failures`, продолжает цикл | `[код 4 — питает AnalysisCoverage у graph:export]` |
| `src/Core/Path/PathFactory.php:54` | `InvalidArgumentException` | глотает, `return null` |
| `src/Infrastructure/Ast/CachedFileParser.php:73` | `CacheWriteException` | глотает молча (комментарий: «non-critical») |
| `src/Infrastructure/Ast/PhpFileParser.php:82` | `Throwable` | логирует warning, перезаворачивает в `ParseException`, бросает |
| `src/Infrastructure/Cache/FileCache.php:55` | `Throwable` | глотает, `@unlink($path)`, `return null` |
| `src/Infrastructure/Console/Command/BaselineCommand.php:46` | `IncompleteAnalysisException` | `return $this->fail(..., EXIT_ANALYSIS_INCOMPLETE)` | `[код 4]` |
| `src/Infrastructure/Console/Command/BaselineCommand.php:48` | `ConfigLoadException \| ArchitectureConfigurationException` | `return $this->fail(...)` (умолчание — код 1) |
| `src/Infrastructure/Console/Command/BaselineCommand.php:50` | `ArchitecturePreparationException` | `return $this->fail(...)` (код 1) |
| `src/Infrastructure/Console/Command/BaselineCommand.php:52` | `BaselineConflictException` | `return $this->fail(...)` (код 1 — известная дыра таблицы `00-overview.md`) |
| `src/Infrastructure/Console/Command/BaselineCommand.php:54` | `InvalidArgumentException \| RuntimeException` | `return $this->fail(...)` (код 1) — общий вторичный `catch`, названный правилом 1 обзора |
| `src/Infrastructure/Console/Command/BaselineCommand.php:61` | `Throwable` | `return $this->fail('Unexpected error: ...')` (код 1) |
| `src/Infrastructure/Console/Command/BaselineConfiguredThresholds.php:137` | `Throwable` | глотает, `return null` (комментарий: пропуск одной строки вывода `explain`) |
| `src/Infrastructure/Console/Command/BaselineConfiguredThresholds.php:175` | `Throwable` | глотает, `return null` |
| `src/Infrastructure/Console/Command/BaselineExplainCommand.php:153` | `InvalidArgumentException` | пишет `<error>`, `return false` |
| `src/Infrastructure/Console/Command/BaselineRenameChannelsCommand.php:107` | `RuntimeException` | конверт `{error, exit_code}` только для машинного формата (код не 0) |
| `src/Infrastructure/Console/Command/CheckCommand.php:141` | `ConsoleExceptionInterface` (Symfony) | пишет диагностику про устаревшие флаги подавления, поток продолжается — вне популяции носителя (framework exception) |
| `src/Infrastructure/Console/Command/CheckCommand.php:163` | `ConflictingCliAliasException` | пишет диагностику, `return EXIT_CONFIG_ERROR` (мёртвая клауза — бросатель `RuleRegistry.php:59` вне достижимого пути, см. `03-normalization-verdicts.md` §4 «места, которых мой набор не содержит») |
| `src/Infrastructure/Console/Command/CheckCommand.php:172` | `ConfigLoadException \| ArchitectureConfigurationException` | пишет диагностику, `return EXIT_CONFIG_ERROR` (код 3) |
| `src/Infrastructure/Console/Command/CheckCommand.php:179` | `ArchitecturePreparationException` | пишет диагностику, `return EXIT_CONFIG_ERROR` (код 3) |
| `src/Infrastructure/Console/Command/CheckCommand.php:190` | `InvalidArgumentException` | пишет диагностику, `return EXIT_CONFIG_ERROR` (код 3) — общий вторичный `catch`, названный правилом 1 обзора |
| `src/Infrastructure/Console/Command/CheckCommand.php:194` | `ComputedMetricConfigurationException \| BaselineLoadException` | пишет диагностику, `return EXIT_CONFIG_ERROR` (код 3) |
| `src/Infrastructure/Console/Command/CheckCommand.php:201` | `Throwable` | пишет `Unexpected error:`, опционально стек-трейс под `-v` (код 1) |
| `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php:160` | `Exception` (не `Throwable`) | `reportError(...)` через `ConfigurationFailure` — `Error` намеренно не ловится |
| `src/Infrastructure/Console/Command/DirectivesCommand.php:142` | `InvalidArgumentException` | `reportError(..., EXIT_CONFIG_ERROR)` (код 3) |
| `src/Infrastructure/Console/Command/DirectivesCommand.php:158` | `Exception` (не `Throwable`) | если `ConfigurationFailure::message()` не `null` — `EXIT_CONFIG_ERROR`; иначе `Unexpected error`, `self::FAILURE` (код 1) |
| `src/Infrastructure/Console/Command/HookInstallCommand.php:142` | `RuntimeException` | глотает, `continue` |
| `src/Infrastructure/Console/FormatterContextFactory.php:38` | `ValueError` | перезаворачивает в `InvalidArgumentException` с валидным списком значений, бросает |
| `src/Infrastructure/Console/RuntimeLimitsController.php:34` | `Throwable` | перезаворачивает в `RuntimeException`, бросает |
| `src/Infrastructure/Console/ScopeWarningChecker.php:51` | `RuntimeException` | глотает, `continue` (несуществующий путь — уже отдельная ошибка выше по стеку) |
| `src/Infrastructure/Console/ScopeWarningChecker.php:64` | `RuntimeException` | глотает, `continue` (autoload-директория не существует) |
| `src/Infrastructure/Console/ScopeWarningChecker.php:93` | `RuntimeException` | глотает, `$resolvedRoot = null` (переключается на неканонизированное сравнение) |
| `src/Infrastructure/Git/GitClient.php:323` | `ProcessFailedException` | перезаворачивает в `RuntimeException`, бросает |
| `src/Infrastructure/Git/GitRepositoryLocator.php:145` | `RuntimeException \| InvalidArgumentException` | глотает, `return null` |
| `src/Infrastructure/Git/GitRepositoryLocator.php:85` | `RuntimeException \| InvalidArgumentException` | глотает, `return null` |
| `src/Infrastructure/Parallel/Strategy/AmphpParallelStrategy.php:244` | `Throwable` | логирует error, рестрасс (`throw $e`) |
| `src/Infrastructure/Parallel/Strategy/AmphpParallelStrategy.php:305` | `Throwable` | логирует warning, счётчик ошибок, продолжает — не выходит наружу для этого файла |
| `src/Infrastructure/Parallel/Strategy/StrategySelector.php:103` | `RuntimeException` | логирует warning, `return $this->sequentialStrategy` (fallback) |

**76 строк** — совпадает с числом, которое даёт команда выше.

## Код 2 отдельно (не строка таблицы)

Ни одна `catch`-клауза этого дерева не назначает код 2 напрямую. `EXIT_INERT_FOUND = 2`
(`DirectivesCommand.php:59`) назначается в `exitCodeFor()` по вердиктам успешно
разобранного отчёта (`$verdict->effect === DirectiveEffect::Inert`), не из пойманного
исключения — этот путь не проходит ни через один `catch` и поэтому не может быть строкой
оракула. Названо явно, а не пропущено, потому что DoD этого пакета требует отдельной
пометки кода 2 и 4 у клауз, которые их дают, а для кода 2 такой клаузы не существует.

## Клаузы с кодом 4 — список (дублирует пометки таблицы)

- `CollectionOrchestrator.php:151`
- `FileProcessor.php:96`
- `DependencyGraphAnalyzer.php:72`
- `DependencyGraphAnalyzer.php:74`
- `BaselineCommand.php:46` (прямое назначение `EXIT_ANALYSIS_INCOMPLETE`)

Первые четыре не назначают код напрямую: они строят `FileProcessingResult::failure`/
`AnalysisFailure`, из которых собирается `AnalysisCoverage`; `isComplete() === false`
на этом коллекторе — то, что превращается в `IncompleteAnalysisException` у
`BaselineRun.php:92` (код 4 у `baseline:*`), в прямую проверку `!isComplete()` у
`DirectivesCommand::exitCodeFor()` (код 4 у `directives`) и в `IncompleteAnalysisException`
у `GraphExportCommand.php:123` (код 4 у `graph:export`). Раунд эти пять клауз не трогает
(`00-overview.md`, «Что раунд обязан не сломать»).
