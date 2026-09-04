# Follow-ups of the rule-vocabulary pass

Deliberately accepted limitations and deferred work, kept here so `PLAN.md` stops
growing. Two neighbours with different jobs: `AUDIT.md` holds product defects
found along the way and out of scope; this file holds what a step *decided* to
leave open, with the measurement that made the decision defensible.

An entry earns a place here only if it names what is missing, what it costs, and
what would close it. "Could be nicer" belongs nowhere.

## Ш5e3-0 (2026-08-26) — the gate's key-map tooling

### A step that renames an aggregation strategy has no shape to declare it

`MetricVocabulary` reads the strategy list from both trees and refuses to run when
they disagree, because forward translation expands a `metric-keys.tsv` row over
the strategies of the tree it is applied to. That refusal is correct and it is
also a dead end: renaming `avg`, or removing a strategy, moves the published
spelling of *every* aggregated metric at once, and no row shape states that.

- **Cost:** such a step cannot be run through the gate at all — not "runs and
  proves less", which is why this is a refusal rather than a warning.
- **What would close it:** a declaration whose unit is the strategy rather than
  the key, applied to the suffix of every expanded spelling. Nothing needs it yet;
  the plan renames keys, not strategies.

### The overlap check cannot see a reference-only key shaped like an aggregation

The load-time check refuses a declared key whose `<key>.<strategy>` spelling is
already carried by another declared name, by a half the split leaves untranslated,
or by a base key the product declares. The last population is read from
`MetricName`'s constants — 71 of the 82 published keys; the other eleven are
collector-owned literals no single file declares, and Ш5e3 is the step that gives
them constants.

What is left uncovered is one arrangement: a key only the **reference** publishes,
shaped like an aggregation of a declared one, moved by the step without a row of
its own. Every other arrangement of that shape ends in a surface diff rather than
in silence.

- **Cost:** that one arrangement is absorbed silently. Measured 2026-08-26 across
  all 83 published base keys: no key of either tree has the shape at all.
- **What would close it:** after Ш5e3 the eleven literals become constants, so the
  same check then covers the whole published universe. Re-measure then, and this
  entry goes away rather than being restated.

### The corpus no longer proves a user formula that reads a metric key

The health case's user-defined computed metric reads no metric: a formula
addresses a key in a grammar, and Ш5e3 replaces that grammar, so the two trees
would spell the line differently and neither spelling could be handed to the
reference. The decision and its alternatives are in `PLAN.md` under Ш5e3-0.

- **Cost, while it lasted:** for one step the `m['...']` grammar was exercised only
  by the six built-in `health.*` formulas and by Ш5e3's own tests, not by a
  user-supplied formula through the config path; and a constant is the same at
  every level, so that channel stopped distinguishing one level's value from
  another's.
- **Closed by Ш6, package П1 (2026-08-29).** The case's user metric is
  `computed.branch-load` again — multi-word, so a user-spelled leaf is back in the
  corpus — and its formula reads a metric key:
  `clamp((m["complexity.ccn.avg"] ?? 0) * 10, 0, 100)`. No map row: the reference
  speaks both kebab and `m["..."]`. Verified that it is not vacuous — the published
  values differ per subject (32.2, 45, 15, 41.4 …), so the channel distinguishes
  levels and subjects again.

## Ш5e3 (2026-08-28) — the breaking key vocabulary

### The type-coverage tie is out of the corpus for one step

`ChannelSuggestionTieTest` shows that a "did you mean" answer can tie between two
channels, which makes the published channel order part of a finding's text.
Ш4c pinned that in the corpus with `@qmx-ignore design.repert-type-coverage`,
three edits from both post-split names. Ш5e3 renames those two channels, and the
old and new spellings are fourteen edits apart, so by the triangle inequality no
string is within the product's five-edit radius of both. Whichever spelling the
corpus held, one side of the gate would print two suggestions and the other none,
and `message` is a compared field a declared delta may not cover.

- **Cost, while it lasted:** for one step the tie was measured only by the unit
  test — reachability and equidistance — and not by a run of the product.
- **Closed by Ш6, package П1 (2026-08-29).** `@qmx-ignore design.type-coverage.propurn`
  is back in `finding-gate/cases/annotations/src/Directives.php`, with no map row:
  the reference already knows the three names. Verified twice — the gate against
  `6b3722b2` is GREEN under empty maps and zero declared deltas, and a run of the
  product prints both suggestions in one message, which is what the corpus is
  there to prove:
  `Addressable names closest to it: design.type-coverage.return, design.type-coverage.property`.

### The metric key and the channel it checks are one string, and one guard pays

Р7 keeps a metric and the rule checking it under one name on purpose, so about
twenty of the fifty-two channel codes are now also metric keys.
`RuleIdentifierLiteralGuardTest` judged ownership by exact string membership, and
a literal in the overlap no longer proves a channel reference — `MetricName`
itself holds all of them.

- **Cost:** the ownership half of that guard no longer covers the overlapping
  names; a catalogue copying another capability's channel code would pass there.
  Coverage is intact for every channel that is not also a metric key, which
  includes all of `architecture.*`, `annotation.*`, `code-smell.*`,
  `duplication.*` and `security.*` — the families both measured drifts occurred
  in. The existence half still reads the whole set.
- **What would close it:** nothing textual can. It needs a reference a literal
  cannot fake — a channel code carried as a value object rather than a string at
  the sites that copy it.

### Words, not spellings: `ccn` / `cyclomatic`, `mi` / `index`, `dit` / `inheritance`

Ш5e3 changed how a metric key is spelled and deliberately changed no words, so
three metrics and the channels checking them stay under different words:
`complexity.ccn` is thresholded by `complexity.cyclomatic`, `maintainability.mi`
by `maintainability.index`, `design.dit` by `design.inheritance`. The first two
are the same thing under an acronym and its expansion; the third is a metric and
a rule named after the subject rather than the measure.

- **Cost:** the pass leaves three pairs of the very defect class it removes
  elsewhere. Owner's decision, 2026-08-28: acceptable now, worth closing.
- **What would close it, and why it is not obvious:** the fix has two directions
  and the cheap one is the opposite of the obvious one. Renaming the metric costs
  711 occurrences of `ccn`, `mi` and `dit` plus the website pages that teach the
  acronyms as industry terms; renaming the three channels costs three map rows on
  a surface Ш5b–Ш5d just stabilised, which Р7 allowed only four exceptions to.
  Neither follows from a decision already taken, so the step that closes this
  measures the radius of both and picks one.

## Ш6 (2026-08-29) — the compaction surface and the audit

### `AnalysisResult` glues three unrelated subjects into one VO

Package П2 added an eighth constructor parameter (`ruleExecution`) to carry
`RuleExecutionResult` from `AnalysisPipeline` to Infrastructure, which is the
step's own contract requirement (Ш6 decision (е)). The value that now needs a
`@qmx-threshold` to stay under `code-smell.constructor-overinjection` and
`code-smell.long-parameter-list` was already at seven fields answering three
different questions with no shared reason to change together: what the run
*measured* (`metrics`, `coverage`, `namespaceTree`, `duration`), what *controls*
were in force going in (`suppressions`, `thresholdOverrides`), and what *rules
said* coming out (`findings`, `ruleExecution`). Splitting by subject — not by
introducing a parameter-object wrapper, which would just rename the same eight
fields under one more class — is the actual answer this shape asks for, and it
is out of scope for Ш6: every consumer listed below would need to learn which
of the three new values to reach through.

- **Cost, measured 2026-08-29 via `mcp__serena__find_referencing_symbols` on
  the exact promoted properties `AnalysisResult::$suppressions` and
  `AnalysisResult::$thresholdOverrides`** (language-server-resolved, so it
  counts only accesses to *this* class's fields and skips the same-named
  fields on `CollectionPhaseOutput`, `RunConfiguration` and other unrelated
  types that a plain `grep -rn '\->suppressions\b'` cannot tell apart): 16
  reference sites for `$suppressions` across `AnalysisResult` itself (4:
  constructor docblock, two in `merge()`, one local variable),
  `AnalysisPipeline::analyze()` (1), `FindingFilterOrchestrator::filterAndReport()`
  (1), `MeasuredFindingSet::run()` (1), `AnalysisResultTest` (7) and
  `MeasuredFindingSetTest`/`InlineSuppressionLayerViolationIntegrationTest` (1
  each); 13 reference sites for `$thresholdOverrides` across `AnalysisResult`
  itself (4), `AnalysisPipeline::analyze()` (1), `StubBaselineRun::measure()`
  (1), `BaselineExplainCommand::doExecute()` (1) and `AnalysisResultTest` (6).
  A three-way split moves all 29 of these call sites at minimum, one import
  and one property access each.
- **What would close it:** a step that gives each of the three subjects its own
  value — a measured-facts VO, a controls-in-force VO, a rule-verdict VO
  (`RuleExecutionResult` already is one) — and makes `AnalysisResult` compose
  the three rather than flatten them. Not attempted here: Ш6's contract names
  `AnalysisResult` as a pass-through carrier, not as the layout to fix, and
  fixing it would touch Reporting and Console call sites that are П4's and
  П5's territory, not П2's.

### The suppressed composition is outside the equivalence gate for one step

`suppressed` is a new output format, and the gate runs every format in
`Surfaces::FORMATS` on both trees. The reference of this step, `6b3722b2`, does
not know the format: putting it in the list now would produce fourteen "unknown
format" diffs plus fourteen exit-code diffs, which say nothing about the
product. So the format is not in the list, and the step's own tests plus the
`src` snapshot are what hold it.

- **Cost:** for one step, a change to the composition's payload — a renamed
  field, a reordered block, a mechanism silently dropped — is invisible to the
  cross-version comparison. The snapshot catches a change in *what* is
  suppressed on our own `src`; it does not catch a change in *how* the format
  spells it, and no corpus case compares the format at all.
- **Closed by package Х3-B1 (2026-09-02).** The entry's own condition came true:
  `38ad58e9` knows the format, so `suppressed` joined `Surfaces::FORMATS` and the
  composition is compared on every case. Deriving the normalization list
  measured exactly one new row for it (`format:suppressed` / `meta.timestamp`)
  and the equivalence tuple did not move, as its rows all come from
  `JsonFindingSection::formatFinding`.

### `contract_surface` cannot express a chain through another owner's carrier

The manifest can declare a *carried* surface — a type that crosses an owner
boundary with no direct import — through the `contract_surface` relation and its
`carrier_fqcn` field. Ш6 is the first step in the project to use it:
`Core\Util\PatternMatch` is declared by four such entries whose carriers are
`PathMatcher` and `NamespaceMatcher`, both of its own owner.

`RuleExecutionResult` cannot be declared that way. Its honest carrier is
`Analysis\Run\Contract\Pipeline\AnalysisResult`, and the check requires a
carrier of the **same owner** as the declared type
(`validateContractSurfaces()` in
`scripts/generate-modular-architecture-production-inventory.php`, refusing with
"requires a same-owner contract carrier"). The real chain,
`Analysis.Finding → Analysis.Run → Infrastructure.Console`, has two steps, and
the relation models one.

- **Cost:** the console orchestrator reads `$result->ruleExecution->published`
  without importing the type, so that owner pair is absent from the inventory.
  No manifest check catches it — only reading the code does. One pair today, and
  one more for every future carried surface that travels through a foreign
  carrier.
- **What must NOT close it:** naming `RuleExecutionInterface` as the carrier via
  its `RulesCommand` consumer. The checker would pass and the statement would be
  false — `RulesCommand` calls `allRules()` and never touches
  `RuleExecutionResult`. Fitting the declaration to the checker is worse than the
  gap, because it makes the gap invisible.
- **What would close it:** either extending the relation to a carrier chain with
  every link checked, or revisiting the same-owner requirement while naming what
  is checked instead. That is the manifest owner's call, not the call of the step
  that first needed it.

## Путь парсера покрывает 6 из 27 классов `ThresholdAware` (измерено в П1)

Память проекта фиксировала слепоту v0.18: 27 интеграционных тестов
`ThresholdAware` звали `withOverride()` напрямую, минуя
`ThresholdOverrideExtractor`, из-за чего сломанный путь аннотации прошёл в
релиз. v0.19 завёл `ThresholdAnnotationParserPathTest`. П1 обязан был измерить,
что именно тот тест закрыл, а не унаследовать вывод.

**Измерение (2026-08-30):** тест называет шесть правил поимённо —
`ComplexityRule`, `DataClassRule`, `GodClassRule`, `MaintainabilityRule`,
`MethodCountRule`, `ParamTypeCoverageRule` (`grep -oE '[A-Za-z]+Rule::class'`).
Это по одному на стратегию валидатора — ровно тот минимум, который план v0.19
себе и ставил, — а не 27. Двадцать один класс опций доходит до
`withOverride()` только напрямую из своих тестов.

**Что это значит и чего не значит.** Стратегия валидации покрыта каждая;
непокрыто соответствие «этот класс — этой стратегии» на пути парсера для 21
класса. Отдельный структурный тест сверяет карту валидаторов с реестром правил,
так что дыра уже, чем в v0.18, но она не пуста.

**Расширение покрытия в П1 не входило** — входило измерение. Решение о том,
доводить ли путь парсера до всех 27, принимает владелец: цена — 21 сквозной
кейс, выгода — закрытие того класса дефектов, который однажды уже доехал до
релиза.

## Х1 (2026-08-31) — хвост захода

Три пункта, и они разного происхождения. Первые два найдены в Ш3, отложены
решением владельца 2026-08-23 «закрываем в конце захода» и заходом Х1 не
закрыты; они переехали сюда из раздела «Хвост захода» `PLAN.md`, чтобы у
каждого был один адрес: план говорит, чем хвост закрыт, этот файл — что из него
осталось. Третий открыт уже ревью исполнения Х1 и сюда не переезжал: он про
обоснование, которым заход объяснил свой же выбор.

### Прямой `vendor/bin/phpunit` без `--no-coverage` не исполняет ни одного теста

