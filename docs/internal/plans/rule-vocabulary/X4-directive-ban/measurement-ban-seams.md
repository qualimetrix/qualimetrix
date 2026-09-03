> Измерено 2026-09-03 до планирования Х4, исполнением на физических копиях
> дерева `6f48b2a0`. Стенды жили вне репозитория и не сохранены: воспроизводятся
> командами, которые названы ниже по тексту. Абсолютные пути машины вычищены.

# Х4 — швы запрета «канал `annotation.unused-directive` не подавляется инлайн»

Измеритель, read-only. Рабочее дерево не тронуто. Все пробные правки — в
физических копиях `/tmp/x4-seams-clone` (эталон), `/tmp/x4-seams-A` (мутация A),
`/tmp/x4-seams-B` (мутация B). `vendor` скопирован физически, симлинков нет
(`find /tmp/x4-seams-A/vendor -maxdepth 3 -type l` — пусто), поэтому
ложно-зелёного из-за резолва PSR-4 в исходное дерево быть не может.

Все команды ниже воспроизводятся из корня рабочего дерева
`<worktree>`,
если не сказано иначе.

---

## 1. `ChannelDeclaration` и новое свойство

### 1.1 Кто конструирует декларации

Конструктор `ChannelDeclaration::__construct` **приватный**. Публичных выражений
три:

| выражение                                                         | кто вызывает                                                                                       |
| ----------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `ChannelDeclaration::magnitude(WorseDirection, SymbolLevel, ...)` | каждый производитель в своём `channelDeclarations()`                                               |
| `ChannelDeclaration::occurrence(SymbolLevel, ...)`                | то же                                                                                              |
| `ChannelDeclaration::asConfigurationError()`                      | **ровно один** производственный сайт: `ChannelDeclarationCompilerPass::collectValidatorChannels()` |

Воспроизвести перечисление производителей деклараций:

```
grep -rn "public static function channelDeclarations" --include=*.php src/ | sort
```

Регистр собирается в
`src/Infrastructure/DependencyInjection/CompilerPass/ChannelDeclarationCompilerPass.php`:

- `readProducerFacts()` — первый проход по всем `qmx.rule`-сервисам, читает пять
  фактов рефлексией по классу (`RuleNameReader`, `ThresholdOverrideSupportReader`,
  `RuleDocsPageReader`, `RuleRemediationMinutesReader`, `RuleShapeReader`);
- `collectChannels()` — упорядоченный обход определений контейнера, вызывает
  `collectRuleChannels()` для правил и `collectValidatorChannels()` для
  валидаторов;
- `collectValidatorChannels()` — единственное место, где к декларации
  применяется `asConfigurationError()`; классификация выводится из **типа
  производителя** (`ConfigurationValidatorInterface`), а не из того, что
  производитель о себе объявил;
- результат кладётся аргументом `$staticDeclarations` в `ChannelUniverse`.

### 1.2 Кто производит `annotation.unused-directive`

`src/Analysis/Policy/Inline/Directive/UnusedDirectiveRule.php` — **обычное
правило** (`AbstractRule`, `RuleInterface`), не валидатор:

```php
public static function channelDeclarations(): array
{
    $name = InlineDirectivePolicy::UNUSED_DIRECTIVE_NAME;
    return [$name => ChannelDeclaration::occurrence(SymbolLevel::File)];
}
```

Само правило ничего не эмитит: `analyze()` только вызывает
`$this->policy->enableUsageReporting($severity)`. Находки собираются позже —
`InlineDirectivePolicy::auditDirectiveUsage()` → `DirectiveUsage::stale()` →
`StaleDirectiveFinding::of(...)`.

**Это ключевой факт для развилки.** Три канала-соседа
(`annotation.unresolved-directive`, `annotation.unsupported-threshold`,
`annotation.invalid-threshold`) объявляет `InlineDirectiveValidator`
(`ConfigurationValidatorInterface`), а `annotation.unused-directive` — правило.
То есть **по типу производителя эти два случая уже разделены**, и разделены
именно так, как нужно: тип «валидатор» уже занят другой семантикой.

Воспроизвести:

```
grep -n "class UnusedDirectiveRule\|channelDeclarations\|enableUsageReporting" \
  src/Analysis/Policy/Inline/Directive/UnusedDirectiveRule.php
grep -n "producerRuleName\|channelDeclarations\|implements" \
  src/Analysis/Policy/Inline/Directive/InlineDirectiveValidator.php
```

### 1.3 Два механизма присвоения нового свойства — и цена каждого

**Вариант A — авторский (производитель объявляет).** Новый витер
`ChannelDeclaration::notInlineSuppressible()` (или второй факультативный
параметр в `occurrence()`/`magnitude()`), вызываемый из
`UnusedDirectiveRule::channelDeclarations()`.

Что придётся тронуть в стражах:

