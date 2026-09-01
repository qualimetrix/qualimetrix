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
- **What closes it, checkably:** the first step whose reference already knows
  `suppressed` adds it to `Surfaces::FORMATS`. That is a property of the
  reference commit, not a promise about the next step: if the next step's
  reference predates the format, the entry stays.

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
- **Что закроет:** либо `--no-coverage` по умолчанию в конфигурации, либо снятие
  `failOnWarning` с рантайм-предупреждений, которые к тестам не относятся. Выбор
  между ними — решение владельца: первое прячет покрытие от того, кто его
  попросит явно, второе ослабляет страж на классе предупреждений целиком.

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
- **Что закроет:** подавление на находке, которую исключает ledger, добавленное
  в кейс `rule-exclusion-ledger`. Относительно любого эталона, предшествующего
  П1, это объявленная дельта — то есть отдельный шаг со своей записью в
  `finding-gate/maps/`, а не хвост пакета.

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

### Отчёт команды печатает пути относительно корня проекта, и только их

`DirectiveSite::$file` — `RelativePath`, и это верно для отчёта об одном дереве.
Для прогона, охват которого пользователь задал несколькими каталогами вне
корня, строка отчёта не говорит, из какого именно они пришли.

- **Цена:** пути неоднозначны ровно в том случае, когда охват неочевиден, то
  есть когда шапка нужнее всего.
- **Что закроет:** печать корня прогона в шапке рядом с охватом — работа на
  одну строку, но она меняет обе проекции и потому не хвост чужого пакета.

### Прогресс-бар печатает управляющие байты перед JSON на терминале

`RuntimeConfigurator::configureProgressReporter()` включает бар по
`isDecorated()`, а `--format=json` на это не влияет. На TTY вывод начинается с
`^D^H^H` и только потом идёт `{`. Проверено исполнением через псевдотерминал
**и на `check`, и на `directives`** — это поведение продукта, а не пакета: у
`check` есть аварийный `--no-progress`, у `directives` его нет.

- **Цена:** обещание «машиночитаемый формат читается парсером» неверно ровно в
  той среде, где человек его пробует руками.
- **Что закроет:** одно правило в `configureProgressReporter()` — машинный
  формат не получает бара, — но оно меняет поведение всех команд сразу и
  требует своего прогона гейта.

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
- **Что закроет:** двухпроходный `stale()` (второй проход судит по `produced`
  плюс выход первого) — это меняет публикуемый канал, требует объявленной
  дельты гейта и рассуждения о неподвижной точке, поэтому это предмет П1, а не
  хвост команды.
