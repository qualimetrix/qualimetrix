# M2. `Outcome::asDeclared()` — измерение

Дерево не менялось: всё измерение — чтение кода + прогоны в hardlink-клонах,
которые харнесс делает сам и сам удаляет. Скрипты измерения лежат рядом с этим
отчётом: `measure.php` (инструментированная копия цикла харнесса, пишет
`actual.jsonl`) и `table.py` (генерирует таблицу из `actual.jsonl`).

## Что подтверждено и что опровергнуто в записи FOLLOWUPS (строка 1445)

**Подтверждено дословно:**

1. «`asDeclared()` считает пробник as declared, когда `missing === []` и
   покраснели не все кейсы прогона» — `scripts/directive-audit-controls/Outcome.php:56-69`.
2. «Кейсы сверх объявленных не проверяются ничем, кроме верхней границы» —
   там же; никакого другого условия про `red` в коде нет.
3. **Расползание действительно есть и оно массовое.** Измерено на всех 116
   пробниках: 39 из них краснят кейсы сверх объявленных, у одного расползание —
   33 кейса сверх одного объявленного (`report-forgets-the-run`). Сегодня все
   116 — «as declared».
4. «Пакет 2 напоролся на соседний экземпляр: пробник объявлял имя метода, а имя
   метода считается покрасневшим от любого из датасетов» — механизм существует и
   **живой экземпляр остался в дереве**: `producer-granularity-instead-of-level`
   объявляет `itLeavesADirectiveUnmeasuredWhenItsRuleIsSwitchedOff` (три
   датасета), а краснит ровно один — `with data set "the level the directive
   sits on"`. Харнесс это принимает.

**Опровергнуто / уточнено:**

5. **«Правка на равенство» в наивной форме НЕ закрывает класс из п. 4.**
   Сопоставление объявленного с покрасневшим — подстрочное
   (`str_contains($case, $declared)`, `Outcome.php:83-92`). Если оставить
   подстроку и добавить лишь требование «каждый покрасневший покрыт каким-то
   объявлением», объявление-имя-метода продолжит покрывать любой свой датасет, а
   `producer-granularity-instead-of-level` останется зелёным (у него нет ни
   одного лишнего кейса — он краснит меньше, чем объявил, и это невидимо в обе
   стороны). Класс закрывает только равенство по **точным именам кейсов**.
6. Цена такой правки больше, чем «перепроверить каждое объявление»:
   **46 объявлений из 191 вообще не являются именами кейсов** — 45 записаны в
   форме `data set "plain"` (сознательно, чтобы не повторять длинное имя метода),
   одно — голое имя метода с датасетами. Все они подлежат переписыванию, а не
   перепроверке.
7. Запись говорит «за правкой обязателен полный прогон с поштучным снятием». Это
   верно по стоимости (23 минуты на полный прогон, ~12 с на пробник), но
   поштучное снятие само по себе не даёт того, что даёт полный прогон, — см. п. 5
   ниже.

## 1. Сегодняшняя семантика вердиктов, по коду

| Что                     | Где                      | Что именно проверяется                                                                                                                    |
| ----------------------- | ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `Outcome::of()`         | `Outcome.php:36-49`      | для каждого объявленного `reddens` ищет **подстроку** в списке покрасневших; не найдено → в `missing`                                     |
| `Outcome::matches()`    | `Outcome.php:83-92`      | `str_contains($case, $declared)` — объявление это подстрока имени кейса, не имя                                                           |
| `Outcome::refused()`    | `Outcome.php:51-54`      | отказ: `cases`/`red` пусты, все объявления считаются `missing`                                                                            |
| `Outcome::asDeclared()` | `Outcome.php:56-69`      | отказ → false; positive-пробник → `red === []`; иначе `missing === [] && count(red) < count(cases)`                                       |
| Верхняя граница         | `Outcome.php:68`         | «не все кейсы прогона сразу»: строго `count(red) < count(cases)`, то есть 168 из 169 проходит                                             |
| `Outcome::verdict()`    | `Outcome.php:71-80`      | REFUSED / as declared / NOT GREEN / MISSED ITS CASE / REDDENED EVERYTHING                                                                 |
| Покрытие                | `Report.php:88-110`      | кейс, не покрасневший **ни от одного** пробника, кроме positive и `blanket`, → «guarded by nothing»                                       |
| Код возврата            | `Report.php:73-79`       | полный прогон: 0, если нет «не as declared» и нет непокрытых; при `--only`: покрытие не считается свидетельством, 0/1 только по пробникам |
| Код возврата харнесса   | `Harness.php:50-64`      | 3 — неизвестная опция или пустой отбор `--only`                                                                                           |
| Отказ по коду PHPUnit   | `Harness.php:130-136`    | ненулевой выход при нулевом числе красных кейсов → REFUSED («измерено ничто»)                                                             |
| Что считается красным   | `Suite.php:119, 134-137` | failure, error **и skipped**; кейс — атрибут `name` из JUnit                                                                              |
| Состав прогона          | `Suite.php:42-52`        | девять тест-файлов, 169 кейсов                                                                                                            |