- `tests/Analysis/Finding/Integration/ConfigurationErrorClassificationTopologyTest.php` —
  **не ломается**, но его три метода (`exactlyOneProductionSiteTurnsADeclarationIntoAConfigurationError`,
  `noOtherProductionFileEvenNamesTheWither`, `noProductionSiteCanHandTheFlagToTheConstructorInstead`)
  специально написаны про `asConfigurationError`, а не про «любой витер». Новый
  витер окажется вне их поля зрения; аналогичного стража у него не будет, если
  его не завести. Это **развилка владельца**: нужен ли новому свойству свой
  топологический страж, и если да — какой (для авторского витера топология
  «ровно один сайт» неверна по построению, там правильный страж — «ровно этот
  один канал», т.е. проверка по регистру, а не по AST);
- `tests/Analysis/Finding/Unit/ChannelDeclarationTest.php` — новый кейс на витер
  (существующий `itYieldsAConfigurationErrorOnlyThroughTheWither` не трогается);
- `tests/Analysis/Finding/Fixtures/Channels/declared.txt` — формат строки
  документирован в шапке файла как
  `<channel> <direction> <levels> [<acceptability>]`; новое свойство —
  это либо новая колонка, либо новое значение в четвёртой. Плюс
  `ChannelDeclarationFixtureDriftTest`, который парсит формат в обе стороны.

**Вариант B — сборкой регистра по типу производителя.** Требует нового типа,
который несёт `UnusedDirectiveRule` (маркер-интерфейс), и второго штампующего
сайта в `ChannelDeclarationCompilerPass`.

Цена:

- `Infrastructure\DependencyInjection\CompilerPass` получает импорт нового
  контракта из `Analysis\Policy\Inline` (сегодня пасс намеренно не импортирует
  внутренности capability, а `ConfigurationValidatorInterface` и
  `RuleInterface` он вообще именует **литералами**, см. константы
  `RULE_INTERFACE`, `VALIDATOR_INTERFACE`, `RULE_EXECUTION`);
- маркер-интерфейс на правиле — это «производитель заявляет о себе», то есть
  ровно та авторская форма, от которой `asConfigurationError()` уходил;
  преимущество варианта B (нельзя соврать) здесь не достигается;
- топологический страж «ровно один сайт штампует» придётся обобщать на два
  разных штампа с разными семантиками, иначе его текст перестанет быть правдой.

**Мой вывод по коду (не решение — оно за владельцем): вариант A дешевле и
честнее.** «Канал не подавляем инлайн» — это утверждение про то, как канал
производится (после исполнения правил, из самого учёта директив), и его знает
владелец правила; тип производителя его не выражает, потому что
`UnusedDirectiveRule` — обычное правило, и делать его валидатором нельзя (см. §2:
это сменит baseline и код возврата, что и записано в 11-two-pass-stale.md
верно).

---

## 2. Все потребители `isConfigurationError()`

Перечисление получено инструментом, а не grep:

```
mcp__serena__find_referencing_symbols
  name_path      = ChannelDeclaration/isConfigurationError
  relative_path  = src/Analysis/Finding/Contract/ChannelDeclaration.php
```

Продуктовые потребители — семь, ровно в четырёх семантиках:

| #   | сайт                                                                                                                            | семантика                                                       | остаётся на старом флаге?                                                 |
| --- | ------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------- |
| 1   | `Infrastructure/Console/ExitCodeResolver::hasConfigurationError()`                                                              | код возврата: находка валит прогон помимо `fail_on`             | **да**                                                                    |
| 2   | `Reporting/FindingProjection/FindingProjector::isConfigurationError()` (через `configurationErrors()` / `filterableFindings()`) | разделение проекций: находку не видит ни одна стадия фильтрации | **да**                                                                    |
| 3   | `Analysis/Policy/Baseline/BaselineGenerator::captureGroup()`                                                                    | `UncapturedReason::ConfigurationErrorChannel` при генерации     | **да**                                                                    |
| 4   | `Analysis/Policy/Baseline/BaselineUpdater::reconcile()`                                                                         | `update` не расширяет принятие на такой канал                   | **да**                                                                    |
| 5   | `Analysis/Policy/Baseline/BaselineEntryParser::parseEntry()`                                                                    | `BaselineEntryRejection` при чтении записи                      | **да**                                                                    |
| 6   | `Analysis/Policy/Baseline/BaselineCleaner::candidates()`                                                                        | кандидат на вычистку из baseline                                | **да**                                                                    |
| 7   | `Analysis/Policy/Baseline/Filter/BaselineCeilingStage` (`configurationErrorEntries()`, `judge()`)                               | потолок не принимает                                            | **да**                                                                    |
| 8   | `Analysis/Policy/Inline/Directive/Audit/DirectiveUsage::suppressible()`                                                         | **универсум подавления в аудите**                               | **нет — это единственный сайт, который обязан перейти на новое свойство** |

Обоснование «остаётся» для 1–7 однотипно и читается из кода: каждый из них
отвечает на вопрос «эта находка — про конфигурацию, а не про долг», и ответ
`annotation.unused-directive` — **нет** (докблок `UnusedDirectiveRule`: «ordinary
debt cleanup a project may schedule, which is why it is a rule's finding and not
a validator's»; `ChannelDeclarationFixtureDriftTest` содержит явный
`assertNotContains('annotation.unused-directive', $configurationErrors)` с
причиной). Перевод любого из 1–7 на новое свойство сделал бы канал
нератчетируемым и/или безусловно валящим прогон — то, что запись
11-two-pass-stale.md называет верно.

