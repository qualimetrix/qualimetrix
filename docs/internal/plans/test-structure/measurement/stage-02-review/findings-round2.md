# Stage 02 — review round 2 (fixes only)

**Range:** `0aa7f642..HEAD` — two commits (`d5f3116f`, `5b52ee7e`). The task
brief said three; only two exist in that range, so `0aa7f642` itself was read
as context, not as material.

**Method:** every "it works" claim below is backed by a refusal on a planted
breakage in a copy of the tree (`mktemp -d`, `git archive HEAD` + the
export-ignored roots copied in, `composer dump-autoload` re-run so PSR-4
resolves inside the copy and not back into the reviewed tree). Nothing in the
reviewed working tree was written.

---

### claude-01

- **reviewer**: claude
- **severity**: HIGH
- **kind**: point
- **domain**: tests
- **title**: Переименование остающейся половины выводит `mixed`-строку из поля зрения `check-verdict-conformance.py` — и этот раунд создал третий такой случай
- **mechanism**: `mixed`-ветка проверки начинается с `os.path.exists(r['path'])`, где `path` — путь файла ДО переезда. Этап принял конвенцию: если имя остающейся половины описывало уехавший контроль, файл переименовывают. После переименования путь из `controls-verdict.tsv` не существует, ветка `mixed` молча пропускается целиком, и проверка не смотрит ни на один контрольный метод этого файла. Отказа на «`mixed`-строка, чей путь исчез» в скрипте нет — исчезнувший путь для `mixed` вообще ничего не значит, хотя он означает ровно одно из двух: файл переименован или уехал целиком (то есть вместе с ним уехала продуктовая половина).
- **trigger**: воспроизводится в нормальной работе — достаточно вернуть уехавший контрольный метод в переименованный файл; проверка остаётся зелёной
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-02/check-verdict-conformance.py:36-41`
- **evidence**:
  ```python
  if cls=='mixed' and os.path.exists(r['path']):
      m=set(re.findall(r'public function (it\w+)', open(r['path']).read()))
  ```
  Живые тёмные строки (посчитано по популяции скрипта — `controls-verdict.tsv` + `triage/batch-*.tsv`):
  - `tests/Analysis/Evidence/ComputedMetrics/Integration/BenchmarkConsumersCoverageTest.php` — переименован **в этом раунде** (`d5f3116f`) в `BenchmarkCoverageRefusalTest.php`;
  - `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGovernanceIntegrationTest.php` — остающаяся половина уехала в `ModularArchitectureGeneratorRefusalTest.php` (раунд 1);
  - `tests/Analysis/Policy/Architecture/Unit/LayersValidatorMembershipRefusalGuardTest.php` — остающаяся половина в `LayersValidatorEmptyMembershipRefusalTest.php` (раунд 1).

  Подсадка в копии: в `tests/.../BenchmarkCoverageRefusalTest.php` добавлен метод `itKeepsTrackedBenchmarkConsumersOnTheCheckCommand` (тот самый, что `controls-verdict.tsv` называет уехавшим контролем) → `python3 check-verdict-conformance.py` даёт **exit 0**.
  Контроль: тот же файл с тем же методом под ДОрениймным именем `BenchmarkConsumersCoverageTest.php` → **exit 1**, `[control method still in tests/] ... -> itKeepsTrackedBenchmarkConsumersOnTheCheckCommand`.
- **verification**: confirmed
- **verification_note**: измерено подсадкой и контролем на копии дерева. Обе тёмные строки раунда 1 проверены поимённо и расколоты ПРАВИЛЬНО — дефект не в расколе, а в том, что проверка их больше не судит: у `ModularArchitectureGovernanceIntegrationTest` в `governance/` ровно 4 метода, названные split-map §7 уезжающими, а 3 оставшихся — в `ModularArchitectureGeneratorRefusalTest`; у `LayersValidatorMembershipRefusalGuardTest` в `governance/` ровно 2 метода из колонки `control_methods` batch-2, а единственный оставшийся — в `LayersValidatorEmptyMembershipRefusalTest` (сверено с редакцией `63224f38^`). Калибровка severity: скрипт по собственному докстрингу «one-off conformance check, not a tracked guard» и в `composer check` НЕ входит — HIGH поставлен за то, что это единственная проверка Definition of Done всего этапа, а не за участие в гейте; понизить сознательно — право оркестратора
- **fix_direction**: `controls-verdict.tsv` по BRIEF не редактируется, поправки живут в скрипте — значит нужна третья карта того же вида, что `corr` и `scope_corr`: старый путь → нынешний, и разбор `mixed` по новому пути. Отдельно и обязательно — отказная ветка «`mixed`-строка, чей путь исчез и не покрыт картой»: сегодня отсутствие файла для `mixed` не значит ничего, а должно значить «объясни, куда он делся». Стоит отметить, что скрипт усиливали в том же коммите, где переименование увело из-под него третий файл

---

### claude-02

- **reviewer**: claude
- **severity**: HIGH
- **kind**: pattern
- **domain**: tests
- **title**: Дублирование data-провайдера при расколе разорвало связь «перепись проверяет тот список, который исполняет поведенческий тест»
- **mechanism**: Правило этапа — дублировать общие хелперы, а не шарить их. В двух расколах из восьми общий хелпер и БЫЛ предметом переписи: контрольный метод утверждал «список кейсов провайдера равен `Enum::cases()`», а тот же провайдер питал поведенческий тест в том же классе. После раскола перепись уехала в `governance/` вместе с ПРИВАТНОЙ КОПИЕЙ провайдера и теперь сверяет с энумом свою копию. Копия в `tests/`, которая реально гоняет поведенческие кейсы, не сверяется ни с чем. Обе копии сегодня совпадают, но ничто этого не требует: список в `tests/` может потерять кейс, и вся сборка останется зелёной.
- **trigger**: воспроизводится в нормальной работе — любая правка провайдера в `tests/` без правки копии в `governance/`
- **in_scope**: да
- **anchor**: `governance/SymbolVocabulary/SymbolTypeProjectionCensusTest.php:38-49` ↔ `tests/Core/Symbol/Unit/SymbolLevelProjectionTest.php`; `governance/FindingVocabulary/FindingFilterStageMembershipCensusTest.php:29-41` ↔ `tests/Analysis/Finding/Unit/FindingFilterStageTest.php`
- **evidence**:
  ```php
  // governance/SymbolVocabulary/SymbolTypeProjectionCensusTest.php
  private static function provideDeclarationKinds(): iterable
  {
      yield 'a method is a callable' => [SymbolType::Method, SymbolLevel::Callable];
  ```
  Подсадка в копии: из `tests/Core/Symbol/Unit/SymbolLevelProjectionTest.php::provideDeclarationKinds()` удалён `yield 'the project'`, из `tests/Analysis/Finding/Unit/FindingFilterStageTest.php::provideStageMembership()` удалён `yield 'git scope'` → прогон `SymbolLevelProjectionTest|SymbolTypeProjectionCensusTest|FindingFilterStageTest|FindingFilterStageMembershipCensusTest` даёт **OK (11 tests)**, ни одна перепись не заметила.
  Контроль: тот же файл в редакции `0aa7f642` (до раскола, перепись и провайдер в одном классе) с тем же удалением → **FAILURES, 1 failure**, `SymbolLevelProjectionTest.php:33`, в диффе отсутствует `5 => 'project'`.
- **verification**: confirmed
- **verification_note**: подсадка + контроль на ДОраскольной редакции того же файла; сегодняшние копии провайдеров побайтово совпадают (сверено по строкам `yield`), так что дефект — потерянная гарантия, а не текущее расхождение. Остальные шесть расколов чисты по этому основанию: `AllowAliasExpanderTest` увёл провайдер вместе с методом (14 кейсов в `governance/`, 20 остались — ни одна половина не пуста), у `Severity`, `MatchMode`, `ChannelLevelSelector`, `MetricsJsonFormatter` и `Finding` дублированных провайдеров нет
- **fix_direction**: там, где предмет переписи — сам список, перепись должна читать список, который исполняет поведенческий тест, а не свою копию (общий источник, публичный провайдер, читаемый обеими сторонами, или перенос поведенческой половины туда же). Либо, если дублирование сохраняется как правило этапа, нужен явный отказ на расхождение двух копий — иначе правило «дублируй, а не шарь» применено к случаю, для которого оно неверно

---

### claude-03

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: `check-verdict-conformance.py` проверяет только УБЫТИЕ и никогда ПРИБЫТИЕ — удалённый контроль неотличим от переехавшего
- **mechanism**: Все три условия скрипта — об отсутствии: `repo-control` не должен существовать под `tests/`, `product-test` должен существовать, контрольный метод не должен остаться в `tests/`. Слова `governance` в скрипте нет вообще. Поэтому удаление контроля (файла целиком или метода) выглядит для проверки ровно как корректный переезд. Компенсирующего стража нет: REPORT §E сам фиксирует, что ни одна группа не несёт пола на собственный размер, а `phpunit.xml.dist` на исчезнувшие файлы не краснеет. Вторая половина того же дефекта популяции: из `triage/batch-*.tsv` в проверку берутся только `repo-control` и `mixed` (`if c[1] in ('repo-control','mixed')`), поэтому 85 триажных `product-test` не проверяются на «исчез» вовсе — асимметрия с `controls-verdict.tsv`, где 15 `product-test` проверяются.
- **trigger**: воспроизводится в нормальной работе — любое удаление вместо переноса
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-02/check-verdict-conformance.py:22-42`
- **evidence**:
  ```python
  if cls=='repo-control' and os.path.exists(r['path']): fail.append(('control still in tests/',r['path']))
  if cls=='product-test' and not os.path.exists(r['path']): fail.append(('product-test vanished',r['path']))
  ```
  Подсадка в копии: удалены три прибывших файла — `governance/RepositoryEntrypoints/BenchmarkScriptEntrypointCoverageTest.php`, `governance/FindingVocabulary/SeverityVocabularyTest.php`, `governance/HealthVocabulary/ComputedMetricDefaultsTest.php` (по одному на каждую форму: расколотая половина, новая половина этого раунда, файл, уехавший целиком) → **exit 0**.
- **verification**: confirmed
- **verification_note**: дефект пред-существующий, не внесён этим раундом; якорь в диффе, потому что раунд правил ровно этот файл и объявил его усиленным. Это прямой ответ на вопрос задания «что она ВСЁ ЕЩЁ не проверяет»
- **fix_direction**: добавить симметричную сторону — для каждой ушедшей единицы (файл или именованный метод) требовать её присутствия под `governance/` — и сделать `triage`-популяцию такой же полной, как `controls-verdict.tsv`, чтобы `product-test` из триажа тоже судился. Отдельный вопрос владельцу: полом на размер группы это не заменяется, потому что пол считает кейсы, а не называет их

---

### claude-04

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: architecture
- **title**: `FindingVocabulary` названа формой утверждения, а не предметом, и её собственное имя не покрывает одного из четырёх членов
- **mechanism**: ADR 0016 требует, чтобы имя каталога отвечало на «про что это», а не описывало форму содержимого. `FindingVocabulary` собрала четыре контроля, чьё единственное общее свойство — форма претензии «замкнутое множество вокруг находки, которое кто-то обязан перечислить». `FindingFieldCensusTest` под это имя не помещается вовсе: его предмет — список ПАРАМЕТРОВ КОНСТРУКТОРА `Finding`, читаемый рефлексией, а не словарь. Со-изменение расходится: добавление кейса в `Severity` трогает один файл, добавление стадии в `FindingFilterStage` — два других, добавление поля в `Finding` — четвёртый. Это ровно обвинение, вынесенное `SolePrimitiveOwnership` в раунде 1 (`claude-03`/`codex-03`), которое было отложено владельцу — а раунд исправлений добавил по той же оси `*Vocabulary` ещё три группы, то есть поверхность отложенного решения выросла.
- **trigger**: недостижим как рантайм-дефект — структурное нарушение принятого правила раскладки
- **in_scope**: да
- **anchor**: ADR 0016 (subject cohesion) / ADR 0022; каталог `governance/FindingVocabulary/` (4 файла), в меньшей степени `governance/LayerPolicyVocabulary/` (2 файла)
- **evidence**: состав `governance/FindingVocabulary/`: `SeverityVocabularyTest.php` (энум `Severity`), `FindingFilterStageMembershipCensusTest.php` (энум `FindingFilterStage`), `SuppressionMechanismTest.php` (энум `Reporting\FindingProjection\SuppressionMechanism` и его отображение стадий), `FindingFieldCensusTest.php` (`(new ReflectionClass(Finding::class))->getConstructor()->getParameters()`). Четвёртый — не словарь. `governance/LayerPolicyVocabulary/` объединяет `DependencyType` (владелец — `Analysis\Evidence\DependencyModel`) и `MatchMode` (владелец — `Analysis\Policy\Architecture\Layer`): два энума разных владельцев, общее — только «конфигурационная поверхность layer policy». `governance/SymbolVocabulary/` этим основанием НЕ обвиняется: оба её файла про полноту одного `SymbolType`, со-изменение сходится.
- **verification**: confirmed
- **verification_note**: со-изменение по git-истории для новых групп неизмеримо — файлы созданы одним коммитом; вердикт вынесен по тесту имени и по несовпадению имени группы с предметом её члена, а не по измерению истории
- **fix_direction**: решать вместе с отложенной находкой D раунда 1, а не отдельно: либо назвать предметы (перепись полей `Finding` — это про форму контракта находки, а не про его словарь), либо зафиксировать `*Vocabulary` как признанную ось с явной записью, что именно она значит и что под неё НЕ кладут. Оставлять как есть без записи опасно по той же причине, что и `SolePrimitiveOwnership`: под имя «словарь находки» по построению подходит любой будущий контроль формы «замкнутое множество»

---

### claude-05

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: Сообщение коммита называет шесть починенных ссылок, а починено восемь — и это тот самый коммит, который чинил разошедшиеся записи
- **mechanism**: `d5f3116f` пишет «Six path references living in comments no longer resolved», REPORT того же раунда пишет «Seven paths … an eighth sits in a comment inside a relocated fixture» и «**Fixed here:** the eight sites», и в диффе действительно восемь правок ссылок в комментариях. Следующий коммит `5b52ee7e` существует именно чтобы согласовать записи раунда с тем, что сделано, и этого расхождения не поймал.
- **trigger**: недостижим как рантайм-дефект — расхождение записи с деревом
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-02-review/REPORT.md:34` ↔ тело коммита `d5f3116f`
- **evidence**: восемь мест в диффе `0aa7f642..HEAD`: `src/Analysis/Evidence/CodeSmell/AbstractCodeSmellRule.php`, `src/Analysis/Finding/Contract/ChannelDeclarationRegistryInterface.php`, `src/Analysis/Finding/Contract/Finding.php`, `src/Infrastructure/DependencyInjection/Configurator/DesignConfigurator.php`, `governance/Channel/Fixtures/declared.txt`, `tests/Analysis/Finding/Unit/RuleExclusionStatsTest.php`, `scripts/generate-rename-enumeration.php` (две строки футера, :1305 и :1353). Строка коммита: «Six path references living in comments no longer resolved.»
- **verification**: confirmed
- **verification_note**: сверено по диффу; ни одна из восьми правок не является спорной, расхождение только в числе
- **fix_direction**: привести число в сообщении коммита или в REPORT к одному; в кампании, чей предмет — записи, переставшие быть верными, расхождение счёта между коммитом и сводом стоит того, чтобы его закрыть

---

## Coverage — что проверено и признано чистым

- **Понижение `ceiling` 60 → 57 (пункт 2 задания) — чисто, проверено отказом.**
  `ceiling=57` при `rows=57`, то есть ровно измеренная величина (`derive()` пишет
  `min($ceiling, count($violations))`), запаса больше нет. Подсадка 58-го нарушения
  (`tests/Analysis/Finding/Unit/PlantedWrongNamespaceTest.php` с чужим неймспейсом) в копии:
  `derive-namespace-path-allow-list.php` → **exit 6 (ABOVE_CEILING)**, трекнутый файл не тронут;
  `TestNamespacesFollowTheirPathTest::itFindsNoTestFileWhoseNamespaceDisagreesWithItsPath` → **красный, называет файл**.
  Ратчет строже, чем был, а не слабее.
- **`finding-gate/enumeration-renames.tsv` (пункт 4) — чисто, проверено регенерацией.**
  `composer enumeration:renames:check` в копии: «up to date (58 channel, 54 producer, 82 metric-key rows, 113 executed)».
  Рост `complexity.ccn` 1105 → 1107 атрибутирован полностью: удаление ОДНОГО нового файла
  `governance/FindingVocabulary/FindingFieldCensusTest.php` и регенерация дают дифф ровно
  из трёх строк (`channel`/`metric-key`/`producer`) 1107 → 1105 и **ничего больше**.
  Пять изменённых строк файла = три счётчика + две строки комментария с путями. Незамеченного сдвига нет.
- **Раскол `AllowAliasExpanderTest` (особое место в задании) — чисто.** Провайдер
  `everyDependencyTypeCase()` уехал вместе с методом и остался `public static`;
  `DependencyType::cases()` = 14, `DependencyTypeAliasCensusTest` исполняет **14 кейсов**,
  оставшаяся половина — **20 кейсов**. Пустой популяции нет ни с одной стороны.
- **Обе половины всех восьми расколов исполняются.** Governance-сьюта в копии даёт **750 тестов**
  — ровно цифра, заявленная в коммите; арифметика коммита сходится (Unit −36, Infrastructure −3,
  Governance +39, итог 0). Три новых каталога зарегистрированы в ОБОИХ адресах
  (`phpunit.xml.dist` и `testSuitePrefixTable()`), неймспейсы новых файлов совпадают с путями
  (`TestNamespacesFollowTheirPathTest` зелёный).
- **Новых протухших ссылок раунд не внёс.** Все path-подобные литералы, добавленные диффом вне
  `docs/`, разрешены на диске; не разрешились только `src/test.php`/`src/other.php` — это фикстурные
  строки внутри конструктора `Finding`, не пути. Классовые ссылки
  (`Governance\FindingVocabulary\FindingFieldCensusTest`, `…\SuppressionMechanismTest`,
  `governance/Occurrence/Occurrence{Kind,Leaf}FreezeGuardTest.php`, `governance/Channel/Fixtures/order.txt`,
  `governance/Channel/ChannelDeclarationFixtureDriftTest.php`) существуют.
- **Пинованные списки не пострадали.** `SILENTLY_EXCLUDED` не затронут — ни один из 11 файлов
  этого раунда не несёт `#[Group('live-freshness')]`. Удаление ветки
  `tests/Integration/Documentation/` из `classifyOwner()` безопасно: каталога нет, другие ветки
  не перекрываются.
- **Неиспользуемых импортов в остающихся половинах нет** (проверено посимвольно по восьми файлам);
  `#[CoversClass]` на обеих сторонах каждого раскола указывает на реально упражняемый класс
  (`LayerDefinitionTest` сохраняет `CoversClass(MatchMode::class)` законно — он строит `MembershipSpec`
  со значениями `MatchMode`).
- **Внутренняя согласованность записей раунда** сходится: batch-4 = 3 `repo-control` + 8 `mixed` + 5 `product-test` = 16
  строк, 11 носителей, 76 + 11 = 87, 21 + 3 = 24 группы — всё как в REPORT.

## Чего НЕ проверял

- Полный `composer check` не гонял (по указанию задания); зелёное — прогон оркестратора.
- Четыре красных теста Governance-сьюты в песочнице (`BenchmarkScriptEntrypointCoverageTest`,
  `TrackedLedgerNonEmptinessTest`, `ModularArchitectureGovernanceIntegrationTest`,
  `BaselineLifecycleEntrypointSurfaceTest`) — **артефакты песочницы без `.git`**: все четыре ходят
  в `git grep`/tracked-state. Это НЕ находки.
- «50 из 84 мёртвых префиксов» в `generate-modular-architecture-test-inventory.php` (записано REPORT
  как унаследованное) не перепроверял.
- Переписанный `census-completeness.md` по существу не читал — только сверил числа, на которые
  ссылается REPORT.
- Со-изменение для трёх новых групп неизмеримо (файлы созданы одним коммитом); судил по тесту имени.
- Из форм нарушения DoD подсадил две — переименование и удаление-без-прибытия. Форма «контрольный
  метод вернулся в `tests/` под ДРУГИМ именем» (регулярка скрипта сверяет имена из `scope`) осталась
  рассуждением, подсадкой не проверена.
- Остальные 13 `mixed`-строк `controls-verdict.tsv`, чьи пути существуют, поштучно не читал —
  проверка их судит, и она зелёная.

## Отклонённые находки

Нет.