Измерено и **исправлено в диагнозе** 2026-08-23. Сначала это выглядело как отказ
фильтра: `--testsuite Integration --filter <ИмяКласса>` печатал «No tests
executed!» и возвращал 1, хотя `--list-tests` с тем же фильтром перечислял все
методы класса. Причина другая: без `--no-coverage` рантайм-предупреждение об
`XDEBUG_MODE` попадает под `failOnWarning="true"` и валит прогон до исполнения.
Рабочая форма точечного прогона — `vendor/bin/phpunit --no-coverage
<путь-или---filter>`; `composer test` именно так и устроен
(`phpunit --no-coverage --exclude-group=benchmark`).

- **Цена:** она мельче, чем казалась, и это часть решения отложить. Отказ громкий
  и по коду возврата, и по тексту, так что «зелёный, ничего не прогнавший» здесь
  не получается — платит время того, кто зовёт `phpunit` напрямую и верит первому
  правдоподобному диагнозу. Запись оставлена и потому, что мой первый диагноз был
  неверен и назвал виновным фильтр: ровно тот случай, когда правдоподобный
  механизм принимается без второй пробы.
- **Закрыто пакетом Х3-A2 (2026-09-02).** Владелец выбрал первую развилку, и
  измерение уточнило адрес: снят не `--no-coverage`, а блок `<coverage>` из
  `phpunit.xml.dist` — писателей отчётов называет `composer test:coverage`.
  Громкость отказа не потеряна, а переехала: покрытие без активного драйвера
  по-прежнему валит прогон, но теперь только там, где его попросили. Возврат
  блока держит `CoverageIsRequestedExplicitlyTest`; без него регрессия была бы
  невидима, потому что CI зовёт `composer test` с `--no-coverage`. Диагноз,
  записанный выше, верен как механизм и неверен как адрес: `failOnWarning`
  превращает предупреждение в отказ, но порождает предупреждение безусловный
  запрос отчётов.

### Порядок регистрации каналов наблюдаем через подсказку «closest to it»

Найдено ревью Ш3 (раунд 6, `native-claude-01`) и подтверждено прогоном обоих
деревьев: порядок `ChannelUniverse::channels()` — это порядок регистрации, он
попадает в текст находки через стабильный `asort` по расстоянию, и вынос
валидаторов переставил в нём один канал. В Ш3 это починено восстановлением
порядка: пасс идёт по сервисам в порядке регистрации, порядок прибит фикстурой,
а корпус получил кейс с опечаткой, равноудалённой от двух каналов, — до него
гейт эту поверхность не видел вовсе.

- **Цена:** историческая случайность никуда не делась, она лишь прибита тестом, и
  её читают четыре потребителя — `DirectiveNameHints`, `ChannelExclusionKeyHints`,
  `DirectiveAddressability` и `DirectiveUsage`. Любая перестановка регистрации
  снова меняет публикуемый текст, и защищает от этого одна фикстура, а не
  свойство. Четвёртым здесь до 2026-08-31 значился `InlineDirectivePolicy`;
  адрес устарел, а не пункт: П2 (`db8b3b0e`) вынес из него `ChannelIdentityInterface`
  и построение находки в `Directive/DirectiveUsage`, и сегодня `InlineDirectivePolicy`
  не импортирует ни `Finding`, ни идентичность каналов вовсе.
- **Что закроет:** тотальный порядок подсказок — сортировка кандидатов по паре
  (расстояние, имя), после которой порядок регистрации не наблюдаем ни через
  одного из четырёх.
- **Почему это шаг, а не рефакторинг:** это объявленная дельта, и её цена
  измерена заранее. Из 58 пар каналов, для которых ничья вообще достижима
  (расстояние между именами ≤ 10), порядок разойдётся с сегодняшним ровно у
  двух — `design.type-coverage.return`/`design.type-coverage.property` и
  `annotation.unsupported-threshold`/`annotation.invalid-threshold`. Обе пары
  называются в Breaking. Решение владельца, 2026-08-23: не делать этого в Ш3,
  потому что шаг с объявленной дельтой перестал бы доказываться пустыми картами
  гейта.

### Одно явление — два разных инструмента принятия: ратчет у П1, точечные пороги у П3

Открыто ревью исполнения Х1 (2026-08-31). Явление в обоих случаях одно и то же —
**афферентный рост от появления нового читателя**, ни один из двух классов не стал
зависеть от большего числа типов:

- П1 расщепил `Design` на четыре подпредмета, афферентный CBO
  `ns:…Evidence\Measurement\Contract` поднялся 41 → 44, и запись **положена в
  ратчет** (`qmx-baseline.json`, `ns:…Evidence\Measurement\Contract` →
  `coupling.cbo`, magnitude 44).
- П3 выделил `ExplainedSubject`, тот стал вторым читателем
  `MetricRepositoryInterface` (raw CBO 45 → 46) и `Baseline` (19 → 20), и обе
  записи **закрыты точечными порогами**, а не ратчетом.

**Решение остаётся: точечные пороги предпочтительнее.** `AGENTS.md` прямо ставит
прямые пороги и точечные подавления выше записей ратчета, и П3 сделал ровно это.
Пересмотра требует **обоснование**, а не выбор.

**Что в обосновании П3 неверно.** Тело `f3c86ac0` объясняет выбор в том числе так:
«the first finding is an error, which the ratchet does not hold at all». Это ложное
правило. Ратчет держит error, и держит его прямо здесь: прогон
`bin/qmx check src/ --format=json --workers=0 --show-suppressed` даёт
`ns:…Evidence\Measurement\Contract`, `coupling.cbo`, metricValue 44, threshold 25,
**severity error**, а тот же прогон с `--baseline=qmx-baseline.json` не оставляет
ни одной находки — то есть эту error-запись гасит именно ратчет, и подняла её туда
П1 того же захода.

**Чем два случая действительно различаются — и различаются ли.** По природе
величины не различаются ничем: и там и там это фан-ин, и там и там условия отмены
нет по замыслу. Различаются по **уровню субъекта**: у П1 субъект — неймспейс
(`ns:…`), у П3 — объявления классов. Точечный порог адресуется объявлению
`@qmx-threshold`, у неймспейса такого носителя нет — аннотацию некуда повесить,
поэтому П1 выбора между двумя инструментами не имел, а П3 имел. Это объясняет
разнобой полностью, но **в теле коммита П3 названо не оно**, а несуществующее
свойство ратчета.

- **Цена, пока это открыто:** обоснование, на которое сошлётся следующий заход,
  учит ложному правилу; применённое буквально, оно погонит любую error-находку
  мимо ратчета к точечному порогу — включая ту, у которой носителя для аннотации
  нет.
- **Что закроет:** решение владельца, формулируется ли правило выбора по уровню
  субъекта («есть объявление-носитель — точечный порог; неймспейс — ратчет»), и
  если да, то запись этого правила рядом с политикой ратчета в `AGENTS.md`. До
  этого поправка живёт здесь: тело `f3c86ac0` неизменяемо.

**Решение владельца 2026-08-31: правило в `AGENTS.md` сейчас не пишется.** Это
вопрос про улучшение нашего собственного кода, а не про продукт и его принципы;
базлайн разбирается отдельным заходом. Поправка к ложному правилу остаётся здесь
и действует как поправка.

## Х2 (2026-08-31) — словарь исключений

### Слово `exclude` покрывает три механизма, из которых два — подавление

Снято по коду при разведке Х2. В одном `qmx.yaml`:

| ключ                                                           | где применяется                                                     | что делает                                                                                         |
| -------------------------------------------------------------- | ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `exclude:` (корень)                                            | discovery, `RunConfigurationResolver.php:29`                        | файл не анализируется вовсе — настоящее исключение                                                 |
| `exclude_paths:` / `exclude_namespaces:` (корень)              | `Reporting\FindingProjection\…\ConfiguredFindingExclusionsResolver` | находка произведена и выброшена на проекции отчёта                                                 |
| те же ключи и `exclude_namespace_channels:` **внутри правила** | `FindingExclusionLedger::keeps()`, по одной находке                 | находка произведена, выброшена на выходе исполнения, посчитана, печатается под `--show-suppressed` |

То есть подавления два разных, они живут у разных владельцев (`Reporting` против
`Analysis\Finding`), пишутся одним словом и различаются только уровнем вложенности
в YAML. Имя `suppress_*` называет предмет верно для второго и третьего случая;
первому слово `exclude` принадлежит по праву.

- **Цена, пока открыто:** оговорка О1 аудита директив Х2 («исход — это
  `produced`, а не `published`») существует **потому**, что эти ключи называются
  исключением, а работают как подавление. Каждый читатель конфига выводит из
  имени, что находки нет, и трижды за заход это приводило к неверному механизму
  снятия находки.
- **Почему не в Х2:** переименование ключей — ломающее изменение схемы
  конфигурации со своим шагом, миграцией и записью `Breaking` в `CHANGELOG.md`.
  Втащить его в команду-аудит значит слепить два предмета.
- **Что закроет:** отдельный шаг словарного захода, решающий заодно, остаются ли
  два разных подавления двумя ключами или сливаются в один с уровнем применения.

## Х2 П1 (2026-09-01) — универсум аудита директив

### Ни наше дерево, ни корпус гейта не подают вход, на котором правка видна

П1 перевёл аудит подавлений с `published` на `produced`. Направление перехода
одностороннее по построению — `SuppressionFilter::suppressesAny()` есть
экзистенциальная проверка по списку находок, — поэтому набор инертных может
только убывать. Убывать оказалось нечему:

- на `src` канал `annotation.unused-directive` даёт ноль находок и до, и после,
  и в `qmx-baseline.json` не встречается ни разу;
- `composer gate -- --reference=6d91dc3a` — GREEN, 0 объявленных дельт: в
  корпусе нет кейса, где подавление накрывает находку, которую выбрасывает
  ledger исключений. Кейс `rule-exclusion-ledger` подаёт исключение без
  подавления на нём.

Единственный свидетель правки — фикстура `DirectiveAuditUniverseTest`, и её
парность проверена: мутация «судить по `published`» красит ровно один из двух
случаев, второй остаётся зелёным.

- **Цена:** различие произведённого и опубликованного не наблюдаемо ни одной
  версионной сверкой. Изменение того, какой универсум уходит в аудит, не
  покрасит гейт ни на одном шаге — его держит один класс тестов.
- **Закрыто пакетом Х3-B2 (2026-09-02).** Кейс `rule-exclusion-ledger` получил
  `SuppressedInsideExcluded` — подавление на находке, которую выбрасывает
  ledger. Относительно `38ad58e9` П1 уже влит, поэтому объявленной дельтой это
  не оказалось: фикстура сегодня не порождает ни одной публикуемой находки. В
  составе `suppressed` она даёт одну строку `rule-namespace-exclusion`; кроме
  того — и об этом стоит сказать прямо, потому что первая формулировка была уже
  правды — пятый файл двигает у кейса счёт разобранных файлов, `debtPer1kLoc`,
  проектный `coupling.class-rank` и `impactScore`. Гейта это не касается: корпус
  оба дерева читают из кандидата. Свидетельская сила
  проверена снятием: если спросить аудит о `published` вместо `produced`, кейс
  публикует `annotation.unused-directive` на файле фикстуры, то есть краснеет на
  сравниваемых поверхностях. Контрольная проба — директива, не адресующая
  ничего, — в корпус не легла: канал `annotation.unused-directive@file`
  принадлежит кейсу `annotations`, и claim auxiliary-кейса проверяется поштучно.

### Коммит исполнения переписал собственный отревьюенный план

Найдено ревью исполнения П1. Коммит `8b7f7461` нёс и код, и правку двух файлов
плана — включая строку DoD, которая после правки описывала уже другое
обязательство (операция контракта перенесена в П2). Правка верна по существу и
объяснена в теле коммита, но текст, который читали ревьюеры плана, остаётся
восстановимым только через git, а изменившееся обязательство не отличимо от
неисполненного.

- **Цена:** любое сравнение «сделано против запланировано» внутри одного коммита
  сравнивает исполнение с уже подправленным планом.
- **Правило на дальше, действует с П2:** правка плана — отдельный коммит перед
  кодом, с причиной в теле. Смешивать их нельзя даже когда причина правки
  возникла из самого кода.


## П2 (2026-09-01) — пороговый аудит

### Второй вход пайплайна стоил классу перехода warning → error по связности

`AnalysisPipeline` получил два новых типа в своём неймспейсе и вызов аудита;
`coupling.cbo` на классе перешёл с `warning` на `error`, а неймспейс
`Qualimetrix\Analysis\Run\Pipeline` набрал `warning`. Оба подавлены
`rule-path-exclusion` в `qmx.yaml` и потому не двигают ни отчёт, ни ратчет —
видны только в `docs/internal/generated/suppression/composition.tsv`.

- **Цена:** сигнал о связности точки композиции гасится настройкой, и его рост
  никто не заметит, пока кто-нибудь не прочитает снимок подавлений.
- **Что закроет:** разделение пайплайна на подготовку прогона и его потребителей
  — работа своего масштаба, не хвост пакета.

### Вердикты молчат, когда выключен сам продюсер директив

`prepareInlineDirectives()` при выключенном `annotation.directive` зовёт
`reset()`, поэтому политика не хранит ни одной директивы. Для
`auditDirectiveUsage()` это правильно (канал принадлежит правилу), для будущей
команды — нет.

**Исправление формулировки (П4, проверено исполнением):** пустеет не «обе
половины», а только половина подавлений. Пороговая читает
`AnalysisContext::$thresholdOverrides` — состояние прогона, а не хранилище
политики, — и отвечает как обычно. Отчёт получался бы не пустым, а наполовину
выдуманным, что хуже: «подавлений в дереве нет» соседствует с настоящими
пороговыми вердиктами и от них неотличимо.

- **Цена:** половина отчёта, неотличимая от «директив в дереве нет».
- **Закрыто в П4:** подготовкой директив независимо от enablement правила.
  Отказ команды отвергнут — он оставляет верный ответ незаданным. Гейт на
  отчётность остаётся там, где он и был: канал включает само правило через
  `enableUsageReporting()`, а валидатор исполняется только внутри слота своего
  продюсера (`RuleExecution::activeRuleInstances()`).

