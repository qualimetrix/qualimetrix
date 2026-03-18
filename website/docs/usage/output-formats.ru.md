# Форматы вывода

AI Mess Detector поддерживает 10 форматов вывода. Выбирайте тот, который подходит для вашего рабочего процесса.

```bash
bin/aimd check src/ --format=<формат>
```

---

## summary (по умолчанию)

Обзор здоровья проекта с оценками, худшими нарушителями и сводкой нарушений. Это вывод CLI по умолчанию, предназначенный для быстрой оценки состояния проекта.

**Когда использовать:** Локальная разработка, быстрый обзор здоровья проекта.

**Основные возможности:**

- 6 измерений здоровья с прогресс-барами (сложность, связность, связанность, типизация, сопровождаемость, общее)
- Топ-3 худших пространств имён и классов с оценками здоровья
- Количество нарушений с оценкой технического долга (включая плотность долга на 1K LOC)
- Контекстные подсказки для следующих шагов

**Пример вывода:**

```
AI Mess Detector — 45 files analyzed, 1.23s

  Complexity     ████████████████░░░░  78 Strong
  Cohesion       ██████████████░░░░░░  68 Acceptable
  Coupling       ████████████░░░░░░░░  59 Acceptable
  Typing         ██████████████████░░  88 Strong
  Maintainability████████████████░░░░  80 Good
  Overall        ██████████████░░░░░░  72 Acceptable

Worst namespaces:
  App\Service           52 Weak      | App\Repository        61 Acceptable
  App\Controller        55 Acceptable

Worst classes:
  App\Service\OrderService          38 Critical  | App\Service\UserService   45 Weak
  App\Repository\OrderRepository    51 Weak

Violations: 12 errors, 8 warnings | Tech debt: 4h 30m (2.1/1K LOC)

Hint: Run with --namespace=App\\Service to drill down into the worst namespace
```

**Детализация с `--namespace` и `--class`:**

```bash
# Показать нарушения для конкретного поддерева пространства имён
bin/aimd check src/ --namespace=App\\Service

# Показать нарушения для конкретного класса
bin/aimd check src/ --class=App\\Service\\UserService
```

**Режим детализации с `--detail`:**

```bash
# Добавить группированный список нарушений (лимит по умолчанию: 200)
bin/aimd check src/ --detail

# Показать все нарушения (без лимита)
bin/aimd check src/ --detail=all

# Пользовательский лимит
bin/aimd check src/ --detail=50
```

!!! note
    `--detail` включается автоматически при использовании `--namespace` или `--class`. Флаг также работает с `--format=text`: добавляет группированный список нарушений после компактного построчного вывода.

---

## text

Компактный вывод, одна строка на нарушение. Совместим с форматом ошибок GCC/Clang, поэтому нарушения кликабельны в большинстве терминалов и IDE.

**Когда использовать:** Локальная разработка, быстрые проверки, передача в `grep` или `wc`.

**Пример вывода:**

```
src/Service/UserService.php:42: error[complexity.cyclomatic.method]: Cyclomatic complexity is 15, max allowed is 10 (calculate)
src/Service/UserService.php:87: warning[size.method-count.class]: Class has 22 methods, max recommended is 20 (UserService)
src/Repository/OrderRepository.php:15: error[coupling.cbo.class]: CBO is 18, max allowed is 15 (OrderRepository)

3 error(s), 0 warning(s) in 45 file(s)
```

**Формат строки:** `файл:строка: уровень[кодНарушения]: сообщение (символ)`

---

## text-verbose

!!! warning "Устарело"
    `text-verbose` устарел. Используйте вместо него `--format=text --detail`, который обеспечивает аналогичный группированный многострочный вывод нарушений.

    ```bash
    # Замена: bin/aimd check src/ --format=text-verbose
    bin/aimd check src/ --format=text --detail
    ```

---

## json

Машиночитаемый JSON-вывод. Формат, ориентированный на сводную информацию: оценки здоровья, худшие нарушители и ограниченный список нарушений.

**Когда использовать:** Пользовательские скрипты, дашборды, программная обработка.

**Пример вывода:**

