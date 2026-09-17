### codex-01

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: pattern
- **domain**: tests
- **title**: После Stage 02 в `tests/` остались явные repository controls
- **mechanism**: три необследованных файла по-прежнему замыкают живую репозиторную популяцию на универсальное свойство или ручной census. Это соответствует принятому критерию repo-control, но файлы не попали ни в исходный verdict, ни в triage 100 кандидатов.
- **trigger**: воспроизводится при обычном добавлении нового computed metric, `SymbolType` или `Severity`: тест краснеет из-за изменения репозиторной популяции, а не из-за ошибки поведения на фиксированном входе.
- **in_scope**: нет — файлы не изменялись в диапазоне, но находка опровергает глобальный DoD этапа «`tests/` holds no control» и относится к 507 явно не прочитанным файлам.
- **anchor**:
  - `tests/Analysis/Evidence/ComputedMetrics/Unit/ComputedMetricDefaultsTest.php:17-160`
  - `tests/Core/Symbol/Unit/SymbolLevelProjectionTest.php:25-36`
  - `tests/Analysis/Finding/Unit/SeverityTest.php:64-103`
- **evidence**:
  - `ComputedMetricDefaultsTest` проверяет количество, полный список ключей и свойства каждого элемента живого `ComputedMetricDefaults::getDefaults()`.
  - `SymbolLevelProjectionTest::itCoversEverySymbolType()` сравнивает `SymbolType::cases()` с ручным provider и прямо декларирует отказ при появлении седьмого case.
  - `SeverityTest` требует универсальных свойств от каждого `Severity::cases()` и уникальности всего набора exit codes.
- **verification**: confirmed
- **verification_note**: файлы прочитаны полностью в релевантных местах; поиск подтвердил, что их нет в `controls-verdict.tsv` и трёх triage batch. Классификация сверена с правилом table-class: closure over all rows — repo-control.
- **fix_direction**: повторно классифицировать эти файлы по принятому критерию; целые controls и control-методы mixed-файлов перенести в предметные группы `governance/`. Отдельно продолжить чтение оставшихся 507 файлов, не выдавая сигнатурный фильтр за доказательство полноты.

