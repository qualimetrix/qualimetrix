# Глубокая проверка по телам: Reporting + Architecture/Unit

**Прочитано телами в этом заходе (волна 2):**
- Architecture (`tests/Analysis/Policy/Architecture/Unit/`): **31 из 31** файлов — полное покрытие среза.
  (Ещё 3 файла из этого же каталога — `ArchitectureInternalTopologyTest`, `LayersValidatorMembershipRefusalGuardTest`,
  `ArchitectureProcessorTest` — были прочитаны целиком уже в волне 1, что и даёт эти 31.)
- Reporting (`tests/Unit/Reporting/`, `tests/Reporting/`, `tests/Functional/Reporting/`): **24 из 61** тестовых файлов
  прочитаны целиком по телам (8 — в волне 1, 16 — в этом заходе). **37 файлов остались непрочитанными телами**
  — список ниже, раздел «Непокрытое».

Срез Architecture закрыт полностью. Срез Reporting **не закрыт** — не хватило бюджета захода дочитать
оставшуюся часть (много крупных Formatter-файлов по 300–1700 строк). DoD «вместе с волной 1 покрыты телами
все файлы обоих срезов» **не выполнен для Reporting**; выполнен для Architecture.

## Таблица дефектов

| файл                                                  | метод                              | строка | класс дефекта                       | чем подтверждается                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| ----------------------------------------------------- | ---------------------------------- | ------ | ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Unit/Reporting/Health/SummaryEnricherTest.php` | `itTypingNotAddedWhenNoDimensions` | 487    | **имя лжёт содержанию** + **дубль** | Имя обещает узкую проверку «ключ `typing` отсутствует среди прочих измерений», но тело — байт-в-байт то же самое построение отчёта и та же единственная проверка `self::assertSame([], $result->healthScores);`, что и в методе `itHealthScoresEmptyWhenNoProjectHealthMetrics` (строка 295) того же файла: нет ни одной проверки про `typing` конкретно. Соседний метод `itTypingNAWhenOtherDimensionsExist` (строка 458) показывает, как выглядела бы настоящая проверка «typing не добавлен» — `assertArrayHasKey('typing', ...)` внутри непустого набора — и как раз этой формы здесь нет. |

Дубль формально не «в другом файле», как задан класс 2 в задании, а внутри одного файла — привожу его
здесь, потому что это тот же SUT (`SummaryEnricher::enrich`) и то же единственное утверждение, дословно
повторённое под другим, вводящим в заблуждение именем; де-факто дефект объединяет оба класса.

### Пограничный случай (не дубль, но пересечение — вынесен отдельно, не в таблицу)

`tests/Reporting/Unit/OutputFormatResolverTest.php::itUsesSummaryByDefaultAndTheLastExplicitFormat` (строка 20,
первая половина ассертов) и `tests/Reporting/Unit/OutputFormatRefusesUnexecutableValuesTest.php::itStillDefaultsWhenNobodyNamedAFormat`
(строка 62) — оба на `OutputFormatResolver`, оба утверждают «пустой документ даёт формат `summary`». Не
считаю полным дублем: у первого теста это лишь одна из двух проверяемых веток (второй ассерт того же метода —
про приоритет `cli` над `config`, чего во втором файле нет), у второго — единственная проверка в отдельном
файле, дополняющем более широкий datapровайдер `provideUnexecutableValues`/`provideExecutableNames`. Это
подтверждает гипотезу волны 1 («Гипотеза без подтверждения») — пересечение реально есть, но не тождественное
дублирование одного и того же утверждения без остатка.

## Architecture/Unit — «чисто» (31/31, дефектов по телам не найдено)

`AllowAliasExpanderTest`, `AllowValidatorTest`, `ArchitectureConfigurationFactoryTest`, `ArchitectureConfigurationTest`,
`ArchitectureInternalTopologyTest`*, `ArchitectureProcessorTest`*, `CapturePatternTest`, `ClassContextFactoryTest`,
`ClassSetTest`, `CoverageDiagnosticsTest`, `CoverageModeTest`, `CoverageValidatorTest`, `ExactAllowCycleValidatorTest`,
`ExcludeSpecTest`, `LayerDefinitionTest`, `LayerExpansionStageTest`, `LayerInstantiatorTest`, `LayerPolicyTest`,
`LayerRegistryTest`, `LayerSelectorTest`, `LayerViolationOptionsTest`, `LayerViolationRuleTest`,
`LayersValidatorMembershipRefusalGuardTest`*, `LayersValidatorTest`, `PatternScopeTest`, `PendingLayerDiagnosticsTest`,
`TemplateLayerDefinitionTest`, `TupleExtractorTest`, `UnassignedClassDiagnosticsTest`, `UnassignedClassOptionsTest`,
`WildcardSelfAllowDetectorTest` (* — прочитаны в волне 1).

Единственное отмеченное волной 1 наблюдение (`LayerExpansionStageTest`, строки 567–568: обращение к
`expandedLayers()`/`emptyTemplateNames()` как к методам против свойств в остальных ~20 кейсах) — перепроверено:
оба метода-аксессора реально существуют на `LayerExpansionResult` и возвращают то же самое, что и одноимённые
свойства (дублирующий, возможно мёртвый, аксессор в продукте — не дефект теста ни по одному из трёх классов
задания).

## Reporting — «чисто» (прочитано в этом заходе, дефектов по телам не найдено)

`ReportTest`, `FormatterContextTest`, `ReportBuilderTest`, `Health/HealthHintProjectorTest`,
`Health/HealthScoreResolverTest`, `Filter/FindingFilterTest`, `FindingProjection/Unit/SuppressionMechanismTest`,
`GraphProjection/Unit/DependencyGraphProjectorTest`, `Unit/OutputFormatResolverTest`,
`Unit/OutputFormatRefusesUnexecutableValuesTest` (оба — см. пограничный случай выше),
`FindingProjection/Unit/ProjectScopedChannelProjectionTest`, `Formatter/Suppressed/Unit/SuppressedFormatterTest`,
`Formatter/Suppressed/Unit/SuppressionSnapshotKeyTest`, `GraphProjection/Unit/NamespaceFilterTest`,
`GraphProjection/Unit/DotExporterTest`, `Formatter/Support/AnsiColorTest`, `Formatter/Support/AcceptedLevelNarratorTest`.

Отдельно перепроверена гипотеза волны 1 о `ProjectScopedChannelProjectionTest` как о возможном втором случае
тавтологии по образцу `DeclaredChannelFileScopeTest`: это НЕ тавтология. Список каналов
(`declaredProjectScopedKeys()`) читается напрямую из констант `LayerPolicyPreparationInterface::PROJECT_SCOPED_CHANNELS`
и `CircularDependencyPreparationInterface::PROJECT_SCOPED_CHANNELS` (тех же, что владеет продукт), а не
дублируется отдельным захардкоженным литералом теста — и дальше тест реально прогоняет `FindingProjector`
через три независимых сценария фильтрации (path/namespace/git-scope) и проверяет, что находка выжила. Это
образцовый анти-тавтологичный тест, что и заявлено в его собственном докблоке.

## Непокрытое (Reporting, 37 файлов — тела не прочитаны ни в волне 1, ни в волне 2)

```
tests/Reporting/FindingProjection/Unit/ConfigurationErrorProjectionTest.php
tests/Reporting/FindingProjection/Unit/FindingProjectorTest.php
tests/Reporting/FindingProjection/Unit/SuppressionCompositionBuilderTest.php
tests/Reporting/Formatter/Sarif/Integration/SarifRuleDescriptorCoverageTest.php   (частично — волна 1 читала выборочно)
tests/Reporting/GraphProjection/Unit/JsonGraphExporterTest.php
tests/Unit/Reporting/Formatter/ArchitectureViolationSmokeTest.php
tests/Unit/Reporting/Formatter/CheckstyleFormatterTest.php
tests/Unit/Reporting/Formatter/FormatterRegistryTest.php
tests/Unit/Reporting/Formatter/GitLabCodeQualityFormatterTest.php
tests/Unit/Reporting/Formatter/GithubActionsFormatterTest.php
tests/Unit/Reporting/Formatter/HealthTextFormatterTest.php
tests/Unit/Reporting/Formatter/Html/HtmlDebtCalculatorTest.php
tests/Unit/Reporting/Formatter/Html/HtmlFindingPartitionerTest.php
tests/Unit/Reporting/Formatter/Html/HtmlMetricAggregatorTest.php
tests/Unit/Reporting/Formatter/Html/HtmlTreeBuilderTest.php
tests/Unit/Reporting/Formatter/HtmlFormatterTest.php
tests/Unit/Reporting/Formatter/Json/JsonFindingSectionTest.php
tests/Unit/Reporting/Formatter/Json/JsonHealthSectionTest.php
tests/Unit/Reporting/Formatter/Json/JsonOffenderSectionDensityTest.php
tests/Unit/Reporting/Formatter/Json/JsonSanitizerTest.php
tests/Unit/Reporting/Formatter/JsonFormatterTest.php
tests/Unit/Reporting/Formatter/MetricsJsonFormatterTest.php
tests/Unit/Reporting/Formatter/Sarif/SarifFormatterPosixSeparatorTest.php
tests/Unit/Reporting/Formatter/Sarif/SarifRuleCollectorTest.php
tests/Unit/Reporting/Formatter/Sarif/SarifSchemaValidationTest.php
tests/Unit/Reporting/Formatter/SarifFormatterTest.php
tests/Unit/Reporting/Formatter/Summary/FindingSummaryRendererTest.php
tests/Unit/Reporting/Formatter/Summary/HealthBarRendererTest.php
tests/Unit/Reporting/Formatter/Summary/HintRendererTest.php
tests/Unit/Reporting/Formatter/Summary/OffenderListRendererDensityTest.php
tests/Unit/Reporting/Formatter/Summary/TopIssuesRendererTest.php
tests/Unit/Reporting/Formatter/SummaryFormatterTest.php
tests/Unit/Reporting/Formatter/Support/DetailedFindingRendererTest.php
tests/Unit/Reporting/Formatter/Support/FindingSorterTest.php
tests/Unit/Reporting/Formatter/TextFormatterTest.php
tests/Unit/Reporting/Formatter/TextVerboseFormatterTest.php
tests/Unit/Reporting/Health/SummaryEnricherTest.php   (прочитан целиком — дефект найден и внесён в таблицу;
                                                        помечаю здесь только чтобы явно не потерять сам файл
                                                        из списка «требовал чтения», не как непрочитанный)
