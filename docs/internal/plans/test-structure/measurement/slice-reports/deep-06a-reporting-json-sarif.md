18 из 18 файлов прочитаны целиком, включая тела всех методов (не только структура/список методов).

## Файлы и их пути (для справки)

1. tests/Unit/Reporting/Formatter/JsonFormatterTest.php (1692 стр.)
2. tests/Unit/Reporting/Formatter/Json/JsonFindingSectionTest.php (502 стр.)
3. tests/Unit/Reporting/Formatter/Json/JsonHealthSectionTest.php (225 стр.)
4. tests/Unit/Reporting/Formatter/Json/JsonOffenderSectionDensityTest.php (198 стр.)
5. tests/Unit/Reporting/Formatter/Json/JsonSanitizerTest.php (127 стр.)
6. tests/Reporting/GraphProjection/Unit/JsonGraphExporterTest.php (389 стр.)
7. tests/Unit/Reporting/Formatter/MetricsJsonFormatterTest.php (363 стр.)
8. tests/Unit/Reporting/Formatter/SarifFormatterTest.php (909 стр.)
9. tests/Unit/Reporting/Formatter/Sarif/SarifFormatterPosixSeparatorTest.php (139 стр.)
10. tests/Unit/Reporting/Formatter/Sarif/SarifRuleCollectorTest.php (382 стр.)
11. tests/Reporting/Formatter/Sarif/Integration/SarifRuleDescriptorCoverageTest.php (242 стр.)
12. tests/Unit/Reporting/Formatter/Sarif/SarifSchemaValidationTest.php (262 стр.)
13. tests/Unit/Reporting/Formatter/CheckstyleFormatterTest.php (426 стр.)
14. tests/Unit/Reporting/Formatter/GitLabCodeQualityFormatterTest.php (612 стр.)
15. tests/Unit/Reporting/Formatter/GithubActionsFormatterTest.php (398 стр.)
16. tests/Unit/Reporting/Formatter/FormatterRegistryTest.php (207 стр.)
17. tests/Reporting/FindingProjection/Unit/FindingProjectorTest.php (1070 стр.)
18. tests/Unit/Reporting/Formatter/Support/FindingSorterTest.php (243 стр.)

## Таблица: файл | метод | строка | класс дефекта | чем подтверждается

Формальных дефектов классов «тавтология / дубль / имя лжёт содержанию» в этом срезе **не найдено**. Таблица пуста; ниже — раздел «чисто» с пограничными случаями, которые были рассмотрены и отклонены как не подпадающие под определение.

## Чисто

Все 18 файлов дают содержательные assert'ы, которые проверяют реальное поведение SUT (форматтеров Json/Sarif/Checkstyle/GitLab/Github, JsonSanitizer, JsonGraphExporter, FormatterRegistry, FindingSorter, FindingProjector) по независимо построенным ожиданиям (константы, явно заданные значения, реальный DI-контейнер), а не по спискам, переписанным с самого SUT.

Пограничные случаи, рассмотренные и отклонённые:

1. **FormatterRegistryTest::itDeclaresKeysOfFormattersHiddenFromListings** (FormatterRegistryTest.php:142) — тест использует магическое имя `'text-verbose'`, совпадающее с приватной константой `FormatterRegistry::HIDDEN_FORMATTERS`. Это связывание с деталью реализации, но НЕ тавтология по определению брифа: тест не воспроизводит список SUT рядом, а полагается на один хардкод-литерал с тем же значением. Само поведение (скрытие из листинга) проверяется реально.

