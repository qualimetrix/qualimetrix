81

Столько уникальных путей в объединении двух свидетелей (60 из `controls-from-category.tsv` + 21 из блока
«только механика»; блок «только отчёты» целиком лежит внутри первых 60). Ровно столько строк в
`controls-verdict.tsv`, заголовка в файле нет.

## Счёт

| вердикт | сколько |
| ------- | ------- |
| control | 43      |
| test    | 24      |
| mixed   | 14      |

Итого «контрольного вещества»: 43 файла целиком плюс 20 отдельных методов внутри 14 смешанных.

## Как разделить каждый mixed

Общее правило для всех четырнадцати: контроль-часть уезжает, продуктовая остаётся на месте под прежним
именем. Имя нового файла ниже — предложение, не требование.

1. `tests/Analysis/Configuration/Unit/ConfigSchemaTest.php` — `itLeavesNoConstantUnreferencedByAConsumer`
   (L279-331) вынести как «каждая константа ConfigSchema имеет потребителя в src/». Остальное — юнит по
   константам, зависимостей на вынесенный метод нет.
2. `.../Identity/ClassProducerOrdinalTest.php` — `itCoversEveryClassMetricProducer` (L121-141) и
   `itFindsTheHelperCallSiteOfEveryCoveredProducer` (L150-171) уезжают вместе: второй доказывает полноту
   популяции первого. Остаётся один продуктовый метод на inline-исходнике.
3. `.../Identity/RatchetKeyGrammarTest.php` — `itFindsNoPositionInAnyDeclarationKeyOfTheRepositoryRatchet`
   (L48-58) уезжает к контролям над `qmx-baseline.json`; грамматика ключа остаётся юнитом.
4. `.../ChannelLevelAssemblyTopologyTest.php` — уезжают
   `itFindsNoProductionSourceThatSpellsALevelSuffixAsALiteral` (L58-81) и его самопроверка
   `itRecognisesARetiredLevelBearingChannelName` (L123-129) вместе с приватным `levelSegmentOf()` и парой
   `sourceRoot()`/`parse()`. Остаётся `itFindsNoDeclaredChannelCodeThatCarriesALevel` — он спрашивает реестр
   из контейнера, но ему нужен `levelSegmentOf()`: при делении примитив придётся продублировать или поднять
   в общий Support.
5. `.../ConfigurationErrorClassificationTopologyTest.php` — уезжают два первых метода (L67-99, L117-154) со
   всей файловой обвязкой (`productionFiles()`, `relative()`, `sourceRoot()`). Остаются пять
   DI/compiler-pass-методов и фикстурные классы внизу файла.
6. `.../RuleDocsPageCoverageTest.php` — уезжают `itRequiresEveryDeclaredDocsPageToCarryTheRulesOwnAnchor`
   (L75-98) и `itRequiresEveryClasslessComputedMetricProducerToCarryItsAnchor` (L107-127) вместе с
   `docsRoot()`. Обоим нужен `ruleClasses()` из контейнера — его копия потребуется и там, и тут.
7. `.../RuleRemediationMinutesCoverageTest.php` — уезжают
   `itRequiresEveryRulesRemediationMinutesToMatchTheReferencePage` (L79-84) и
   `itRequiresEveryProducerOfTheComputedFamilyToBeOnTheReferencePage` (L120-138) вместе с приватными
   `assertReferencePageMatchesDeclaredMinutes()` (L86-118), `readFile()` и `docsRoot()`. Первый метод —
   двухстрочный делегат, вся работа в помощнике: переносить парой, иначе уедет пустышка.
8. `.../Exclusion/ConfiguredSuppressionTest.php` — `itIsTheOnlyPlaceInSourceThatReadsASuppressionOptionKey`
   (L78-103) уезжает целиком, остаток — чистый юнит на два метода.
9. `.../Baseline/Functional/BaselineCommandOptionSurfaceTest.php` —
   `itKeepsRepositoryEntrypointsOnTheBaselineLifecycleSurface` (L169-203) уезжает к контролям над
   `action.yml` / `docker-compose.yml` / `scripts/pre-commit-hook.sh`; четыре метода по поверхности команд
   остаются.
