# Deep read — Reporting Html/Text/Summary slice (wave 2, second half)

19 файлов прочитаны телами полностью (все 18 из списка + бонусный ArchitectureViolationSmokeTest.php).
Ни один файл не пропущен, неосиленных нет.

Файлы:
1. tests/Unit/Reporting/Formatter/HtmlFormatterTest.php (184 строки)
2. tests/Unit/Reporting/Formatter/Html/HtmlTreeBuilderTest.php (898)
3. tests/Unit/Reporting/Formatter/Html/HtmlDebtCalculatorTest.php (175)
4. tests/Unit/Reporting/Formatter/Html/HtmlFindingPartitionerTest.php (437)
5. tests/Unit/Reporting/Formatter/Html/HtmlMetricAggregatorTest.php (216)
6. tests/Unit/Reporting/Formatter/SummaryFormatterTest.php (1288)
7. tests/Unit/Reporting/Health/SummaryEnricherTest.php (511)
8. tests/Unit/Reporting/Formatter/TextFormatterTest.php (618)
9. tests/Unit/Reporting/Formatter/TextVerboseFormatterTest.php (297)
10. tests/Unit/Reporting/Formatter/HealthTextFormatterTest.php (406)
11. tests/Unit/Reporting/Formatter/Summary/HealthBarRendererTest.php (542)
12. tests/Unit/Reporting/Formatter/Summary/HintRendererTest.php (351)
13. tests/Unit/Reporting/Formatter/Support/DetailedFindingRendererTest.php (408)
14. tests/Unit/Reporting/Formatter/Summary/FindingSummaryRendererTest.php (420)
15. tests/Unit/Reporting/Formatter/Summary/TopIssuesRendererTest.php (455)
16. tests/Unit/Reporting/Formatter/Summary/OffenderListRendererDensityTest.php (307)
17. tests/Reporting/FindingProjection/Unit/SuppressionCompositionBuilderTest.php (488)
18. tests/Reporting/FindingProjection/Unit/ConfigurationErrorProjectionTest.php (341)
19. tests/Unit/Reporting/Formatter/ArchitectureViolationSmokeTest.php (551, bonus — числился непрочитанным)

## Таблица

| Файл                    | Метод                              | Строка  | Класс дефекта | Чем подтверждается                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| ----------------------- | ---------------------------------- | ------- | ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| SummaryEnricherTest.php | `itTypingNotAddedWhenNoDimensions` | 487–509 | дубль         | Байт-в-байт идентично телу `itHealthScoresEmptyWhenNoProjectHealthMetrics` (295–317): тот же `MetricBag::fromArray(['complexity.ccn.avg' => 5.0, 'size.loc' => 1000])`, та же сборка `Report`, тот же единственный assert `self::assertSame([], $result->healthScores)`. Проверено `diff` после переименования метода — 0 расхождений. Оба имени описывают один и тот же факт («нет health.* метрик → healthScores пуст»), только под разными формулировками — дубль, а не просто похожий тест |

## Пограничный случай (не дефект, отмечаю отдельно)

`SummaryEnricherTest.php`: `itReturnsUnchangedReportWhenNoMetrics` (53–71, метрики не переданы вовсе — используется
default) и `itNullMetricsReturnsUnchangedReport` (354–370, метрики переданы явно как `null`) — тела разные
(второй проверяет меньше полей: только `$result === $report` и `healthScores === []`, тогда как первый
дополнительно проверяет `worstNamespaces`, `worstClasses`, `techDebtMinutes`). Не байт-дубль, поэтому в таблицу
дефектов не включаю, но по факту второй тест — строгое подмножество первого и не добавляет покрытия сверх
проверки того, что явный `null` ведёт себя как default (что тоже небесполезно, но неявно пограничная зона
между «дублирует» и «расширяет»).

## Чисто

Все остальные 18 файлов (и 17 из 18 методов SummaryEnricherTest.php) — без тавтологий, без дублей, без
несовпадения имени и тела:

- **HtmlFormatterTest, HtmlDebtCalculatorTest, HtmlMetricAggregatorTest, HtmlFindingPartitionerTest,
  HtmlTreeBuilderTest** — каждый тест строит свежую фигуру (findings/metrics/tree), assert сверяет либо
  арифметику (суммы LOC, средневзвешенное health, debt-минуты 30/60/75 через `RemediationTimeRegistry`), либо
  структуру дерева/партиционирования, которую тест сам не подставлял константой, а получил через прогон SUT.
  NaN/Inf-обнуление, JSON_HEX_TAG-экранирование, fallback на namespace-узел при отсутствии class-узла — везде
  отдельные, различимые кейсы.
