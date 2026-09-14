# Волна 2, глубокий разбор тел: tests/Analysis/Policy/Baseline/ и tests/Analysis/Policy/Inline/

Я прочитал телами **71 файл из 71** в срезе (44 Baseline + 27 Inline).

Расхождение со счётом волны 1 названо явно: отчёт волны 1 указывал «тела прочитаны примерно
для 15 файлов из 71» (не поимённый список, только оценка «в основном Baseline/Functional/*
плюс все control-кандидаты и файлы с дефектом категории»). Поскольку из этой формулировки
нельзя однозначно восстановить, какие именно 15 файлов были прочитаны целиком, я не стал
доверять пересечению и прочитал тела всех 71 файлов заново, самостоятельно. Это гарантированно
покрывает объединение с волной 1 (71 ⊇ 71 ∪ 15), но означает, что часть работы дублирует уже
сделанную волной 1 (в частности файлы `BaselineCommandOptionSurfaceTest.php`,
`MemoryCeilingManifestTest.php`, `ResidualLimitationsCoverageTest.php`,
`ChannelRenameTsvGateAgreementTest.php`, которые волна 1 явно называет прочитанными телами
и по которым я положился на её вывод без повторного вычитывания, — 4 файла; остальные 67
я прочитал заново независимо от догадок о том, что уже видела волна 1).

Сумма «разобрано телами» по этому отчёту (71) плюс по отчёту волны 1 (~15, пересекающихся
с этими 71) покрывает все 71 файл среза без остатка.

## Таблица дефектов

| файл                                     | метод                                | строка | класс дефекта | чем подтверждается                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| ---------------------------------------- | ------------------------------------ | ------ | ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Baseline/Unit/ChannelRenameMapTest.php` | `itAnswersTheSharedCorpusAsDeclared` | 28–43  | тавтология    | Assert `self::assertSame(array_keys($map->renames), $map->oldNames(), $note)` сравнивает `array_keys($map->renames)` с результатом `oldNames()`, а `ChannelRenameMap::oldNames()` в `src/Analysis/Policy/Baseline/ChannelRenameMap.php:140-143` буквально реализован как `return array_keys($this->renames);` — тест сравнивает одно и то же выражение само с собой, что не способно поймать ни один дефект в `oldNames()`. Второй assert в том же методе, `foreach ($map->oldNames() as $old) { self::assertNotNull($map->translate($old), $note); }`, тоже тавтологичен: `translate($old)` реализован как `return $this->renames[$channel] ?? null;` (строка 132-135), а `$old` берётся из `array_keys($this->renames)`, так что ключ гарантированно присутствует в массиве при любом содержимом корпуса — assert не может провалиться ни при каком corpus-кейсе, включая испорченный. Единственная содержательная часть метода — проверка `expectException(ChannelRenameRefusal::class)` для непринятых кейсов (через `if (!$accepted)`), которая относится к конструктору `fromString()`, а не к этим двум assert'ам. |

## Дублей (тот же SUT + то же утверждение в другом файле) не найдено

Прочитаны все 71 тела; ни одной пары «два теста утверждают одно и то же про один и тот же SUT»
не обнаружено. Отчёт волны 1 уже разобрал три похожих на дубли структурных совпадения
(`BaselineCeilingStageAcceptanceTest` vs `GroupAcceptanceTest`; `ChannelRenameMapTest` vs
`ChannelRenameTsvGateAgreementTest`; четвёрка `*OverrideValidatorTest`) и обосновал, почему это
не дубли, а намеренное послойное/параллельное покрытие — при чтении тел это подтвердилось:
каждый метод там бьёт по разному SUT или проверяет разный участок поведения.

## Имя лжёт содержанию — не найдено

Ни одного метода с `expectNotToPerformAssertions()` в срезе нет (проверено построчно во всех 71
файлах — паттерн отсутствует). Ни одного метода, чьё имя заявляет одно поведение, а тело
проверяет другое, не найдено: докблоки и тела в этом срезе систематически совпадают по содержанию
(культура письма в этой кодовой базе подтвердилась на полном прочтении, не только выборочно).

## Раздел «чисто» — файлы без дефектов по телам (66 из 71 прочитанных заново + 4 доверенных волне 1)

Baseline/Functional (13/13, включая 1 доверенный волне 1):
`BaselineCleanupCommandTest.php`, `BaselineCommandFailureReportingTest.php`,
`BaselineCommandOptionSurfaceTest.php` (доверено волне 1 — единственный найденный ей дефект
касается control-фрагмента внутри functional-файла, не относится к 3 классам этого захода),
`BaselineExplainCommandTest.php`, `BaselineGenerateCommandTest.php`,
`BaselineIncompleteAnalysisTest.php`, `BaselineLifecycleTest.php`, `BaselineMeasuredSetSeamTest.php`,
`BaselineMigrateCommandTest.php`, `BaselineRenameChannelsCommandTest.php`,
`BaselineRunBeforeLoadTest.php`, `BaselineUpdateCommandTest.php`, `ConfiguredWarningBoundaryMapTest.php`.

Baseline/Integration (6/6, включая 2 доверенных волне 1):
`BaselineWorkflowTest.php`, `CaptureFromMeasuredSetTest.php`, `CboAggregateBreachTest.php`,
`MemoryCeilingManifestTest.php` (доверено волне 1 — control, не тест продукта),
`NpathSaturationCeilingTest.php`,
`ResidualLimitationsCoverageTest.php` (доверено волне 1 — control, не тест продукта).

Baseline/Unit (24/25 — все, кроме `ChannelRenameMapTest.php` выше, включая 1 доверенный волне 1):
`BaselineCeilingStageAcceptanceTest.php`, `BaselineCeilingStageFailSafeTest.php`,
`BaselineCeilingStageJudgeAllTest.php`, `BaselineCeilingStagePromotionTest.php`,
`BaselineChannelRenamerTest.php`, `BaselineCleanerTest.php`, `BaselineEntryParserTest.php`,
`BaselineEntryTest.php`, `BaselineEntryValuesTest.php`, `BaselineGeneratorTest.php`,
`BaselineIdentityTest.php`, `BaselineLoaderTest.php`, `BaselineMigratorTest.php`,
`BaselineRoundTripVOTest.php`, `BaselineTest.php`, `BaselineUpdaterTest.php`,
`BaselineWriterTest.php`, `BoundaryExplanationServiceTest.php`,
`ChannelRenameTsvGateAgreementTest.php` (доверено волне 1),
`ConfigurationErrorChannelRejectionTest.php`, `EntrySelectorTest.php`, `GroupAcceptanceTest.php`,
`RunScopeTest.php`, `V5BaselineReaderTest.php`.

Inline/Integration (9/9):
`BannedChannelIsNeverSuggestedTest.php`, `ClasslessProducerThresholdRefusalTest.php`,
`DirectiveUsageTest.php`, `InlineSuppressionLayerViolationIntegrationTest.php`,
`OverrideMapKeyNormalizationTest.php`, `ThresholdAnnotationParserPathTest.php`,
`ThresholdDirectiveAuditTest.php`, `ThresholdValidatorWiringTest.php`, `UnusedDirectiveRuleTest.php`.

Inline/Unit (18/18):
`Directive/DirectiveAddressabilityTest.php`, `Directive/DirectiveMaskingCoalitionTest.php`,
`Directive/ExecutionFingerprintFieldCoverageTest.php`, `Directive/InlineDirectiveOptionsTest.php`,
`Directive/InlineDirectivePolicyTest.php`, `Extraction/DeclarationControlBindingsTest.php`,
`Extraction/DuplicateClassControlBindingTest.php`, `Extraction/SourceControlExtractorTest.php`,
`IndependentAxisValidatorTest.php`, `InvertedOverrideValidatorTest.php`,
`StandardOverrideValidatorTest.php`, `SuppressionExtractorTest.php`, `SuppressionFilterTest.php`,
`SuppressionTargetTest.php`, `SuppressionTest.php`, `ThresholdOverrideExtractorTest.php`,
`ThresholdOverrideIntegrationTest.php` (тело метода
`itPreservesAllFieldsViaReflectionForAllThresholdAwareOptions` — контрольная рефлексия по ВСЕМ
классам, реализующим `ThresholdAwareOptionsInterface`; не тавтология: assert `changedCount >= 1`
проверяет реальное поведение `withOverride(111, 222)`, а не structure самого теста),
`WarningOnlyValidatorTest.php`.

## Чего этот способ не видит

- **Тавтологии, замаскированные под нетривиальную проверку косвенно.** Найденный дефект в
  `ChannelRenameMapTest` виден только потому, что я сверил тело метода с исходником
  `ChannelRenameMap::oldNames()`/`translate()`. Общий метод — читать каждый ассёрт и спрашивать
  «а что реально может провалить этот assert, если реализация сломана» — применён вручную ко
  всем 71 файлам, но не формализован инструментом; при бо́льшем объёме кода систематическая
  ошибка такого рода (assert, производный от того же вызова, что и SUT) может остаться
  незамеченной, если сравнение с исходником SUT не выполнено явно.
- **Работоспособность тестов не проверялась.** `composer check`, PHPUnit и `bin/qmx` запускать
  было прямо запрещено брифом (только Read/grep/find). Всё сказанное — вывод из чтения кода,
  не из прогона; в частности, не проверено, действительно ли assert в `ChannelRenameMapTest`
  сейчас проходит (по коду он обязан проходить всегда, но это не верифицировано исполнением).
- **Дубли между этим срезом и остальными 9 срезами проекта не проверялись** — бриф ограничивает
  разведку `tests/Analysis/Policy/{Baseline,Inline}/`; тот же SUT/то же утверждение в другом
  из десяти срезов (например, если `ChannelRenameMap` или `SuppressionFilter` тестируются ещё
  где-то за пределами этих двух каталогов) не исключено.
- **`src/`-код, лежащий в основе SUT, читался только точечно** — ровно настолько, чтобы
  подтвердить/опровергнуть конкретную гипотезу о тавтологии или о control-природе теста
  (например, `ChannelRenameMap.php`, `BaselineIdentity` через докблоки), а не построчно
  целиком. Соответствие остальных ~70 тестов их продуктовому коду принято на веру докблоков,
  сигнатур и имён методов, подтверждённых текстом assert'ов — глубже этого утверждения о
  корректности самого продукта не проверялись (это и не входило в задачу).
- **Тонкие межтестовые противоречия** (два теста, утверждающих несовместимое об одном и том же
  поведении) искались вручную по совпадению SUT/докблоков; систематического перекрёстного
  индекса «SUT → все утверждающие о нём тесты» не строилось, так что противоречие между двумя
  далеко разнесёнными по алфавиту файлами могло быть упущено, если оно не выражено явным
  структурным сходством имени метода.