10. `.../Baseline/Unit/ChannelRenameMapTest.php` — `itReadsTheRepositorysOwnDeclaredChannelMap` (L65-75)
    уезжает и логически смыкается с уже целиком контрольным `ChannelRenameTsvGateAgreementTest`; три метода
    на корпусе строк остаются.
11. `.../Sarif/Integration/SarifRuleDescriptorCoverageTest.php` — уезжают два coverage-метода (L57-79,
    L90-118) с приватным помощником, который делает `is_file()` и ищет якорь `**Rule ID:**` в
    `website/docs`. Остаётся `itKeepsTheHumanisedFallbackAndTheRepositoryUrlForAnUnknownCode` — чистое
    поведение форматтера.
12. `tests/Unit/Core/Util/GlobSyntaxTest.php` — `itIsTheOnlyPlaceInSourceThatEnumeratesTheGlobCharacters`
    (L69-92) уезжает; два юнит-метода остаются.
13. `tests/Unit/Core/Util/NamespaceMatcherTest.php` —
    `itLeavesPatternNormalizationToThePrimitiveOnEverySurface` (L341-382) уезжает вместе с приватным
    `codeWithoutComments()`; тридцать юнит-методов остаются.
14. `tests/Unit/Core/VersionTest.php` — `itDoesNotResolveTheVersionThroughTheRootPackage` (L42-56) уезжает
    (читает исходник `Version.php` текстом); два поведенческих метода остаются.

## Что дублирует уже существующие механизмы репозитория

Проверено чтением `composer.json` (`scripts`) и самих тестов, не по именам.

**Дубль проверки, но НЕ дубль маршрута — удалять можно только вместе с правкой маршрута:**

Оба файла ниже помечены `#[Group('live-freshness')]`, а `scripts/phpunit-aggregate.py:36` исключает эту
группу. То есть под `composer check` (через `check:code` → `test:aggregate`) они **не исполняются вовсе**,
а под голым `composer test` — исполняются. Это осознанная развилка: тот же
`ModularArchitectureGovernanceIntegrationTest::itRoutesFreshnessOraclesExactlyOnceThroughAggregateCheck`
утверждает, что `composer test` обязан сохранить полное покрытие свежести, а агрегат — отдать его
`check:artifacts`. Поэтому удаление обязано сопровождаться правкой этого утверждения, иначе оно покраснеет.

- `tests/Reporting/Formatter/Suppressed/Integration/SuppressionSnapshotFreshnessTest.php` — его
  единственный метод запускает `php scripts/generate-suppression-snapshot.php --check` подпроцессом и
  утверждает `exit == 0`. Это буквально `composer suppression-snapshot:check`, уже входящий в
  `check:artifacts`. Под `composer check` — чистый дубль.
- `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGovernanceIntegrationTest.php`,
  метод `itChecksEveryGeneratedProjectionWithoutWriting` (L21-32) — запускает
  `php scripts/generate-modular-architecture.php --check`, то есть ровно `composer architecture:check`,
  который уже стоит и в `check:artifacts`, и первой половиной в `selfcheck`. **Остальные шесть методов
  этого файла дубля не образуют** (граф composer-скриптов, permanent exact composition bindings, покрытие
  PHPUnit-сьютов, production→test импорты) — файл удалять нельзя, удалять надо этот метод.

Что именно теряется при удалении: способность голого `composer test` (без `composer check`) заметить
устаревший артефакт. Решение за владельцем маршрута, не за переносом.

**Перекрывается частично — удалять нельзя:**

- `tests/Analysis/Policy/Architecture/Integration/DogfoodingTopologyTest.php` — читает
  `docs/internal/modular-architecture-manifest.json` и утверждает семантику проекции (владелец↔слой,
  fail-closed покрытие, ацикличность, `external` только для непроектных неймспейсов). `architecture:check`
  проверяет свежесть сгенерированного, а не эти свойства.
