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

Hints: --detail to see violations (top 200) | --namespace='App\Billing\Invoice' to drill down | --format=html -o report.html for full report
```

Флаг, управляющий числом записей в `Top issues by impact`, описан на странице [CLI Options](cli-options.md).

**Детализация с `--namespace` и `--class`:**

```bash
# Показать нарушения для конкретного поддерева пространства имён
bin/qmx check src/ --namespace=App\\Service

# Показать нарушения для конкретного класса
bin/qmx check src/ --class=App\\Service\\UserService
```

**Режим детализации с `--detail`:**

```bash
# Добавить группированный список нарушений (лимит по умолчанию: 200)
bin/qmx check src/ --detail

# Показать все нарушения (без лимита)
bin/qmx check src/ --detail=all

# Пользовательский лимит
bin/qmx check src/ --detail=50
```

!!! note
    `--detail` включается автоматически при использовании `--namespace` или `--class`. Флаг также работает с `--format=text`: добавляет группированный список нарушений после компактного построчного вывода.

---

## text

Компактный вывод, одна строка на нарушение. Совместим с форматом ошибок GCC/Clang, поэтому нарушения кликабельны в большинстве терминалов и IDE.

**Когда использовать:** Локальная разработка, быстрые проверки, передача в `grep` или `wc`.

**Пример вывода:**

```
src/Service/UserService.php:42: warning[code-smell.error-suppression]: Error suppression (@) on find() - handle errors explicitly
src/Service/UserService.php: warning[complexity.ccn]: Cyclomatic complexity is 15, exceeds threshold of 10. Consider extracting methods or simplifying conditions (UserService::calculate)
src/Repository/OrderRepository.php: warning[coupling.class-rank]: ClassRank is 0.041, exceeds threshold of 0.037 (scaled for 45 classes). This class is a critical hub — changes have wide impact (OrderRepository)

0 error(s), 3 warning(s) in 45 file(s)
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

**Ключи верхнего уровня:** `meta`, `summary`, `coverage`, `health`, `worstNamespaces`, `worstClasses`, `topIssues`, `violations`, `violationsMeta`, плюс `violationGroups`, когда передан `--group-by` — без него ключа нет вовсе, это не пустой объект.

<!-- llms:skip-begin -->
**Пример вывода:**

```json
{
    "meta": {
        "version": "1.0.0",
        "package": "qmx",
        "timestamp": "2025-01-15T10:30:00+00:00"
    },
    "summary": {
        "filesAnalyzed": 45,
        "filesSkipped": 0,
        "duration": 1.234,
        "violationCount": 3,
        "errorCount": 2,
        "warningCount": 1,
        "techDebtMinutes": 270,
        "debtPer1kLoc": 2.1
    },
    "health": {
        "complexity": {
            "score": 78.0,
            "label": "Excellent",
            "threshold": {"warning": 50, "error": 25},
            "decomposition": []
        },
        "overall": {
            "score": 72.0,
            "label": "Fair",
            "threshold": {"warning": 50, "error": 25},
            "decomposition": []
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
`violationsMeta` также сообщает `shown` — число нарушений, фактически
включённых в этот payload; оно может быть меньше `total`, когда
`--format-opt=violations=N` обрезает список.

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

Ключи группы — не всегда FQCN класса или пространство имён. Для `--group-by=class`: ключ — это FQCN класса для находки уровня класса, путь к файлу для находки уровня файла без контекста класса, и пустая строка `""` для находки уровня проекта (у неё нет ни класса, ни файла). Для `--group-by=namespace`: ключ — это пространство имён для класса внутри него, `<global>` для класса без пространства имён, и `__PROJECT__` для находки уровня проекта.

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

**Ключи верхнего уровня:** `version`, `toolVersion`, `package`, `timestamp`, `symbols[]` (каждый с `type`: file/class/namespace/method/function/project, `name`, `file`, `line`, `metrics: {...}`), `coverage`, `summary`. Типа `callable` не существует; одна запись `project` агрегирует статистические метрики по всему проекту (min/max/avg/p95 по всем символам) и имеет `line` равным `null`.

<!-- llms:skip-begin -->
**Пример вывода (сокращённо):**

```json
{
    "version": "1.0.0",
    "toolVersion": "0.26.0",
    "package": "qmx",
    "timestamp": "2025-01-15T10:30:00+00:00",
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
    "summary": {
        "filesAnalyzed": 45,
        "filesSkipped": 0,
        "duration": 1.234
    }
}
```
<!-- llms:skip-end -->

**Использование:**

```bash
bin/qmx check src/ --format=metrics --no-progress > metrics.json
```

!!! note
    Формат `metrics` экспортирует **все собранные метрики**, а не только те, которые вызвали нарушения. Это делает его полезным для отслеживания трендов метрик со временем, даже для кода, который проходит все правила.

---

## checkstyle

Формат Checkstyle XML. Широко поддерживается CI-инструментами.

**Когда использовать:** Jenkins, SonarQube или любой инструмент, принимающий Checkstyle XML.

Checkstyle 3.0 XML: `<file name="...">` с вложенными `<error line="" severity="error|warning|info" message="" source="qmx.<rule>"/>`.

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

`runs[].invocations[0]` сообщает `executionSuccessful` (см. таблицу coverage ниже), а `runs[].originalUriBaseIds` объявляет базу `%SRCROOT%`, на которую ссылается каждый `artifactLocation.uriBaseId`, разрешая её в корень анализируемого проекта как `file://`-URI.

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
- Поддержка drill-down через `--namespace` и `--class`