Обоснование «переходит» для 8: `suppressible()` отвечает на другой вопрос —
«могла ли директива вообще что-то из этого погасить». Сегодня он пользуется
`isConfigurationError()` как *прокси* для «инлайн этого не гасит», и именно этот
прокси перестаёт быть точным, как только запрет распространяется на канал,
который не является конфигурационной ошибкой. Докблок метода это уже почти
признаёт: «This is not the publication ledger… this is a property of the
producing type, true for every run and every configuration».

Тестовые потребители флага (правятся только если меняется формат фикстуры или
добавляется страж нового свойства): `ChannelDeclarationFixtureDriftTest`,
`ConfigurationErrorClassificationTopologyTest`, `ConfigurationValidatorSilencingPathsTest`,
`ChannelDeclarationTest`, `UnassignedClassDiagnosticsTest`.

---

## 3. Универсум подавления и разворачивание селектора

### 3.1 Где живёт `expand()` и кто его потребляет

Контракт: `ChannelIdentityInterface::expand(NameSelector): list<FindingChannel>`
(`src/Analysis/Finding/Contract/ChannelIdentityInterface.php`).
Единственная реализация — `Infrastructure/Rule/ChannelUniverse::expand()`:
фильтр `channels()` по `$selector->matches($channel->code)`.

Потребители (инструментом):

```
mcp__serena__find_referencing_symbols
  name_path      = ChannelIdentityInterface/expand
  relative_path  = src/Analysis/Finding/Contract/ChannelIdentityInterface.php
```

Продуктовых четыре:

1. `Analysis/Policy/Inline/Directive/DirectiveAddressability::problemWithSuppression()`
   — `expand(...) !== []` ⇒ директива адресуема; иначе
   `annotation.unresolved-directive`. **Это и есть путь отказа, которым план
   собирается воспользоваться.**
2. `Analysis/Finding/Contract/Rule/ChannelLevelAddressing::problemWithAmong()` и
   `refusePair()` — общая грамматика пары `channel:level`. Её спрашивают **обе**
   семьи: инлайн-директивы и `exclude_namespace_channels`.
3. `Analysis/Policy/Inline/Directive/Audit/DirectiveUsage::addressedCodes()` —
   кормит `unmeasurableReason()`.
4. `Infrastructure/Console/ChannelExclusionKeyValidator::assertAddressesAProducedChannel()`
   — валидация ключа **конфигурации** `exclude_namespace_channels`; отказ здесь
   бросает `InvalidArgumentException` и кончает прогон.

**Кто НЕ потребляет `expand()`** (проверено тем же перечислением): `qmx rules`,
`baseline:explain`, baseline (принятие/генерация/потолок), отчёты и форматтеры,
`DirectiveNameHints`. Они ходят через `channels()`, `hasChannel()`,
`producerOf()`, `levelsOf()`.

### 3.2 Что будет, если исключить канал из разворачивания

Заметят трое (кроме желаемого №1):

- **`ChannelExclusionKeyValidator`** — ключ `exclude_namespace_channels`,
  написанный как `annotation.unused-directive` под правилом
  `annotation.directive`, начнёт **отвергаться на старте прогона**
  `InvalidArgumentException`. Это чужая семья (конфигурация публикации), запрет
  к ней отношения не имеет. Сегодня такой ключ принимается (хотя и не фильтрует
  ничего: канал репортит только на `file`, а опция применяется на `namespace`).
- **`ChannelLevelAddressing`** — изменится текст отказа для пары
  `annotation.unused-directive:file` **в обеих семьях сразу**, потому что это
  один объект, спрашиваемый обоими семами.
- **`DirectiveUsage::addressedCodes()`** — вернёт пустой список ⇒
  `unmeasurableReason()` уйдёт в `sawDisabledProducer === false` ⇒
  `DirectiveUnmeasurableReason::AlreadyRefused`, вердикт `Unmeasured`. Это ровно
  то, что план и хочет («аудит даёт `unmeasured / already-refused`»), и оно
  получается **бесплатно**, если отказ стоит в адресуемости.

Плюс один **молчаливый** побочный эффект, которого в записи нет:

- **групповая форма `annotation.*` не отвергается**, а *молча сужается*.
  `expand('annotation.*')` вернёт три канала вместо четырёх, `!== []` ⇒
  адресуема ⇒ ни `annotation.unresolved-directive`, ни staleness. Автор пишет
  `@qmx-ignore-file annotation.*`, получает подавление трёх каналов и молча не
  получает четвёртого. Это та же болезнь, ради которой запрет и вводится
  («автор пишет строку, которая молча не делает ничего»), только в другой
  форме.
- **`DirectiveNameHints` продолжит советовать банённый канал.**
  `forChannelSelector()` и `channelsOf()` читают `channels()`, не `expand()`,
  поэтому сообщение «`annotation.directive` names a rule, not a channel. Its
  channels are: …» по-прежнему перечислит `annotation.unused-directive` как то,
  что автору предлагается написать. Это закреплено тестом
  `UnusedDirectiveRuleTest::itRejectsARuleNameWhereASuppressionMustNameAChannel`,
  который прямо ассертит присутствие `UNUSED_DIRECTIVE_NAME` в тексте.