### `Overrun` не различает направления границы

Директива, которая границу **ужесточает**, и директива, которая её поднимает,
дают одинаковую форму различия отпечатков: идентичность совпала, граница
разъехалась. Понятия «строже» у слоя правил нет — у `coupling.instability` хуже
больше, у `cohesion.tcc` меньше, — поэтому механически они неразличимы. Вердикт
утверждает ровно «применена, ничего не двинулось, кроме напечатанной границы»;
слово `Overrun` описывает частый случай.

- **Цена:** автор ужесточающей директивы прочитает в отчёте слово, означающее
  несдержанное обещание, хотя обещания послабления не было.
- **Закрыто в П4:** текстовая проекция печатает утверждение, а не ярлык —
  «применена; не сдвинулось ничего, кроме напечатанной ею границы». Машинная
  сохраняет `overrun` как стабильный ключ. Разделение вердикта надвое остаётся
  открытым и возможно только после того, как понятие направления появится у
  слоя правил.


## П4 (2026-09-01) — команда

### Семь команд консоли стоят ровно на когезии 40, и все — в ратчете

`DirectivesCommand` стал седьмым: `BaselineCleanup`, `BaselineExplain`,
`BaselineGenerate`, `BaselineUpdate`, `LayerAssignment`, `HookUninstall` и он.
Число одно и то же, потому что причина одна: `configure()` не трогает ни одного
поля, а помощники команды статические — у класса-команды когезия по общим полям
низка по построению, а не по дефекту.

- **Цена:** правило `health.cohesion` для этого места ничего не сообщает, а
  семь принятых записей выглядят как семь решений, хотя решение одно.
- **Что закроет:** решение владельца — оставить как есть, или заменить семь
  записей ратчета одним `exclude_namespace_channels` на точный корень
  `Infrastructure\Console\Command`. Второе снимает сигнал и с будущих команд,
  поэтому это политика, а не хвост пакета.
- **Закрыто пакетом Х3-C2, гигиена ратчета (2026-09-03).** Владелец выбрал
  замену, но названный здесь механизм для неё не годится:
  `exclude_namespace_channels` отбирает находки с субъектом-неймспейсом, а все
  семь записей — `declaration:class:`, так что его запись была бы инертной
  (измерено: семь из семи остались, подавлено ноль). Работает
  пер-правильная `exclude_namespaces` под `health.cohesion` на префикс
  `Qualimetrix\Infrastructure\Console\Command` в простой, не глобовой форме:
  ратчет 228 → 221 группы, исчезают ровно семь названных субъектов.

### Отчёт команды печатает пути относительно корня проекта, и только их

`DirectiveSite::$file` — `RelativePath`, и это верно для отчёта об одном дереве.
Для прогона, охват которого пользователь задал несколькими каталогами вне
корня, строка отчёта не говорит, из какого именно они пришли.

- **Цена:** пути неоднозначны ровно в том случае, когда охват неочевиден, то
  есть когда шапка нужнее всего.
- **Что закроет:** печать корня прогона в шапке рядом с охватом — работа на
  одну строку, но она меняет обе проекции и потому не хвост чужого пакета.

### Прогресс идёт в тот же поток, что и полезная нагрузка

`ConsoleProgressBar` пишет в `$output->section()`, то есть в **stdout** — туда
же, куда уходит отчёт. `configureProgressReporter()` включает бар по
`isDecorated()`, и формат вывода на это не влияет, поэтому на TTY вывод
`--format=json` начинается с `^D^H^H` и только потом идёт `{`. Проверено
исполнением через псевдотерминал **и на `check`, и на `directives`**.

Режимы тут ни при чём, и «гасить бар при машинном формате» — лечение не того.
Срез неверен: интерактивность и формат независимы, а человек в терминале хочет
и бар, и разбираемый документ. Общепринятое решение — развести **потоки**:
нагрузка в stdout, прогресс и диагностика в stderr (`curl`, `docker`, `git`,
`npm`, `cargo`, `ffmpeg`). Тогда `> out.json` даёт чистый JSON, а бар остаётся
виден, потому что он и не был в перенаправленном потоке.

Соглашение в дереве уже есть и уже записано: `CheckCommand::writeWarning()`
берёт `getErrorOutput()` с комментарием «to avoid polluting structured output».
Прогресс-бар ему просто не следует.

- **Цена:** обещание «машиночитаемый формат читается парсером» неверно ровно в
  той среде, где человек его пробует руками.
- **Что закроет:** `RuntimeConfigurator` передаёт `ConsoleProgressBar`
  `$output->getErrorOutput()`, когда вывод — `ConsoleOutputInterface`; условие
  `isDecorated()` применяется к тому же потоку. Плюс `--no-progress` у
  `directives` (у `check` он есть) и уважение `NO_COLOR` / `TERM=dumb`. Меняет
  поведение всех команд сразу, поэтому свой шаг и свой прогон гейта.

- **Закрыто пакетом Х3-G (2026-09-03).** Не ровно тем лечением, которое здесь
  предписано, и запись ошибалась дважды. Во-первых, `getErrorOutput()` отдаёт
  `StreamOutput`, а не `ConsoleOutputInterface`: передача его бару выключила бы
  прогресс совсем, потому что гейт бара спрашивал именно про этот интерфейс.
  Секцию над потоком ошибок конфигуратор собирает сам, а бар принимает готовую.
  Во-вторых, работы по `NO_COLOR` и `TERM=dumb` не было: Symfony обрабатывает
  их в `StreamOutput::hasColorSupport()` для каждого потока отдельно.
  `--no-progress` не хватало не одной команде, а шести — объявлений три:
  общее определение команд отчёта, вход измеренного прогона baseline-команд и
  собственное определение `check`. Измерено под псевдотерминалом при
  `COLUMNS=120`: первый байт stdout у `check --format=json` был 2697-м из
  296 840, стал нулевым, и `json.loads` всего stdout проходит.

### Диагностика идёт мимо кадра прогресса, и её фолбэки не сведены

Владелец объединённой записи. Соседняя запись Х3 («Подробный режим печатает лог
в тот же поток, где рисуется бар») описывала то же явление со стороны
подробности вывода; verbosity лишь поставляет писателей в поток, а ломается
кадр от любого несекционированного писателя. Вести их врозь значило закрывать
симптом отдельно от владельца.

**Что измерено (Х5, до плана и повторно исполнением пакета 5).** Прогон под
псевдотерминалом, воспроизведённый через терминал, применяющий стирания:

| режим  | кадров бара | строк лога | итоговый экран                        |
| ------ | ----------- | ---------- | ------------------------------------- |
| (нет)  | 3           | 0          | чистый                                |
| `-v`   | 3           | 7          | чистый                                |
| `-vv`  | 3           | 15         | повреждён                             |
| `-vvv` | 3           | 15         | повреждён, бар застрял на `0/20 … 0%` |

Порог — `-vv`, а не `-vvv`, как было записано; гибнут **две** строки лога **и**
сам бар: кадр секции считает себя двухстрочным и стирает вверх изнутри чужого
вывода, а последующие кадры ложатся не туда.

**Закрыто пакетом 5 захода Х5 (`a4291097`).** Перечисление вместо счёта швов:
писателей у этого процесса **шесть**, а не четыре — не были посчитаны отчёт
`graph:export` о неполном анализе и собственный рендер исключений Symfony
(единственный, кто гарантированно печатает при живом кадре). Фолбэков было
четыре формы, а не три, и один из них писал диагностику в полезную нагрузку,
обещающую разбираться парсером.

`ErrorStream` держит список секций, создаёт секцию диагностики раньше секции
прогресса и выдаёт обе: диагностика стирает кадр, пишется постоянно, кадр
перерисовывается под ней. `DiagnosticOutput` удалён, `LoggerFactory` больше не
выбирает поток, `ProgressConfigurator` забрал четыре гейта прогресса из
`RuntimeConfigurator`. Единственный фолбэк роняет диагностику там, где у вывода
нет канала ошибок.

- **Отвергнуто и почему:** гасить бар выше `NORMAL`. Это сделало бы обещание
  README верным и оставило бы механизм сломанным для первого же предупреждения
  при обычной подробности — лечение симптома вместо владельца.
- **Оракул:** обе половины написаны до измерения изменённого дерева; байтовые
  потоки целого и разрушенного прогона различаются в любом случае, поэтому
  сравнивается итоговый экран. stdout побайтно одинаков во всех четырёх режимах,
  до и после.
- **Остаётся:** предупреждение `WorkerBootstrap` пишется в `STDERR` дочернего
  процесса (см. запись Х5 ниже) — этот шов новым владельцем не покрыт, потому
  что он в другом процессе.
### `check` и `directives` расходятся о подавлении позднего канала

`annotation.unused-directive` — выход `DirectiveUsage::stale()`, поэтому в
универсум самого `stale()` он попасть не может: список находок этого канала и
есть то, что у него просят. Половина вердиктов такого ограничения не имеет и
получает список шире — с этим каналом.

Форм расхождения две, и цена у них разная.

**Соседская.** `// @qmx-ignore-next-line annotation.unused-directive` строкой
выше протухшей директивы: `check` печатает `annotation.unused-directive` на
строке самой этой директивы, то есть считает её ничего не погасившей, а `qmx
directives` называет её `effective`. Проверено исполнением. Права команда —
находку соседа она действительно погасила, что видно по тому же прогону `check`:
находка соседа не напечатана. Здесь расхождение остаётся открытым.

**Само-совпадающая.** `@qmx-ignore-file annotation.unused-directive` как
единственная директива файла накрывает собственную жалобу и объявляет себя
живой. Права здесь была не команда, а сужение: третий раунд ревью нашёл это
исполнением (`check` печатает одно и то же с директивой и без неё), и П4
исключает из универсума конкретной директивы жалобу, порождённую ею самой.
Дефект первого раунда это не возвращает — соседские жалобы остаются на месте.

Первая редакция этой записи обобщила: «права команда, сужать нельзя». Обобщение
было построено на одной из двух форм и на второй оказалось неверным.

- **Цена оставшейся формы:** два ответа продукта об одной директиве в одном
  прогоне, и докблок `DirectiveUsage` не может обещать «одно вычисление — один
  ответ» без оговорки.
- **«Что закроет» переписано заходом Х3 после того, как предписанное лечение
  было исполнено и снято.** Двухпроходный `stale()` работает: узкое исключение
  («жалоба не засчитывается директиве, которая сама адресует этот канал, если
  породила её другая директива того же канала») плюс два прохода дают один
  универсум обоим потребителям, `composer check` и контроли зелены, цена 8 мс.
  Первая редакция решения — итерация с отказом на взаимной самоссылке — была
  отвергнута измерением: отказ нелокален (посторонняя протухшая директива в том
  же файле его отменяет) и бьёт по невиновному автору с широкой `annotation.*`.
- **Закрывать надо запретом, а не вычислением.** Решение владельца 2026-09-03:
  канал становится **не подавляемым инлайн**. Защищаемая возможность — заглушить
  жалобу «эта директива ничего не гасит» второй директивой вместо удаления
  первой — странна сама по себе и уже наполовину сломана: символьная форма не
  может сработать никогда, потому что субъект находки файловый, а символьное
  подавление сопоставляется по точному субъекту объявления. Законный сценарий
  (директиву держат мёртвой, пока правило выключено) обслуживается конфигурацией,
  где решение видно в одном месте. В нашем дереве лазейкой не пользуется ни одна
  директива — измерено, ноль.
- **Цена запрета измерена.** Шов «канал не подавляем инлайн» сегодня не отделим:
  `DirectiveUsage` фильтрует универсум по `isConfigurationError()`, а этот флаг
  несёт ещё baseline, код возврата и разделение проекций. Нужно новое свойство
  декларации канала; путь отказа заводить не надо — `annotation.unresolved-directive`
  и вердикт `unmeasured / already-refused` уже покрывают «канала никто не
  производит». Подробности и порядок работ — в
  `X3-followups/11-two-pass-stale.md`.
- **Закрыто заходом Х4, пакет 2 (2026-09-03).** Не вычислением, а запретом:
  директива не может погасить `annotation.unused-directive` ни одной формой.
  Соседская форма `@qmx-ignore-next-line annotation.unused-directive` теперь
  отвергается там, где написана, поэтому расходиться `check` и `directives`
  больше не о чем; само-совпадающая форма отвергается по той же причине, и
  сужение `DirectiveUsage::withoutOwnComplaint()`, которое её обслуживало,
  снято. Проверяют:
  `DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel`
  (двенадцать форм, оба вывода на одной фикстуре) и
  `SuppressionFilterTest::itLetsNoDirectiveSilenceTheChannelThatReportsWhatDirectivesDid`.
  Цена запрета, названная выше («нужно новое свойство декларации канала»),
  измерением не подтвердилась: у факта не нашлось кросс-owner потребителя, и
  он лёг в `DirectiveChannelBan` внутри `Analysis\Policy\Inline`.

## П5 (2026-09-02) — узкий проход и его контроль

### Контроль эквивалентности силён ровно настолько, насколько разнороден наш `src`

Узкий и полный свипы сравнены на нашем дереве: 43 вердикта, охват и коды
возврата совпали. **Все 43 — `Effective`.** Контроль, поставленный на такой
популяции, покраснел бы от дефекта, обращающего вердикты в `Inert` или
`Unmeasured`, и не покраснел бы от дефекта, обращающего всё в `Effective`, —
именно этот исход он и наблюдает как норму.

- **Цена:** зелёный `directives:narrow-control` на нашем дереве — свидетельство
  о нашем дереве, а не о словаре вердиктов. Разнородные вердикты держат только
  тесты, то есть фикстуры.