- `tests/Analysis/Policy/Architecture/Unit/ArchitectureInternalTopologyTest.php` и
  `.../ComputedMetrics/Unit/ComputedMetricsInternalTopologyTest.php` — внутренний DAG капабилити;
  манифестный чекер судит межвладельческие импорты, а не внутреннюю зонность.
- `tests/Unit/RuleVocabulary/DirectiveAudit*Test.php` (три файла) — это юнит-тесты **читателя и гейта**
  отчёта аудита (`scripts/directive-audit/*`), а не повторный запуск `bin/qmx directives`. Предмет другой:
  они и есть проверка прибора. То же для `DirectiveAuditControlsSuiteKeyTest` (ключ набора контролей).
- `tests/Unit/RuleVocabulary/RenameEnumerationRetirementTest.php` — тестирует код
  `scripts/generate-rename-enumeration.php`, а не сверяет свежесть его выхода (этим занят
  `enumeration:renames:check`).
- `tests/Analysis/Policy/Baseline/Unit/ChannelRenameTsvGateAgreementTest.php` и метод
  `itReadsTheRepositorysOwnDeclaredChannelMap` из `ChannelRenameMapTest` — требуют, чтобы **продуктовый**
  `ChannelRenameMap` читал тот же `finding-gate/maps/channels.tsv`, что и гейт. `composer gate` сравнивает
  находки; согласия двух читателей одной таблицы он не проверяет.
- `tests/Unit/PromiseEffect/*` (три файла) — юниты по `scripts/promise-effect/*`; `promise-effect:check`
  гоняет сам инструмент, но не его внутренние классы.

## Расхождения с волной 1 (вердикт изменён)

- `ChannelDeclarationFixtureDriftTest`, `ChannelOrderFixtureDriftTest`: волна 1 — control («fixture
  drift»). Здесь — **test**: популяция берётся из собранного контейнера, а снимок для сверки лежит
  **внутри `tests/`**. По разграничителю брифа контейнерный путь остаётся тестом. Это единственные два
  вердикта, где разграничитель спорит со здравым смыслом: по духу они контроли дрейфа, по букве — нет.
- `LevelActivityCoversEveryDeclaredLevelTest`, `WarningBoundaryDeclarationTest`,
  `ErrorStreamContainerIdentityTest`, `RuleRegistryTest`: волна 1 — «пограничный control-invariant». Здесь —
  **test**: файловой системы нет вовсе, только контейнер и рефлексия по классам, которые он выдал.
- `MetricNameVocabularyTest`: волна 1 — «спорная граница». Здесь — **test**: рефлексия по константам одного
  класса не есть «рефлексия поверх обхода каталога».
- `CodeSmellRuleContractTest`: волна 1 — mixed (метод 2 «integration через DI»). Здесь — **control целиком**:
  второй метод сверяет обход каталога с реестром, то есть его предмет — согласие файловой системы и
  контейнера, а не поведение продукта.
- `RuleThresholdKeyGroupRegistryCompletenessTest` / `...DriftTest`, `ThresholdValidatorAssignmentTest`:
  волна 1 колебалась («control-invariant-подобный», «кандидат»). Здесь — **control** без оговорок.
- Блок «только механика» (21 путь): 17 подтверждены как **test** (ложные срабатывания механического
  свидетеля), а 4 оказались контрольным веществом, которого волна 1 по категории не увидела:
  `SuppressionSnapshotKeyTest` — control целиком, плюс три mixed —
  `BaselineCommandOptionSurfaceTest`, `ChannelRenameMapTest`, `SarifRuleDescriptorCoverageTest`.
  Первого волна 1 не увидела потому, что он подтягивает код не импортом, а `require_once` из
  `scripts/` — ровно тот класс промаха, который бриф и предсказал.