```json
{
    "meta": {
        "version": "1.0.0",
        "package": "aimd",
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
            "label": "Strong",
            "threshold": {"warning": 50, "error": 25},
            "decomposition": []
        },
        "overall": {
            "score": 72.0,
            "label": "Acceptable",
            "threshold": {"warning": 50, "error": 25},
            "decomposition": []
        }
    },
    "worstNamespaces": [
        {
            "symbolPath": "App\\Service",
            "healthOverall": 52.0,
            "label": "Weak",
            "reason": "high coupling",
            "violationCount": 15,
            "classCount": 8,
            "healthScores": {}
        }
    ],
    "worstClasses": [
        {
            "symbolPath": "App\\Service\\UserService",
            "healthOverall": 45.0,
            "label": "Weak",
            "reason": "low cohesion",
            "violationCount": 8,
            "file": "src/Service/UserService.php",
            "metrics": {},
            "healthScores": {}
        }
    ],
    "violations": [
        {
            "file": "src/Service/UserService.php",
            "line": 42,
            "symbol": "App\\Service\\UserService::calculate",
            "namespace": "App\\Service",
            "rule": "complexity.cyclomatic",
            "code": "complexity.cyclomatic.method",
            "severity": "error",
            "message": "Cyclomatic complexity: 15 (threshold: 10) — too many code paths",
            "recommendation": null,
            "metricValue": 15,
            "threshold": 10,
            "techDebtMinutes": 30
        }
    ],
    "violationsMeta": {
        "total": 3,
        "limit": 50,
        "truncated": false,
        "byRule": {
            "complexity.cyclomatic": 2,
            "coupling.cbo": 1
        }
    }
}
```

**Опции:**

```bash
# Управление лимитом нарушений (по умолчанию: 50)
bin/aimd check src/ --format=json --format-opt=limit=100

# Показать все нарушения (без лимита)
bin/aimd check src/ --format=json --format-opt=violations=all

# Управление количеством худших нарушителей (по умолчанию: 10)
bin/aimd check src/ --format=json --format-opt=top=20
```

**Использование в CI:**

```bash
bin/aimd check src/ --format=json --no-progress > report.json
```

---

## metrics

Необработанные значения метрик для каждого символа (файл, класс, метод, пространство имён). В отличие от `json`, который выводит нарушения, `metrics` экспортирует исходные данные метрик, которые оценивают правила.

**Когда использовать:** Пользовательские дашборды, анализ трендов, пайплайны data science или создание собственных критериев качества на основе сырых метрик.

**Пример вывода (сокращённо):**

```json
{
    "version": "1.0.0",
    "package": "aimd",
    "timestamp": "2025-01-15T10:30:00+00:00",
    "symbols": [
        {
            "type": "file",
            "name": "src/Service/UserService.php",
            "file": "src/Service/UserService.php",
            "line": 1,
            "metrics": {
                "loc": 150,
                "lloc": 120,
                "classCount": 1
            }
        },
        {
            "type": "class",
            "name": "App\\Service\\UserService",
            "file": "src/Service/UserService.php",
            "line": 10,
            "metrics": {
                "methodCount": 8,
                "propertyCount": 3,
                "lcom4": 2,
                "wmc": 35,
                "ca": 5,
                "ce": 12,
                "cbo": 17,
                "instability": 0.71
            }
        },
        {
            "type": "method",
            "name": "App\\Service\\UserService::calculate",
            "file": "src/Service/UserService.php",
            "line": 42,
            "metrics": {
                "ccn": 15,
                "cognitive": 22,
                "halstead.volume": 384.5,
                "loc": 35
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

**Использование:**

```bash
bin/aimd check src/ --format=metrics --no-progress > metrics.json
```

!!! note
    Формат `metrics` экспортирует **все собранные метрики**, а не только те, которые вызвали нарушения. Это делает его полезным для отслеживания трендов метрик со временем, даже для кода, который проходит все правила.

---

## checkstyle

Формат Checkstyle XML. Широко поддерживается CI-инструментами.

**Когда использовать:** Jenkins, SonarQube или любой инструмент, принимающий Checkstyle XML.

**Пример вывода:**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<checkstyle version="3.0">
  <file name="src/Service/UserService.php">
    <error line="42"
           severity="error"
           message="Cyclomatic complexity is 15, max allowed is 10"
           source="aimd.complexity.cyclomatic.method"/>
    <error line="87"
           severity="warning"
           message="Class has 22 methods, max recommended is 20"
           source="aimd.size.method-count.class"/>
  </file>
</checkstyle>
```

**Использование в CI (Jenkins):**

```bash
bin/aimd check src/ --format=checkstyle --no-progress > checkstyle.xml
```

---

## sarif

SARIF (Static Analysis Results Interchange Format) 2.1.0. Стандартный формат для инструментов статического анализа, принятый GitHub, Microsoft и многими производителями IDE.

**Когда использовать:** Вкладка Security на GitHub, VS Code (с расширением SARIF Viewer), JetBrains IDE, Azure DevOps.

**Пример вывода (сокращённо):**