Это не рассуждение, а измерение. Мутация «сужать всегда не тем правилом»
(`narrowedTo()` возвращает чужое имя) прошла контроль **зелёным**, пока сужение
выражалось в двух местах: базовый отпечаток снимался по своему выражению, и
рассогласование сторон давало `Effective` — то самое, что на нашем дереве и
есть норма. После сведения сужения к одному источнику та же мутация краснеет,
но краснеет третьим контролем, а не сравнением вердиктов: сравнение вердиктов
на однородной популяции по-прежнему её не видит.

- **Что закроет:** прогон контроля по дереву, где вердикты разнородны, —
  например, по корпусу `finding-gate/`, где директивы посажены нарочно, либо по
  временной копии `src/` с посаженными мёртвой и `Overrun`-директивами. Второе
  дешевле и не трогает корпус.

- **Закрыто пакетом Х3-F (2026-09-03, во второй редакции).** Контроль получил
  цель, конфигурацию и пол разнородности; по фикстуре
  `tests/Analysis/Policy/Inline/Fixtures/NarrowControl` идут два прогона — вся
  она под полом и её половина `Silenced/` без пола. Мутация «сужать всегда не
  тем правилом» краснеет сравнением вердиктов на второй из них (код 1, три
  вердикта расходятся) и упирается в третий контроль аудита на первой (код 3);
  почему так — отдельной записью в разделе Х3-F. Первая редакция этой строки
  утверждала, что сравнением вердиктов мутация не ловится вовсе; это было
  обобщение по одной популяции, и оно неверно.

  Второй класс дефектов измерен на второй мутации: сведение опорного отпечатка
  к полному прогону (`reference()` всегда возвращает `$baseline`) переворачивает
  четыре вердикта из восьми на фикстуре — код 1 — и ноль из сорока трёх на
  `src/`, где прогон остаётся зелёным. Это ровно тот класс «всё стало
  `Effective`», ради которого запись заведена. Проба не изолирована: тем же
  изменением выключается и `assertNarrowingChangedNothing()`, потому что при
  `reference()`, равном полному отпечатку, узкий прогон вообще не берётся.
  Вывод от этого не меняется — на `src/` мутация зелёная, на фикстуре красная,
  — но свидетельствует она о паре механизмов, а не об одном.

### Удельная цена больше не линейна по числу директив

План П5 назвал порог «сорок восемь директив вернут вопрос на стол», сняв
удельную цену с полного прохода (~1.6 с на директиву). Узкий проход делает цену
директивы ценой адресуемого правила: 261 мс у `coupling.cbo` против 37 мс у
`code-smell.constructor-overinjection`, то есть разброс в семь раз.

- **Цена:** число директив перестало предсказывать цену шага; тринадцать
  директив на `coupling.cbo` дороже тридцати на дешёвом правиле.
- **Что закроет:** если шаг снова начнёт мешать, считать не директивы, а
  Σ(nᴿ · Tᴿ) — обе величины уже снимаются профилировщиком и отчётом команды.

### Два полных исполнения остаются в цене узкого прохода

Контроль воспроизводимости до и после свипа исполняет все правила дважды:
~4.6 с из 16.0 с. Он не сокращается сужением, потому что связывает
перестроенный контекст с настоящим прогоном — единственное, что вообще
привязывает узкие отпечатки к прогону, которым жил `check`.

- **Цена:** около трети шага уходит на контроль, а не на предмет.
- **Что закроет:** ничего дешёвого. Сузить его — значит перестать доказывать
  ровно то, ради чего он есть; запись существует, чтобы следующий заход не
  «оптимизировал» его, не увидев этой связи.

### Аудит директив не адресует продюсера без класса, и ветка сужения для него недостижима

`ComputedMetricChannelFamily::SUPPORTS_THRESHOLD_OVERRIDE = false`, а все шесть
продюсеров без собственного класса — семейство `health.*`. Значит
`@qmx-threshold` не может назвать ни одного из них, и ветка «сужение называет
продюсера, размещённого в чужом хосте» из продукта не вызывается: её
единственный свидетель — юнит-тест на рукотворной декларации. Туда же фильтр
`published` под сужением: единственный вызывающий читает `produced`.

- **Цена:** контракт исполнения определяет поведение, которого сегодня нельзя
  добиться из продукта, и зелёный тест на него не является свидетельством о
  продукте.
- **Что закроет:** первый настоящий потребитель сужения помимо аудита — либо
  снятие ветки вместе с обещанием, если такого потребителя не появится.

### `isRuleDisabledByOptions()` не спускается в поуровневую конфигурацию

Найдено ревью П5, но дефект не этого захода: правило, выключенное по всем
уровням (`callable.enabled: false` плюс `class.enabled: false`), отчитывается
включённым, поэтому директива на нём получает `Inert` вместо
`Unmeasured / ProducerDisabled` — то есть аудит потребует снять живую
директиву.

- **Цена, пока это было открыто:** ложный `Inert` на коде возврата `2`, то есть
  красный CI с неверным требованием к автору.
- **Закрыто заходом Х3, пакет A.** Не тем лечением, которое здесь предписано:
  «научить метод читать поуровневую конфигурацию» — это вторая копия семантики,
  а копии в этом заходе и убираются. Вместо этого исполнение записывает, какие
  пары «продюсер × уровень» оно дало отработать (`LevelActivity` в
  `RuleExecutionResult`), а аудит читает запись; операция
  `isRuleDisabledByOptions()` из `RuleConfigurationInterface` удалена.
- **Запись покрывала не весь дефект.** Она называет только полное выключение
  всех уровней; измерено, что частичное (`callable.enabled: false` при
  включённом `class`) даёт тот же ложный `Inert` и тот же код `2`, и по-другому
  чиниться не может: директива живёт на объявлении, то есть на уровне.
- **Третий исход, которого здесь не было.** Продюсер, не объявляющий уровень
  директивы вовсе (`@qmx-threshold coupling.cbo` на методе), не выключен —
  вердикт остаётся прежним, и считать его `ProducerDisabled` значило бы
  отвечать фактом об уровне, которого директива не адресовала.

### Физическая директива не несёт уровня, и на ней исходный дефект остался

Открыто ревью исполнения Х3-A. Пакет A научил аудит читать записанную
исполнением активность по паре «продюсер × уровень», но уровень директивы
берётся у её субъекта, а субъект по инварианту `Suppression` есть только у
символьной формы: `NextLine` и `File` — физические, и то, что стоит на
подавляемой строке, известно сбору, а в директиву не передаётся.

Измерено на одном дереве и одной конфигурации
(`complexity.cyclomatic: {callable: {enabled: false}, class: {enabled: true}}`):

| форма                                         | вердикт      | код   |
| --------------------------------------------- | ------------ | ----- |
| `@qmx-ignore` в докблоке метода               | `unmeasured` | 0     |
| `// @qmx-ignore-next-line` над тем же методом | `inert`      | **2** |

- **Цена:** обещание «директива на выключенном уровне не требует снятия»
  выполняется для двух авторских форм из трёх; на третьей CI по-прежнему
  красный с неверным требованием. `CHANGELOG.md` называет это ограничение
  прямо, чтобы обещание не читалось шире, чем оно есть.
- **Почему не в пакете A:** закрытие требует, чтобы извлечение несло субъект
  строки для `NextLine`, то есть ослабления инварианта VO («физические формы не
  имеют ни субъекта, ни охвата») и правки пути сбора. Это отдельный предмет со
  своим прогоном, а не хвост пакета.
- **Что закроет:** `NextLine` получает субъект объявления, начинающегося на
  подавляемой строке, — тогда `DirectiveLevels` отвечает про него так же, как
  про символьную форму. Для `@qmx-ignore-file` уровня нет и быть не может:
  файл содержит объявления всех уровней, и гранулярность продюсера там верна.

### Вывод команды `directives` не входит в сравниваемые поверхности гейта

Открыто ревью плана Х3-A. `Surfaces::FORMATS` перечисляет двенадцать форматов
`check` (с Х3-B1 — включая `suppressed`) плюс `rules`, `baseline:explain` и
сгенерированный baseline; вердиктов команды `directives` там нет.

- **Цена:** для шага, меняющего вердикт директивы, зелёный гейт свидетельствует
  «ничто другое не сдвинулось», а не «изменение верно» — и это приходится
  оговаривать в DoD каждого такого шага, иначе он читается как доказанный. Пакет
  A захода Х3 — первый, кому пришлось.
- **Что закроет:** `directives` в списке поверхностей, с корпусным кейсом, в
  котором директивы разнородны по вердикту. Своя работа: у команды два формата и
  свои коды возврата, то есть сравнивать надо тройку, а не строку.

### Разнородный прогон контроля эквивалентности некуда положить

Единственный прогон, в котором ветки коалиций и `Overrun` вообще исполнялись
под контролем эквивалентности, сделан ревьюером на копии `src/` с посаженными
`Overrun`, мёртвой директивой и маскирующей парой: 45 вердиктов, оба охвата
совпали. Дерева нет в репозитории, а `directives:narrow-control` не принимает
цель — `src/` зашит.

- **Цена:** утверждение «узкий проход согласуется с полным и на разнородной
  популяции» держится протоколом одного сеанса, и изменение веток коалиций
  ничем не будет опровергнуто.
- **Что закроет:** параметр цели у контроля плюс засеянное дерево, живущее в
  репозитории как фикстура. Корпус `finding-gate/` для этого не годится без
  отдельного решения: он намеренно внешний, а директивы здесь — предмет нашего
  собственного дерева.

- **Закрыто пакетом Х3-F (2026-09-03).** `--target` и `--config` — обязательные
  параметры, и второй обязателен не для симметрии: продукт подхватывает
  `qmx.yaml` из рабочего каталога, поэтому прогон без явной конфигурации
  измеряется тем, где его запустили. Прогон по `src/` называет корневой
  `qmx.yaml`, чтобы пакет не переопределил молча смысл сегодняшнего контроля.
  Засеянное дерево живёт рядом с существующими фикстурами Inline, со своим
  `qmx.yaml`. Вне `src/` его держат два теста в
  `ThresholdPopulationAgreementTest`: `itKeepsTheSeededDirectivesOutOfTheEnumerationOverSrc`
  сравнивает пороговые сайты по содержанию, а не по пути, и
  `itKeepsEverySeededFixtureFileOutOfSrc` сравнивает файлы по хэшу — второй
  нужен для `EveryChannelSuppression.php`, где нет ни одного `@qmx-threshold` и
  который поэтому невидим для любого перечисления. Оба проверены снятием
  посадкой соответствующей копии под `src/`.

### Две меры популяции директив связаны тестом, который требует их совпадения

Шаг `directives:audit` сверяет универсум аудита с `enumerate-inline-directives.php`.
Обе меры читают одну и ту же регулярку: перечислитель держит копию
`ThresholdOverrideExtractor::PATTERN`, а `ThresholdDirectivePatternSyncTest`
требует, чтобы копия оставалась идентичной. Регрессия уровня самого паттерна
попадёт в обе меры разом.

- **Цена:** пара свидетелей ловит расхождение путей (discovery, `exclude_*`,
  адресуемость, селекция правил), но не ловит регрессию в общем паттерне —
  ровно тот класс, ради которого вторая мера заводилась.
- **Закрыто пакетом Х3-C, вторая половина (2026-09-03).** Копии паттерна больше нет:
  `ThresholdDirectiveScan` разбирает строку докблока по словам (директива —
  слово, оканчивающееся на тег) и режет цель по собственному, выписанному в
  этом же классе списку символов. Текстовый `ThresholdDirectivePatternSyncTest`
  удалён вместе со своим предметом, а на его место встал поведенческий
  `ThresholdPopulationAgreementTest`: обе меры отвечают на фикстуре авторских
  форм, и ответ каждой сверяется с ожиданием, а не только их ответы между
  собой. Шесть кейсов фикстуры — по одному на класс символов (`*`, `#`, `:`,
  цифра, `_`, заглавная), которых в `src/` нет ни одного, поэтому сужение
  паттерна там не измеримо ничем.
- **Измерено на двух сужениях (2026-09-03), и свидетели разные.** Сужение
  `[\w.*#:-]` → `[\w.-]` (уходят `*`, `#`, `:`): `composer directives:audit`
  остаётся зелёным с кодом 0 — в дереве таких целей нет, — а тест согласия
  краснеет тремя кейсами. Сужение `[\w.*#:-]` → `[\w*#:-]` (уходит точка):
  гейт краснеет кодом 5, «audit judged 0 sites, enumeration found 31». То есть
  живая популяция и фикстура ловят непересекающиеся сужения, и пара нужна
  целиком.
- **Ревью исполнения нашло два расхождения, которых первая редакция меры не
  видела (2026-09-03).** Первое: жадность группы значений — свойство
  сматчившейся группы, а не паттерна. Если цель упирается в несловарный символ,
  группа значений не срабатывает, `preg_match_all` продолжает с позиции сразу
  за целью и находит вторую директиву в той же строке; мера возвращала одну.
  Второе: колонка `values` не сравнивалась ничем, и на однострочном докблоке
  продукт снимает терминатор (`20`), а мера его несла (`20 */`). Оба закрыты, и
  теперь тест согласия сравнивает и значения — не побайтно с продуктом, чего
  нельзя (продукт публикует разобранные пороги, а не строку), а через продукт:
  каждая пара «цель + значения» возвращается экстрактору на собственном
  докблоке, и его прочтение обязано совпасть с прочтением исходной строки.
- **Расширение символьного класса меры теперь тоже сторожится.** Сужения ловились,
  расширения — нет: мера, допустившая `/` или `+`, читает дальше продукта, и все
  пробники сужения при этом зелёные. В фикстуре есть цели, обрываемые продуктом
  на `/`, `+` и запятой, а пробник на класс символов разбит по одному символу —
  прежний «класс сужен до букв» краснил двенадцать кейсов при шести объявленных,
  то есть был blanket-поломкой под видом точечной.
- **Заодно исправлено снятие бэктиков.** Перечислитель вырезал бэктик-регион
  целиком, а продукт заменяет его пробелами, сохраняя переводы строк; на
  многострочном регионе перед директивой номера строк расходились на число
  съеденных строк. Теперь снятие такое же, как у продукта, и колонка `values`
  сгенерированной таблицы несёт пробелы там, где раньше был вырезанный текст.