### 3.3 Где тогда должно стоять исключение

Три возможных места, и они дают **разные наблюдаемые исходы**:

| место                                                                                     | точная форма `@qmx-ignore annotation.unused-directive`                                   | групповая `annotation.*`                                           | форма без фильтра (`@qmx-ignore-file` без канала)                   | `exclude_namespace_channels`             |
| ----------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------- | ---------------------------------------- |
| **(а) адресуемость** (`DirectiveAddressability::problemWithSuppression`)                  | громкий отказ `annotation.unresolved-directive`, прогон красный                          | по умолчанию **проходит**; нужен отдельный явный отказ по покрытию | `appliesToEveryChannel()` ⇒ не судится (`Unmeasured`) — не меняется | не тронут                                |
| **(б) `expand()`**                                                                        | тот же громкий отказ (через (а)), плюс аудит автоматически даёт `already-refused`        | **молча сужается**                                                 | не меняется                                                         | **ломается**: ключ отвергается на старте |
| **(в) фильтр подавления** (`SuppressionFilter::applies` / `DirectiveUsage::suppressible`) | директива принимается, ничего не гасит, и **сама становится stale** ⇒ находка на находке | так же                                                             | не меняется                                                         | не тронут                                |

Наблюдаемая разница коротко: **(а)** говорит автору «так писать нельзя»;
**(в)** говорит «написал — и получил ещё одну жалобу»; **(б)** — то же, что (а),
но с уроном по чужой семье и с молчаливым исходом на группе.

**Развилка владельца:** покрывает ли запрет групповую форму `annotation.*`, и
если да — отказом (тогда `@qmx-ignore-file annotation.*` перестаёт работать
целиком, а это законный способ заглушить три диагностики… которые и так
неподавляемы как config-error, то есть форма уже сегодня бесполезна) или
сужением. По коду сегодня `annotation.*` подавляет **ровно один** канал из
четырёх — остальные три отсеивает `suppressible()`. То есть отказ на группе
ничего рабочего не отнимает; это аргумент в пользу отказа, но решение не моё.

---

## 4. Расхождение публикации и аудита

Точки, где решается «эта находка подавлена», перечислены по путям исполнения:

| #   | точка                                                                                  | где в коде                                                        | что решает                                                                   | видит ли поздний канал                                                                                          |
| --- | -------------------------------------------------------------------------------------- | ----------------------------------------------------------------- | ---------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| 1   | `SuppressionFilter::shouldInclude()` ← `apply()`                                       | `src/Analysis/Policy/Inline/Suppression/SuppressionFilter.php`    | публикация в `check`                                                         | да — `AnalysisPipeline::reportedFindings()` приклеивает `auditInlineDirectives()` к `published` **до** проекции |
| 2   | `FindingProjector::configurationErrors()` / `filterableFindings()`                     | `src/Reporting/FindingProjection/FindingProjector.php`            | изъятие config-error из конвейера **до** стадии подавления                   | нет (канал не config-error)                                                                                     |
| 3   | `SuppressionFilter::suppressesAny()` (static) ← `DirectiveUsage::anyOfTheGroupFired()` | там же + `Audit/DirectiveUsage.php`                               | аудит: «сработала ли директива»                                              | да — но только потому, что вызывающий передал широкий список                                                    |
| 4   | `DirectiveUsage::suppressible()`                                                       | `Audit/DirectiveUsage.php`                                        | универсум аудита: что вообще можно было погасить                             | фильтрует **только** config-error                                                                               |
| 5   | `AnalysisPipeline::auditDirectives()` — склейка `produced` + `auditInlineDirectives()` | `src/Analysis/Run/Pipeline/AnalysisPipeline.php:127`              | универсум команды `directives`                                               | да, ради этого склейка и написана                                                                               |
| 6   | `DirectiveUsage::withoutOwnComplaint()`                                                | `Audit/DirectiveUsage.php`                                        | сужение по строке: директива не кредитуется собственной жалобой              | да                                                                                                              |
| 7   | `DirectiveSuppressorResolver::resolve()`                                               | `src/Reporting/FindingProjection/DirectiveSuppressorResolver.php` | `--show-suppressed`: **какая именно** директива погасила уже изъятую находку | наследует №1                                                                                                    |
| 8   | `SuppressionCompositionBuilder::stageSuppressedFindings()`                             | `src/Reporting/FindingProjection/`                                | сборка `SuppressionComposition` из `removedBy(stage)`                        | наследует №1                                                                                                    |

**Поправка к постановке задания.** `InertSuppressor` **не** является точкой
решения «находка подавлена». Его собственный докблок это заявляет: он покрывает
четыре паттерн-механизма (`PathExclusion`, `NamespaceExclusion` и их
per-rule половины) и явно исключает директивы — «A `@qmx-ignore` directive that
silenced nothing is a different question (`annotation.unused-directive`)». В
периметр запрета он не входит.