**Чего не проверяется ничем:** покрасневшие кейсы сверх объявленных (кроме
границы «не все»), совпадение гранулярности объявления с гранулярностью кейса,
и уникальность имени кейса — `Suite::fromJUnit()` кладёт кейсы в массив по
`name` без `classname` (`Suite.php:119`), поэтому два одноимённых метода в разных
классах слились бы в одну запись. Сегодня коллизий нет: 169 имён — 169
уникальных (проверено на JUnit-логе зелёного прогона).

## 2. Фактическое множество каждого пробника

Способ: `measure.php` — точная копия цикла `Harness::observe()`
(`Harness.php:116-144`): клон, снос `.git`, `Mutation::apply()`, `Suite::runIn()`,
`Outcome::of()`. Отличие одно — пишет полные списки `cases`/`red`/`missing` в
`actual.jsonl`, а не только счётчики, как `Report::print()`. Прогон:
116 пробников, 169 кейсов в каждом, ни одного отказа, все 116 — «as declared»,
непокрытых кейсов 0 (то есть штатный прогон сегодня вышел бы 0).

Колонка «равенство» = «каждое объявление кому-то соответствует **и** каждый
покрасневший кейс покрыт каким-то объявлением» (подстрочное сопоставление, как
сейчас). Колонка «объявлено, но зелено» пуста у всех 116 — сегодня все
объявления держатся.

116 пробников, 169 кейсов в каждом прогоне; при равенстве прошли бы 77, упали бы 39