### Страж проверяет имя экземпляра, а инварианту нужно имя продюсера

`ThresholdOverrideOwnRuleNameGuardTest` требует, чтобы первым аргументом
`getThresholdOverride()` было `$this->getName()`. Сужение же сравнивает имя
**продюсера**. Для всех правил, кроме одного экземпляра, публикующего семь
продюсерских имён, это одно и то же.

- **Цена:** ноль сегодня — семейство computed порогов не поддерживает; но
  выражение, которое страж требует, отвечает на соседний вопрос, а не на тот.
- **Что закроет:** первый продюсер без класса, поддерживающий порог; тогда
  страж обязан спрашивать про имя продюсера находки.

### Предмет «авторская группа директив» не назван, и это он держит WMC

Форма `array{file, line, rule, bindings}` протянута нетипизированной через
полтора десятка сигнатур в аудите и коалиции. Выделение коалиции сняло
предупреждение `complexity.wmc`, но разрез прошёл по метрике: цикл импортов,
возникший в первой редакции выделения, был свидетельством того, что два класса
делят третий предмет, которого нет.

- **Цена:** `site()` и `subjects()` живут публичными статиками на классе, чьё
  имя про другое; докблоки повторяют форму группы полтора десятка раз.
- **Что закроет:** значимый объект авторской группы, владеющий `site()` и
  `subjects()`; аудит и коалиция зависят на него, и ни один докблок больше не
  описывает группу массивом.
- **Закрыто пакетом Х3-E (2026-09-03).** `AuthoredDirectiveGroup` владеет
  `site()` и `subjects()`, `MaskingOutcome` — обёрткой исхода; форма ушла из
  всех пятнадцати аннотаций и из всех локальных носителей, кроме одного
  накопителя внутри производителя. Когезия коалиции 50 → 85.36 при шести
  методах против четырёх; у аудита не изменилась ничего, и это ожидалось: его
  четырнадцать методов не достают до обрыва на шести.

### `Policy\Inline\Directive` разложен на предмет и его аудит

**Закрыто пакетом Х3-E (2026-09-03).** Пакет добавил в корень два класса, и
`size.class-count` встал ровно на пороге: 15 при 15. Порог не трогали и в
ратчет ничего не клали — метрика моделировала дизайн верно, предмет
действительно вырос.

Первый разрез (пять классов сквозного аудита порогов в `ThresholdAudit/`)
**отвергнут по измерению со-изменения**, второй принят. `git log -M`,
29 коммитов, трогающих классы корня:

| разрез                   | внутри / снаружи | коммитов трогает группу | выходят наружу | партнёры по выходу                              |
| ------------------------ | ---------------- | ----------------------- | -------------- | ----------------------------------------------- |
| A: только сквозной аудит | 5 / 10           | 12                      | **5 (42 %)**   | `DirectiveUsage` ×5, `InlineDirectivePolicy` ×3 |
| **B: принят**            | **7 / 8**        | 16                      | **4 (25 %)**   | `InlineDirectivePolicy` ×4                      |
| C: B + адресуемость      | 8 / 7            | —                       | 11             | —                                               |

У A `DirectiveUsage` — сильнейший партнёр аудита (5 из его 12 коммитов) и есть
**в каждом** выходящем коммите: это зеркальная половина того же отчёта
`qmx directives`, и разрез резал предмет пополам, а не по шву. У B имя
симметрично («что сделала каждая авторская директива, обе половины»),
со-изменение лучшее из измеренных, а весь остаток идёт через
`InlineDirectivePolicy` — хранилище прогона, хаб, а не предмет-ровня.
Дублирования нет ни у одного.

Сделано: `Directive/Audit/` на семь классов — `ThresholdDirectiveAudit`,
`DirectiveMaskingCoalition`, `ExecutionFingerprint`, `AuthoredDirectiveGroup`,
`MaskingOutcome`, `DirectiveUsage`, `StaleDirectiveFinding`; в корне остались
восемь. То, что B задел `DirectiveUsage` и `StaleDirectiveFinding` за пределами
исходного объёма Х3-E, принято сознательно: верная раскладка дешевле красного
порога, оставленного «на потом».

Числа до → после: `size.class-count` корня **15 → 8**, у `Audit/` — **7**.
Перенос внутри одного владельца `Analysis.Policy.Inline`, граф слоёв qmx не
изменился: 37 owner-слоёв, 0 seam, 13 coarse-рёбер до и после. Тесты остались
на прежних путях — владение тестами ключуется владельцем, а не подпредметом.

### Идентичность авторской группы: путь строкой, субъекты строками

`AuthoredDirectiveGroup::$fileKey` — строка, а не `RelativePath`, и
`subjects()` отдаёт канонические строки, а не `MetricSubject`. Это измерено, а
не забыто: строка служит **ключом** карты `thresholdOverrides`, которую аудит
переписывает, а `RelativePath::fromString()` нормализует — группа с
нормализованной формой переписала бы другую корзину, чем заполнил прогон.
Субъекты же читает `array_intersect`, сравнивающий приведением к строке.

- **Цена:** тип не отказывает на бессмысленном значении; два поля выглядят
  примитивами там, где рядом лежат VO.
- **Что закроет:** нормализация ключей карты в одном месте (тогда путь может
  стать `RelativePath`) и пересечение субъектов по значению VO. Оба — отдельные
  предметы: смена любого из них молча меняет адресацию, а не только тип.

### Страж инварианта видит форму, а не тип, и три класса чтения проходят мимо

Третий раунд ревью прогнал шестнадцать форм через сам страж. Не видны: контекст,
добытый не из параметра (свойство, в том числе promoted), хинт по полному имени
или через алиас импорта, `AnalysisContext|null`, простое переприсваивание в
локальную переменную, имя метода, собранное конкатенацией. Видны: `?->`,
комментарий между оператором и именем, фигурная и `call_user_func`-формы,
первоклассный callable, `?AnalysisContext`.

Отдельно: у стража есть ложное срабатывание. Класс-коллаборатор, которому имя
правила передаётся параметром, краснит стража, ничего не нарушая, — а вынесение
логики порога в коллаборатор в этом репозитории обычный ход.

- **Цена:** пропуск неотличим от отсутствия — файл, в котором тип не распознан
  текстуально, выпадает из вселенной проверки; а ложное срабатывание толкает
  следующего автора ослабить стража вместо того, чтобы чинить код.
- **Что закроет:** проверка по типу, а не по тексту (значимый объект
  «собственное имя правила», который правило может создать только из себя, либо
  разбор с настоящим резолвом типов), плюс перечисление публичных носителей
  `AnalysisContext` — сегодня их два: `PreparedRun::$context` и
  `ThresholdDirectiveAuditInput::$baseline`.
- **Закрыто пакетом Х3-D (2026-09-03) в части, которая покупалась текстом.**
  Ложное срабатывание снято: страж судит вызов и чтение только на приёмнике,
  который он распознал как `AnalysisContext`, — коллаборатор с параметром
  `$ruleName` больше не краснеет. Оба носителя из «что закроет» измеряются, а не
  перечисляются в прозе: набор публичных полей типа `AnalysisContext` собирается
  по `src/` и сегодня равен `baseline, context`, и чтение через цепочку полей
  видно. Типовой гарантии здесь нет — три измерения, по которым она не куплена,
  ниже, в разделе Х3-D. Остаются слепыми динамическое имя метода и носитель,
  нераспознаваемый текстуально; они названы в докблоке стража.

### Пол CI-шага считает измеренным всё, что не названо неизмеренным

`directive-audit-gate.php` считает вердикт измеренным по условию
`effect !== 'unmeasured'`, поэтому отсутствующий ключ, `null` или опечатка в
сериализации перечисления пройдут за измеренный вердикт. Сегодня недостижимо —
презентер всегда пишет значение перечисления.

- **Цена:** тот же fail-open по неузнанной форме, ради устранения которого
  переписывался страж.
- **Закрыто пакетом Х3-C, первая половина (2026-09-03).** Измеренность вердикта решает
  замороженная таблица `MeasuredEffects::TABLE`, а значение вне её — отказ шага
  (код 7), причём проверяется она при чтении отчёта, для обеих форм директив, а
  не лениво на пороговой половине. Оба скрипта читают отчёт одной библиотекой
  `scripts/directive-audit/`, так что условия пола больше не две копии одной
  строки. Строка TSV разбирается `SiteEnumeration::fromTsv()` с отказом на
  недостающей колонке, нечисловом номере строки и пустой цели; таб внутри
  значений отказом не является.

### Контроль полноты стража не привязан к местам, которые называет

`itFindsAtLeastTheTwoKnownProductionCallSites()` требует «не меньше двух»
распознанных вызовов по всему `src/`, тогда как докблок называет два конкретных
места. Исчезновение обоих канонических вызовов при появлении двух других
контроль не заметит.

- **Цена:** контроль своей полноты слабее, чем читается.
- **Что закроет:** проверка присутствия вызова именно в названных файлах.
- **Закрыто пакетом Х3-D (2026-09-03).** `itFindsExactlyTheKnownProductionCallSites()`
  сверяет перечисление «файл => методы» с `KNOWN_CALL_SITES` целиком, поэтому
  краснеет и на исчезнувшем каноническом вызове, и на невнесённом третьем.
  Заодно исправлен адрес: второй канонический вызов живёт в
  `LongParameterListRule::checkVoConstructor()`, а не в `analyze()`, как называл
  докблок.

## Х3 (2026-09-02) — разбор follow-ups

### Проза повторяет счёт, который живёт в коде, и расходится с ним молча

Обобщение трёх случаев, найденных пакетами Х3-B1 и его ревью. Предмет не в том,
что где-то написано не то число, а в том, что число вообще написано: счёт,
живущий в коде, дублируется прозой, и у копии нет никакого способа узнать, что
оригинал сдвинулся.

Известные экземпляры на 2026-09-02:

| счёт                              | живёт в                        | прозы                                                                                                                 | состояние                                                      |
| --------------------------------- | ------------------------------ | --------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| сравниваемые гейтом форматы       | `Surfaces::FORMATS`            | четырнадцать мест                                                                                                     | выправлен B1, но тем же способом, который и порождает проблему |
| кейсы корпуса                     | каталоги `finding-gate/cases/` | «fourteen» при шестнадцати: `finding-gate/README.md:397`, `Controls.php:840`, `RenameMaps.php:50`, `SelfTest.php:612` | не правлен                                                     |
| контроли эквивалентности в полёте | `Controls::all()`              | «fourteen» при семнадцати: `Harness.php:39-40`, `Shell.php:324`                                                       | не правлен                                                     |

`ReportPayload.php:17` в счёт не входит: там измерение Ш5e3, предмет прошедшего
времени.

- **Цена.** Комментарий, объясняющий калибровку числом, читается как измеренный
  факт; разойдясь с деревом, он аргументирует решение, которого больше нет. Ни
  одно из названных мест не несёт исполняемой арифметики, так что цена пока
  только в чтении — но она уже реализовалась дважды за один заход, и второй раз
  дефект пережил ревью плана и был найден только ревью исполнения.
- **Почему правка чисел закрытием не является.** B1 выправил четырнадцать мест
  и на пятнадцатом (`RenameMaps.php:131`, «the other eight formats») промахнулся
  ровно потому, что производный счёт не похож на итог. Следующая правка
  `Surfaces::FORMATS` начнёт цикл заново: он не сходится, потому что причина не
  в числах, а в том, что копия существует.
- **Что закроет, и в репозитории для этого есть образец.**
  `tests/System/DocumentationConsistency/ChannelPublicationConsistencyTest`
  заведён ровно на этот класс — он привязывает прозаические счёты каналов к
  машинным декларациям, потому что восемь страниц разъехались одновременно. То
  же для трёх счётов выше: тест, читающий прозу периметра и требующий согласия с
  `count(Surfaces::FORMATS)`, числом каталогов кейсов и `Controls::all()`.
  Инструмент перечисления уже написан — `X3-followups/enumeration-surface-count.sh`
  и его таблица, — и его субъектные слова (`format`, `surface`, `формат`,
  `поверхность`) для двух других счётов надо дополнить своими (`case`, `control`).
  Пока такого теста нет, любая правка чисел — отсрочка, а не закрытие.

### Разделитель `\s+` у продукта переходит через перевод строки

Найдено ревью исполнения Х3-C. Между тегом и целью у
`ThresholdOverrideExtractor::PATTERN` стоит `\s+`, а `preg_match_all` идёт по
всему тексту докблока, а не построчно. Поэтому форма

```
/**
 * @qmx-threshold
 * complexity.cyclomatic 15
 */
```

распознаётся продуктом с целью `*` — звёздочкой продолжения докблока, — и
директива без цели тихо становится директивой, адресующей бессмыслицу. Обе
построчные меры популяции её не видят.

- **Цена:** автор, забывший цель, получает не отказ, а вердикт о канале `*`.
  В `src/` такой формы сегодня нет, поэтому расхождение мер дремлет; ценой оно
  станет в первый же день, когда появится.
- **Почему не в пакете C:** правка меняет извлечение, то есть публикуемые
  находки и вердикты, и требует своего прогона гейта эквивалентности. Пакет C
  правит только то, что читает отчёт, и намеренно не вносит это расхождение в
  ожидания фикстуры: согласовать меры на нём можно только через продукт.
- **Что закроет:** разделитель `[ \t]+` вместо `\s+` (либо явный отказ на
  директиве без цели), плюс кейс в фикстуре авторских форм.

### Согласие двух мер проверено на фикстуре и на `src/`, но не на корпусе

Вторая половина пакета Х3-C сверяет меры на фикстуре авторских форм и, косвенно, на живом
`src/` — каждым прогоном `composer directives:audit`. Корпус
`finding-gate/cases/` в сверку не входит: перечислитель адресуется к `src/`, а
цели у него нет.

- **Цена:** формы, которые пишут в корпусных фикстурах, второй мерой не
  измеряются вовсе; если такая форма разойдётся с продуктом, узнать об этом
  будет неоткуда до первого ручного прогона.