2. **GitLabCodeQualityFormatterTest::itKeepsLegacyFingerprintsAndSeparatesTargetOnlyEdges** (GitLabCodeQualityFormatterTest.php:252) и **SarifFormatterTest::itKeepsLegacyFingerprintsAndSeparatesTargetOnlyEdges** (SarifFormatterTest.php:116) — одинаковое имя метода и почти одинаковая структура фикстур (4 edge-finding'а, магические числа `:15:`/`:14:` в ожидаемых строках). НЕ дубль по определению брифа: разные SUT (GitLabCodeQualityFormatter против SarifFormatter), разные форматы результата (GitLab хеширует md5, Sarif сравнивает сырую строку `primaryLocationLineHash` без хеширования) — это параллельное покрытие одного и того же контракта идентичности через два разных форматтера, а не повтор одного и того же утверждения.

3. **JsonFormatterTest::itIncludesShownCountInFindingsMeta** (JsonFormatterTest.php:1186, 55 findings) и **itShowsShownEqualsTotalInFindingsMeta** (JsonFormatterTest.php:1215, 10 findings) — пересекающееся покрытие (оба проверяют `shown === total` при дефолтных опциях), но тела не идентичны: разное количество findings, первый дополнительно проверяет `limit === null`. Не дубль в строгом смысле (нет побайтово одинаковых тел под разными именами).

4. **JsonFormatterTest::itIncludesAllFindingsByDefault** (detailLimit не задан → null) и **itShowsAllFindingsWhenDetailEnabled** (detailLimit: 0) — проверено: дефолт `FormatterContext::$detailLimit` равен `null`, а не `0` (см. src/Reporting/FormatterContext.php:42). Это две разные конфигурации (detail-режим выключен вовсе / явно включён с «без лимита»), а не дублирующий сетап с одинаковым результатом по совпадению — не дефект.

5. **SarifRuleDescriptorCoverageTest::UNIVERSE_CHANNEL_COUNT = 58** и **DOCS_BASE_URI** литерал (SarifRuleDescriptorCoverageTest.php:45,54) — хардкод-константы, дублирующие фактические значения SUT/окружения. Оба явно и подробно задокументированы в докблоках как осознанное решение (число каналов — с объяснением, откуда оно берётся; DOCS_BASE_URI — «duplicated on purpose so the test asserts the collector's actual output against an independently stated expectation»). Не тавтология: сравнение идёт с реальным выводом `SarifRuleCollector`, а не само с собой.

6. **MetricsJsonFormatterTest::itGivesEveryDeclarationKindAPublicationPosition** (MetricsJsonFormatterTest.php:356) — читает константу `MetricsJsonFormatter::DECLARATION_KINDS` через Reflection и сравнивает с `SymbolType::cases()`. Это не тавтология: источник истины (enum `SymbolType`) независим от константы SUT, тест реально проверяет инвариант «каждый case enum'а имеет позицию публикации».

7. **SarifFormatterPosixSeparatorTest** (обе проверки) — входные `RelativePath` уже заданы в POSIX-форме (`'src/Sub/Dir/Foo.php'`), поэтому тест не может напрямую продемонстрировать конверсию бэкслэшей → слэшей на входе. Но это не тавтология: проверяется, что форматтер НЕ вносит бэкслэши на выходе (`assertStringNotContainsString('\\', $uri)`), что является реальным (хоть и более слабым, чем название могло бы предполагать) регрессионным пином, подтверждённым докблоком, ссылающимся на ADR 0015.

## Чего этот способ не видит

- **Не запускались тесты и не собирался DI-контейнер.** Часть тестов (SarifRuleCollectorTest, SarifFormatterTest, SarifRuleDescriptorCoverageTest) строит реальный контейнер и сверяется с реальными описаниями каналов — корректность самих описаний (a не структуры теста) не проверялась.
- **Чтение без запуска не ловит семантические дефекты уровня "assert технически верен, но SUT сам содержит баг"** — верифицировалась только форма теста (что и как он проверяет), а не то, совпадает ли ожидаемое поведение с продуктовым намерением за пределами документированного в докблоках.
- **Магические числа в строках-фикстурах (`:15:`, `:14:` в fingerprint-тестах) не были прослежены до формулы SUT** — не открывался код, формирующий эти offset'ы (вероятно, длины путей/строк), поэтому нельзя исключить, что сами числа были получены копированием фактического вывода SUT, а не независимым расчётом. Это способ не видит по построению: тело теста и есть единственный источник; чтобы проверить независимость числа, нужно было бы прочитать код форматтера и посчитать вручную.
- **Дубли между этим срезом и остальными 661 файлами среза не проверялись** — задание ограничено 18 файлами первой половины; вторую половину читает другой агент, пересечения не сверялись.
- **Не проверялась whitespace/форматирование помимо тела метода** (например, действительно ли `#[CoversClass]` указывает верный класс) — считано на глаз при чтении use-секции, отдельно не верифицировалось через Serena/grep.