**Поправка вторая.** `DirectiveSuppressorResolver` — **третья независимая
реплика** правил размещения директивы (после `SuppressionFilter::applies()` и
`SuppressionFilter::suppressesAny()`), написанная по публичным полям
`Suppression`. Она отвечает не «подавлена ли», а «кем подавлена», и получает
только уже изъятые находки, поэтому запрет её не касается — но если запрет
поставить в `SuppressionFilter::applies()`, эта реплика останется согласованной
автоматически, а если поставить только в `DirectiveUsage::suppressible()` —
тоже (она не вызывается для непогашенных находок).

### 4.1 Где должен встать запрет, чтобы `check` и `directives` дали один ответ

Единственная точка, общая для обоих путей, — **№1 `SuppressionFilter::applies()`**,
потому что аудит (№3) ходит в тот же `applies()`. Но запрет там даёт исход (в) из
§3.3: директива принимается и становится stale.

Чтобы ответы совпали и при этом форма отвергалась, запрет должен стоять
**в адресуемости (№ вне таблицы: `DirectiveAddressability`) — она общая для обоих
путей по построению**: `InlineDirectiveValidator` исполняется как часть
`annotation.directive` в обоих прогонах, и `annotation.unresolved-directive` —
config-error, то есть проходит через `FindingProjector` нефильтруемой и валит
`check`; а в `directives` та же директива получает `Unmeasured /
already-refused` через `unmeasurableReason()`.

**Формы входа, у которых ответы разойдутся при односторонней постановке:**

- **запрет только в `DirectiveUsage::suppressible()`** — `check` продолжает
  гасить находку (публикация), `directives` считает директиву `inert`. Это
  сегодняшний дефект, только с обратным знаком.
- **запрет только в `SuppressionFilter::applies()`** — `check` печатает и
  находку, и новую staleness-жалобу на саму директиву; `directives` даёт
  `inert`. Ответы формально согласованы, но оба «наказывают», а не отказывают.
- **запрет только в адресуемости, без изменения `suppressible()`** — расхождения
  нет для точной формы, но остаётся групповая `annotation.*` (см. §3.2) и
  остаётся символьная форма `@qmx-ignore annotation.unused-directive` в докблоке:
  она уже сегодня инертна по построению (субъект находки — файловый агрегат,
  символьное подавление сопоставляется по точному субъекту объявления —
  `SuppressionFilter::applies()`, ветка `SuppressionType::Symbol`), но
  адресуемость её примет и **не** отвергнет, если отказ ставить по expand'у,
  а не по форме. Это подтверждает запись 11-two-pass-stale.md.
- **`--show-suppressed`** (`annotationSuppressionDisabled`) — путь, где
  подавлённые findings возвращаются в отчёт. При запрете в адресуемости
  различий нет; при запрете в фильтре подавления `--show-suppressed` покажет
  находку как «подавленную» с суппрессором `(unresolved directive)`, потому что
  `DirectiveSuppressorResolver` не найдёт директиву, которую фильтр отказался
  применить.

---

## 5. Мертвеет ли склейка — ИЗМЕРЕНО

Утверждение записи: после запрета склейка в `AnalysisPipeline::auditDirectives()`
и `DirectiveUsage::withoutOwnComplaint()` обе мертвы. Проверено снятием каждой
по отдельности **на дереве БЕЗ запрета**.

### Эталон

```
cp -a <worktree>/ /tmp/x4-seams-clone/
cd /tmp/x4-seams-clone && composer test
```
→ `OK, but some tests were skipped! Tests: 7961, Assertions: 28575, Skipped: 1`, время 02:59.

```
cd /tmp/x4-seams-clone && php bin/qmx check src/ --memory-limit=512M
```
→ exit 2, `223 violations (2 errors, 221 warnings)`.

### Мутация A — снята склейка

```php
// src/Analysis/Run/Pipeline/AnalysisPipeline.php:127
- $produced = [ ...$prepared->ruleExecution->produced,
-               ...$this->ruleProducerPreparation->auditInlineDirectives(...) ];
+ $produced = $prepared->ruleExecution->produced;
```

`composer test` → exit 2, **ровно один** релевантный отказ:

```
DirectivesCommandTest::itJudgesASuppressionOfTheChannelProducedAfterRuleExecution
- 'annotation.unused-directive' => 'effective',
+ 'annotation.unused-directive' => 'inert',
```

`php bin/qmx check src/` → exit 2, вывод **побайтно равен** эталону
(`diff base.check.direct.log A.check.direct.log` — пусто).

### Мутация B — `withoutOwnComplaint()` сделан тождественным

```php
// src/Analysis/Policy/Inline/Directive/Audit/DirectiveUsage.php:160
- return array_values(array_filter($findings, ...));
+ return $findings;
```

`composer test` → exit 2, **два** отказа:

```
DirectivesCommandTest::itDoesNotLetADirectiveJustifyItselfWithItsOwnComplaint
- 'inert'  +  'effective'

ModularArchitectureGovernanceIntegrationTest::itChecksEveryGeneratedProjectionWithoutWriting
Generated artifact is stale:
  docs/internal/generated/modular-architecture/manifest-enforcement-summary.tsv
```

Второй — побочный: удаление обращения к `InlineDirectivePolicyInterface`
сделало импорт неиспользуемым, и точный manifest-чекер это заметил. **Это
самостоятельный факт для §6:** любая правка импортов в этом файле краснит
`check:artifacts`.