- `LayerViolationRuleTest`, `LayersValidatorTest`, `RuleOptionsFactoryTest` — три самых больших файла
  объединения (2194/1644/1230 строк) оказались чистыми **test**: механика сработала на слове `glob` в имени
  продуктового `LayerSelector::glob`, на слове `require` в комментарии и на импортах фикстур из `tests/`.

## Чего этот способ не видит

- **Помощники.** Я собрал все импорты `Qualimetrix\Tests\*` из 24 файлов с вердиктом `test` (их ровно
  десять: четыре фикстуры `TestRuleOptions*`, `AllowListBuilder`, `LayerVerdicts`, `ProcessorBuilder`,
  `BaselineCliFixture`, `TempDirectory`, `PseudoTerminalRun`) и прогрепал каждый на обход дерева. Ходит в
  файловую систему только `BaselineCliFixture::copyDirectory()` — копирует каталог фикстуры во временный
  проект, то есть харнес, не утверждение. Вердикты держатся. Но грепом же проверены и помощники контролей
  (`FromArrayKeyReader` читает файлы — ожидаемо): третьего уровня (помощник помощника) я не смотрел.
- **Базовые классы.** Проверено: 79 из 81 классов наследуют `TestCase` напрямую, два —
  `PHPStan\Testing\RuleTestCase` из vendor. Своего общего предка с `setUp()`, куда мог бы спрятаться
  контроль, в объединении нет.
- **`require_once` второго уровня.** Я нашёл прямые `require_once` из `scripts/`, но не проверял, не
  подтягивает ли сам подтянутый скрипт третий файл, который ходит в `src/`.
- **Граница «контроль над `tests/`».** Разграничитель говорит про `src/`, `docs/`, `website/`, `scripts/`,
  конфиги и сгенерированные артефакты. Два файла (`ScratchPathsCarryRealEntropyTest`,
  `ErrorStreamSoleOwnerTest`) сметают в том числе `tests/`; я счёл их контролями, но формально бриф на
  этот случай молчит.
- **Граница «контейнер против снимка в `tests/`».** Тот же молчащий случай с другой стороны: fixture-drift
  тесты (пп. выше) по букве тесты, по назначению контроли. Решение принято по букве и может быть
  перевёрнуто одним словом в разграничителе.
- **Дробление по методам не проверено исполнением.** Я нигде не запускал PHPUnit (бриф запрещает), поэтому
  предложения «вынести метод X» не доказаны сборкой: приватные помощники, общие константы (`REGISTERED_RULE_COUNT`,
  pinned-списки) и `#[CoversClass]` могут связывать половинки сильнее, чем видно из чтения.
- **Вердикт по файлу, а не по строке покрытия.** Там, где контроль-метод и продуктовый метод делят
  популяцию (`ruleClasses()` из контейнера в `RuleDocsPageCoverageTest` и
  `RuleRemediationMinutesCoverageTest`), деление создаст дубликат кода — это цена, а не дефект вердикта,
  но она здесь не измерена.
- **Третий класс, которого разграничитель не называет: тесты репозиторного тулинга.** Десять вердиктов
  `control` держатся не на «узнаёт о `src/` через ФС» и не на «сверяет содержимое `scripts/`», а на том,
  что предмет теста — код, живущий в `scripts/`, подтянутый через `require_once`:
  `ClassifierTest`, `FloorTest`, `LedgerVocabularyTest`, `DirectiveAuditGateTest`,
  `DirectiveAuditReportReadingTest`, `DirectiveAuditControlsSuiteKeyTest`,
  `ChannelRenameTsvGateAgreementTest`, `RenameEnumerationRetirementTest`, `SuppressionSnapshotKeyTest`,
  и тулинговые половины `BenchmarkConsumersCoverageTest` и `ThresholdPopulationAgreementTest`.
  Это моё расширение разграничителя, а не его буква: одной строкой в брифе оно переворачивается для всех
  десяти сразу.
- **Полнота входа.** Сам вход — объединение двух свидетелей волны 1. Контроль, который проглядели оба
  (например, тест без файловых вызовов и с категорией «unit»), в эти 81 путь не попал и этим способом не
  ловится вовсе.
