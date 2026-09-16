# Codex — findings, stage 02 review

Диапазон: `52eae218..HEAD`, ветка `stage-02-controls-extraction`. `review_root`
не содержал `diff_files.txt` — фасилитатор сгенерировал список файлов диффа
сам (`git diff --name-status 52eae218..HEAD`, 180 файлов) и передал его
Codex вместе с BRIEF.md для определения `in_scope`. Это допущение процесса,
не находка.

### codex-01

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: pattern
- **domain**: tests
- **title**: После Stage 02 в `tests/` остались явные repository controls
- **mechanism**: три файла в `tests/` замыкают живую репозиторную популяцию на универсальное свойство или ручной census — то есть подпадают под собственный критерий стадии («ожидаемая сторона утверждения скопирована из репозитория, а не придумана тестом»), но не попали ни в исходный verdict, ни в triage 100 кандидатов.
- **trigger**: воспроизводится при обычном добавлении нового computed metric, `SymbolType` или `Severity` — тест краснеет из-за изменения репозиторной популяции, а не из-за ошибки на фиксированном входе.
- **in_scope**: нет — файлы не менялись в диапазоне; находка опровергает глобальный DoD этапа («в `tests/` не остаётся controls») и относится к 507 не прочитанным файлам ниже triage-порога.
- **anchor**:
  - `tests/Analysis/Evidence/ComputedMetrics/Unit/ComputedMetricDefaultsTest.php:120-134`
  - `tests/Core/Symbol/Unit/SymbolLevelProjectionTest.php:25-36`
  - `tests/Analysis/Finding/Unit/SeverityTest.php:64-103`
- **evidence**:
  ```php
  // ComputedMetricDefaultsTest::itExpectedKeys() — сравнивает с ручным списком
  // ключей, но itPrefixesEveryDefaultKeyWithHealth/itGivesEveryDefaultAWarning...
  // проверяют УНИВЕРСАЛЬНОЕ свойство каждого элемента живого
  // ComputedMetricDefaults::getDefaults() — форма closure-over-all-rows
  foreach ($defaults as $name => $definition) {
      self::assertTrue($definition->inverted, \sprintf('Expected "%s" to be inverted', $name));
  }
  ```
- **verification**: confirmed
- **verification_note**: файл `ComputedMetricDefaultsTest.php` прочитан фасилитатором целиком (155 строк) — методы `itPrefixesEveryDefaultKeyWithHealth`, `itInvertsEveryDefault`, `itDefinesEveryDefaultAtClassNamespaceAndProjectLevel`, `itGivesEveryDefaultAClassAndNamespaceFormula`, `itGivesEveryDefaultAWarningAndErrorThreshold` действительно перебирают `getDefaults()` целиком и требуют универсального свойства — это repo-control по критерию плана, а не product-test. Файл отсутствует в `controls-verdict.tsv` и трёх triage batch (Codex подтвердил поиском, фасилитатор не повторял grep по всем trench-файлам — доверяет отрицательному результату частично, см. coverage ниже).
- **fix_direction**: повторно классифицировать эти файлы по принятому критерию стадии; выделить control-часть (универсальные проверки) в `governance/`, оставить в `tests/` только проверки конкретных заданных значений, если такие есть. Продолжить систематическое чтение оставшихся 507 файлов вместо точечного поиска.