- **Что закроет:** параметр цели у перечислителя плюс сверка по корпусу —
  та же работа, что нужна разнородному прогону контроля узкого прохода
  (запись выше), и делать её стоит одним шагом.

### `CheckCommand` на когезии 50.0 перестал быть наблюдаемым по этому каналу

Хвост пакета Х3-C2 (гигиена ратчета). Исключение
`Qualimetrix\Infrastructure\Console\Command` из `health.cohesion` снимало
канал со всего корня команд — измерено: 7 подавленных находок при 19 классах
корня, у которых канал вообще есть, и со всякого класса, который в этом корне
появится.

**Исправление формулировки.** Прежняя редакция называла `CheckCommand`
«в нуле от восьмой записи ратчета», и это читается как «он был кандидатом».
Он им не был: 50.0 при границе предупреждения 50.0 находки **не порождает уже
сегодня**. Он в нуле от того, чтобы им стать, — это другое утверждение и другая
цена.

**Что отменило измерение — дважды.** Прежнее «что закроет» предписывало
«осознанный порог `size.method-count` для этого корня». Механизма нет: у блока
правила только три ключа `exclude_*`, порогов, ограниченных неймспейсом или
путём, в продукте не существует. Второй кандидат, `@qmx-threshold` на классе,
не существует тоже — вычисляемые каналы переопределение порога не поддерживают
(`SUPPORTS_THRESHOLD_OVERRIDE = false`), и аннотация ушла бы в
`annotation.unsupported-threshold`.

**Закрыто пакетом 6 захода Х5.** Исключение сужено с неймспейса до семи путей —
ровно тех классов, что стоят на 40. Каждый кандидат проверен прогоном на самом
`health.cohesion`, а не рассуждением:

| механизм                                         | проверка                                              | исход                                                                                 |
| ------------------------------------------------ | ----------------------------------------------------- | ------------------------------------------------------------------------------------- |
| опустить глобальный порог `size.method-count`    | сколько классов вне корня краснеет                    | отвергнут: чтобы задеть корень вообще, нужен порог ≤ 11, а это 51 класс **вне** корня |
| `@qmx-ignore health.cohesion` на семи классах    | принимает ли инлайн-форма вычисляемый канал           | **работает** (фикстура: `suppression: 1`, находка класса уходит), но отвергнут        |
| `exclude_paths` на семь файлов вместо неймспейса | публикуется ли находка `CheckCommand`, когда она есть | **выбран**                                                                            |
| оставить как есть                                | —                                                     | не понадобился                                                                        |

Оракул выбора: при границе, сдвинутой на 51, форма по неймспейсу находку
`CheckCommand` подавляет, а форма по путям — публикует (50.0). При настоящей
границе опубликованное множество `bin/qmx check src/` под обеими формами
одинаково — 221 находка, побайтно, — то есть ранний сигнал возвращён без единой
новой записи ратчета.

- **Почему не инлайн, хотя он работает:** AGENTS.md предпочитает конфигурацию
  инлайн-тегам, когда исключение относится к категории, а «команда консоли не
  разделяет полей по построению» — свойство категории. У инлайна есть одно
  настоящее преимущество, и оно записано ниже отдельной записью: протухшая
  аннотация получает вердикт, а протухшее исключение не получает ничего.
- **Цена выбранного:** файл из списка, чья когезия позже поднимется, оставит
  исключение, которое ничего не гасит и никому об этом не скажет.
- **Уточнение, которого в записи не было:** когезия остаётся членом
  `health.overall`, который на этом корне не исключён. Сигналом это не является:
  у худших классов корня 84.3–85 при любом разумном пороге.
### Подробный режим печатает лог в тот же поток, где рисуется бар

Та же запись, что «Диагностика идёт мимо кадра прогресса, и её фолбэки не
сведены» (~576), увиденная со стороны подробности вывода. Х5 измерил обе и
показал, что предмет один: verbosity лишь поставляет писателей в поток ошибок,
а кадр бара разрушает любой несекционированный писатель — включая
предупреждение при обычной подробности, у которого verbosity нет вовсе.

**Закрыто вместе с ней, пакетом 5 захода Х5 (`a4291097`).** Подробности
измерения, перечисление писателей и отвергнутые лечения — у записи-владельца;
здесь не повторяются, чтобы два раздела не разошлись об одном коде.

Что из прежней формулировки было неверно и исправлено измерением: порог — `-vv`,
а не `-vvv`; цена больше записанной (гибнут две строки лога и сам бар, а не одна
строка); предмет — не verbosity. Ложный буллет README о том, что `-v`/`-vv`/`-vvv`
выключают прогресс, был снят ещё на Х3; бар выше `NORMAL` сознательно **не**
гасится, и это решение записано у владельца.
## Х3-F (2026-09-03) — разнородная популяция контроля эквивалентности

### Популяция, удовлетворяющая полу, не даёт сравнению вердиктов увидеть сужение не тем правилом

Первая редакция этой записи обобщила измерение на одной популяции и написала
«структурно недостижимо». Ревью исполнения опровергло: на той же фикстуре **без**
`OverrunBoundary.php` та же мутация `narrowedTo()` → чужое имя даёт `MISMATCH` и
код 1, три вердикта расходятся (`narrow=inert` против `full=effective` и
`full=unmeasured/masked`). Третий контроль аудита
(`assertNarrowingChangedNothing()`) срабатывает не «из-за `Overrun`», а из-за
того, что адресуемое правило хоть что-то произвело в базовом прогоне; `Overrun`
— лишь один способ это обеспечить, зато обязательный, раз пол его требует.

Верное утверждение узкое: **популяция, удовлетворяющая полу разнородности,
обязана нести `Overrun`, поэтому на ней эта мутация останавливается кодом 3
раньше любого вердикта.** На популяции, где каждое адресуемое правило заглушено
своей директивой, она доходит до сравнения и красит его кодом 1.

- **Закрыто пакетом Х3-F, вторая редакция (2026-09-03).** Третьим прогоном
  `composer directives:narrow-control` по
  `tests/Analysis/Policy/Inline/Fixtures/NarrowControl/Silenced` — без пола,
  0.5 с. Чисто даёт 0, под мутацией 1. DoD 4 закрыт сравнением вердиктов, как и
  требовалось.
- **Осталось как цена:** два прогона по одной фикстуре вместо одного, и
  требование помнить, что пол и различающая сила тянут в разные стороны — пол
  требует `Overrun`, а `Overrun` закрывает одну из проб.

### Ось причин у пола разнородности — покрытие словаря, а не различение

Пол требует все четыре значения `DirectiveUnmeasurableReason`, но различающую
силу из них несёт один. `AddressesEveryChannel` достижим только у подавления, то
есть у формы, которую свип не двигает по построению; `AlreadyRefused` и
`ProducerDisabled` присуждаются из конфигурации и каталога каналов до того, как
свип о чём-либо спросят. Ветка, ради которой ось заведена, — `Masked`.

- **Цена:** три четверти оси — проверка того, что фикстура упоминает весь
  словарь, а не того, что популяция что-то различает. Ось вердиктов после ревью
  считается только по форме `threshold` именно потому, что смешение этих двух
  смыслов один раз уже пропустило вырожденную популяцию.
- **Что закроет:** ничего дешёвого и ничего нужного. Запись существует, чтобы
  следующий заход не прочитал «четыре причины» как «четыре ветки свипа».

### Перечисление мест, дающих непонятный код возврата, было короче самих мест дважды

Сначала план называл «десять мест», бросающих голое исключение, и потерял два:
«отчёт сообщает о другом свипе» и «корень репозитория неразрешим» —
перечисление писалось по описаниям исходов, а не по точкам `throw`. Первая
редакция этой записи объявила лечением «перечислять точки `throw` командой по
файлу» и сама на этом попалась: `Process::run()` бросает
`QmxFindingGate\GateError`, а скрипт подключал только `Process.php`, который
своего исключения не требует, автозагрузчика же здесь нет. Измерено ревью:
`Process::run(["php","-v"], "/no/such/dir")` давало
`Fatal error: Class "QmxFindingGate\GateError" not found` и 255 — ровно тот
исход, которого коды обещали не оставить.

- **Закрыто пакетом Х3-F, вторая редакция (2026-09-03).** `require` на
  `GateError.php` плюс `catch (\Throwable)` в `Harness::main()`; гарантия
  сформулирована над `Throwable`, а не над списком мест. Проверено снятием на
  клоне: `Error`, брошенный внутри сравнения, даёт 3, а под прежним
  `catch (RuntimeException)` — 255.
- **Осталось как урок:** перечисление точек `throw` **в одном файле** не видит
  `throw` в подключаемом хелпере. Оба раза дефект был в перечислении, а не в
  коде, и оба раза перечисление казалось исчерпывающим.

### Составной прогон контроля возвращает код первой неудачи, а не худший из трёх

`composer directives:narrow-control` — три последовательные команды composer.
Composer прекращает список на первой ненулевой, поэтому код цели — код первого
упавшего прогона, а не максимум по трём, и остальные при его падении не
исполняются вовсе.

- **Цена:** увидеть все три при сломанном первом можно только запуском по
  отдельности.
- **Смягчено во второй редакции:** порядок — от самого дешёвого сигнала к
  самому дорогому (фикстура 0.6 с, `Silenced/` 0.5 с, `src/` 70 с), так что
  70-секундный прогон больше не стоит перед тем, что несёт весь новый сигнал.
  Сказано в `scripts-descriptions` и в AGENTS.md, а не только здесь.
- **Что закроет:** оболочка, исполняющая все цели и сводящая коды. Пока её нет,
  формулировка «первый упавший» — та, что верна.

### Посаженная фикстура входит в перечисление переименований

Утверждение первой редакции «посаженные директивы не попадают в измерения
дерева» неверно. Измерено 2026-09-03 удалением каталога фикстуры на клоне:
`enumeration-renames.tsv` — отслеживаемый артефакт под `check:artifacts` —
меняется на девяти счётчиках (`code-smell.long-parameter-list` 70→69,
`complexity.cognitive` 85→83 и так далее). Ратчет, снимок подавлений,
`bin/qmx check src/`, перечисление директив по `src/` и корпус гейта фикстурой
действительно не задеты.

- **Цена:** правка докблока фикстуры требует перегенерации
  `enumeration-renames.tsv`, иначе `check:artifacts` краснеет.
- **Решено не исключать каталог из периметра.** Это перечисление отвечает на
  вопрос «сколько текста придётся править при переименовании канала», и докблок
  фикстуры — такой же текст, как докблок
  `Fixtures/ThresholdAudit/PairedDirectives.php`, входящий туда с самого начала.
  Исключение сделало бы перечисление меньше правды ради тишины.

## Х3-D (2026-09-03) — собственное имя правила

### Инвариант «правило не читает чужую директиву» типом в этом дереве не купить

Первая редакция плана Х3-D предлагала снять текстового стража и держать
инвариант типом: `getThresholdOverride()` принимает экземпляр правила вместо
строки, карта переопределений приватизируется. Ревью опровергло это тремя
измерениями на дереве `84effeaf`; каждое достаточно само по себе, и все три
записаны здесь, чтобы следующий заход не измерял их заново.

1. **Цикл зависимостей уровня `error`.** `RuleInterface` уже импортирует
   `AnalysisContext`; типизация параметра на `RuleInterface` замыкает пару.
   Измерено на копии дерева с применённой правкой: до правки циклов ноль, после
   — один прямой, то есть `error`, а `selfcheck` идёт с `--fail-on=warning`.
2. **Приватизация карты ломает живого производственного читателя.**
   `ThresholdDirectiveAudit` читает `$input->baseline->thresholdOverrides` в двух
   местах (строки 99 и 195), а `$input->baseline` — это `AnalysisContext`. Ноль
   нарушений у прежнего стража получался ровно потому, что его трекер не видел
   доступ через чужое поле: вывод о мире был сделан из измерения инструмента.
3. **Экземпляр правила подделывается одной строкой.** Конструктор `AbstractRule`
   публичный: `new WmcRule(new WmcOptions())` даёт `complexity.wmc` — проверено
   исполнением. `AbstractRule` не финален, анонимный подкласс с произвольным
   `getName()` тоже доступен. «Чтобы прочитать чужую директиву, нужен чужой
   экземпляр» барьером не является: экземпляр не добывают, его делают.

- **Цена:** инвариант держится текстом. Правило, собравшее имя метода
  динамически или получившее контекст через носитель, который страж не
  распознаёт текстуально, проходит мимо; перечисление публичных полей-носителей
  закрывает те два, что есть сегодня, а не общий случай.
- **Что закроет:** другой контракт, а не более умный токенизатор, — например,
  переопределения приходят правилу уже отфильтрованными по его имени, и читать
  чужое нечего. Это работа своего масштаба: она снимает и цикл (правилу не нужен
  тип правила), и приватизацию карты (аудит остаётся владельцем).
- **Перечитано после пакета 3 захода Х5: механизма не появилось.** Пакет 3
  добавил `RuleExecutionInterface::publishable()` — операцию про отбор каналов у
  публикации, а не про то, чью директиву читает правило. Карта переопределений
  по-прежнему приходит правилу целиком, инвариант по-прежнему держится текстом.

### Гейт не умеет объявить намеренное изменение множества находок

Запись Х3-H распадается надвое: **движение поля внутри записи** и **изменение
состава записей**. Х5 измерил обе половины и закрыл первую.

**Половина I — движение поля внутри записи. Закрыта пакетом 1 захода Х5
(`e494cda7`, `0058c805`).** `delta-overreach` отказывал строке диффа, двигающей
сравниваемое поле, если движение не объяснено объявленным `split`, а `split`
производит движения только трёх полей — `channel`, `rule`, `code`
(`ChannelSplit::FIELDS`). Поэтому `message`, `techDebtMinutes`, `file`, `line`
и `subject` не мог лицензировать никто: не потому, что двигать их опасно, а
потому, что списка для них не существовало. `declared-field-moves.tsv` — этот
список: одна строка лицензирует одну точную четвёрку
(поверхность, поле, из, в), срабатывает по равенству, а не по вхождению, несёт
обязательную причину и становится `field-move-stale`, когда ни одна строка
диффа этого движения не совершает.