| пробник                                                | объявлено | покраснело | сверх объявленного (по тест-классам)                                                                                                                                                                                                                                                                                                             | объявлено, но зелено | равенство |
| ------------------------------------------------------ | --------- | ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------- | --------- |
| `positive`                                             | 0         | 0          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `outcome-always-matched` (blanket)                     | 1         | 32         | 31 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×11, Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest×15, Qualimetrix.Tests.Analysis.Run.Integration.DirectiveAuditPipelineTest×1, Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×4) | —                    | **НЕТ**   |
| `outcome-never-matched` (blanket)                      | 1         | 15         | 14 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×10, Qualimetrix.Tests.Analysis.Run.Integration.DirectiveAuditPipelineTest×1, Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×3)                                                                                                   | —                    | **НЕТ**   |
| `removal-removes-nothing`                              | 1         | 17         | 16 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×11, Qualimetrix.Tests.Analysis.Run.Integration.DirectiveAuditPipelineTest×1, Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×4)                                                                                                   | —                    | **НЕТ**   |
| `first-binding-only`                                   | 1         | 5          | 4 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×4)                                                                                                                                                                                                                                                           | —                    | **НЕТ**   |
| `boundary-out-of-fingerprint`                          | 1         | 5          | 4 (Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest×3, Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×1)                                                                                                                                                                 | —                    | **НЕТ**   |
| `recommendation-as-identity`                           | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-lists-drift-from-the-code`                      | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `no-masking`                                           | 1         | 3          | 2 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×2)                                                                                                                                                                                                                                                           | —                    | **НЕТ**   |
| `structural-masking`                                   | 1         | 4          | 3 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×3)                                                                                                                                                                                                                                                           | —                    | **НЕТ**   |
| `pairwise-masking`                                     | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `coalition-against-the-run`                            | 1         | 2          | 1 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×1)                                                                                                                                                                                                                                                           | —                    | **НЕТ**   |
| `masker-named-by-position`                             | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `no-control-before`                                    | 1         | 5          | 4 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×4)                                                                                                                                                                                                                                                           | —                    | **НЕТ**   |
| `no-control-after`                                     | 1         | 3          | 2 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×2)                                                                                                                                                                                                                                                           | —                    | **НЕТ**   |
| `control-skips-the-rebuild`                            | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `no-control-narrowing`                                 | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `verdict-ignores-the-measurement`                      | 1         | 17         | 16 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×11, Qualimetrix.Tests.Analysis.Run.Integration.DirectiveAuditPipelineTest×1, Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×4)                                                                                                   | —                    | **НЕТ**   |
| `boundary-always-observable`                           | 1         | 2          | 1 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×1)                                                                                                                                                                                                                                                           | —                    | **НЕТ**   |
| `judge-the-unaskable`                                  | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `ignore-disabled-producer`                             | 2         | 5          | 3 (Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×3)                                                                                                                                                                                                                                                                  | —                    | **НЕТ**   |
| `producer-granularity-instead-of-level`                | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `judge-by-published`                                   | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `suppression-never-fires`                              | 4         | 4          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `exit-on-an-unaskable-inert`                           | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `command-drops-the-discovery`                          | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `suppression-never-inert`                              | 1         | 8          | 7 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest×1, Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×6)                                                                                                                                                                                       | —                    | **НЕТ**   |
| `verdict-forgets-where-it-was-written`                 | 1         | 5          | 4 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest×2, Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×2)                                                                                                                                                                                       | —                    | **НЕТ**   |
| `grouping-ignores-the-tag`                             | 1         | 2          | 1 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest×1)                                                                                                                                                                                                                                                                    | —                    | **НЕТ**   |
| `grouping-splits-one-site`                             | 1         | 2          | 1 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest×1)                                                                                                                                                                                                                                                                    | —                    | **НЕТ**   |
| `suppression-judges-the-unaddressable-pair`            | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `suppression-judges-every-channel`                     | 1         | 3          | 2 (Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×2)                                                                                                                                                                                                                                                                  | —                    | **НЕТ**   |
| `suppression-ignores-a-disabled-producer`              | 3         | 3          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `ban-refuses-nothing`                                  | 14        | 14         | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `ban-spreads-to-configuration-errors`                  | 3         | 3          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `ban-yields-to-the-pair-grammar`                       | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `publication-silences-the-banned-channel`              | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `banned-channel-lifted-out-of-the-pipeline`            | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `suppression-silences-a-configuration-error`           | 3         | 3          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `guard-counts-discovered-not-analysed`                 | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `command-accepts-any-format`                           | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `command-accepts-any-sweep`                            | 1         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `command-errors-in-prose-under-json`                   | 1         | 2          | 1 (Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×1)                                                                                                                                                                                                                                                                  | —                    | **НЕТ**   |
| `unreadable-config-is-not-a-config-error`              | 1         | 2          | 1 (Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×1)                                                                                                                                                                                                                                                                  | —                    | **НЕТ**   |
| `scope-that-read-nothing-is-clean`                     | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-list-uncovered`                                 | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `report-forgets-the-run`                               | 1         | 34         | 33 (Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×33)                                                                                                                                                                                                                                                                | —                    | **НЕТ**   |
| `field-location`                                       | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-subject`                                        | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-symbol-path`                                    | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-rule-name`                                      | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-code`                                           | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-severity`                                       | 1         | 2          | 1 (Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest×1)                                                                                                                                                                                                                                                           | —                    | **НЕТ**   |
| `field-metric-value`                                   | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-related-locations`                              | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-dependency-target`                              | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-dependency-type`                                | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-accepted-level`                                 | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-occurrence-key`                                 | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-threshold`                                      | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-message`                                        | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `field-recommendation`                                 | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `measured-table-flipped`                               | 2         | 4          | 2 (Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest×2)                                                                                                                                                                                                                                                                      | —                    | **НЕТ**   |
| `unknown-verdict-guessed`                              | 5         | 5          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `table-forgets-a-verdict`                              | 2         | 9          | 7 (Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest×1, Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest×6)                                                                                                                                                                                                      | —                    | **НЕТ**   |
| `floor-removed`                                        | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `population-never-mismatches`                          | 1         | 2          | 1 (Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest×1)                                                                                                                                                                                                                                                                               | —                    | **НЕТ**   |
| `empty-population-floored`                             | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `disqualified-run-judged`                              | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `enumeration-failure-is-not-a-refusal`                 | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `no-report-read-as-a-report`                           | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `missing-field-defaulted`                              | 6         | 6          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `missing-line-defaulted`                               | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `verdict-list-unchecked`                               | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `envelope-read-as-a-measurement`                       | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `population-holds-both-halves`                         | 2         | 5          | 3 (Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest×1, Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest×2)                                                                                                                                                                                                      | —                    | **НЕТ**   |
| `verdict-map-drops-a-duplicate`                        | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `population-as-a-set`                                  | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `tsv-split-unbounded`                                  | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `tsv-columns-unchecked`                                | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `tsv-line-number-untyped`                              | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `tsv-empty-target-accepted`                            | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `json-summary-by-hand`                                 | 1         | 2          | 1 (Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×1)                                                                                                                                                                                                                                                                  | —                    | **НЕТ**   |
| `text-summary-by-hand`                                 | 1         | 2          | 1 (Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest×1)                                                                                                                                                                                                                                                                  | —                    | **НЕТ**   |
| `scan-class-drops-the-dot`                             | 15        | 15         | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-class-drops-the-underscore`                      | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-class-drops-digits`                              | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-class-drops-capitals`                            | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-class-drops-the-star`                            | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-class-drops-the-hash`                            | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-class-drops-the-colon`                           | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-class-drops-the-hyphen`                          | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-class-admits-a-slash`                            | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-class-admits-a-plus`                             | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-demands-a-whole-word`                            | 1         | 2          | 1 (Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest×1)                                                                                                                                                                                                                                                                     | —                    | **НЕТ**   |
| `scan-accepts-a-tag-with-a-suffix`                     | 1         | 2          | 1 (Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest×1)                                                                                                                                                                                                                                                                     | —                    | **НЕТ**   |
| `scan-reads-backticked-documentation`                  | 1         | 2          | 1 (Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest×1)                                                                                                                                                                                                                                                                     | —                    | **НЕТ**   |
| `scan-cuts-a-backtick-region-out`                      | 1         | 2          | 1 (Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest×1)                                                                                                                                                                                                                                                                     | —                    | **НЕТ**   |
| `scan-splits-on-a-comma`                               | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-keeps-the-docblock-terminator`                   | 3         | 3          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-stops-at-a-valueless-directive`                  | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-reads-any-file-in-the-tree`                      | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-skips-what-it-cannot-read`                       | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `fixture-grows-an-unnamed-form`                        | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-keeps-reading-past-a-directive`                  | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `scan-admits-an-empty-target`                          | 1         | 2          | 1 (Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest×1)                                                                                                                                                                                                                                                                     | —                    | **НЕТ**   |
| `scan-reads-ordinary-comments`                         | 2         | 2          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `extractor-class-drops-punctuation`                    | 3         | 4          | 1 (Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest×1)                                                                                                                                                                                                                                                                     | —                    | **НЕТ**   |
| `extractor-class-drops-word-characters`                | 3         | 4          | 1 (Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest×1)                                                                                                                                                                                                                                                                     | —                    | **НЕТ**   |
| `heterogeneity-forgets-a-verdict`                      | 2         | 4          | 2 (Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest×2)                                                                                                                                                                                                                                                                      | —                    | **НЕТ**   |
| `heterogeneity-forgets-a-refusal`                      | 2         | 3          | 1 (Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest×1)                                                                                                                                                                                                                                                                      | —                    | **НЕТ**   |
| `heterogeneity-reports-one-shortfall`                  | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `reason-key-defaulted`                                 | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |
| `heterogeneity-counts-a-verdict-the-sweep-cannot-move` | 1         | 2          | 1 (Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest×1)                                                                                                                                                                                                                                                                      | —                    | **НЕТ**   |
| `seeded-fixture-copied-into-src`                       | 1         | 2          | 1 (Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest×1)                                                                                                                                                                                                                                                                     | —                    | **НЕТ**   |
| `seeded-suppression-copied-into-src`                   | 1         | 1          | —                                                                                                                                                                                                                                                                                                                                                | —                    | да        |

Проверить таблицу заново: `php <scratch>/measure.php` (≈23 мин, ничего в дереве
не меняет), затем `python3 <scratch>/table.py`.

## 3. Цена перехода на равенство

Две разные правки с очень разной ценой — измерены обе.

**Вариант A: равенство при сохранении подстрочного сопоставления**
(«каждый красный покрыт каким-то объявлением»).

- прошли бы **77** из 116, упали бы **39**;
- суммарно **179 покрасневших кейсов** сверх объявленного придётся либо сузить
  поломкой, либо объявить явно;
- распределение расползания: 20 пробников на +1 кейс, 5 на +2, 3 на +3, 4 на +4,
  2 на +7, 1 на +14, 2 на +16, 1 на +31, 1 на +33;
- оба `blanket`-пробника (`outcome-always-matched`, `outcome-never-matched`)
  среди упавших **по построению** — они краснят 32 и 15 кейсов и обязаны это
  делать (`Probe.php:23-27`). Значит правка обязана предусмотреть для них
  освобождение, иначе флаг `blanket` придётся дублировать смыслом;
- этот вариант **не закрывает** класс из записи (см. п. 6).

**Вариант B: равенство по точным именам кейсов** (единственный, который
закрывает класс).

- упали бы **58** из 116;
- **46 объявлений из 191** переписываются механически, потому что не являются
  именами кейсов: 45 в форме `data set "…"`, одно — голое имя метода;
- 25 пробников содержат хотя бы одно такое объявление; у 7 из них одновременно
  есть и расползание;
- к этому добавляются те же 179 лишних кейсов варианта A.

## 4. Есть ли способ объявить «краснею больше, и вот что именно»

**Нет.** `Probe` несёт ровно пять полей: `id`, `claim`, `mutation`, `reddens`,
`blanket` (`Probe.php:28-34`). Ближайшее к искомому — `blanket`
(`Probe.php:77-85`), но он говорит другое: «эта поломка коротит сравнение целиком»
и влияет **только** на счёт покрытия (`Report.php:100`), не на `asDeclared()`.
Комментария-«ожидаемо шире» в фабриках нет; ни одна фабрика не принимает второй
список.

Минимальная форма, которой хватило бы (схема, без исходного кода продукта):

```
Probe::breaking(id, claim, file, replacement, reddens)          // как сейчас
Probe::breaking(...)->alsoReddens([...точные имена кейсов...])  // явный «хвост»