```json
{
    "$schema": "https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json",
    "version": "2.1.0",
    "runs": [
        {
            "tool": {
                "driver": {
                    "name": "AI Mess Detector",
                    "version": "0.1.0",
                    "rules": [...]
                }
            },
            "results": [
                {
                    "ruleId": "complexity.cyclomatic.method",
                    "level": "error",
                    "message": {
                        "text": "Cyclomatic complexity is 15, max allowed is 10"
                    },
                    "locations": [
                        {
                            "physicalLocation": {
                                "artifactLocation": {
                                    "uri": "src/Service/UserService.php"
                                },
                                "region": {
                                    "startLine": 42
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
- name: Run AIMD
  run: bin/aimd check src/ --format=sarif --no-progress > results.sarif

- name: Upload SARIF to GitHub Security
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: results.sarif
```

Результаты появятся во вкладке **Security** вашего репозитория и как инлайн-аннотации в пулл-реквестах.

---

## gitlab

Формат GitLab Code Quality JSON. Показывает нарушения прямо в диффах Merge Request.

**Когда использовать:** GitLab CI/CD с отчётами Code Quality.

**Пример вывода (сокращённо):**

```json
[
    {
        "description": "Cyclomatic complexity is 15, max allowed is 10",
        "check_name": "complexity.cyclomatic.method",
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

**Маппинг уровней:**

| Уровень AIMD | Уровень GitLab |
| ------------ | -------------- |
| error        | critical       |
| warning      | major          |

**Использование в CI (GitLab CI):**

```yaml
code_quality:
  stage: test
  script:
    - bin/aimd check src/ --format=gitlab --no-progress > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

Нарушения появятся инлайн во вкладке **Changes** вашего Merge Request.

---

## github

Формат workflow-команд GitHub Actions. Создаёт инлайн-аннотации, которые отображаются прямо в диффах пулл-реквестов при запуске в GitHub Actions.

**Когда использовать:** GitHub Actions CI. Проще в настройке, чем SARIF — не нужен шаг загрузки.

**Пример вывода:**

```
::warning file=src/Service/UserService.php,line=87,title=size.method-count.class::Class has 22 methods, max recommended is 20
::error file=src/Service/UserService.php,line=42,title=complexity.cyclomatic.method::Cyclomatic complexity is 15, max allowed is 10
```

**Маппинг уровней:**

| Уровень AIMD | Команда GitHub |
| ------------ | -------------- |
| warning      | `::warning`    |
| error        | `::error`      |

**Использование в CI (GitHub Actions):**

```yaml
- name: Run AIMD
  run: vendor/bin/aimd check src/ --format=github --fail-on=error --no-progress
```

Аннотации появляются прямо на изменённых строках вашего пулл-реквеста — загрузка SARIF не требуется.

!!! tip "Совет"
    Используйте `--format=github` для быстрых инлайн-аннотаций. Используйте `--format=sarif`, если также хотите видеть результаты во вкладке Security на GitHub.

---

## health

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
bin/aimd check src/ --format=health -o report.html
```

**Пример рабочего процесса:**

```bash
# Сгенерировать и открыть отчёт
bin/aimd check src/ --format=health -o report.html
open report.html  # macOS
xdg-open report.html  # Linux
```

!!! note
    Флаг `-o` (output) рекомендуется при использовании формата `health`. Без него HTML-содержимое выводится в stdout.

---

## Сравнительная таблица

| Формат         | Читаемость    | Машинный    | Группировка                    | Интеграция с CI            |
| -------------- | ------------- | ----------- | ------------------------------ | -------------------------- |
| `summary`      | Лучшая        | Нет         | Оценки здоровья, drill-down    | Любой (код выхода)         |
| `text`         | Хорошая       | Парсируемый | `--group-by`                   | Любой (код выхода)         |
| `text-verbose` | Хорошая       | Нет         | `--group-by` (по умолч.: file) | Любой (код выхода)         |
| `json`         | Нет           | Да          | Встроенная (по файлам)         | Скрипты                    |
| `metrics`      | Нет           | Да          | Встроенная (по символам)       | Скрипты, дашборды          |
| `checkstyle`   | Нет           | Да          | Встроенная (по файлам)         | Jenkins, SonarQube         |
| `sarif`        | Нет           | Да          | Встроенная                     | GitHub, VS Code, JetBrains |
| `gitlab`       | Нет           | Да          | Плоский список                 | GitLab MR виджет           |
| `github`       | Нет           | Нет         | Плоский список                 | GitHub Actions аннотации   |
| `health`       | Интерактивная | Нет         | Иерархия treemap               | Отчёты, ревью              |

### Коды выхода

Все форматы используют одинаковые коды выхода:

| Код выхода | Значение                                 |
| ---------- | ---------------------------------------- |
| 0          | Нет нарушений                            |
| 1          | Есть предупреждения (но нет ошибок)      |
| 2          | Есть хотя бы одно нарушение уровня error |

При использовании `--fail-on=error` предупреждения больше не вызывают код выхода 1 — только ошибки приводят к ненулевому коду выхода.