- **SummaryFormatterTest.php (44 теста)** — крупнейший файл среза; каждый тест комбинирует свой набор
  findings/healthScores/worstNamespaces/worstClasses и проверяет соответствующий фрагмент вывода
  (`assertStringContainsString`/`NotContainsString`), включая численно выведенные значения (debt "1h 30min",
  "2.5 min/kLOC", weighted health 45%). Цветовые пороги (`itColorsScoreBoundaryAtWarningThreshold` /
  `...GreenAboveWarningThreshold` / `...RedAtErrorThreshold`) целенаправленно проверяют граничные значения
  (50.0 / 50.1 / 30.0) — не дубли, а разные точки одной границы.
- **TextFormatterTest, TextVerboseFormatterTest, HealthTextFormatterTest** — фиксация формата строки
  (`itOmitsTheAcceptedLevelFragmentWhenAbsent` содержит явный комментарий "Regression pin: byte-for-byte") —
  это заявленный контракт формата, не тавтология; см. правило брифа про пиннинг формулировки.
- **HealthBarRendererTest.php** — включает проверку арифметики ширины бара (`itCalculatesBarWidthCorrectly`,
  30 символов, 15 `#`/15 `.` при score=50) и C2-дельта фикстуру с реальным `MetricRepositoryInterface`-моком,
  вычисляющую childScore через `2*overallScore - flatScore` — не подстава ожидаемого числа, а честный расчёт.
- **HintRendererTest, DetailedFindingRendererTest, FindingSummaryRendererTest, TopIssuesRendererTest,
  OffenderListRendererDensityTest** — распространённый паттерн: разные Offender/Finding фикстуры → разные
  фрагменты текста, включая сортировку (`itReordersOffendersWhenRankingByDensity`,
  `itSortsNullDensityOffendersLastWhenRankingByDensity`) через `strpos`-сравнение позиций, что действительно
  проверяет порядок, а не просто наличие подстрок.
- **SuppressionCompositionBuilderTest.php** — явно спроектирован «один тест = один механизм» (см. докблок
  класса), плюс два защитных теста от конкретных багов (ledger-producer расхождение с `ruleName`,
  overlapping global exclude patterns) — образцовая структура, не тавтология.
- **ConfigurationErrorProjectionTest.php** — «один тест на каждый способ уйти из пайплайна» (Suppression,
  PathExclusion, NamespaceExclusion, Baseline, GitScope) + отдельный тест на measuredFindings — намеренно
  похожая структура (это разграничитель, а не тавтология: каждый тест бьёт свой этап пайплайна).
- **ArchitectureViolationSmokeTest.php** — по одному тесту на форматтер (Text/TextVerbose/Json/MetricsJson/
  Html/Checkstyle/Sarif/GitLab/Health/Summary/GithubActions), каждый проверяет специфичную для формата
  структуру вывода (валидность XML, счётчики через `count($run['results'])`, regex на `::error|warning|notice`)
  — не дубли, а honest per-format smoke coverage.

## Чего этот способ не видит

- **Только тела методов**, не SUT. Я не читал сами классы `HtmlTreeBuilder`, `SummaryFormatter`,
  `SuppressionCompositionBuilder` и т.д. — арифметика (30 мин на `complexity.ccn`, weighted average 87.5,
  bar width 30) сверяется по комментариям в тестах и внутренней согласованности, а не по независимому
  пересчёту от исходной формулы SUT. Если комментарий и код SUT расходятся, дефект такого рода не поймать.
- **Дубли между файлами волны 1 и волны 2 не проверялись** — сравнение шло только внутри волны 2 (18+1
  файлов), не против остальных ~660 файлов среза. Тот же паттерн `itFormatsWithNullMetrics` /
  `itBuildsWithNullMetrics` мог повториться в JSON/Sarif-группе первого агента — не проверено (граница брифа:
  "его файлов не трогай").
- **Обнаружение дублей — построчный diff двух явно похожих кандидатов**, не систематическая нормализация
  всех ~600 тел методов в этом срезе программно (кластеризация по хэшу нормализованного тела). Нашёлся один
  дубль, потому что бриф прямо указал на него и я проверил файл целиком; менее очевидные пары
  (структурно похожие, но с одним отличающимся полем) могли остаться незамеченными — как пограничный случай
  `itReturnsUnchangedReportWhenNoMetrics`/`itNullMetricsReturnsUnchangedReport` выше показывает, что почти-дубли
  существуют и за пределами явно найденного.
- **Пиннинг формулировки vs контракт формата** — граница оценивалась вручную (комментарии в коде типа
  "Regression pin"), не автоматическим критерием; в спорных случаях (например, `assertStringContainsString`
  на конкретный текст типа "sub-namespaces raise the score" в HealthBarRendererTest) решение "это контракт
  формата" принято по контексту, не формально доказано.