Измерение, отменившее прежнюю оценку: правка подсказок «did you mean» состав
находок **не меняет** — `finding-count-mismatch` не возникает, число записей то
же. Это движение одного поля одной записи на девяти поверхностях; восемь из
девяти `DeclaredDelta` принимал и до пакета 1, девятая (`format:json`) и взяла
единственную строку лицензии. Прежняя редакция записи утверждала, что объявить
эту правку нечем; объявить её было чем на восьми поверхностях из девяти, а
недоставало ровно одной формы.

**Половина II — изменение состава находок. Открыта, отложена по измеренному
основанию.** Три прогона против `38ad58e9` с корпусной фикстурой:

| прогон                    | исход                                                                |
| ------------------------- | -------------------------------------------------------------------- |
| `--derive-declared-delta` | отказ писать вердикт: `finding-count-mismatch`, candidate 7 против 8 |
| причины заполнены руками  | RED, 27 отказов: тот же счёт плюс 26 `delta-overreach`               |
| фикстура без объявлений   | RED, 13 отказов (счёт плюс двенадцать поверхностей)                  |

Исчезнувшая запись уносит с собой все поля кортежа эквивалентности, и никакая
лицензия движения поля этого не покрывает: движение поля предполагает запись, в
которой оно движется.

- **Почему отложена:** её сегодня не требует ни один шаг на столе, а `SelfTest`
  не покрывает счётчик находок ни одним ассертом.
- **Две развилки её проекта, не измеренные и обязанные не потеряться:**
  - исчезновение **последней** записи канала в кейсе поднимает
    `case-claim-mismatch` и `coverage-shortfall`, то есть требует снять канал из
    `case.json` и из витнесса — взаимодействие с покрытием не спроектировано;
  - агрегаты (`techDebtMinutes` итоговой строки) двигаются **как следствие**
    исчезновения записи и не принадлежат ни одной исчезнувшей идентичности:
    отдельная объявляемая величина или нормализация — не решено.
- **Что закроет:** форма объявления, ключуемая кейсом и точными множествами
  идентичностей исчезающих и появляющихся записей. Это правка самого
  компаратора, поэтому за ней обязателен повторный `composer gate:controls`.
### Символьная форма адресует канал, который она не может погасить никогда

`@qmx-ignore annotation.unused-directive` в докблоке инертна **по построению**:
субъект `StaleDirectiveFinding` — файловый агрегат, а символьное подавление
сопоставляется по точному субъекту объявления. Никакая правка вычисления её не
оживила бы.

**Закрыто заходом Х4, пакет 2 (2026-09-03).** Выбрана развилка «форма
отвергается внятно»: отказ называет канал и печатается на авторской строке.
Проверяет
`UnusedDirectiveRuleTest::itRefusesAnExactChannelAndAGroupThatReachTheBannedChannel`.

**Перечитано после пакетов 3 и 4 захода Х5 — формулировка не устарела.**
Пакет 3 подчинил поздний канал отбору каналов, то есть тронул то, что решает
`--disable-rule`/`--only-rule`, а не то, что решает директива; пакет 4 тронул
написание ключа конфигурации, а не форму директивы. Ни один из них не даёт
символьной форме способа адресовать этот канал, и запрет Х4 остаётся
единственным ответом на неё.
### Публикация и аудит расходятся ещё в одном месте, и оно остаётся

**Причина, записанная прежде, неверна, и это измерено.** Запись говорила:
`SuppressionFilter` применяется после конвейера, аудит судит до него, отсюда
расхождение. Свидетеля у этой причины больше нет — запрет Х4 спрашивается
внутри `SuppressionFilter::applies()`, общего предиката публикации и аудита,
поэтому позднее положение фильтра могло бы создать расхождение только на канале,
который сам собирается поздно, а его ни одна директива больше не сопоставляет.
Для всех прочих каналов у публикации и у аудита буквально один метод. Тот
экземпляр, на котором расхождение измеряли (`@qmx-ignore-file annotation.*`),
сегодня даёт у обеих команд один ответ.

**Живое расхождение есть, и механизм другой: разные универсумы.** Аудит
спрашивают про `ruleExecution->produced`, отчёт строится из `published` — после
леджера исключений, отбора каналов, ратчета и git-скоупа. Свидетель (фикстура,
`rules.code-smell.boolean-argument.exclude_paths` на аннотированный файл):
снятие директивы не меняет отчёт ни на байт, а `bin/qmx directives` в том же
прогоне говорит `1 effective`. Направление обратное записанному прежде.

Проверено, что не всякий источник универсума даёт расхождение: отбор каналов не
даёт — аудит честно отвечает `unmeasured` с причиной «продюсер адресованного
канала не исполнялся». Ратчет и git-скоуп как источники **не измерены** и
названы гипотезой средней уверенности, выведенной из порядка фильтров.

- **Что сделано Х5, пакет 6:** сужено обещание докблока
  `DirectiveEffect::Effective` — вердикт отвечает про то, что произвели правила,
  а не про то, что покажет отчёт, и измеренный экземпляр назван в нём же.
  Расхождение при этом документировано как намеренное
  (`website/docs/usage/cli-options.md`), то есть предмет записи — не дефект
  публикации, а точность обещания.
- **Что остаётся открытым:** порядок стадий не тронут, и отдельного вердикта
  «effective, но ничего не публикует» нет — он записан ниже, в разделе Х5.
## Х4 (2026-09-03) — запрет подавлять `annotation.unused-directive`

### Подсказки «did you mean» советуют канал, который нельзя погасить

`DirectiveNameHints` подбирал ближайшее имя к опечатке и предлагал
`annotation.unused-directive`: этот канал в одном исправлении от собственного
семейства, поэтому попадал в список по расстоянию. Автор писал предложенное и
получал отказ.

**Закрыто пакетом 1 захода Х5 (`44f13a71`) — в поисковой части.** Оба прыжка
поиска (ближайшие каналы и каналы ближайшего правила) фильтруют кандидатов через
`addressable()`. Страж измеряет радиус, а не повторяет его: забаненное имя лежит
внутри расстояния подсказки от пробы, поэтому его отсутствие есть работа фильтра,
а не слишком короткий радиус.

**Прежняя причина отсрочки была неточна, и это стоит назвать прямо.** Запись
утверждала: правку нечем объявить гейту, поэтому шаг снят с Х4 целиком.
Измерение Х5 показало другое — состав находок правка не меняет, движется одно
поле `message` одной записи на девяти поверхностях, и восемь из девяти
`DeclaredDelta` принимал уже тогда. Недоставало ровно лицензии на движение поля
на девятой; её и завёл пакет 1.

- **Что осталось и записано отдельно (см. раздел Х5):** ветка «X names a rule,
  not a channel. Its channels are: …» через `addressable()` не проходит и
  по-прежнему печатает забаненный канал среди каналов правила.
### Форма без фильтра правил не получает вердикта и может умереть молча

`@qmx-ignore-file` без канала не называет ничего, поэтому у неё нет продюсера,
которого можно спросить, и аудит отвечает `unmeasured` с причиной
`addresses-every-channel` — не `inert` и не `effective`. До запрета единственным
наблюдаемым следствием этой формы на нашем дереве было гашение забаненного
канала; запрет его снял.

- **Цена, принятая сознательно:** форма не судится по построению. Потеряв
  единственное покрытие (сегодня — фикстура
  `tests/.../NarrowControl/EveryChannelSuppression.php`, обязательная для пола
  разнородности контроля с причиной `addresses-every-channel`), она станет
  мёртвой и ничто об этом не скажет.
- **Что закроет:** отдельный вердикт для формы без фильтра — «покрывает всё, и
  вот что именно она погасила в этом прогоне», то есть измерение вместо отказа
  измерять. Это работа своего масштаба: у формы нет адресуемого канала, поэтому
  ответ придётся строить из состава подавленного, а не из каталога.
- **Перечитано после пакета 3 захода Х5: единственное покрытие цело.** Фикстура
  `tests/Analysis/Policy/Inline/Fixtures/NarrowControl/EveryChannelSuppression.php`
  на месте и по-прежнему несёт причину `addresses-every-channel`, обязательную
  для пола разнородности контроля. Пакет 3 менял, что публикуется, а не что
  измеримо, поэтому вердикт этой формы он не тронул.

### Поканальные исключения к этому каналу неприменимы по построению

Запись верна по всем четырём проверенным утверждениям, но её предмет был уже,
чем есть, и уже он был не по каналам, а **по потребителям**. В
`RuleExecution::published()` стоят две проверки, и позднему каналу были
недоступны обе: леджер исключений **и** `RuleSelector::isChannelEnabled()`.
Отсюда пять поверхностей, а не три (измерено на фикстуре с контролями):

| поверхность                            | результат до Х5                       | как отказывала |
| -------------------------------------- | ------------------------------------- | -------------- |
| `rules.<r>.exclude_paths`              | инертна                               | молча          |
| `rules.<r>.exclude_namespaces`         | инертна                               | молча          |
| `rules.<r>.exclude_namespace_channels` | недостижима                           | exit 3         |
| `--disable-rule=<код канала>`          | инертна                               | **молча**      |
| `--only-rule=<код канала>`             | публиковала канал, который не назвала | **молча**      |

**Половина «отбор каналов» закрыта пакетом 3 захода Х5 (`25ee9ef8`).**
`RuleExecutionInterface::publishable()` отдаёт ту же половину `published()`
единственному вызывающему, который собирает находки поздно, и
`AnalysisPipeline::reportedFindings()` спрашивает её там, где канал входит в
отчёт. Предикат — тот же объект и тот же вызов, поэтому квантифицированная по
объединению половина грамматики не выводится заново для каждого селектора.

Пакет 3 исправил и арифметику самой записи: симптом «пустой отчёт при
`--only-rule=annotation.unused-directive`» порождался **неизмеримостью**, а не
отбором, и в первой редакции плана Х5 был истолкован как инверсия. Настоящая
вторая половина дефекта — `--only-rule`, назвавший **соседний** канал того же
продюсера, публиковал поздний, хотя не называл его.

**Половина «леджер исключений» осталась, и теперь у неё есть измеренная
причина.** Применить леджер к позднему каналу технически можно, и пакет 3 это
измерил: находка из отчёта уходит, но ни счётчик механизма, ни
`--show-suppressed`, ни атрибуция её удаления этого не показывают — леджер живёт
одну итерацию `execute()`, и его счёт заморожен в результате раньше, чем этот
канал существует. Поэтому применён только отбор каналов, а леджер сознательно не
применён: тихое удаление находки хуже, чем неприменимая опция.

- **Цена сегодня:** точечно выключить канал по-прежнему нечем, кроме
  верхнеуровневого `exclude_paths`, ратчета и git-скоупа; поканальные
  `exclude_*` под правилом принимаются и молчат.
- **Что закроет:** решение о месте сборки — либо канал порождается внутри
  исполнения правила и проходит леджер вместе со счётом, либо у поздних каналов
  заводится собственная стадия исключений со своим счётом. Оба варианта трогают
  порядок публикации, то есть предмет соседней открытой записи.
### `exclude_namespace_channels` не может назвать канал с дефисом

Ключи этой опции лежат на уровне 3 секции `rules`, которая нормализует всё ниже
уровня 1, поэтому имя канала камелкейсилось в имя, не адресующее ни одного
канала: `code-smell.boolean-argument` доходил до валидатора как
`codeSmell.booleanArgument` и кончал прогон кодом 3, печатая верное имя в той же
фразе, где отвергал написанное.

**Охват переписан по измеренному, и он в обе стороны не тот, что был записан.**
По статическому словарю опция срабатывает только на находках уровня `namespace`,
а таких каналов четыре (`coupling.cbo`, `coupling.distance`,
`coupling.instability`, `size.class-count`), и дефис содержит ровно один. Но
настоящий охват — **весь открытый словарь вычисляемых метрик**: валидатор имён
`health.*` / `computed.*` kebab **предписывает**, а вычисляемые метрики по
умолчанию отчитываются на уровнях `namespace` и `project` — то есть ровно тот
словарь, для которого опция и написана, был недостижим целиком. Ломались и
групповая форма `X.*`, и парная `channel:namespace` формы ADR 0025.

**Закрыто пакетом 4 захода Х5 (`aa948685`).** Какие ключи — слова пользователя,
осталось свойством схемы, как ADR 0009 решил про сами политики секций:
`ConfigSchema` объявляет опции, ключуемые идентификаторами, загрузчик читает этот
список, а политика отвечает, чем обходится подмассив под таким ключом. Список не
читается внутри перечисления: схема уже называет перечисление, и обратное ребро
— цикл, который собственный анализ проекта отвергает.

- **Проверено фикстурой, а не своим деревом:** единственный исключённый канал
  нашего `qmx.yaml` дефиса не содержит, поэтому дерево эту правку засвидетельствовать
  не может.
- **Хвост, названный пакетом заранее и оставшийся:** ключ
  `annotation.unused-directive` под `annotation.directive` теперь **принимается**
  и не гасит ничего — производимость, а не применимость, по ADR 0025. Отдельная
  запись об этом — в разделе Х5 ниже.
### `Outcome::asDeclared()` сверяет объявленное подмножеством, а не равенством

Харнесс пробников считал пробник «as declared», когда все объявленные им кейсы
покраснели и покраснели не все кейсы прогона. Обе половины прятали живой дефект:
подмножество оставляло непроверенным всё, что пробник краснит **сверх**
объявленного, а подстрочное сопоставление засчитывало объявление, называющее имя
метода, по любому из его датасетов.

**Закрыто пакетом 2 захода Х5 (`7955a703`) в части семантики.** Объявление —
равенство над точными именами кейсов; каскад, который поломка действительно
вызывает, записывается, а не терпится: `Probe::alsoReddens()` принимает причину
рядом с кейсами, а красный кейс ни в одном из двух списков даёт новый вердикт
`REDDENED MORE THAN DECLARED`. `blanket` по-прежнему освобождает пробник от
верхней границы и ни от чего больше, поэтому оба blanket-пробника называют свои
32 и 15 кейсов.