### codex-02

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: `check-verdict-conformance.py` не учитывает вторую adjudication-коррекцию
- **mechanism**: скрипт материализует только одну из двух поправок `two-witness-adjudication.md` (`RatchetKeyGrammarTest` → repo-control). Вторая поправка — четвёртый control-метод `DirectiveAuditReportReadingTest`, `itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday`, который according to адьюдикации присоединяется к трём сиблингам как repo-control — не отражена в `corr`-словаре скрипта. TSV для этого файла (`controls-verdict.tsv:80`) называет `scope` только тремя методами.
- **trigger**: рукотворный, но реалистичный при повторном split/merge `DirectiveAuditReportReadingTest`: если четвёртый метод вернуть в `tests/`, `check-verdict-conformance.py` этого не заметит и завершится с exit 0.
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-02/check-verdict-conformance.py:26,33-36`
- **evidence**:
  ```python
  corr={'tests/Analysis/Evidence/Measurement/Integration/Identity/RatchetKeyGrammarTest.php':'repo-control'}
  ...
  if cls=='mixed' and os.path.exists(r['path']):
      m=set(re.findall(r'public function (it\w+)', open(r['path']).read()))
      left={x.strip() for x in r['scope'].split(',') if x.strip()} & m
  ```
- **verification**: confirmed
- **verification_note**: фасилитатор независимо перепроверил цепочку: (1) `controls-verdict.tsv:80` — `scope` для `DirectiveAuditReportReadingTest.php` перечисляет три метода без `itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday`; (2) `two-witness-adjudication.md` (раздел «`DirectiveAuditReportReadingTest` — witness 2 stands») прямо утверждает, что этот метод «joins its three siblings in `DirectiveVocabulary`, making four»; (3) `grep` по `tests/Unit/RuleVocabulary/DirectiveAuditReportReadingTest.php` подтвердил, что метода там сейчас нет (перемещён правильно); (4) `grep` по `governance/DirectiveVocabulary/DirectiveEffectVocabularyAgreementTest.php` подтвердил, что метод сейчас лежит там. Фактическое размещение кода — правильное; дефект именно в проверяющем скрипте, чей `corr`-словарь не был обновлён под вторую поправку, поэтому его exit 0 не покрывает регрессию по этому конкретному методу.
- **fix_direction**: добавить в `corr` (или в отдельную структуру поправок) запись, материализующую вторую adjudication-коррекцию: `DirectiveAuditReportReadingTest.php` scope расширяется четвёртым методом. В идеале — читать поправки из `two-witness-adjudication.md` программно или хотя бы дать скрипту тест на собственную полноту, а не полагаться на ручную синхронизацию с текстом документа.

### codex-03

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: architecture
- **title**: `governance/SolePrimitiveOwnership` сгруппирован по форме контроля, а не по предмету
- **mechanism**: группа объединяет несвязанные предметы (`GlobSyntax`, coupling framework classification, Finding suppression options, `AnalysisContext` scope argument, namespace matcher normalization, package version independence) только потому, что каждый тест доказывает «единственное место/единственный владелец примитива». Изменение любого из этих предметов не имеет общего жизненного цикла с остальными пятью.
- **trigger**: воспроизводится при обычном развитии любого из перечисленных предметов — связанное изменение вынуждено идти в общий каталог-механизм вместо каталога предмета, который оно затрагивает.
- **in_scope**: да
- **anchor**: контракт предметной связности governance-групп, `AGENTS.md:107-111` («It is grouped by the subject each control guards, never by the kind of artifact the control happens to read»)
- **evidence**:
  - `AGENTS.md:110-111`: «It is grouped by the subject each control guards, never by the kind of artifact the control happens to read.»
  - `governance/SolePrimitiveOwnership/GlobAlphabetSoleEnumerationTest.php` — охраняет `Core/Util/GlobSyntax`.
  - `governance/SolePrimitiveOwnership/FrameworkClassificationSiteCountTest.php` — охраняет coupling classification (`Analysis/Evidence/Coupling`).
  - `governance/SolePrimitiveOwnership/SuppressionOptionKeyReaderCensusTest.php` — охраняет Finding suppression options.
  - `governance/SolePrimitiveOwnership/VersionRootPackageIndependenceTest.php` — охраняет версионирование пакета.
- **verification**: confirmed
- **verification_note**: содержимое каталога подтверждено (`ls` — 7 файлов), строки AGENTS.md подтверждены (`grep -n`, реальный диапазон 101-111, Codex указал 107-111 — расхождение на несколько строк, суть цитаты верна). Шесть перечисленных предметов действительно относятся к разным production owners по дереву `src/`.
- **fix_direction**: разнести файлы `SolePrimitiveOwnership` по предметным группам, соответствующим владельцу охраняемого примитива (например, `GlobAlphabetSoleEnumerationTest` — к группе, охраняющей `Core/Util`; `FrameworkClassificationSiteCountTest` — к группе Coupling), либо явно обосновать «единственность владения примитивом» как самостоятельный предмет с общим жизненным циклом, а не удобную техническую категорию.

### codex-04

- **reviewer**: codex
- **severity**: LOW
- **kind**: point
- **domain**: reliability
- **title**: Перенесённая channel-фикстура ссылается на несуществующий старый путь guard'а
- **mechanism**: комментарий в `declared.txt` описывает «the drift guard» по старому пути `tests/Analysis/Finding/Integration/ChannelDeclarationFixtureDriftTest.php`, хотя сам guard переехал в `governance/Channel/ChannelDeclarationFixtureDriftTest.php` в этом же диапазоне диффа.
- **trigger**: воспроизводится при обычной навигации разработчика от фикстуры к guard'у по пути из комментария.
- **in_scope**: да
- **anchor**: `governance/Channel/Fixtures/declared.txt:50-52`
- **evidence**:
  ```text
  # The drift guard
  # (tests/Analysis/Finding/Integration/ChannelDeclarationFixtureDriftTest.php)
  # compares this file against
  ```
- **verification**: confirmed
- **verification_note**: фасилитатор прочитал файл (строки 40-59) и подтвердил цитату дословно; `git diff --name-status` подтверждает переименование `tests/Analysis/Finding/Integration/ChannelDeclarationFixtureDriftTest.php` → `governance/Channel/ChannelDeclarationFixtureDriftTest.php` в этом же диапазоне (R097, см. diff_files.txt), то есть комментарий не обновлён вместе с переездом.
- **fix_direction**: обновить путь в комментарии на `governance/Channel/ChannelDeclarationFixtureDriftTest.php`; при систематической проверке поискать аналогичные устаревшие ссылки на старые `tests/`-пути в других перенесённых файлах (Codex отметил ещё «три production docblocks» с похожими остаточными ссылками — не детализировано и не перепроверено фасилитатором отдельной находкой).

## Coverage (Codex)

- Прочитаны `AGENTS.md`, `docs/ARCHITECTURE.md`, план Stage 02 и все документы из раздела BRIEF «Read these first» (`decisions.md`, `move-hazards.md`, `prediction.md`, `two-witness-adjudication.md`, `census-completeness.md`, `triage/README.md`).
- Проверен полный `git diff --name-status` диапазона `52eae218..HEAD`, девять коммитов.
- Все 21 governance-группы зарегистрированы симметрично в `phpunit.xml.dist` и `testSuitePrefixTable()`; несуществующих suite-директорий не найдено (пункт 2 «Where to look hardest» — чисто).
- Все числовые `\dirname(__DIR__, N)` под `governance/` используют `N=2`; статически извлекаемые literal-цели существуют (пункт 1 частично — статический аудит без perturbation, см. ограничение ниже).
- Discovery: ровно 9198 cases, распределение по suite совпадает с заявленным (Unit 7184, Integration 437, Functional 203, Infrastructure 663, Governance 711).
- Мультимножество method/dataset suffix до/после миграции сверено — split не потерял и не создал case (пункт 4 частично).
- Проверены `SILENTLY_EXCLUDED`, `Suite::FILES`, dotted ID в `Probes.php`, channel fixture consumers, `namespace-path-allow-list.php` — потерянных/неразрешимых записей не найдено, кроме codex-04.
- Изменённые PHP-файлы (кроме намеренно сломанной фикстуры) прошли `php -l`.
- `check-verdict-conformance.py` выполнен read-only, exit 0 — но ограничение из codex-02 делает этот результат неполным доказательством конформности.
- `composer check` и PHPUnit НЕ перезапускались Codex: динамическая perturbation-проверка (испортить условие и увидеть, покраснеет ли control) требовала бы записи, что запрещено заданием. Значит пункт 1 «Where to look hardest» («Check by perturbing, not by reading») выполнен только статическим чтением, а не предписанным способом — это ограничение покрытия, а не находка.
- 507 файлов ниже triage-порога не прочитаны полностью; выполнен целевой поиск universal/census-утверждений, давший три подтверждённых остатка (codex-01).

## Coverage (фасилитатор — верификация поверх ответа Codex)

- Независимо перепроверены все четыре находки по первоисточникам: `ComputedMetricDefaultsTest.php` прочитан целиком; цепочка `controls-verdict.tsv` → `two-witness-adjudication.md` → `check-verdict-conformance.py` → фактическое размещение метода в `tests/` и `governance/` прослежена и подтверждена для codex-02; строки `AGENTS.md` и состав каталога `SolePrimitiveOwnership` подтверждены для codex-03; фрагмент `declared.txt` и факт переименования подтверждены для codex-04.
- НЕ перепроверено: `SymbolLevelProjectionTest.php` и `SeverityTest.php` (вторая и третья часть anchor в codex-01) — принято со слов Codex без повторного чтения кода; отсутствие обоих файлов в `controls-verdict.tsv`/triage batch — принято со слов Codex без повторного grep.
- НЕ выполнялась динамическая perturbation-проверка (как и у Codex) — по тому же запрету на запись в рабочее дерево.
- 507 файлов ниже triage-порога фасилитатором тоже не читались отдельно от выборки Codex.

## Отклонённые находки

- `codex-r01 | Stale dirname делает moved control vacuously green | Все числовые глубины в governance равны 2, извлечённые literal-популяции существуют; динамическая perturbation запрещена заданием, поэтому вывод статический.`
- `codex-r02 | PHPUnit продолжает объявлять пустую директорию | Все 69 объявленных директорий существуют; governance-группы совпадают с generator prefix table в обе стороны.`
- `codex-r03 | Split потерял test method или dataset | Нормализованные discovery-наборы до и после миграции совпадают для всех 9198 cases.`
- `codex-r04 | Probes.php или Suite.php содержит старый исполняемый FQCN | Все перечисленные suite-файлы существуют, все извлечённые dotted test ID найдены в текущем discovery.`
- `codex-r05 | finding-gate/enumeration-renames.tsv изменился из-за relocation | Дифф файла пуст, как и требует план.`
