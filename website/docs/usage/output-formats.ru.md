# Форматы вывода

Qualimetrix поддерживает 12 форматов вывода (включая устаревший
`text-verbose`). Выбирайте тот, который подходит для вашего рабочего процесса.

```bash
bin/qmx check src/ --format=<формат>
```

---

## summary (по умолчанию)

Обзор здоровья проекта с оценками, худшими нарушителями и сводкой нарушений. Это вывод CLI по умолчанию, предназначенный для быстрой оценки состояния проекта.

**Когда использовать:** Локальная разработка, быстрый обзор здоровья проекта.

**Основные возможности:**

- Общий бар здоровья плюс по одному бару на измерение (сложность, связность, связанность, типизация, сопровождаемость), за каждым — декомпозиция с `↳`, детализирующая метрики, из которых сложилась оценка
- Оценки в процентах, а не в сырых баллах
- Однострочные списки `Worst namespaces` / `Worst classes`, каждая запись с оценкой впереди, и хвост `+N more (use ...)`, когда список был обрезан
- Секция `Top issues by impact`: ранжированный список самых влиятельных отдельных нарушений — severity, impact score, файл, оценка времени на исправление, канал правила, сообщение и символ
- Количество нарушений с оценкой технического долга (включая плотность долга на 1K LOC)
- Несколько контекстных `Hints:` для следующих шагов

**Пример вывода:**

```
Qualimetrix 0.26.0 — 62 files analyzed, 0.8s

Analysis complete: 62 analyzed, 0 generated file(s) excluded.

Health █████████████████████░░░░░░░░░ 71.4% Fair

  Complexity      ████████████████████████░░░░░░ 79.2% Good
                   ↳ Cyclomatic (avg): 3.1 (target: below 4) — manageable branching
                   ↳ Cognitive (avg): 2.4 (target: below 5) — straightforward control flow
                   ↳ Cyclomatic: 14 (target: below 4) — too many code paths
                   ↳ Cognitive: 18 (target: below 5) — deeply nested, hard to follow
  Cohesion        ████████████████░░░░░░░░░░░░░░ 54.8% Poor
                   ↳ TCC: 0.4 (target: above 0.5) — methods rarely share fields
                   ↳ LCOM4: 3 (target: 1 or less) — class splits into unrelated clusters
  Coupling        ███████████████████░░░░░░░░░░░ 63.1% Fair
                   ↳ Ce (avg): 4.7 (target: below 3) — elevated outgoing coupling
                   ↳ Ce pkg (avg): 1.8 (target: below 1) — wide package dependencies
                   ↳ Distance: 0.52 (target: below 0.3) — poor balance of abstraction and stability
  Typing          ███████████████████████████░░░ 91.3% Excellent
                   ↳ Parameter types: 91 (target: 100%) — 251 of 275 typed (91.3%)
                   ↳ Return types: 88 (target: 100%) — 231 of 262 typed (88.2%)
                   ↳ Property types: 95 (target: 100%) — 118 of 124 typed (95.2%)
  Maintainability █████████████████████████░░░░░ 82.0% Good
                   ↳ MI (avg): 71.4 (target: above 65) — code is maintainable
                   ↳ MI (p5): 52.6 (target: above 50) — even worst methods are maintainable
                   ↳ MI: 41.8 (target: above 65) — code is hard to change safely
  * Labels reflect per-dimension scales (e.g., Typing requires >80% for Acceptable)

Worst namespaces
  48.2 App\Billing\Invoice (6 classes, 11 violations, 3.8/100 LOC)
  55.9 App\Service\Order (4 classes, 7 violations, 2.1/100 LOC)
  61.3 App\Repository (9 classes, 5 violations, 0.9/100 LOC)
  +5 more (use --format=html or --format-opt=top=8)

Worst classes
  38.4 App\Billing\Invoice\InvoiceCalculator — low cohesion
  45.1 App\Service\Order\OrderService — high coupling
  52.7 App\Repository\OrderRepository
  +9 more (use --format=html or --format-opt=top=10)


Top issues by impact
  1. [ERR] 4.12  src/Billing/Invoice/InvoiceCalculator.php  [45min]
         complexity.cognitive: Cognitive complexity: 24 (threshold: 15). Top: nested if +4 L88, nested foreach +3 L74, nested if +2 L91 — deeply nested, hard to follow (InvoiceCalculator::recalculate)
  2. [ERR] 3.65  src/Service/Order/OrderService.php  [30min]
         coupling.cbo: CBO is 21 (threshold: 15) — too many collaborators (OrderService)
  3. [WRN] 2.90  src/Repository/OrderRepository.php  [20min]
         complexity.ccn: Cyclomatic complexity: 13 (threshold: 10) — too many code paths (OrderRepository::findByCriteria)
82 violations (19 errors, 63 warnings) | Tech debt: 6h 20min (54.3 min/kLOC to fix)

Hints: --detail to list violations (up to 200; --detail=all for every one) | --namespace='subtree:App\Billing\Invoice' to drill down | --format=html -o report.html for full report
Docs: https://qualimetrix.dev · AI agents: https://qualimetrix.dev/llms.txt
```

Флаг, управляющий числом записей в `Top issues by impact`, описан на странице [CLI Options](cli-options.md).

**Детализация с `--namespace` и `--class`:**

```bash
# Показать нарушения для конкретного поддерева пространства имён
bin/qmx check src/ --namespace='subtree:App\Service'

# Показать нарушения для конкретного класса
bin/qmx check src/ --class=App\\Service\\UserService
```

Детализация сужает то, что показывает отчёт, но не то, что решает код
возврата. Поэтому строка счётчиков говорит о выборке («… in this scope»),
называет нарушения вне её, которые решают код возврата, и берёт цвет от всего
прогона: чистое поддерево проекта с ошибками печатает
`No violations in this scope. 9 outside it (5 errors, 4 warnings) decide the exit code`,
а не зелёное `No violations found.`. `--format=text` делает то же в своей
итоговой строке.

Структурированные форматы тоже перечисляют только выборку, и каждый сообщает,
что осталось вне её, в том канале, которым уже пользуется для диагностики о
самом документе. Формат без такого канала вместо этого отклоняет выборку:

| Формат       | Что осталось вне выборки                                                                                                       |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------ |
| `json`       | Объект верхнего уровня `outOfScope`: `violationCount`, `errorCount`, `warningCount`, `infoCount`                               |
| `metrics`    | Объект верхнего уровня `outOfScope`: `violations`, `errors`, `warnings`, `info`                                                |
| `sarif`      | Уведомление уровня `note` в `runs[0].invocations[0].toolExecutionNotifications[]` с дескриптором `QMX-DRILL-DOWN-OUT-OF-SCOPE` |
| `gitlab`     | Отказ с кодом 3 до анализа: виджет merge request считает каждую запись проблемой                                               |
| `checkstyle` | Отказ с кодом 3 до анализа: читатель Checkstyle считает каждую запись ошибкой                                                  |
| `github`     | Строка `::notice title=drill-down.out-of-scope::`                                                                              |
| `html`       | Баннер над отчётом                                                                                                             |
| `suppressed` | Ничего: его документ — состав подавлений всего прогона, выборка его не сужает                                                  |

`json` и `metrics` несут `outOfScope` в каждом документе: `null` без выборки и
нулевые счётчики, когда вне выборки ничего не осталось. `sarif`, `github` и
`html` добавляют свою запись, только когда вне выборки что-то есть. Код возврата
вычисляется по выборке и `outOfScope` вместе, поэтому чистая выборка может
завершиться с кодом 2.