```

Уточнение по последней строке: `SummaryEnricherTest.php` фактически прочитан целиком (511 строк) — именно
этим чтением найден дефект в таблице выше. Он не входит в «непокрытое»; оставлен в блоке для трассируемости
между списком волны 1 («требует чтения») и итогом. Реально непокрытых файлов — **36**, не 37; поправляю
итог первой строки документа этим примечанием.

Крупнейшие из непрочитанных: `JsonFormatterTest.php` (1692 строк, 47 тестов), `SummaryFormatterTest.php`
(1288, 42 теста), `FindingProjectorTest.php` (1070, 36 тестов), `SarifFormatterTest.php` (909, 24 теста),
`HtmlTreeBuilderTest.php` (898).

## Что этот способ не видит

- Никакие тесты не запускались (по границам задания — запрещено). Все выводы «тест проверяет X» сделаны
  чтением исходного текста, не наблюдением реального прохождения/падения.
- Для оставшихся 36 файлов Reporting (список выше) метод дефектов класса 1–3 **не применён вообще** — они
  не читались ни структурно, ни телами в этом заходе. Волна 1 по ним имела только структурный/грепп-сигнал
  (см. её собственный раздел «чего не видит»).
- Для прочитанных «чисто» файлов проверка тавтологии и дублей ограничена: (а) построчным чтением одного
  человека без независимого второго прохода (см. «two-witness» урок в памяти проекта — здесь применён
  только один свидетель); (б) механическим поиском точных байт-в-байт дублей тел методов по всему срезу
  (скрипт на Python, нормализация пробелов) — он находит только полностью идентичные тела, не находит
  дублирование, отличающееся хотя бы одной строкой или порядком ассертов, и не находит семантический дубль
  двумя разными путями конструирования одного и того же случая.
- Дубль `SummaryEnricherTest` найден именно механическим скриптом (нормализация + хэш), не глазом при чтении
  по порядку — это довод в пользу того, что среди непрочитанных 36 файлов такие же интра- и кросс-файловые
  дубли вероятны и не пойманы, потому что скрипт по всему срезу гонялся один раз и его результат (3
  совпадения, из них одно — реальный дефект, два — легитимные повторы шаблонных тестов на РАЗНЫХ формееро-
  классах) не перепроверялся руками на каждом из непрочитанных файлов индивидуально.
- Гипотезы волны 1 про `SarifRuleCollectorTest` vs `SarifRuleDescriptorCoverageTest` (оба про
  `SarifRuleCollector`) и про совпадение `JsonOffenderSectionDensityTest`/`OffenderListRendererDensityTest`
  остаются неподтверждёнными и непроверенными — оба файла из каждой пары либо не читались, либо читалась
  только половина пары.