`php bin/qmx check src/` → exit 2, вывод **побайтно равен** эталону.

### Шум

В обоих мутантных прогонах дополнительно упали
`HookInstallCommandTest` / `HookStatusCommandTest` / `HookUninstallCommandTest`
с `TypeError: array_diff(): Argument #1 must be of type array, false given`.
Эталонный прогон их прошёл; мутантные шли **параллельно** на одной машине и
делят git-состояние временных каталогов. Считаю средовым шумом, не связанным с
мутациями; при последовательном перепрогоне это стоит подтвердить.

### Проба самих стражей

```
cd /tmp/x4-seams-clone && composer directives:controls -- \
  --only=universe-drops-the-late-channel,directive-justifies-itself
```
→ exit 0:

```
universe-drops-the-late-channel  as declared          1 of 151 cases red
directive-justifies-itself       as declared          1 of 151 cases red
```

### Цена утверждения

**Оба механизма охраняются — но каждый ровно одним тестом и ровно одним
пробником, и оба этих теста живут в одном файле** (`DirectivesCommandTest`).
Ни один из них не наблюдается в `check`: вывод `bin/qmx check src/` не меняется
ни от одной мутации, потому что в нашем дереве ни одна директива лазейкой не
пользуется (что и было измерено раньше: «в нашем дереве лазейкой не пользуется
ни одна директива — измерено, ноль»).

Отсюда точный периметр удаления: снятие склейки и `withoutOwnComplaint()`
потребует **удалить** `DirectivesCommandTest::itJudgesASuppressionOfTheChannelProducedAfterRuleExecution`
и `::itDoesNotLetADirectiveJustifyItselfWithItsOwnComplaint`, и **удалить**
пробники `universe-drops-the-late-channel` и `directive-justifies-itself` из
`scripts/directive-audit-controls/Probes.php` (иначе `directives:controls`
покраснеет: пробник не найдёт строку для подстановки). Пробник
`suppression-never-fires` называет `itJudgesASuppressionOfTheChannelProducedAfterRuleExecution`
вторым охраняемым кейсом — его список тоже придётся сократить.

Воспроизведение мутаций: `/tmp/x4-seams-work/mutate.py <root> splice|own`,
логи — `/tmp/x4-seams-work/*.log`.

---

## 6. Периметр правок, полный

### 6.1 Продуктовый код

| файл                                                                               | почему                                                                                                                                                                                    |
| ---------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Analysis/Finding/Contract/ChannelDeclaration.php`                             | новое поле + витер                                                                                                                                                                        |
| `src/Analysis/Policy/Inline/Directive/UnusedDirectiveRule.php`                     | объявление свойства для своего канала (вариант A)                                                                                                                                         |
| `src/Analysis/Policy/Inline/Directive/Audit/DirectiveUsage.php`                    | `suppressible()` фильтрует по новому свойству; `withoutOwnComplaint()` удаляется; правится большой докблок класса (в нём сейчас описана вся снимаемая семантика)                          |
| `src/Analysis/Policy/Inline/Directive/DirectiveAddressability.php`                 | место отказа (если решено ставить там)                                                                                                                                                    |
| `src/Analysis/Run/Pipeline/AnalysisPipeline.php`                                   | склейка в `auditDirectives()` уходит вместе с абзацем докблока; `reportedFindings()` **остаётся** — это путь `check`, он не про подавление                                                |
| `src/Analysis/Policy/Inline/Contract/Directive/InlineDirectivePolicyInterface.php` | докблок `directiveVerdicts()` содержит абзац «`$producedFindings` must carry everything the run produced, the channel a run assembles *after* rule execution included» — становится ложью |
| `src/Analysis/Policy/Inline/Directive/DirectiveNameHints.php`                      | если решено, что банённый канал не должен предлагаться в «did you mean»                                                                                                                   |
| `src/Analysis/Finding/Contract/ChannelIdentityInterface.php`                       | если запрет ставится в `expand()` — правится контракт метода                                                                                                                              |

### 6.2 Тесты

| файл                                                                                                                               | почему                                                                                                                                                                                                                                              |
| ---------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Infrastructure/Console/Functional/DirectivesCommandTest.php`                                                                | **два теста удаляются** (§5): их фикстуры — это ровно та лазейка, которую запрет закрывает                                                                                                                                                          |
| `tests/Analysis/Policy/Inline/Integration/UnusedDirectiveRuleTest.php`                                                             | `itAcceptsAnExactChannelAndAGroupThatCoversSomething` ассертит `runWithSuppression(UNUSED_DIRECTIVE_NAME) === []` — переворачивается; `itRejectsARuleNameWhereASuppressionMustNameAChannel` ассертит наличие имени канала в «did you mean»          |
| `tests/Analysis/Finding/Unit/ChannelDeclarationTest.php`                                                                           | кейс на новый витер                                                                                                                                                                                                                                 |
| `tests/Analysis/Finding/Integration/ChannelDeclarationFixtureDriftTest.php`                                                        | если фикстура получает колонку; сам метод `exactlyTheLayerPolicyAndDirectiveDiagnosticsDeclareAConfigurationError` **остаётся как есть** — его `assertNotContains('annotation.unused-directive', …)` продолжает быть верным и становится ещё нужнее |
| `tests/Analysis/Finding/Fixtures/Channels/declared.txt`                                                                            | формат `<channel> <direction> <levels> [<acceptability>]` + шапка                                                                                                                                                                                   |
| `tests/Analysis/Finding/Integration/ConfigurationErrorClassificationTopologyTest.php`                                              | если заводится страж нового витера                                                                                                                                                                                                                  |
| `tests/Analysis/Policy/Inline/Integration/DirectiveUsageTest.php`, `tests/Analysis/Run/Integration/DirectiveAuditUniverseTest.php` | кейсы про универсум аудита; нужно перечитать поштучно                                                                                                                                                                                               |
| `scripts/directive-audit-controls/Probes.php`                                                                                      | три пробника: два удаляются, у `suppression-never-fires` сокращается список охраняемых кейсов; вероятно, добавляется новый пробник на сам запрет                                                                                                    |
| `tests/Analysis/Finding/Integration/ConfigurationValidatorSilencingPathsTest.php`                                                  | не ломается (канал не config-error), но его перечисление «ровно восемь» стоит перечитать: у нового свойства такого стража нет                                                                                                                       |