### codex-02

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Conformance checker не проверяет вторую adjudication-коррекцию
- **mechanism**: скрипт утверждает, что учитывает обе коррекции, но код корректирует только классификацию `RatchetKeyGrammarTest`. Scope `DirectiveAuditReportReadingTest` берётся неизменённым из TSV, где отсутствует четвёртый control-метод `itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday`. Возврат этого метода в исходный файл оставит проверку зелёной.
- **trigger**: рукотворный, но реалистичный при повторном split/merge `DirectiveAuditReportReadingTest`; именно такой отказ должен доказывать conformance checker.
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-02/check-verdict-conformance.py:26-36`
- **evidence**:
  ```python
  corr={'tests/Analysis/Evidence/Measurement/Integration/Identity/RatchetKeyGrammarTest.php':'repo-control'}
  ...
  left={x.strip() for x in r['scope'].split(',') if x.strip()} & m
  if left: fail.append(('control method still in tests/',r['path']+' -> '+','.join(sorted(left))))
  ```
- **verification**: confirmed
- **verification_note**: TSV перечисляет три метода, а `two-witness-adjudication.md:69-76` добавляет четвёртый. В скрипте нет соответствующей поправки. Текущий код размещён правильно в governance, но exit 0 скрипта не доказывает именно этот факт.
- **fix_direction**: материализовать обе adjudication-коррекции в проверяемой authority и проверять не только отсутствие старого метода, но и его ожидаемое положительное местоположение.

### codex-03

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: architecture
- **title**: `SolePrimitiveOwnership` сгруппирован по форме контроля, а не по предмету
- **mechanism**: каталог объединяет несвязанные предметы только потому, что каждый тест доказывает «единственное место/владение». Изменение glob-синтаксиса, coupling classification, suppression options, `AnalysisContext`, namespace matching и версии пакета не имеет общего жизненного цикла.
- **trigger**: воспроизводится при обычном развитии любого из перечисленных предметов: связанное изменение вынуждено идти в общий механизм-каталог, а не в каталог охраняемого предмета.
- **in_scope**: да
- **anchor**: контракт предметной связности governance-групп из `AGENTS.md`
- **evidence**:
  - `AGENTS.md:107-111`: governance группируется по guarded subject, «never by the kind of artifact».
  - `governance/SolePrimitiveOwnership/GlobAlphabetSoleEnumerationTest.php:14-28` охраняет `Core/Util/GlobSyntax`.
  - `governance/SolePrimitiveOwnership/FrameworkClassificationSiteCountTest.php:10-33` охраняет coupling classification.
  - `governance/SolePrimitiveOwnership/SuppressionOptionKeyReaderCensusTest.php:14-39` охраняет Finding suppression options.
- **verification**: confirmed
- **verification_note**: содержимое всех семи файлов группы просмотрено; они относятся к разным production owners. Сам `controls-taxonomy.md` называет эту группу слабейшей и отмечает, что co-change для неё не проверялся.
- **fix_direction**: разнести controls по предметам, которые они охраняют, либо назвать и обосновать самостоятельный предмет с общим жизненным циклом. Не сохранять группу только из-за одинаковой формы утверждения.

### codex-04

- **reviewer**: codex
- **severity**: LOW
- **kind**: point
- **domain**: reliability
- **title**: Перенесённая channel fixture сама ссылается на несуществующий старый путь
- **mechanism**: файл переехал в `governance/Channel/Fixtures`, но его пояснение продолжает направлять читателя к удалённому `tests/Analysis/Finding/Integration/ChannelDeclarationFixtureDriftTest.php`.
- **trigger**: воспроизводится при обычной навигации разработчика от fixture к guard.
- **in_scope**: да
- **anchor**: `governance/Channel/Fixtures/declared.txt:50-52`
- **evidence**:
  ```text
  # The drift guard
  # (tests/Analysis/Finding/Integration/ChannelDeclarationFixtureDriftTest.php)
  # compares this file against
  ```
- **verification**: confirmed
- **verification_note**: старого пути нет; guard находится в `governance/Channel/ChannelDeclarationFixtureDriftTest.php`. Аналогичные текущие ссылки на старую директорию остались в трёх production docblocks.
- **fix_direction**: обновить текущие ссылки на новый governance-путь; исторические упоминания в ADR менять только если они описывают текущее, а не прежнее состояние.

## Coverage

- Прочитаны `AGENTS.md`/`CLAUDE.md`, `docs/ARCHITECTURE.md`, план Stage 02 и все семь обязательных measurement-документов.
- Проверен диапазон `52eae218..0aa7f642`, девять коммитов и полный `git diff --name-status`.
- Все 21 governance-группы существуют и симметрично зарегистрированы в `phpunit.xml.dist` и `testSuitePrefixTable()`. Несуществующих suite-директорий не найдено.
- Во всех числовых `dirname(__DIR__, N)` под `governance/` используется `N = 2`; все статически извлекаемые literal-цели существуют.
- Generated discovery содержит ровно 9198 cases: Unit 7184, Integration 437, Functional 203, Infrastructure 663, Governance 711.
- Мультимножество method/dataset suffix до и после миграции совпадает: split не потерял и не создал case. Проверены destinations семи triage-split и обозначенные в split-map shared helpers/constants.
- Проверены `SILENTLY_EXCLUDED`, `Suite::FILES`, dotted IDs в `Probes.php`, channel fixture consumers, suite classifier и namespace allow-list. Потерянных или неразрешимых записей не найдено.
- Все изменённые PHP-файлы вне намеренно сломанной fixture прошли `php -l`.
- `check-verdict-conformance.py` выполнен read-only и вернул 0, но ограничение из codex-02 делает этот результат неполным доказательством.
- `git diff --check` сообщает trailing tabs в `case-census.tsv`; это пустая последняя TSV-колонка, а не дефект данных.
- `composer check` и PHPUnit не перезапускались: они и perturbation требуют записи во временные каталоги, что запрещено текущим заданием. Поэтому динамическая мутационная проверка vacuous-green не выполнялась.
- 507 файлов ниже triage-порога не прочитаны полностью. Выполнен целевой поиск универсальных/census-утверждений; три подтверждённых остатка приведены в codex-01.

## Отклонённые находки

- `codex-r01 | Stale dirname делает moved control vacuously green | Все числовые глубины в governance равны 2, извлечённые literal-популяции существуют; динамическая perturbation запрещена.`
- `codex-r02 | PHPUnit продолжает объявлять пустую директорию | Все 69 объявленных директорий существуют; governance-группы совпадают с generator prefix table в обе стороны.`
- `codex-r03 | Split потерял test method или dataset | Нормализованные discovery-наборы до и после совпадают для всех 9198 cases.`
- `codex-r04 | Probes.php или Suite.php содержит старый исполняемый FQCN | Все перечисленные suite-файлы существуют, все извлечённые dotted test IDs найдены в текущем discovery.`
- `codex-r05 | finding-gate/enumeration-renames.tsv изменился из-за relocation | Дифф файла пуст, как требует план.`