**Режим детализации с `--detail`:**

```bash
# Добавить группированный список нарушений (лимит по умолчанию: 200)
bin/qmx check src/ --detail

# Показать все нарушения (без лимита)
bin/qmx check src/ --detail=all

# Пользовательский лимит
bin/qmx check src/ --detail=50
```

`--detail` включает список нарушений с необязательным потолком; он ничего не
ранжирует. `--detail=N` показывает первые N нарушений в том порядке, в каком
список печатается (по файлу, если `--group-by` не задаёт другого), — то есть
всегда первые N из тех, что напечатал бы `--detail=all`. `--detail=0` равен
`--detail=all`. Любое другое значение (`--detail=abc`, `--detail=-1`)
отклоняется с кодом 3 до начала анализа. Ранжированный раздел
`Top issues by impact` — это `--top`.

!!! note
    `--detail` включается автоматически при использовании `--namespace` или `--class`. Флаг также работает с `--format=text`: добавляет группированный список нарушений после компактного построчного вывода.

---

## text

Компактный вывод, одна строка на нарушение. Совместим с форматом ошибок GCC/Clang, поэтому нарушения кликабельны в большинстве терминалов и IDE.

**Когда использовать:** Локальная разработка, быстрые проверки, передача в `grep` или `wc`.

**Пример вывода:**

```
src/Repository/OrderRepository.php: error[coupling.class-rank]: ClassRank is 0.6491, exceeds threshold of 0.3536 (scaled for 2 classes). This class is a critical hub — changes have wide impact (OrderRepository)
src/Repository/OrderRepository.php: warning[complexity.ccn]: Cyclomatic complexity is 10, exceeds threshold of 10. Consider extracting methods or simplifying conditions (OrderRepository::findByCriteria)
src/Service/UserService.php:9: warning[code-smell.error-suppression]: Error suppression (@) on file_get_contents() - handle errors explicitly
src/Service/UserService.php: warning[complexity.ccn]: Cyclomatic complexity is 14, exceeds threshold of 10. Consider extracting methods or simplifying conditions (UserService::calculate)

Qualimetrix 0.26.0: 1 error(s), 3 warning(s) in 2 file(s)
Analysis complete: 2 analyzed, 0 generated file(s) excluded.
Technical debt: 1h 40min
Docs: https://qualimetrix.dev · AI agents: https://qualimetrix.dev/llms.txt
```

**Формат строки:** есть три формы строк — в зависимости от того, к чему относится находка.

- Нарушение, привязанное к конкретному оператору, несёт номер строки: `файл:строка: уровень[кодНарушения]: сообщение (символ)`.
- Находка уровня класса или метода, где правило судит о декларации целиком, а не об одном операторе — например, `complexity.ccn`, `complexity.wmc`, `coupling.class-rank` — вместо этого опускает сегмент строки: `файл: уровень[кодНарушения]: сообщение (символ)`, хотя та же находка несёт `line` в `--format=json`.
- Находка уровня проекта (у неё вообще нет владеющего файла — например, `architecture.unreachable-layer`) опускает и сегмент файла: `[project]: уровень[кодНарушения]: сообщение`, без завершающего `(символ)`. На самоанализе этого проекта третья форма — не редкий случай: `bin/qmx check src/Analysis/Evidence/Complexity --format=text` печатает строки уровня проекта для большинства вывода.

---

## text-verbose

<!-- llms:skip-begin -->
!!! warning "Устарело"
    `text-verbose` устарел. Используйте вместо него `--format=text --detail`, который обеспечивает аналогичный группированный многострочный вывод нарушений.

    ```bash
    # Замена: bin/qmx check src/ --format=text-verbose
    bin/qmx check src/ --format=text --detail
    ```
<!-- llms:skip-end -->
<!-- llms-only
Устарел. Используйте `--format=text --detail`.
-->

---

## json

Машиночитаемый JSON-вывод. Формат, ориентированный на сводную информацию: оценки здоровья, худшие нарушители и все нарушения.

**Когда использовать:** Пользовательские скрипты, дашборды, программная обработка.