Счёт, добытый переходом на равенство: 39 пробников из 116 краснили 179 кейсов,
которых никто не объявлял, один из них — 33. Ни одна из 39 поломок не была
сужена: в каждой лишние кейсы читают тот же узел, что и кейс самого утверждения.
45 объявлений вида `data set "..."` переписаны в полные имена кейсов скриптом по
JUnit-логу зелёного прогона, не руками.

- **Что осталось открытым и записано отдельно (раздел Х5):** ключ кейса без
  `classname`; условие покрытия, считающее защищённым кейс, который краснеет
  только чьим-то каскадом; и недетерминизм стенда под нагрузкой.
### Методическая: запись follow-up описывает предмет уже, чем он есть

Х3 уже давал этот урок (измерение опровергло постановку в четырёх пакетах из
шести). Х4 добавил к нему два новых механизма, и оба стоит назвать.

**Запись, написанная по исполненному и измеренному пакету, всё равно оказалась
неточной.** Запись 11 (`11-two-pass-stale.md`) была составлена после того, как
двухпроходное решение было реализовано и измерено, и всё же разошлась с кодом в
шести местах — включая «нужно новое свойство декларации канала», которого не
понадобилось.

**Ревью плана и ревью исполнения ловят разное, и оба обязательны.** Первый раунд
ревью плана дал 23 находки (семь HIGH) и сменил конструкцию; второй — 15 (три
HIGH) и сменил счёт потребителей и способ доказательства, причём две HIGH били
уже не в замысел, а в **DoD**: измерение было предписано в точке, где
выполнялось тождественно, а оракул наблюдал не тот канал, который порождает
запрет. Ревью **исполнения** после этого опровергло ещё три утверждения плана —
про замену `exclude_namespaces`, про групповую форму как третий разрыв и про
исполнимость предиката мёртвости.

- **Что из этого следует практически:** измерение до планирования обязательно
  даже тогда, когда запись выглядит измеренной; второй раунд ревью плана
  обязателен, если первый сменил конструкцию; проверять надо не только замысел,
  но и то, чем он будет доказан; и ревью исполнения не заменяется зелёным
  барьером.

## Х5 (2026-09-04) — хвосты Х3 и Х4, разобранные по измерению

Записи заведены исполнением пакетов 1-5 и пакетом 6. Каждая называет, чем именно
измерена; выведенное из кода без исполнения помечено гипотезой.

### Обязанность снять объявления гейта переходит первому шагу следующего захода

Заход оставляет в дереве непустыми три артефакта деклараций, и снять их он не
может: они написаны против эталона `ab614111`, и против того же эталона дерево
без них красно по построению — правка, которую они объясняют, никуда не делась.

Снять их обязан **первый шаг следующего захода**, чей эталон уже содержит эти
правки. Поимённо:

| файл                                    | что в нём                           |
| --------------------------------------- | ----------------------------------- |
| `finding-gate/declared-delta.tsv`       | 9 строк плюс заголовок              |
| `finding-gate/declared-delta/`          | 9 файлов диффов кейса `annotations` |
| `finding-gate/declared-field-moves.tsv` | 1 строка плюс заголовок             |

`finding-gate/maps/` заход не трогал: все четыре карты содержат только заголовок,
снимать в них нечего.

- **Чем обнаружится невыполнение:** на первом же прогоне `composer gate`
  следующего захода — `delta-stale` для каждой строки `declared-delta.tsv`,
  чей дифф больше не совершается, и `field-move-stale` для строки
  `declared-field-moves.tsv`. Громко, но не здесь.
- **Почему это правило, а не оплошность:** так уже работала история —
  `e993cfc4` опустошил карты словами «this step's reference already speaks
  kebab», то есть снимал их следующий шаг.

### `Fs::write` писал сквозь жёсткую ссылку, и стенд правил рабочее дерево

Найдено и **починено пакетом 1** (`593b6db2`). Стенд контролей клонирует рабочее
дерево жёсткими ссылками, поэтому объявление в скретч-дереве и объявление
разработчика — один инод, а `file_put_contents` усекает инод по месту. Измерено:
один прогон контролей оставил в этом репозитории `declared-delta.tsv` с
тринадцатью строками, выведенными из мутированного клона.

- **Почему запись, если починено:** у дефекта «деривация писала вопреки своему
  сообщению» (ниже) был второй канал последствий, и он опаснее первого. Первый
  портил вердикт прогона; этот молча правил файл в дереве разработчика,
  находящийся под контролем версий. Класс «инструмент доказательства пишет туда,
  куда не смотрит» стоит того, чтобы быть названным один раз.
- **Как держится:** `Fs::write` пишет рядом и переименовывает поверх; self-test
  гейта держит это на настоящей жёсткой ссылке.

### `--derive-declared-delta` печатал «nothing was written», уже переписав файлы

Найдено попутно измерением Х5 и **починено пакетом 1** (`8efb778c`). Запись
лежала в `Gate`, отказ — в точке входа, и отказ приходил вторым. Измерено:
полная деривация по дереву с одной выброшенной находкой вышла с кодом 5,
напечатала эту фразу и оставила тринадцать выведенных строк там, где стояло
посаженное объявление. Обычный прогон после этого судится против объявления,
измеренного с поломки.

- **Почему запись:** класс «сообщение расходится с действием» тем и опасен, что
  отчёт о нём и есть то, чему нельзя верить. Контроль
  `derive-refuses-broken-run` — первый, чей предмет вообще не попадает в отчёт:
  он сверяет дайджесты файлов до и после.

### Подсказка о каналах правила по-прежнему называет забаненный канал

Открыта. Пакет 1 отфильтровал забаненный канал из обоих прыжков поиска по
близости, но ветка «X names a rule, not a channel. Its channels are: …» через
`addressable()` не проходит. Измерено фикстурой:

```
@qmx-ignore annotation.directive
→ annotation.unresolved-directive: … "annotation.directive" names a rule, not a
  channel. Its channels are: annotation.invalid-threshold,
  annotation.unresolved-directive, annotation.unsupported-threshold,
  annotation.unused-directive.
```

- **Почему не в пакете 1:** формально это другая ветка того же класса, и она
  отвечает на другой вопрос — «что производит это правило», а не «как пишется то,
  что ты имел в виду». Ответ верен как перечисление и вреден как совет.
- **Цена:** та же, ради устранения которой пакет 1 и делался: автор пишет
  предложенное и получает отказ.
- **Что закроет:** решение о том, что это за список. Если совет — фильтровать
  через `addressable()`; если перечисление каналов правила — помечать в нём
  неадресуемый канал, а не убирать его. Меняет наблюдаемое `message`, в корпусе
  не упражняется, поэтому потребует своей строки объявления.

### Ключ кейса харнесса пробников — имя без `classname`

Открыта. `Suite::fromJUnit()` ключует кейс одним атрибутом `name`. Два тестовых
класса с одноимённым методом (или с одинаковой подписью датасета) сливаются в
один ключ, и покраснение одного читается как покраснение обоих.

- **Цена:** объявление пробника может считаться выполненным кейсом, которого
  пробник не касался. Пакет 2 снял подстрочное сопоставление, но не эту
  коллизию: равенство над неоднозначным ключом остаётся равенством над
  неоднозначным ключом.
- **Что закроет:** ключ `classname::name`, плюс переписывание 191 объявления
  скриптом по JUnit-логу — ровно та же операция, которой пакет 2 переписал 45.

### Условие покрытия стенда читает покрасневшее, а не объявленное

Открыта, и это вторая независимая дыра того же семейства, что закрыл пакет 2.
`Report::unguarded()` складывает `$outcome->red` — фактически покрасневшее, — а
`Probe::alsoReddens()` объявляет каскад, который тоже попадает в `red`. Поэтому
кейс, который не защищает **ни одно** утверждение и краснеет только чьим-то
каскадом, считается защищённым.

- **Измерено (счёт по объявлениям, без прогона):** у 113 непозитивных
  небланкетных пробников 146 объявленных кейсов и 71 кейс каскада, из которых
  **23 не объявлены никем**. Ровно они сегодня выглядят защищёнными, не будучи.
- **Дешёвая форма лечения:** считать покрытие от объявлений (`$probe->reddens`),
  а не от покрасневшего. Тогда каскад остаётся тем, чем он объявлен, — терпимым
  хвостом, а не свидетельством.

### Стенд пробников недетерминирован под нагрузкой — гипотеза

Измерено оркестратором. Полный прогон `composer directives:controls` под чужой
CPU-нагрузкой дал три пробника `REDDENED MORE THAN DECLARED`, каждый ровно на
один кейс: `verdict-ignores-the-measurement`, `boundary-always-observable`,
`producer-granularity-instead-of-level`. Те же пробники, снятые поштучно, зелены;
повторный полный прогон на спокойной машине — 116 из 116, код возврата 0.

- **Что это говорит о пакете 2:** равенство сделало флаки-природу **видимой**;
  подмножество её прятало, потому что лишнее покраснение оно и так не проверяло.
  Это довод за переход, а не против.
- **Механизм не диагностирован** — отсюда «гипотеза». Правдоподобные кандидаты:
  тест, чувствительный ко времени или к порядку, и клон, разделяющий кэш с
  другим прогоном. Ни один не проверен.
- **Цена:** красный стенд на загруженной машине не отличим от настоящей находки
  без поштучного пересъёма.

### Ключ исключения, называющий канал без уровня `namespace`, принимается и молчит

Открыта. Прежний хвост, ставший достижимым: до пакета 4
`rules.annotation.directive.exclude_namespace_channels[annotation.unused-directive]`
отвергался **по неверной причине** — камелкейсом ключа. Теперь ключ принимается,
а находка не гасится, потому что канал не отчитывается на уровне `namespace`.

- **Это не новая молчащая поверхность, а прежняя, у которой сменилось
  сообщение.** Валидатор проверяет производимость имени, а не применимость, и
  так решено сознательно (ADR 0025). Второй раунд ревью плана Х5 отверг предикат
  применимости отдельно: он отверг бы ровно те ключи, ради которых пакет 4
  писался, — `code-smell.boolean-argument` объявляет только уровень `callable`.
- **Что закроет:** смена контракта валидатора с производимости на применимость —
  предмет своего ADR, а не строчка в этом.

### Отдельного вердикта «effective, но ничего не публикует» нет

Открыта. Пакет 6 сузил обещание докблока `DirectiveEffect::Effective` до того,
что аудит действительно обещает: вердикт про то, что произвели правила, а не про
то, что покажет отчёт. Обещание стало верным; расхождение осталось.

- **Цена:** `effective` читается пользователем как «отчёт изменится», а под
  поканальным `exclude_paths` отчёт не меняется ни на байт.
- **Что закроет:** аудиту передаётся опубликованное множество рядом с
  произведённым, и вердикт различает «погасило находку» и «погасило находку,
  которую отчёт всё равно не показал бы». Вывод команды `directives` **не входит**
  в сравниваемые поверхности гейта, поэтому гейт этот шаг не проведёт и не
  покраснеет от него.

### Предупреждение `WorkerBootstrap` пишется в `STDERR` дочернего процесса — гипотеза

Открыта, выведена из кода, **не исполнена**.
`WorkerBootstrap::canInstantiate()` печатает предупреждение о пропущенном
коллекторе через `fwrite(\STDERR, …)`. В параллельном режиме этот код исполняется
в дочернем процессе, чей поток забирает amphp, поэтому предупреждение, вероятно,
не доходит ни до кого.

- **Почему отдельно от записи про владельца потока ошибок:** тот владелец —
  внутри одного процесса, и шов в другом процессе он не покрывает по построению.
- **Что закроет:** сперва измерение — прогон с коллектором, не реализующим
  `ParallelSafeCollectorInterface`, при `--workers>0` и наблюдение обоих потоков.
  Пока это не сделано, «предупреждение теряется» остаётся гипотезой, а не фактом.

### Порог `code-smell.constructor-overinjection` у `ResultPresenter` поднят с 9 до 10

Названо, а не спрятано: это отступление от доктрины «рефакторинг вместо подкрутки
порога», и его обоснование стоит целиком в докблоке класса.

Обоснование: девятый сотрудник — `ErrorStream` — не новый. Класс всегда имел его
для своих шести сообщений в поток ошибок и строил приватно, и это и давало потоку
ошибок двух владельцев. Сделать его параметром — то, что позволяет единственный
общий экземпляр; спрятать обратно — восстановить дефект.

- **Почему это всё-таки отступление:** доктрина требует рефакторинга, и здесь его
  не было; изменился счёт сотрудников, а не разбиение предмета.
- **Что закроет:** разбиение `ResultPresenter` на предмет отчёта и предмет
  подавленной композиции. Это работа своего масштаба, и порог 10 — расписка в
  том, что она отложена.

### Протухшее исключение конфигурации не получает вердикта, а протухшая директива получает

Открыта, найдена выбором пакета 6. Инлайн-директива, переставшая что-либо гасить,
становится `annotation.unused-directive` и попадает в отчёт; строка
`exclude_paths`, переставшая что-либо гасить, не порождает ничего.

- **Где это стоило решения:** при сужении исключения `health.cohesion` до семи
  путей инлайн-форма была измерена работающей (`@qmx-ignore health.cohesion` на
  классе гасит находку, механизм `suppression`), и именно этим преимуществом она
  и была сильна. Выбрана всё-таки конфигурация — по правилу AGENTS.md о
  категории, — то есть семь строк, чьё протухание никто не заметит.
- **Что закроет:** вердикт протухания для исключений конфигурации, аналогичный
  директивному: «этот `exclude_paths` в этом прогоне не гасил ничего». Универсум
  для него уже собирается — `--format=suppressed` знает механизм и суппрессора
  каждой подавленной находки; недостаёт обратного вопроса «какие настроенные
  исключения не подавили ничего».