### 6.3 Документация

| файл                                                      | почему                                                                                                                                                                                                                                                                                                                                                  |
| --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `website/docs/rules/annotation.md` + `.ru.md`             | строки 31/35 («can be baselined or **suppressed** like any other finding»), 130, 136                                                                                                                                                                                                                                                                    |
| `website/docs/usage/baseline.md` + `.ru.md`               | строка 184 — то же утверждение                                                                                                                                                                                                                                                                                                                          |
| `website/docs/reference/default-thresholds.md` + `.ru.md` | строка 113 — «Can be baselined or suppressed like any other channel»                                                                                                                                                                                                                                                                                    |
| `website/docs/rules/index.md` + `.ru.md`                  | строка 155                                                                                                                                                                                                                                                                                                                                              |
| `website/docs/usage/cli-options.md` + `.ru.md`            | строка 833 — абзац про универсум аудита («включая `annotation.unused-directive`, который прогон собирает уже после исполнения правил»)                                                                                                                                                                                                                  |
| `src/Analysis/Policy/Inline/README.md`                    | строки 47, 142; раздел про четыре канала                                                                                                                                                                                                                                                                                                                |
| `docs/adr/`                                               | новый ADR: почему у декларации появилось второе неавторское/авторское свойство и почему запрет, а не неподвижная точка. `0039-directive-audit-command-and-contract.md` (строки 14, 112, 155) и `0037-suppressed-format-and-produced-findings.md` (строка 75) содержат утверждения, которые запрет отменяет — их правит новый ADR, а не редактура старых |
| `CHANGELOG.md`                                            | `Breaking`: форма, которая раньше принималась, начинает отвергаться                                                                                                                                                                                                                                                                                     |

Проверка сборки: `composer docs:check`.

### 6.4 Генерируемые артефакты

Список взят из `composer.json`, цель `check:artifacts` (а не по памяти):

```
composer check:artifacts
  = architecture:check
  + enumeration:renames:check
  + enumeration:runtime-channels:check
  + enumeration:directives:check
  + suppression-snapshot:check
```

| цель                                 | файл                                                          | почему в периметре                                                                                                                                                                                      |
| ------------------------------------ | ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `architecture:check`                 | `docs/internal/generated/modular-architecture/*`              | **измерено в §5**: правка импортов в `DirectiveUsage.php` уже краснит `manifest-enforcement-summary.tsv`. Плюс `test-phpunit-discovery.txt` перечисляет имена тестов — удаление двух тестов его двигает |
| `enumeration:renames:check`          | `docs/internal/plans/rule-vocabulary/enumeration-renames.tsv` | строка `annotation.unused-directive channel ? 15 12 0 0 0 29 0 2` — счётчики вхождений имени по коду/тестам/докам; любая правка §6.1–6.3 их двигает                                                     |
| `enumeration:runtime-channels:check` | `enumeration-runtime-channels.tsv`                            | статический канал; проверить, попадает ли он в таблицу — по grep не попал, вероятно вне периметра                                                                                                       |
| `enumeration:directives:check`       | таблица инлайн-директив дерева `src/`                         | меняется только если правки §6.1 добавляют/убирают `@qmx-*` в `src/`                                                                                                                                    |
| `suppression-snapshot:check`         | `docs/internal/generated/suppression/{composition,inert}.tsv` | `grep annotation` по обоим файлам — пусто; в периметре только если запрет что-то сдвинет в подавлениях `src/` (по §5 — не сдвигает)                                                                     |

Плюс `qmx-baseline.json` — трогать не требуется (канал в нём отсутствует;
`grep unused-directive qmx-baseline.json` — пусто).

### 6.5 Корпус гейта

`finding-gate/cases/annotations/` — единственный кейс, называющий канал:
`case.json` перечисляет `annotation.unused-directive@file` в `channels`.
Фикстура `src/Directives.php` содержит семь директив, **ни одна из которых не
адресует банённый канал** (проверено: `grep -n "qmx-" finding-gate/cases/annotations/src/Directives.php`).
Значит:

- без правки фикстуры гейт на запрете **зелёный и бессодержательный** — это
  запись 11-two-pass-stale.md утверждает верно;
- с посаженной фикстурой гейт упрётся в ту же стену `finding-count-mismatch` /
  `delta-overreach`, что и на снятом двухпроходном пакете.

Правило корпуса из AGENTS.md: канал и его фикстуру добавляют вместе, в кейсе,
владеющем семьёй, — то есть в `annotations`.

---

## 7. Что в записи `11-two-pass-stale.md` оказалось неточным или неполным

**Верно и подтверждено:**

- `DirectiveUsage::suppressible()` фильтрует по `isConfigurationError()`, и этот
  флаг несёт ещё три семантики; перечисленные потребители (`BaselineUpdater`,
  `BaselineCleaner`, `BaselineGenerator`, `BaselineEntryParser`,
  `BaselineCeilingStage`, `ExitCodeResolver`, `FindingProjector`) — полны:
  перечисление инструментом дало ровно этот набор и ничего сверх;
- запрету нужно новое свойство декларации;
- путь отказа `annotation.unresolved-directive` уже есть, нового исхода заводить
  не надо;
- `withoutOwnComplaint()` и склейка охраняются — и **измерено**, чем именно.

**Неточно или неполно:**

1. **«разворачивание селектора обязано канал не покрывать»** — сказано как одно
   требование, а это выбор из трёх мест с разными исходами (§3.3). И у выбора
   «в `expand()`» есть цена, в записи не названная: **ломается валидация ключа
   `exclude_namespace_channels`** — чужая семья, отказ на старте прогона.
2. **Групповая форма `annotation.*` в записи не разобрана вовсе.** При запрете
   через `expand()` она молча сужается — воспроизводится та же болезнь
   «написал строку, которая молча не делает того, что думает автор», ради
   которой запрет и вводится.
3. **«Три авторские формы»** — в записи их три; в коде форм суппрессии четыре
   считая `@qmx-ignore-file` без канала (`appliesToEveryChannel()` ⇒
   `Unmeasured/addresses-every-channel`). Она не адресует канал и запретом не
   затрагивается, но её стоит назвать явно, иначе перечисление форм неполно.
   (Таблицу форм меряет параллельный агент — здесь только отметка, что множество
   форм больше трёх.)
4. **`InertSuppressor` в периметре не участвует** — задание его называет, но по
   докблоку и коду он покрывает только четыре паттерн-механизма и директивы
   явно исключает.
5. **Периметр правок в записи короче фактического.** Не названы:
   `InlineDirectivePolicyInterface` (докблок `directiveVerdicts()` становится
   ложью), `DirectiveNameHints` («did you mean» продолжит советовать банённый
   канал), `scripts/directive-audit-controls/Probes.php` (три пробника),
   `UnusedDirectiveRuleTest` (два теста), `declared.txt` (формат строки),
   генерируемые артефакты `architecture:check` и `enumeration:renames:check`
   (первое измерено), ADR 0037/0039.
6. **Про «сборкой регистра по типу производителя»** запись молчит, а это
   реальная развилка: `annotation.unused-directive` объявляет **правило**, а не
   валидатор, поэтому существующий механизм «свойство следует из типа» к нему
   неприменим без нового маркер-интерфейса и второго штампующего сайта в
   компилятор-пассе (§1.3).

---

## 8. Развилки, требующие решения владельца

1. **Механизм присвоения свойства**: авторский витер на `ChannelDeclaration`
   (дёшево, но «производитель заявляет о себе» — то, от чего уходил
   `asConfigurationError`) против маркер-интерфейса + второго штампа в
   `ChannelDeclarationCompilerPass` (дороже, ломает «пасс не импортирует
   внутренности capability», и всё равно авторское по сути).
2. **Нужен ли новому свойству топологический страж**, и какой: для авторского
   витера «ровно один сайт» неверно по построению; правильный страж — по
   регистру («ровно этот канал несёт свойство»), т.е. новый метод в
   `ChannelDeclarationFixtureDriftTest`, а не расширение
   `ConfigurationErrorClassificationTopologyTest`.
3. **Место запрета**: адресуемость / `expand()` / фильтр подавления (§3.3).
   Наблюдаемые исходы разные; побочный урон по `exclude_namespace_channels` есть
   только у `expand()`.
4. **Покрывает ли запрет `annotation.*`** и, если да, отказом или сужением.
5. **Правится ли `DirectiveNameHints`**, чтобы «did you mean» перестал советовать
   банённый канал (сегодня это закреплено ассертом теста).
6. **Ставится ли корпусная фикстура в `finding-gate/cases/annotations/`.** Без
   неё гейт зелёный и бессодержательный; с ней — упирается в измеренную стену
   `finding-count-mismatch`/`delta-overreach`, то есть шаг блокируется до
   закрытия пробела гейта.
7. **Судьба `AnalysisPipeline::reportedFindings()`** — склейку в
   `auditDirectives()` запись объявляет мёртвой, но `reportedFindings()` делает
   структурно похожую вещь на пути `check` и мёртвой **не** становится. Стоит
   зафиксировать это в плане явно, иначе исполнитель снимет обе.