**Ключи верхнего уровня:** `meta`, `summary`, `outOfScope`, `coverage`, `projectScope` (см. [Охват проекта во всех форматах](#project-scope-in-every-format)), `health`, `worstNamespaces`, `worstClasses`, `topIssues`, `violations`, `violationsMeta`, плюс `violationGroups`, когда передан `--group-by` — без него ключа нет вовсе, это не пустой объект.

`meta` называет инструмент, записавший документ: `version`, `package`, `timestamp` и два адреса документации — `docs`, сайт документации, и `llmsTxt`, индекс для ИИ-агентов. Оба адреса есть в каждом JSON-отчёте, у которого есть объект-конверт; см. исключения в [Адреса документации в JSON-отчётах](#documentation-addresses).

<!-- llms:skip-begin -->
**Пример вывода:**

```json
{
    "meta": {
        "version": "1.0.0",
        "package": "qmx",
        "timestamp": "2025-01-15T10:30:00+00:00",
        "docs": "https://qualimetrix.dev",
        "llmsTxt": "https://qualimetrix.dev/llms.txt"
    },
    "summary": {
        "filesAnalyzed": 45,
        "filesSkipped": 0,
        "duration": 1.234,
        "violationCount": 3,
        "errorCount": 2,
        "warningCount": 1,
        "infoCount": 0,
        "techDebtMinutes": 270,
        "debtPer1kLoc": 2.1
    },
    "outOfScope": null,
    "projectScope": {"state": "covered", "uncoveredAutoloadTargets": [], "unjudgedChannels": [], "unjudgedValues": []},
    "health": {
        "complexity": {
            "score": 78.0,
            "label": "Excellent",
            "threshold": {"warning": 50, "error": 25},
            "coverage": {
                "state": "measured",
                "measured": 2263,
                "eligible": 2263,
                "ratio": 1.0,
                "unit": "callables",
                "basis": "complexity.ccn.count",
                "reason": null
            },
            "decomposition": [
                {
                    "metric": "complexity.ccn.sum",
                    "humanName": "Cyclomatic complexity",
                    "value": 412,
                    "good": true,
                    "direction": "lower-is-better"
                }
            ],
            "worstContributors": [
                {
                    "symbolPath": "App\\Service\\UserService",
                    "className": "App\\Service\\UserService",
                    "metrics": {"complexity.ccn.sum": 96}
                }
            ]
        },
        "overall": {
            "score": 72.0,
            "label": "Fair",
            "threshold": {"warning": 50, "error": 25},
            "coverage": {
                "state": "not-applicable",
                "measured": null,
                "eligible": null,
                "ratio": null,
                "unit": null,
                "basis": null,
                "reason": "health.overall composes the other dimensions; each of them publishes its own coverage"
            },
            "decomposition": [],
            "worstContributors": []
        }
    },
    "worstNamespaces": [
        {
            "symbolPath": "App\\Service",
            "healthOverall": 52.0,
            "label": "Poor",
            "reason": "high coupling",
            "violationCount": 15,
            "size.class-count": 8,
            "healthScores": {}
        }
    ],
    "worstClasses": [
        {
            "symbolPath": "App\\Service\\UserService",
            "healthOverall": 45.0,
            "label": "Poor",
            "reason": "low cohesion",
            "violationCount": 8,
            "file": "src/Service/UserService.php",
            "metrics": {},
            "healthScores": {}
        }
    ],
    "topIssues": [
        {
            "rank": 1,
            "file": "src/Service/UserService.php",
            "line": 42,
            "symbol": "App\\Service\\UserService::calculate",
            "rule": "complexity.ccn",
            "severity": "error",
            "message": "Cyclomatic complexity: 15 (threshold: 10) — too many code paths",
            "recommendation": null,
            "impactScore": 3.71,
            "coupling.class-rank": 0.1237,
            "debtMinutes": 30
        }
    ],
    "violations": [
        {
            "file": "src/Service/UserService.php",
            "line": 42,
            "subject": "declaration:callable:App\\Service\\UserService::calculate@src/Service/UserService.php",
            "symbol": "App\\Service\\UserService::calculate",
            "channel": "complexity.ccn",
            "occurrence": null,
            "edge": null,
            "namespace": "App\\Service",
            "rule": "complexity.ccn",
            "code": "complexity.ccn",
            "severity": "error",
            "message": "Cyclomatic complexity: 15 (threshold: 10) — too many code paths",
            "recommendation": null,
            "metricValue": 15,
            "threshold": 10,
            "techDebtMinutes": 30,
            "acceptedLevel": null
        }
    ],
    "violationsMeta": {
        "total": 3,
        "shown": 3,
        "limit": null,
        "truncated": false,
        "byRule": {
            "complexity.ccn": 2,
            "coupling.cbo": 1
        }
    },
    "violationGroups": {}
}
```
<!-- llms:skip-end -->

Записи `worstNamespaces` и `worstClasses` включают поле `violationDensity` -- количество нарушений на 100 строк кода -- для нормализованной по размеру оценки качества кода.

`topIssues` — тот же ранжированный список, что формат `summary` печатает как
«Top issues by impact»; другие форматы его не выводят. Каждая запись называет
`impactScore`, специфичный для правила и используемый для ранжирования, и
оценку `debtMinutes`. Ключ `coupling.class-rank` присутствует всегда, но его
значение равно `null`, если правило-производитель не читает сигнал коупл-хаба;
`file` и `line` тоже могут быть `null` — у находки уровня проекта нет позиции
в исходнике.

Каждое нарушение несёт `acceptedLevel`: потолок baseline, относительно
которого эта находка измерена, или `null`, когда у находки нет собственного
принятого уровня. За этим значением стоят два разных случая, которые оно не
различает: baseline вообще не настроен для прогона, либо baseline настроен,
но именно эту находку он ещё не оценивал (новая находка). Ни JSON-payload, ни
само поле `acceptedLevel` не говорят, какой из случаев перед вами.

Когда значение не равно null, `acceptedLevel` — это объект: находка, чья
собственная группа идентичности превысила принятый уровень, публикуется как
прорыв и несёт `{"shape": "occurrence", "describe": "2 occurrences", "count": 2}`.
`shape` называет, что именно считает потолок, `count` — принятое количество,
`describe` — то же число словами. Прорыв к тому же повышается до severity
`error`, что бы ни сообщило правило само по себе.
`violationsMeta` также сообщает `shown` — число нарушений, фактически
включённых в этот payload; оно может быть меньше `total`, когда
`--format-opt=violations=N` обрезает список. Обрезанный список — это первые N
в порядке идентичности, описанном ниже.

`message` и `recommendation` значат одно и то же в `violations` и в
`topIssues`: сообщение находки и её рекомендацию или `null`. При
`--namespace`/`--class` объект `summary` сохраняет все свои ключи и считает
только выборку; `debtPer1kLoc` в нём равен `null`, потому что долг выборки на
строки всего проекта смешал бы две области. Находки, оставшиеся вне выборки,
посчитаны в `outOfScope`; без выборки он равен `null` — как и у остальных
форматов, это показано в таблице детализации в разделе `summary` выше.

Когда имя символа из анализируемого кода — невалидный UTF-8 (парсер принимает в
идентификаторе любой байт выше 0x7F), каждый невалидный байт публикуется как
U+FFFD, а документ получает ключ верхнего уровня `invalidUtf8Replaced` с числом
исправленных строк. `metrics`, `suppressed` и нагрузка `html` делают то же;
`sarif` сообщает об этом уведомлением инструмента
`QMX-PUBLICATION-INVALID-UTF8`, `gitlab` — записью `publication.invalid-utf8`,
`checkstyle` — ошибкой под синтетическим файлом `[publication]`. Путь к файлу,
не являющийся валидным UTF-8, исправляется и помечается так же; `sarif`
исправляет его до процентного кодирования, поэтому URI артефакта несёт
`%EF%BF%BD`, а не голый `%FF`.

Для машинной идентичности используй `channel + subject + optional occurrence +
optional edge`. `symbol` — логическая проекция для отображения; строка исходника,
сообщение и порядок вывода не являются стабильной идентичностью. `subject`
различает точные декларации, логические и агрегатные subjects, `occurrence`
различает семантические свидетельства внутри канала, а `edge` содержит
обязательную цель зависимости и необязательный `type` ссылки. Нетипизированное
ребро имеет вид `{"target": "class:App\\Dependency"}`, типизированное —
`{"type": "new", "target": "class:App\\Dependency"}`. Fingerprints форматтеров
используют ту же комбинацию, поэтому target-only рёбра различаются по цели и
отличаются от типизированного ребра к той же цели. Существующие fingerprints
без ребра и с полностью типизированным ребром не меняются.

При использовании `--group-by=class` или `--group-by=namespace` нарушения организуются в объект `violationGroups`. Каждая группа — это `{count, violations}`: счётчик нарушений и их массив; собственных `errorCount`, `warningCount` или `violationDensity` у группы нет.

Ключи группы — не всегда FQCN класса или пространство имён. Для `--group-by=class`: ключ — это FQCN класса для находки уровня класса, путь к файлу для находки уровня файла без контекста класса, и пустая строка `""` для находки уровня проекта (у неё нет ни класса, ни файла). Для `--group-by=namespace`: ключ — это пространство имён для класса внутри него, `<global>` для класса без пространства имён, и `(project)` для находки уровня проекта.

<!-- llms:skip-begin -->
```json
{
    "violationGroups": {
        "App\\Service\\UserService": {
            "count": 3,
            "violations": [...]
        }
    }
}
```
<!-- llms:skip-end -->

**Опции:**

```bash
# Ограничить количество нарушений в выводе (по умолчанию: все)
bin/qmx check src/ --format=json --format-opt=violations=50

# Управление количеством худших нарушителей (по умолчанию: 10)
bin/qmx check src/ --format=json --format-opt=top=20
```

Каждое значение `--format-opt` разбирается до начала анализа, одной грамматикой
на ключ, какой бы формат его ни читал: `violations` и `limit` принимают целое
число или `all`, `top` — целое число от 1, `contributors` — целое число,
`rank-by` — `count` или `density`, `project-name` — непустое имя. Значение,
которое не разбирается, отклоняется с кодом 3, а не заменяется значением по
умолчанию. `violations` и `limit` ограничивают один и тот же список —
`violations=0` не показывает ничего, `limit=0` показывает всё, — поэтому оба
вместе или `limit` рядом с `--all` отклоняются с кодом 3, а не игнорируется
один из них.

```bash
# Группировка нарушений по классу или пространству имён
bin/qmx check src/ --format=json --group-by=class
bin/qmx check src/ --format=json --group-by=namespace
```

**Использование в CI:**

```bash
bin/qmx check src/ --format=json --no-progress > report.json
```

---

## metrics

Необработанные значения метрик для каждого символа (файл, класс, пространство имён, метод, функция, проект). В отличие от `json`, который выводит нарушения, `metrics` экспортирует исходные данные метрик, которые оценивают правила.

**Когда использовать:** Пользовательские дашборды, анализ трендов, пайплайны data science или создание собственных критериев качества на основе сырых метрик.

**Ключи верхнего уровня:** `version`, `toolVersion`, `package`, `timestamp`, `docs`, `llmsTxt`, `symbols[]` (каждый с `type`: file/class/namespace/method/function/project, `name`, `file`, `line`, `metrics: {...}`), `outOfScope`, `projectScope`, `coverage`, `summary`. При `--namespace`/`--class` `summary` считает только выборку, а `outOfScope` — то, что осталось вне её; `symbols[]` выборка не сужает никогда. Типа `callable` не существует; одна запись `project` агрегирует статистические метрики по всему проекту (min/max/avg/p95 по всем символам) и имеет `line` равным `null`. Здесь `version` — версия формата этой выгрузки, а `toolVersion` — версия Qualimetrix; `docs` и `llmsTxt` — те же адреса документации, что `json` публикует в `meta`.

<!-- llms:skip-begin -->
**Пример вывода (сокращённо):**

```json
{
    "version": "1.0.0",
    "toolVersion": "0.26.0",
    "package": "qmx",
    "timestamp": "2025-01-15T10:30:00+00:00",
    "docs": "https://qualimetrix.dev",
    "llmsTxt": "https://qualimetrix.dev/llms.txt",
    "symbols": [
        {
            "type": "file",
            "name": "src/Service/UserService.php",
            "file": "src/Service/UserService.php",
            "line": 1,
            "metrics": {
                "size.loc": 150,
                "size.lloc": 120,
                "size.class-count": 1
            }
        },
        {
            "type": "class",
            "name": "App\\Service\\UserService",
            "file": "src/Service/UserService.php",
            "line": 10,
            "metrics": {
                "size.method-count": 8,
                "size.property-count": 3,
                "cohesion.lcom": 2,
                "complexity.wmc": 35,
                "coupling.ca": 5,
                "coupling.ce": 12,
                "coupling.cbo": 17,
                "coupling.instability": 0.71
            }
        },
        {
            "type": "method",
            "name": "App\\Service\\UserService::calculate",
            "file": "src/Service/UserService.php",
            "line": 42,
            "metrics": {
                "complexity.ccn": 15,
                "complexity.cognitive": 22,
                "maintainability.halstead.volume": 384.5,
                "size.loc": 35
            }
        }
    ],
    "projectScope": {"state": "covered", "uncoveredAutoloadTargets": [], "unjudgedChannels": [], "unjudgedValues": []},
    "summary": {
        "filesAnalyzed": 45,
        "filesSkipped": 0,
        "duration": 1.234,
        "violations": 3,
        "errors": 2,
        "warnings": 1,
        "info": 0
    },
    "outOfScope": null
}
```
<!-- llms:skip-end -->

**Использование:**

```bash
bin/qmx check src/ --format=metrics --no-progress > metrics.json
```

!!! note
    Формат `metrics` экспортирует **все собранные метрики**, а не только те, которые вызвали нарушения. Это делает его полезным для отслеживания трендов метрик со временем, даже для кода, который проходит все правила.

!!! info "Строки на уровнях namespace и project считаются по разным популяциям"
    `size.loc`, `size.lloc` и `size.cloc` на двух агрегатных уровнях считают разное. Неймспейс считает строки внутри своей инструкции `namespace`; проект считает каждый проанализированный файл целиком, включая открывающий тег, комментарии-заголовки и `declare` над неймспейсом. Поэтому `size.loc.sum` проекта больше суммы `size.loc` по неймспейсам, и это не ошибка округления или агрегации. Внутри дерева неймспейсов суммы сходятся: `size.loc.sum` неймспейса равен его собственному `size.loc` плюс `size.loc.sum` его детей.

---

## checkstyle

Формат Checkstyle XML. Широко поддерживается CI-инструментами.

**Когда использовать:** Jenkins, SonarQube или любой инструмент, принимающий Checkstyle XML.

Checkstyle 3.0 XML: `<file name="...">` с вложенными `<error line="" severity="error|warning|info" message="" source="qmx.<rule>"/>`.

Выборка `--namespace`/`--class` с этим форматом отклоняется (код 3): каждый
`<error>` для его читателя — ошибка, поэтому сказать, что отчёт перечисляет
лишь часть прогона, было бы нечем.

<!-- llms:skip-begin -->
**Пример вывода:**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<checkstyle version="3.0">
  <file name="src/Service/UserService.php">
    <error line="42"
           severity="error"
           message="Cyclomatic complexity is 15, max allowed is 10"
           source="qmx.complexity.ccn"/>
    <error line="87"
           severity="warning"
           message="Class has 22 methods, max recommended is 20"
           source="qmx.size.method-count"/>
  </file>
</checkstyle>
```

**Использование в CI (Jenkins):**

```bash
bin/qmx check src/ --format=checkstyle --no-progress > checkstyle.xml
```
<!-- llms:skip-end -->

---

## sarif

SARIF (Static Analysis Results Interchange Format) 2.1.0. Стандартный формат для инструментов статического анализа, принятый GitHub, Microsoft и многими производителями IDE.

**Когда использовать:** Вкладка Security на GitHub, VS Code (с расширением SARIF Viewer), JetBrains IDE, Azure DevOps.

SARIF 2.1.0: `runs[].results[]` с `ruleId`, `ruleIndex` (позиция правила в `tool.driver.rules`), `level` (error/warning/note), `message.text`, `partialFingerprints.primaryLocationLineHash`, и `locations[].physicalLocation.{artifactLocation.{uri,uriBaseId}, region.{startLine,startColumn}}`. `locations` есть не у каждой записи: у находки уровня проекта без позиции в исходнике (например, `architecture.unreachable-layer`) массива `locations` вообще нет.

`runs[].invocations[0]` сообщает `executionSuccessful` (см. таблицу coverage ниже), а `runs[].originalUriBaseIds` объявляет базу `%SRCROOT%`, на которую ссылается каждый `artifactLocation.uriBaseId`, разрешая её в корень анализируемого проекта как `file://`-URI. Каждый `artifactLocation.uri` — относительная ссылка в процентной кодировке (пробел — `%20`, `#` — `%23`), закодированная так же, как эта база. Связанное место без файла несёт `message` и не несёт `physicalLocation`.

`runs[].tool.driver` описывает сам инструмент: `name`, `version`,
`informationUri` (сайт документации, `https://qualimetrix.dev`), мешок
`properties`, в котором `properties.llmsTxt` — индекс для ИИ-агентов, и каталог
`rules[]`, в который указывает `ruleIndex` каждой записи. Адрес лежит в
`properties`, потому что SARIF закрывает `driver` для ключей, которых не
определяет сам, а `properties` — его точка расширения. Каждая запись каталога —
`{"id": "...", "name": "...", "shortDescription": {"text": "..."}, "fullDescription": {"text": "..."}, "helpUri": "...", "defaultConfiguration": {"level": "..."}}`.

Каждая запись `runs[].invocations[]` —
`{"executionSuccessful": true, "toolExecutionNotifications": []}`, а каждое
уведомление в ней —
`{"descriptor": {"id": "..."}, "level": "...", "message": {"text": "..."}}`;
именно здесь сообщается о файле, который не удалось разобрать.
`runs[].originalUriBaseIds` отображает `%SRCROOT%` в
`{"uri": "file:///path/to/project/"}`.

`partialFingerprints.primaryLocationLineHash` важен не только для полноты
спецификации: именно по нему де-дупликация алертов code-scanning на GitHub
между прогонами определяет, что это один и тот же отслеживаемый алерт, а не
новый — пока фингерпринт остаётся стабильным.

<!-- llms:skip-begin -->
**Пример вывода (сокращённо):**

```json
{
    "$schema": "https://raw.githubusercontent.com/oasis-tcs/sarif-spec/main/sarif-2.1/schema/sarif-schema-2.1.0.json",
    "version": "2.1.0",
    "runs": [
        {
            "tool": {
                "driver": {
                    "name": "Qualimetrix",
                    "version": "0.26.0",
                    "informationUri": "https://qualimetrix.dev",
                    "properties": {
                        "llmsTxt": "https://qualimetrix.dev/llms.txt"
                    },
                    "rules": [...]
                }
            },
            "results": [
                {
                    "ruleId": "complexity.ccn",
                    "ruleIndex": 0,
                    "level": "error",
                    "message": {
                        "text": "Cyclomatic complexity is 15, max allowed is 10"
                    },
                    "partialFingerprints": {
                        "primaryLocationLineHash": "complexity.ccn:declaration:callable:App\\Service\\UserService::calculate@src/Service/UserService.php:4e6e45ba70fb46d4"
                    },
                    "locations": [
                        {
                            "physicalLocation": {
                                "artifactLocation": {
                                    "uri": "src/Service/UserService.php",
                                    "uriBaseId": "%SRCROOT%"
                                },
                                "region": {
                                    "startLine": 42,
                                    "startColumn": 1
                                }
                            }
                        }
                    ]
                }
            ]
        }
    ]
}
```

**Использование в CI (GitHub Actions):**

```yaml
- name: Run Qualimetrix
  run: bin/qmx check src/ --format=sarif --no-progress > results.sarif

- name: Upload SARIF to GitHub Security
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: results.sarif
```

Результаты появятся во вкладке **Security** вашего репозитория и как инлайн-аннотации в пулл-реквестах.
<!-- llms:skip-end -->

---

## gitlab

Формат GitLab Code Quality JSON. Показывает нарушения прямо в диффах Merge Request.

**Когда использовать:** GitLab CI/CD с отчётами Code Quality.

Массив объектов с `description`, `check_name`, `fingerprint`, `severity` (critical/major/info), `location.{path,lines.begin}`. Маппинг: error → critical, warning → major, info → info.

Выборка `--namespace`/`--class` с этим форматом отклоняется (код 3): каждая
запись — проблема в виджете merge request, поэтому сказать, что отчёт
перечисляет лишь часть прогона, было бы нечем.

<!-- llms:skip-begin -->
**Пример вывода (сокращённо):**

```json
[
    {
        "description": "Cyclomatic complexity is 15, max allowed is 10",
        "check_name": "complexity.ccn",
        "fingerprint": "a1b2c3d4e5f6...",
        "severity": "critical",
        "location": {
            "path": "src/Service/UserService.php",
            "lines": {
                "begin": 42
            }
        }
    }
]
```

**Использование в CI (GitLab CI):**

```yaml
code_quality:
  stage: test
  script:
    - bin/qmx check src/ --format=gitlab --no-progress > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

Нарушения появятся инлайн во вкладке **Changes** вашего Merge Request.
<!-- llms:skip-end -->

---

## github

Формат workflow-команд GitHub Actions. Создаёт инлайн-аннотации, которые отображаются прямо в диффах пулл-реквестов при запуске в GitHub Actions.

**Когда использовать:** GitHub Actions CI. Проще в настройке, чем SARIF — не нужен шаг загрузки.

Формат workflow-команд: `::<level> file=<path>,line=<n>,title=<rule>::<message>` (по строке на нарушение). Маппинг: warning → `::warning`, error → `::error`.

На каждой строке присутствует только `title=`. У находки уровня проекта нет
позиции в исходнике, поэтому она печатается как
`::<level> title=<rule>::<message>` — без `file=` и `line=`; GitHub тогда
показывает её у прогона workflow, а не у строки в диффе.

<!-- llms:skip-begin -->
**Пример вывода:**

```
::warning file=src/Service/UserService.php,line=87,title=size.method-count::Class has 22 methods, max recommended is 20
::error file=src/Service/UserService.php,line=42,title=complexity.ccn::Cyclomatic complexity is 15, max allowed is 10
```

**Использование в CI (GitHub Actions):**

```yaml
- name: Run Qualimetrix
  run: vendor/bin/qmx check src/ --format=github --no-progress
```

Аннотации появляются прямо на изменённых строках вашего пулл-реквеста — загрузка SARIF не требуется. По умолчанию `--fail-on=error` — предупреждения не блокируют сборку.
<!-- llms:skip-end -->

!!! tip "Совет"
    Используйте `--format=github` для быстрых инлайн-аннотаций. Используйте `--format=sarif`, если также хотите видеть результаты во вкладке Security на GitHub.

---

## health

Текстовая таблица оценок здоровья для терминального вывода. Показывает каждое измерение с оценкой, статусом, порогами и деталями декомпозиции.

**Когда использовать:** Быстрая проверка здоровья из CLI, рабочие процессы AI-агентов, диагностика пайплайнов.

**Основные возможности:**

- Табличное отображение всех измерений здоровья (сложность, связность, связанность, типизация, сопровождаемость)
- Цветовая индикация статуса (зелёный/жёлтый/красный)
- Видимость порогов (предупреждение и ошибка)
- Декомпозиция по каждому измерению
- Колонка `Coverage` и по одной строке `Computed over N of M ...` на измерение внутри декомпозиции — доля предмета, о которой говорит эта оценка (см. [Что покрывает оценка](../reference/health-scores.ru.md#what-a-score-covers))
- Поддержка drill-down через `--namespace` и `--class`

**Худшие участники по измерениям:**

Вывод health включает худших участников для каждого измерения -- классы или пространства имён, которые больше всего снижают каждую оценку здоровья. Управляйте количеством отображаемых участников через `--format-opt=contributors=N` (по умолчанию: 3):

```bash
bin/qmx check src/ --format=health --format-opt=contributors=5
```

**Использование:**

```bash
bin/qmx check src/ --format=health
bin/qmx check src/ --format=health --namespace='subtree:App\Service'
```

---

## html

Интерактивный отчёт в виде treemap с визуализацией D3.js. Генерирует самодостаточный HTML-файл с иерархией пространств имён и классов.

**Когда использовать:** Визуализация всего проекта, отчёты для заинтересованных сторон, командные ревью.

**Основные возможности:**

- Иерархия пространств имён и классов с размерами, пропорциональными LOC
- Цветовая кодировка оценок здоровья для каждого узла
- Переход вглубь пространств имён по клику
- Панель деталей с метриками, нарушениями и декомпозицией
- Покрытие рядом с каждым проектным баром здоровья (`n/a`, когда покрытие не определено) — из объекта `summary.healthCoverage`, который нагрузка несёт рядом с `summary.healthScores`
- Каждое нарушение отчёта висит на узле дерева, поэтому счётчики дерева сходятся с `summary.totalViolations`: нарушение без собственного узла класса или пространства имён — проектное, файловое в файле без класса или с несколькими, глобальная функция вне пространства имён — показывается на корне проекта
- Отчёт назван по анализируемому проекту: `--format-opt=project-name=...`, иначе `name` из его `composer.json`, иначе имя его каталога
- Самодостаточный HTML-файл (без внешних зависимостей)

**Использование:**

```bash
bin/qmx check src/ --format=html -o report.html
```

**Пример рабочего процесса:**

```bash
# Сгенерировать и открыть отчёт
bin/qmx check src/ --format=html -o report.html
open report.html  # macOS
xdg-open report.html  # Linux
```

!!! note
    Флаг `-o` (output) рекомендуется при использовании формата `html`. Без него HTML-содержимое выводится в stdout.

---

## suppressed

Машиночитаемый JSON-состав того, что прогон исключил из отчёта и почему.
Отдельный формат, а не секция `json`: обычный payload `check` не меняет форму
из-за возможности, которую вы не запрашивали, каким бы форматом вы его ни
выбрали.

**Когда использовать:** разобраться, почему ожидаемое нарушение отсутствует в
отчёте; проверить, что именно молчаливо исключает настройка `qmx.yaml`; найти
неработающую запись `suppress_paths`/`suppress_namespaces` (опечатку в пути,
удалённый файл).

**Захват включается двумя независимыми способами** — флагом
`--show-suppressed` или выбором `--format=suppressed`, в том числе через
`format: suppressed` в `qmx.yaml`. Оба пути включают один и тот же захват
пер-рулевого исключения, поэтому счётчики по этому механизму на обеих
поверхностях никогда не расходятся.

**В остальном эти две поверхности не эквивалентны.** `--show-suppressed` на
`--format=text` печатает прозой инлайновые подавления `@qmx-ignore` и
пер-рулевые исключения. Глобальные `path-suppression` и `namespace-suppression`
там видны только как счётчик под `-v`, а не по находкам; снятия `baseline` и
`git-scope` не выводятся вовсе; текстового аналога `neverMatched` нет.
`suppressed` — единственная поверхность, публикующая все семь механизмов
по отдельным находкам.

**Состав — это мультимножество, а не множество находок.** Одна находка может
попасть под несколько механизмов сразу — например, находку, которую убрал бы
инлайновый `@qmx-ignore`, могло раньше убрать исключение по неймспейсу. Всего
семь механизмов: `suppression` (инлайновые `@qmx-ignore`/`@qmx-ignore-file`/
`@qmx-ignore-next-line`), `path-suppression` и `namespace-suppression` (глобальные
`suppress_paths`/`suppress_namespaces`), `baseline` (потолок принятого уровня),
`git-scope` (сужение `--report=git:*`) и две половины пер-рулевого леджера
исключений, настраиваемого под ключом `rules: {<имя-правила>: {...}}` —
`rule-namespace-suppression` и `rule-path-suppression`. `byMechanism` считает
записи по каждому механизму отдельно; поскольку одна и та же находка может
попасть под несколько механизмов, эти счётчики **не складываются** в число
различных подавленных находок — об этом прямо говорит поле `note` самого
формата.

Отдельный список `neverMatched` показывает настроенные подавители, не
исключившие в этом прогоне ничего: без него устаревшую запись `suppress_paths`,
указывающую на удалённый файл, невозможно отличить от записи, которую вообще
никогда не писали.

`meta` — тот же блок, что у `json`, включая `docs` и `llmsTxt`.

**Ключи верхнего уровня:** `meta`, `note`, `coverage` (тот же объект, что у
`json`, — аудит неполного прогона говорит, что он неполон), `projectScope`
(тот же объект, что у `json`, — аудит суженного прогона называет каналы
подавлений, которые не судились), `mechanisms` (все
семь, всегда присутствуют), `byMechanism` (счётчик на каждый механизм, включая
нулевые), `suppressed` (само мультимножество), `neverMatched`.

Каждая запись `suppressed` несёт идентичность, которую публикует `json`, —
`channel` (здесь это код находки), `subject`, `occurrence`, `edge`, — чтобы её
можно было машинно сопоставить с записью `json`, и `message` и `recommendation`
находки под теми же ключами, что в `json`. Полей `metricValue`, `threshold`,
`techDebtMinutes` и `acceptedLevel` в ней нет: формат проверяет, что удержало
находку, а идентичность ведёт к её собственной записи.

<!-- llms:skip-begin -->
**Пример вывода (сокращённый, из самоанализа этого проекта):**

```json
{
    "meta": {
        "version": "dev-main",
        "package": "qmx",
        "timestamp": "2026-08-29T09:14:02+00:00",
        "docs": "https://qualimetrix.dev",
        "llmsTxt": "https://qualimetrix.dev/llms.txt"
    },
    "note": "suppressed is a multiset of mechanism x finding, not a set of findings: one finding can appear under more than one mechanism, so byMechanism counts do not sum to the number of distinct findings suppressed.",
    "coverage": {
        "complete": true,
        "discovered": 1204,
        "analyzed": 1204,
        "generatedExcluded": 0,
        "failed": 0,
        "failures": []
    },
    "projectScope": {"state": "covered", "uncoveredAutoloadTargets": [], "unjudgedChannels": [], "unjudgedValues": []},
    "mechanisms": [
        "suppression",
        "path-suppression",
        "namespace-suppression",
        "baseline",
        "git-scope",
        "rule-namespace-suppression",
        "rule-path-suppression"
    ],
    "byMechanism": {
        "suppression": 12,
        "path-suppression": 0,
        "namespace-suppression": 0,
        "baseline": 0,
        "git-scope": 0,
        "rule-namespace-suppression": 58,
        "rule-path-suppression": 131
    },
    "suppressed": [
        {
            "mechanism": "suppression",
            "suppressor": "src/Infrastructure/Ast/CachedFileParser.php:15",
            "rule": "code-smell.empty-catch",
            "channel": "code-smell.empty-catch",
            "subject": "aggregate:file:src/Infrastructure/Ast/CachedFileParser.php",
            "occurrence": "6f1c0e9b2a4d7e35",
            "edge": null,
            "file": "src/Infrastructure/Ast/CachedFileParser.php",
            "line": 73,
            "symbol": "src/Infrastructure/Ast/CachedFileParser.php",
            "severity": "error",
            "message": "Empty catch block detected - exceptions should not be silently ignored",
            "recommendation": "Log or rethrow the exception, or handle it explicitly. A comment alone does not clear this finding; suppress an intentional ignore with `@qmx-ignore code-smell.empty-catch` and a reason."
        },
        {
            "mechanism": "rule-path-suppression",
            "suppressor": "code-smell.constructor-overinjection",
            "rule": "code-smell.constructor-overinjection",
            "channel": "code-smell.constructor-overinjection",
            "subject": "declaration:callable:Qualimetrix\\Analysis\\Run\\Contract\\Collection\\SuccessfulFileProcessing::__construct@src/Analysis/Run/Contract/Collection/SuccessfulFileProcessing.php",
            "occurrence": null,
            "edge": null,
            "file": "src/Analysis/Run/Contract/Collection/SuccessfulFileProcessing.php",
            "line": 28,
            "symbol": "Qualimetrix\\Analysis\\Run\\Contract\\Collection\\SuccessfulFileProcessing::__construct",
            "severity": "warning",
            "message": "Constructor of SuccessfulFileProcessing has 8 parameters (threshold 8). Consider using a parameter object or splitting responsibilities",
            "recommendation": "Constructor parameters: 8 (threshold: 8) — consider splitting responsibilities"
        }
    ],
    "neverMatched": [
        {
            "mechanism": "rule-path-suppression",
            "suppressor": "coupling.cbo: src/Analysis/Evidence/Design/*Visitor.php"
        }
    ]
}
```

Для двух механизмов леджера (`rule-namespace-suppression`,
`rule-path-suppression`) `suppressor` называет правило-производитель; для
`path-suppression`/`namespace-suppression` — сработавший настроенный паттерн; для
`suppression` — `файл:строка` директивы; для `baseline` — описание принятой
записи; для `git-scope` — настроенную git-ссылку.
<!-- llms:skip-end -->

**Использование:**

```bash
bin/qmx check src/ --format=suppressed --no-progress > suppressed.json
```

---

## Адреса документации в JSON-отчётах {#documentation-addresses}

Каждый JSON-отчёт из таблицы ниже называет, где лежит документация, чтобы
скрипт или ИИ-агент, у которого есть только вывод, нашёл остальное: `docs` —
сайт документации, `llmsTxt` — индекс для ИИ-агентов.

| Документ                                 | Где адреса                                                                             |
| ---------------------------------------- | -------------------------------------------------------------------------------------- |
| `check --format=json`                    | `meta.docs`, `meta.llmsTxt`                                                            |
| `check --format=suppressed`              | `meta.docs`, `meta.llmsTxt`                                                            |
| `check --format=metrics`                 | `docs`, `llmsTxt` верхнего уровня, рядом с `version` (формат выгрузки) и `toolVersion` |
| `check --format=sarif`                   | `runs[].tool.driver.informationUri` и `runs[].tool.driver.properties.llmsTxt`          |
| `directives --format=json`               | `meta.docs`, `meta.llmsTxt`                                                            |
| `baseline:rename-channels --format=json` | `meta.docs`, `meta.llmsTxt`                                                            |
| `debug:layer-assignment --format=json`   | `meta.docs`, `meta.llmsTxt`                                                            |
| `graph:export --format=json`             | `meta.docs`, `meta.llmsTxt`, рядом со своим `meta.version` (формат графа)              |

Три команды вне `check` начинают документ с того же объекта `meta`, что и
`json`, — `version`, `package`, `timestamp`, `docs`, `llmsTxt` — перед своими
собственными ключами. `graph:export --format=json` дополняет тот же блок
`meta`, который уже был в его конверте, сохраняя свой `version` (формат графа,
а не версию инструмента) — так же, как `metrics` сохраняет свой.

Адреса есть не в каждом JSON-выводе. `gitlab` — голый массив, в нём нет объекта
для них; у DOT-вывода `graph:export` нет конверта вовсе; отказ — это всегда
ровно `{"error": ..., "exit_code": ..., "position": ...}`, где `position` равен
`null`, если отказ не привязан к месту в конфигурационном документе: значение
из командной строки, файл целиком и объединённое значение вроде
`memory_limit: 010M` дают `null`, даже когда сообщение называет ключ. Если
`position` есть, он указывает отвергнутое место так, как его нашла проверка:
для обязательного ключа, которого нет, `path` заканчивается этим ключом, а
`written` называет его; а baseline-файл — который пишут
`baseline:generate`, `update`, `cleanup` и переписывает на месте
`baseline:rename-channels` — это версионированный входной артефакт, который
инструмент читает обратно, со своей схемой, а не отчёт.

## Покрытие анализа во всех форматах

Каждая обнаруженная запись классифицируется как проанализированная,
намеренно исключённый generated-файл или ошибка. Запись — это PHP-файл, который
прогон измерил, PHP-файл, который он не смог прочитать, или запись файловой
системы, которую он вообще не открывал: каталог, который нельзя перечислить,
ссылка, по которой он не спускается. Generated-исключения не делают анализ
неполным; любая ошибка делает политический результат неавторитетным. Нуль
найденных файлов всё равно проходит через выбранный форматтер.

| Формат         | Представление coverage                                                                                               |
| -------------- | -------------------------------------------------------------------------------------------------------------------- |
| `summary`      | Текстовая строка coverage после заголовка                                                                            |
| `text`         | Текстовая строка coverage после сводки нарушений                                                                     |
| `text-verbose` | Та же проекция, что и у `text --detail`                                                                              |
| `health`       | Текстовая строка coverage после заголовка                                                                            |
| `json`         | Объект `coverage` верхнего уровня: `complete`, `discovered`, `analyzed`, `generatedExcluded`, `failed`, `failures[]` |
| `metrics`      | Тот же объект `coverage` верхнего уровня, что и в `json`                                                             |
| `sarif`        | `runs[0].invocations[0].executionSuccessful`; ошибки в `toolExecutionNotifications[]`                                |
| `gitlab`       | По blocker-issue на каждый сбой с `check_name: analysis.<kind>`; пустой полный прогон даёт `[]`                      |
| `checkstyle`   | Сбои как errors в синтетическом файле `[analysis]`, source — `qmx.analysis.<kind>`                                   |
| `github`       | По одной `::error`-аннотации на каждый сбой; полный прогон без нарушений не даёт аннотаций                           |
| `html`         | Встроенные данные `coverage`; при неполном анализе также виден warning-banner                                        |
| `suppressed`   | Объект `coverage` верхнего уровня: `complete`, `discovered`, `analyzed`, `generatedExcluded`, `failed`, `failures[]` |

В `json` и `metrics` каждый элемент `failures[]` содержит `path`, `kind` и
`message`. Текстовые форматы различают нуль найденных файлов, только
generated-файлы, полный и неполный анализ.

У `kind` пять значений:

| `kind`                 | Запись                                                                                            |
| ---------------------- | ------------------------------------------------------------------------------------------------- |
| `parse`                | PHP-файл, который не удалось разобрать                                                            |
| `processing`           | PHP-файл, упавший при измерении                                                                   |
| `directory-symlink`    | символическая ссылка на каталог, встреченная внутри сканируемого дерева                           |
| `not-regular-file`     | запись `*.php`, не являющаяся обычным файлом: FIFO, сокет, устройство, ссылка с исчезнувшей целью |
| `unreadable-directory` | каталог, который процессу нельзя перечислить                                                      |

Последние три называют запись, которая так и не стала единицей анализа, поэтому
несут путь этой записи, а не PHP-файла. Незнакомое значение считай записью,
которую прогон не прочитал: список может вырасти, и отказ от всего документа
ради этого знания хуже, чем сообщить о неполном прогоне.

## Охват проекта во всех форматах {#project-scope-in-every-format}

Некоторые каналы утверждают, что настроенное значение ни с чем в проекте не
совпало: слой, которому не принадлежит ни один класс, `exclude:`, не убравший
ни одного каталога, подавление, не называющее ничего. Прогон по части проекта
не может утверждать такое о коде, который не анализировал, поэтому эти каналы
говорят только тогда, когда анализируемые пути покрывают всё, что
`composer.json` объявляет в `autoload` (и в `autoload-dev` с
[`--include-autoload-dev`](cli-options.ru.md#--include-autoload-dev)). Отчёт
сообщает, в каком из трёх состояний был прогон:

| Состояние  | Когда                                                                                                                  | Каналы всего проекта                                                                                                         |
| ---------- | ---------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `covered`  | анализируемые пути содержат каждую объявленную autoload-цель                                                           | судятся                                                                                                                      |
| `narrowed` | какая-то объявленная цель лежит вне анализируемых путей                                                                | не судятся; отчёт называет их и оставленные вне прогона цели                                                                 |
| `unknown`  | `composer.json` отсутствует, не разбирается или не объявляет production-autoload вне `vendor`, `node_modules` и `.git` | судятся, принимая анализируемые пути за весь проект, — кроме значений-неймспейсов каналов подавлений, которые отчёт называет |

Только на прогоне по всему проекту судятся каналы
`architecture.unreachable-layer`, `architecture.empty-template`,
`architecture.unmatched-exclude`, `coupling.unmatched-framework-namespace`,
`discovery.unmatched-exclude`, `suppression.unmatched-path`,
`suppression.unmatched-namespace` и `suppression.unmatched-rule-ledger`.
Отчёт суженного прогона перечисляет их все, независимо от того, включены ли
они в этом прогоне.

`covered` — утверждение об автозагрузке, а не о каждом заданном значении.
Каналы подавлений ещё и судят каждое значение по месту, которое оно называет, и
значение, называющее место вне проанализированных путей, пропускается:
`suppress_paths: [{subtree: tests/Legacy}]` при `qmx check src/`, когда `tests/`
объявлен только в `autoload-dev`, на этом прогоне в состоянии `covered` не
судится. Отчёт называет каждое пропущенное значение в `unjudgedValues`, а его
канал — в `unjudgedChannels`, так что «просужено и привязалось» и «не
просматривалось» читаются по-разному. См.
[правила подавления](../rules/suppression.ru.md).

На проекте в состоянии `unknown` запускайте проверку по всему его коду: более
узкий прогон там судится так, будто он и есть весь проект, и слой, чьи классы
лежат вне названных путей, будет назван не совпавшим ни с чем. Исключение —
значения-неймспейсы глобального `suppress_namespaces` и заданных под правилом
`suppress_namespaces` и `suppress_namespace_channels`: без объявленного автозагрузчика неймспейсу
негде находиться, поэтому на таком проекте они не судятся ни на каком прогоне,
а отчёт называет каждое такое значение в `unjudgedValues`, а его канал —
`suppression.unmatched-namespace` или `suppression.unmatched-rule-ledger` — в
`unjudgedChannels`.

| Формат                                      | Представление охвата проекта                                                                                                              |
| ------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `json`, `metrics`, `suppressed`             | Объект верхнего уровня `projectScope` в каждом документе: `state`, `uncoveredAutoloadTargets[]`, `unjudgedChannels[]`, `unjudgedValues[]` |
| `sarif`                                     | `note` в `runs[0].invocations[0].toolExecutionNotifications[]` с дескриптором `QMX-RUN-PROJECT-SCOPE`                                     |
| `github`                                    | Строка `::notice title=run.project-scope::`                                                                                               |
| `html`                                      | Баннер над отчётом                                                                                                                        |
| `summary`, `text`, `text-verbose`, `health` | Строка `Project scope …` рядом с фразой о покрытии                                                                                        |
| `gitlab`, `checkstyle`                      | Ничего: их потребители считают каждую запись находкой, а сужение прогона — не его дефект                                                  |

У `projectScope` одни и те же ключи в любом состоянии. `uncoveredAutoloadTargets`
пуст, если состояние не `narrowed`. `unjudgedValues` перечисляет каждое
заданное значение подавления, которое прогон в состоянии `covered` или
`unknown` пропустил, в виде `{"option", "pattern"}`: `option` — это
`suppress_paths`, `suppress_namespaces` или `rules.<rule>.<option>`, ключ, под
которым искать, а `pattern` — селектор в записанном виде
(`subtree:tests/Legacy`). `unjudgedChannels` для `narrowed` называет все каналы
всего проекта — там не судилось ни одно значение, и `unjudgedValues` пуст, — а
в остальных состояниях каналы пропущенных значений. Остальные форматы добавляют
запись для `narrowed`, для `unknown` и для прогона в состоянии `covered`,
пропустившего значение. Консоль, кроме того, печатает в stderr предупреждение с
autoload-целями, которые суженный прогон оставил вне анализа.

## Сравнительная таблица

| Формат         | Читаемость    | Машинный    | Группировка                          | Интеграция с CI            |
| -------------- | ------------- | ----------- | ------------------------------------ | -------------------------- |
| `summary`      | Лучшая        | Нет         | Оценки здоровья, drill-down          | Любой (код выхода)         |
| `text`         | Хорошая       | Парсируемый | `--group-by`                         | Любой (код выхода)         |
| `text-verbose` | Хорошая       | Нет         | `--group-by` (по умолч.: file)       | Любой (код выхода)         |
| `json`         | Нет           | Да          | Встроенная (по файлам)               | Скрипты                    |
| `metrics`      | Нет           | Да          | Встроенная (по символам)             | Скрипты, дашборды          |
| `checkstyle`   | Нет           | Да          | Встроенная (по файлам)               | Jenkins, SonarQube         |
| `sarif`        | Нет           | Да          | Встроенная                           | GitHub, VS Code, JetBrains |
| `gitlab`       | Нет           | Да          | Плоский список                       | GitLab MR виджет           |
| `github`       | Нет           | Нет         | Плоский список                       | GitHub Actions аннотации   |
| `health`       | Хорошая       | Нет         | Измерения здоровья                   | Быстрые проверки, CI       |
| `html`         | Интерактивная | Нет         | Иерархия treemap                     | Отчёты, ревью              |
| `suppressed`   | Нет           | Да          | Плоское мультимножество по механизму | Аудит подавления           |

### Коды выхода

Все форматы используют одинаковые коды выхода:

| Код выхода | Значение                                                        |
| ---------- | --------------------------------------------------------------- |
| 0          | Нет нарушений (или только предупреждения при `--fail-on=error`) |
| 1          | Есть предупреждения (при `--fail-on=warning`)                   |
| 2          | Есть хотя бы одно нарушение уровня error                        |
| 3          | Ошибка конфигурации или входных данных                          |
| 4          | Анализ неполон; политический результат неавторитетен            |

По умолчанию `--fail-on=error`: предупреждения отображаются, но не приводят к ненулевому коду выхода. Используйте `--fail-on=warning`, чтобы предупреждения тоже вызывали код выхода 1. Код 4 имеет приоритет над policy-кодами warning/error.

Прогон неполон, когда он не смог прочитать часть дерева, на которое его направили: файл, который не удалось разобрать, каталог, который нельзя перечислить, символическую ссылку на каталог внутри дерева или запись `*.php`, не являющуюся обычным файлом. Раньше такая запись молча отбрасывалась, и подобный прогон отвечал 0 или 2.

Символическая ссылка, **названная как сканируемый путь**, — единственное исключение: `qmx check src/linked` анализирует то, на что ссылка указывает, и прогон полон, тогда как та же ссылка, встреченная при обходе `src/`, сообщается как непрочитанная запись. Назвать путь — значит попросить проанализировать то, что за ним; ссылку внутри дерева никто не просил, а переход по ней изменил бы состав измеряемых файлов, мог бы увести за корень проекта и не завершился бы на цикле.

!!! note "Примечание"
    Все диагностические сообщения `check` вне выбранного report-payload (уведомления и ошибки конфигурации, deprecation, logging и сообщения о записи файла) выводятся в **stderr**, а не в stdout. Это позволяет безопасно перенаправлять вывод анализа в файл или другой инструмент: `bin/qmx check src/ --format=json > results.json`.