Outcome::asDeclared():
    red == reddens ∪ alsoReddens          // равенство множеств точных имён
    и (blanket или red != все кейсы)
verdict: добавляется REDDENED MORE THAN DECLARED (список лишних кейсов в отчёт)
Report::print(): печатает лишние кейсы так же, как сейчас печатает "stayed green"
```

Два условия, без которых форма станет тем же подмножеством другими словами:
`alsoReddens` сравнивается точными именами (иначе подстрока вернёт дыру п. 6), и
`blanket` остаётся освобождением от **верхней** границы, а не от равенства.

## 5. Цена контрольного прогона

- **116 пробников** (`Probes::all()`), из них 1 положительный и 2 `blanket`;
  191 объявление; в каждом прогоне 169 кейсов из девяти файлов (`Suite::FILES`).
- **Полный прогон: `composer directives:controls` — 22 мин 04 с, код возврата 0,
  «116 probes, 0 not as declared, 0 cases guarded by nothing»** (замерено `time`,
  одной фоновой задачей, вывод в `official.log`). Инструментированный цикл
  (`measure.php`) дал 1377 с ≈ 23 мин: на пробник min 10.4 с, медиана 11.8 с,
  max 13.0 с.
- **Сверка двух прогонов машинная:** по всем 116 пробникам вердикт и число
  красных кейсов совпали **без единого расхождения**, то есть таблица в п. 2
  описывает тот же прогон, который печатает штатная команда.
- Один пробник = один hardlink-клон дерева + один прогон девяти тест-файлов
  (сам прогон вне харнесса — 9.4 с, `vendor/bin/phpunit --no-coverage` по
  `Suite::FILES`).
- `--only=<id,...>` (`Harness.php:151-171`) отбирает пробники по точному `id`;
  пустой отбор — код 3. При сужении `Report::print()`
  (`Report.php:73-77`) **сам печатает**, что покрытие перестало быть
  свидетельством, и возвращает 0/1 только по объявлениям отобранных пробников.
  Причина: условие покрытия — свойство **списка целиком** («нет кейса, который
  не краснит ни один пробник»), и при отборе одного пробника «непокрытыми»
  оказываются 150+ кейсов, что не факт о дереве, а факт об отборе. Поэтому
  поштучное снятие годится для итерации по одному пробнику и не годится как
  доказательство, что стенд в порядке.

## 6. Гранулярность: что харнесс называет «кейсом»

Измерено на JUnit-логе зелёного прогона тех же девяти файлов:

- **Кейс = датасет.** PHPUnit пишет `name="itX with data set "plain""`, и
  `Suite::fromJUnit()` (`Suite.php:108-119`) берёт именно эту строку. То есть в
  списках `cases`/`red` датасеты различаются.
- **Файл в ключ не входит.** Ключ — только `name`, без `classname`
  (`Suite.php:119`). Сегодня 169 имён уникальны, коллизий нет; появление
  одноимённых методов в двух из девяти файлов молча схлопнет их в один кейс.
- **Объявление — произвольная подстрока**, и именно здесь живёт класс из записи:
  `itLeaves… ` покрывается любым из своих датасетов.

Машинная проверка объявлений против зелёного прогона: **2 объявления из 191
соответствуют более чем одному кейсу**:

| пробник                                 | объявление                                             | сколько кейсов покрывает                       | что на самом деле краснеет                      |
| --------------------------------------- | ------------------------------------------------------ | ---------------------------------------------- | ----------------------------------------------- |
| `producer-granularity-instead-of-level` | `itLeavesADirectiveUnmeasuredWhenItsRuleIsSwitchedOff` | 3 датасета                                     | ровно 1 (`… "the level the directive sits on"`) |
| `command-accepts-any-sweep`             | `itRefusesAnUnknownSweep`                              | 2 (само имя и `itRefusesAnUnknownSweepInJson`) | оба                                             |

Отсюда прямой ответ на вопрос задания: **правка на равенство множеств класс из
записи не закрывает, если сопоставление остаётся подстрочным.**
`producer-granularity-instead-of-level` при варианте A остаётся зелёным (лишних
кейсов у него нет), а `command-accepts-any-sweep` — тоже (оба его красных
покрыты одним объявлением-префиксом). Закрывает только вариант B: точные имена
плюс равенство. Второй, независимый хвост того же класса — ключ кейса без
`classname` — не закрывается ни A, ни B.

## Чего я не измерил и почему

- **Ничего из штатного прогона не осталось неизмеренным**: `composer
  directives:controls` отработал целиком (22:04, код 0) и сошёлся с моим циклом
  по всем 116 пробникам. Первый мой запуск я оборвал сам — он шёл через
  `| head -5`, что убило бы отчёт по SIGPIPE.
- **Поведение при изменённой семантике.** Я ничего не менял в дереве, поэтому
  «упали бы 39/58» — расчёт по измеренным множествам, а не наблюдение красного
  стенда. Проверяется только правкой.
- **Правомерность каждого расползания.** Я измерил, что 39 пробников краснят
  сверх объявленного, но не судил, где это законный каскад (поломка в общем узле
  сравнения), а где реальная неточность поломки. Это ровно та работа, которую
  правка и потребует.
- **Прогон на другой машине/в CI.** Тайминги сняты на этой машине при
  посторонней нагрузке (в фоне работали чужие phpunit-процессы); порядок
  величины устойчив, точные секунды — нет.

## Оценка объёма: один пакет или больше

**Больше одного, если делать вариант B (а только он закрывает предмет записи).**
Механическая часть — переписать 46 объявлений в точные имена — действительно
один пакет и проверяется скриптом. Содержательная часть — 179 лишних
покраснений у 39 пробников, по каждому решение «сузить поломку или объявить
хвост» — это разбор каждого пробника поодиночке, с двумя полными прогонами по
23 минуты на каждую итерацию, плюс освобождение для двух `blanket`-пробников.
Реалистично: пакет 1 — семантика (`alsoReddens` + точные имена + новый вердикт)
и механическая переписка объявлений; пакет 2 — разбор 39 расползаний; отдельным
пунктом (можно в ту же запись FOLLOWUPS) — ключ кейса без `classname`, который
ни A, ни B не трогают.
