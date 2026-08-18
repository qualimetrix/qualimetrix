# План: report-only через удаление `info` из `fail_on`

**Статус:** предложение, перед ревью
**Дата:** 2026-08-16
**Область:** `src/Analysis/Finding/Contract/Severity.php`, `src/Infrastructure/Console/ExitCodeResolver.php`, валидация `fail_on`, доки

---

## 1. Предпосылки

«Наблюдать, но не гейтить» сегодня выражается трюком: правило включают с недостижимым
порогом, чтобы нарушения показывались, но никогда не валили сборку. Это ложь в модели
данных — правило заявлено «с порогом», хотя порог фиктивен; намерение закодировано числом,
а не записано.

Факты по коду:

- `Severity` — `Info | Warning | Error` (`Severity.php:21-25`).
- `ExitCodeResolver::resolve()` (`ExitCodeResolver.php:30-68`): `fail_on` принимает
  `Severity|false|null`; `fail_on: info` означает «любое нарушение валит», и Info-only
  прогон возвращает exit 1 (ветка `:64`).
- `fail_on: info` задокументирован как способ гейтить `unreachable-layer` /
  `potential-shadow` (`website/docs/reference/default-thresholds.md:97-98`), но оба
  диагностика уже имеют пер-rule knob (`unreachable_layer_severity`,
  `potential_shadow_severity`) — точная альтернатива глобальному `fail_on: info`.
- Пресеты: `strict.yaml` → `warning`, `ci.yaml` → `error`; ни один не использует `info`.
- `fail_on: warning` уже сегодня не гейтит Info (`severityRank(Info)=0 < 1`,
  `ExitCodeResolver.php:70-79`): удаляется ровно одна возможность — `fail_on: info`. Весь
  breaking-риск локализован в одном значении.

## 2. Аргументы

**Почему убрать `info`, а не добавить четвёртый severity.** Новый severity (`report`)
плюс четвёртый уровень `fail_on` — это лишний виток модели. Убрав `info` из `fail_on`, мы
получаем ровно желаемую семантику: `Severity::Info` переопределяется как «никогда не
гейтит», и `severity: info` на правиле становится *декларацией* report-only, а не
недостижимым порогом.

**Ограничение обещания: «Info не гейтит» — только про `fail_on`.** Baseline-breach — отдельный
механизм, повышающий пробитую базлайнимую находку до `Error` (`Baseline README:128`). Это
регрессионный гейт против принятого состояния, ортогональный severity; он остаётся и после среза
**для базлайнимых каналов**.

Существенная оговорка, вытекающая из порядка исполнения (см. ниже): для четырёх архитектурных
диагностик этот механизм после подложки неприменим — они становятся `ConfigurationError` и потому
небазлайнимыми. Прежняя редакция этого плана опиралась на них как на пример «Info с сохранённым
вторым зубом»; пример больше не годится, и подложка компенсирует потерю нижней границей severity
для конфигурационных ошибок. Обещание формулируется как «Info не гейтит
непосредственно через `fail_on`»; report-only как «не гейтит вообще никогда» потребовало бы
отдельной небазлайнимой семантики — вне скоупа этого плана.

**Цена — два следствия, принятые осознанно:**

1. Breaking change: `fail_on: info` удаляется. По Backward Compatibility Policy это
   легально, но требует записи в `CHANGELOG.md` (`Breaking`).

**Порядок исполнения: после подложки идентичности канала.** `channel-identity-substrate.md` переводит четыре
архитектурные диагностики в `ConfigurationError` и тем делает их небазлайнимыми. Это отменяет посылку §2 ниже о
том, что у `unreachable-layer`/`potential-shadow` остаётся второй зуб в виде baseline-breach: после подложки его
нет, и потому подложка вводит нижнюю границу severity для конфиг-ошибок. Писать этот план надо в мире, где
переклассификация уже произошла, иначе его обоснование окажется верным только на момент написания.
2. Caveat снят (проверено по коду): жёстко зашитых `Info` без knob'а нет —
   `unreachable-layer`/`potential-shadow` дефолтят в `Info`, но knob'ы есть; `empty-template`
   и `coverage` не Info вовсе. Реальная дыра рядом — `annotation.unsupported-threshold` /
   `annotation.invalid-threshold` с жёстким `Warning` без knob
   (`AnalysisPipeline.php:353,465`); её чинит соседний threshold-план. **Порядок обязателен:**
   этот план первым (фиксирует «Info = report-only»), threshold-план вторым (поднимает
   `annotation.*` до Error в уже определённой модели).

## 3. Решение

1. `fail_on` принимает только `none | warning | error`. Значение `info` отклоняется
   валидатором конфига (или перестаёт быть допустимым значением).
2. `Severity::Info` остаётся в перечислении (используют 17 файлов в `src/`: форматеры,
   сортировщики, счётчики), но не участвует в вычислении exit-кода **через `fail_on`** — это
   report-only уровень (baseline-breach остаётся отдельным гейтом, см. §2).
3. `ExitCodeResolver` — Info-порог удаляется: ветка `ExitCodeResolver.php:64`
   (`severityRank(Severity::Info) => Warning->getExitCode()`) становится мёртвой и удаляется,
   а не «перестаёт обрабатываться»; `fail_on` минимально `warning`, Info-нарушения не влияют
   на exit-код.
4. `Severity` docblock и `ExitCodeResolver` docblock переписываются под новую семантику.

## 4. Последствия

- Код: `Severity.php` (docblock), `ExitCodeResolver.php`, парсер/валидатор значения
  `fail_on` (SCALAR в `ConfigSchema.php:103` — проверка значения уходит в резолвер
  runtime-конфига).
- Доки: `website/docs/getting-started/configuration.{md,ru.md}` (таблица `fail_on`),
  `website/docs/reference/default-thresholds.{md,ru.md}` (строки про `fail_on: info`
  заменяются на пер-rule knob), `qmx.yaml.example`.
- CHANGELOG: запись `Breaking`.
- Тесты: `ExitCodeResolver` unit (info больше не гейтит; мёртвая ветка `:64` удалена),
  валидация `fail_on: info` отклоняется, пресеты не трогаются (ни один не использует info).
- Порядок с threshold-планом: этот — первым; threshold-план поднимает `annotation.*` до
  Error поверх зафиксированной здесь модели.