**Худшие участники по измерениям:**

Вывод health включает худших участников для каждого измерения -- классы или пространства имён, которые больше всего снижают каждую оценку здоровья. Управляйте количеством отображаемых участников через `--format-opt=contributors=N` (по умолчанию: 3):

```bash
bin/qmx check src/ --format=health --format-opt=contributors=5
```

**Использование:**

```bash
bin/qmx check src/ --format=health
bin/qmx check src/ --format=health --namespace='App\Service'
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

**Ключи верхнего уровня:** `meta`, `note`, `mechanisms` (все семь, всегда
присутствуют), `byMechanism` (счётчик на каждый механизм, включая нулевые),
`suppressed` (само мультимножество), `neverMatched`.

<!-- llms:skip-begin -->
**Пример вывода (сокращённый, из самоанализа этого проекта):**

```json
{
    "meta": {
        "version": "dev-main",
        "package": "qmx",
        "timestamp": "2026-08-29T09:14:02+00:00"
    },
    "note": "suppressed is a multiset of mechanism x finding, not a set of findings: one finding can appear under more than one mechanism, so byMechanism counts do not sum to the number of distinct findings suppressed.",
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
            "file": "src/Infrastructure/Ast/CachedFileParser.php",
            "line": 73,
            "symbol": "src/Infrastructure/Ast/CachedFileParser.php",
            "severity": "error",
            "message": "Log the exception or add a comment explaining why it is safe to ignore."
        },
        {
            "mechanism": "rule-path-suppression",
            "suppressor": "code-smell.constructor-overinjection",
            "rule": "code-smell.constructor-overinjection",
            "channel": "code-smell.constructor-overinjection",
            "file": "src/Analysis/Run/Contract/Collection/SuccessfulFileProcessing.php",
            "line": 28,
            "symbol": "Qualimetrix\\Analysis\\Run\\Contract\\Collection\\SuccessfulFileProcessing::__construct",
            "severity": "warning",
            "message": "Constructor parameters: 8 (threshold: 8) — consider splitting responsibilities"
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

## Покрытие анализа во всех форматах

Каждый обнаруженный PHP-файл классифицируется как проанализированный,
намеренно исключённый generated-файл или файл с ошибкой parsing/processing.
Generated-исключения не делают анализ неполным; любая ошибка делает
политический результат неавторитетным. Нуль найденных файлов всё равно
проходит через выбранный форматтер.

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
| `suppressed`   | Не представлено — этот формат публикует состав подавленного, а не объект `coverage`                                  |

В `json` и `metrics` каждый элемент `failures[]` содержит `path`, `kind` (`parse` или
`processing`) и `message`. Текстовые форматы различают нуль найденных файлов,
только generated-файлы, полный и неполный анализ.

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

!!! note "Примечание"
    Все диагностические сообщения `check` вне выбранного report-payload (уведомления и ошибки конфигурации, deprecation, logging и сообщения о записи файла) выводятся в **stderr**, а не в stdout. Это позволяет безопасно перенаправлять вывод анализа в файл или другой инструмент: `bin/qmx check src/ --format=json > results.json`.
