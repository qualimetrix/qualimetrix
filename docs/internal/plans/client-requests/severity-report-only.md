# План: report-only через удаление `info` из `fail_on`

**Статус:** исполнен
**Дата:** 2026-08-16, переписан по факту кода 2026-08-19

> Первая редакция писалась до подложки идентичности канала
> (`channel-identity-substrate.md`, PR #14, `f9039a45`). Подложка приземлилась 2026-08-19 и
> отменила часть посылок; документ переписан целиком под фактическое состояние кода, чтобы не
> спорить сам с собой. Расхождения прежней редакции с фактом перечислены в §5.

**Область:** `src/Analysis/Finding/Contract/Severity.php`,
`src/Infrastructure/Console/ExitPolicy.php`, `src/Infrastructure/Console/ExitCodeResolver.php`,
доки.

---

## 1. Предпосылки

«Наблюдать, но не гейтить» сегодня выражается трюком: правило включают с недостижимым
порогом, чтобы нарушения показывались, но никогда не валили сборку. Это ложь в модели
данных — правило заявлено «с порогом», хотя порог фиктивен; намерение закодировано числом,
а не записано.

Факты по коду (сверено 2026-08-19, после подложки):

- `Severity` — `Info | Warning | Error`.
- Значение `fail_on` резолвится и валидируется в `ExitPolicy::fromContributions()`
  (`src/Infrastructure/Console/ExitPolicy.php`), а не в `ConfigSchema`: схема объявляет
  `fail_on` как `SCALAR` и значения не проверяет. До этого изменения `Severity::tryFrom()`
  пропускал `info`.
- `ExitCodeResolver::resolve()` сравнивает найденные severity с порогом; `fail_on: info`
  означал «любое нарушение валит», и Info-only прогон возвращал exit 1 — специальная ветка,
  подменявшая собственный exit-код Info (0) на 1.
- CLI-описание `--fail-on` в `CheckCommandDefinition` уже перечисляло только
  `none, warning, error` — то есть справка и валидатор расходились.
- `fail_on: warning` и так не гейтит Info (rank 0 < 1): удаляется ровно одна возможность —
  `fail_on: info`. Весь breaking-риск локализован в одном значении.
- Пресеты: `strict.yaml` → `warning`, `ci.yaml` → `error`; ни один не использует `info`.
- Единственный продукционный канал, дефолтящий в `Info`, — `annotation.unused-directive`;
  его серьёзность поднимается опцией правила `unused_directive_severity`
  (`annotation.directive`). Это и есть точная замена глобальному `fail_on: info`.

## 2. Аргументы

**Почему убрать `info`, а не добавить четвёртый severity.** Новый severity (`report`)
плюс четвёртый уровень `fail_on` — это лишний виток модели. Убрав `info` из `fail_on`, мы
получаем ровно желаемую семантику: `Severity::Info` переопределяется как «никогда не
гейтит», и `severity: info` на правиле становится *декларацией* report-only, а не
недостижимым порогом.

**Границы обещания.** «Info не гейтит» — утверждение про `fail_on` и только про него. Два
механизма проходят мимо сравнения с порогом и не спрашивают, какую severity выбрало правило:

- **Baseline-breach** поднимает пробитую базлайнимую находку до `Error` самостоятельно.
- **`ChannelAcceptability::ConfigurationError`** гейтит безусловно, вплоть до `fail_on: none`,
  и вдобавок `ExitCodeResolver` бросает `LogicException`, если такой канал вообще выпустил
  находку на `Info`.

Прежняя редакция приводила `unreachable-layer` / `potential-shadow` как пример «Info с
сохранённым вторым зубом в виде baseline-breach». Пример мёртв дважды: подложка зафиксировала
severity этих каналов на `Error`, удалила три per-diagnostic ключа и сделала каналы
небазлайнимыми; а `potential-shadow` вдобавок сужен и на легальной идиоме «narrow before
broad» молчит.

**Цена — одно следствие, принятое осознанно:** breaking change, `fail_on: info` удаляется. По
Backward Compatibility Policy это легально, но требует записи `Breaking` в `CHANGELOG.md`.

## 3. Решение

1. `fail_on` принимает только `none | warning | error`. `info` отклоняется
   `ExitPolicy` — и в `fromContributions()`, и в конструкторе, чтобы недопустимое состояние
   нельзя было собрать в обход резолва. Сообщение об ошибке называет допустимые значения и
   указывает замену (поднять severity самого правила).
2. `Severity::Info` остаётся в перечислении (его читают форматеры, сортировщики, счётчики),
   но не участвует в вычислении exit-кода через `fail_on`. Знание «Info не гейтит» живёт в
   самом перечислении — метод `Severity::gatesRun()`, единственный источник и для проверки, и
   для списка допустимых значений в сообщении.
3. `ExitCodeResolver`: специальная ветка «Info-порог совпал → вернуть exit-код Warning»
   удалена, а не обойдена. Раз минимальный порог — `warning`, всё, что его достигает, имеет
   собственный ненулевой exit-код, и резолвер возвращает его напрямую. Внутренний хелпер
   вернулся с рангов на `?Severity`.
4. Докблоки `Severity` и `ExitCodeResolver` переписаны под новую семантику.

## 4. Последствия

- Код: `Severity.php` (докблок + `gatesRun()`), `ExitPolicy.php` (валидация),
  `ExitCodeResolver.php` (удаление мёртвой ветки).
- Доки: `website/docs/getting-started/configuration.{md,ru.md}` — из примера убрано
  `fail_on: info`, добавлена врезка «`info` — только для отчёта» с заменой через
  `unused_directive_severity`. `website/docs/reference/default-thresholds.{md,ru.md}` правки
  не потребовали: подложка уже переписала эти строки под безусловный гейт конфигурационных
  ошибок. `qmx.yaml.example` `fail_on` не упоминает.
- CHANGELOG: запись `Breaking`.
- Тесты: `ExitPolicyTest` (info отклоняется в обеих точках входа, остальные значения
  принимаются, сообщение перечисляет допустимые), `ExitCodeResolverReportOnlyTest` (Info-only
  прогон даёт 0 при любом `fail_on`; гейтящая находка по-прежнему валит),
  `ExitCodeResolverConfigurationErrorTest` — из перебора политик убран невозможный теперь
  `ExitPolicy(Severity::Info)`. Пресеты не трогались.

## 5. Что в прежней редакции разошлось с кодом

- Валидация `fail_on` не «уходит в резолвер runtime-конфига» из `ConfigSchema.php:103` — она
  уже жила в `ExitPolicy::fromContributions()`; менялся именно этот метод.
- `website/docs/reference/default-thresholds.md:97-98` больше не рекомендует `fail_on: info`
  для `unreachable-layer` / `potential-shadow`: подложка переписала эти строки, а
  per-diagnostic ключи (`unreachable_layer_severity`, `potential_shadow_severity`,
  `empty_template_severity`) удалены. Замена для `fail_on: info` в доках — не они, а
  `unused_directive_severity`.
- Caveat про «жёсткий `Warning` без knob у `annotation.unsupported-threshold` /
  `annotation.invalid-threshold` в `AnalysisPipeline.php:353,465`» устарел: оба канала уже
  `Severity::Error` с acceptability `ConfigurationError`
  (`src/Analysis/Policy/Inline/Directive/InlineDirectiveRule.php`). Обязательный порядок «этот
  план первым, threshold-план вторым» тем самым потерял свою причину — обе половины уже
  выполнены независимо.
- Номера строк `ExitCodeResolver.php:30-68 / :64 / :70-79` не соответствовали файлу после
  подложки (добавились ветки `ReportCoverage` и `ConfigurationError`).
