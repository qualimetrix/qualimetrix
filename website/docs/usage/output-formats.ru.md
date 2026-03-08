# Форматы вывода

AI Mess Detector поддерживает 7 форматов вывода. Выбирайте тот, который подходит для вашего рабочего процесса.

```bash
bin/aimd check src/ --format=<формат>
```

---

## text (по умолчанию)

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

Подробный, многострочный вывод с группировкой. Показывает больше контекста, чем `text`, включая количество файлов и время выполнения.

**Когда использовать:** Детальный локальный обзор, когда нужна группировка нарушений по файлу, правилу или уровню.

**Пример вывода:**

```
AI Mess Detector Report
──────────────────────────────────────────────────

src/Service/UserService.php (2)

  ERROR src/Service/UserService.php:42  App\Service\UserService::calculate
    Cyclomatic complexity is 15, max allowed is 10 (15) [complexity.cyclomatic.method]

  WARN src/Service/UserService.php:87  App\Service\UserService
    Class has 22 methods, max recommended is 20 (22) [size.method-count.class]

src/Repository/OrderRepository.php (1)

  ERROR src/Repository/OrderRepository.php:15  App\Repository\OrderRepository
    CBO is 18, max allowed is 15 (18) [coupling.cbo.class]

──────────────────────────────────────────────────
Files: 45 analyzed, 0 skipped | Errors: 2 | Warnings: 1 | Time: 1.23s
```

**Группировка:** По умолчанию `--group-by=file`. Можно изменить:

```bash
bin/aimd check src/ --format=text-verbose --group-by=rule
bin/aimd check src/ --format=text-verbose --group-by=severity
```

---

## json

Машиночитаемый JSON-вывод. Совместим с форматом PHPMD JSON для интеграции с инструментами.

**Когда использовать:** Пользовательские скрипты, дашборды, программная обработка.

**Пример вывода:**

```json
{
    "version": "1.0.0",
    "package": "aimd",
    "timestamp": "2025-01-15T10:30:00+00:00",
    "files": [
        {
            "file": "src/Service/UserService.php",
            "violations": [
                {
                    "beginLine": 42,
                    "endLine": 42,
                    "rule": "CyclomaticComplexityRule",
                    "code": "complexity.cyclomatic.method",
                    "symbol": "App\\Service\\UserService::calculate",
                    "priority": 1,
                    "severity": "error",
                    "description": "Cyclomatic complexity is 15, max allowed is 10",
                    "metricValue": 15
                }
            ]
        }
    ],
    "summary": {
        "filesAnalyzed": 45,
        "filesSkipped": 0,
        "violations": 3,
        "errors": 2,
        "warnings": 1,
        "duration": 1.234
    }
}
```

**Использование в CI:**

```bash
bin/aimd check src/ --format=json --no-progress > report.json
```

---

## metrics-json

Необработанные значения метрик для каждого символа (файл, класс, метод, пространство имён). В отличие от `json`, который выводит нарушения, `metrics-json` экспортирует исходные данные метрик, которые оценивают правила.

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
                "classCount": 1,
                "ccn:App\\Service\\UserService::calculate": 15,
                "cognitive:App\\Service\\UserService::calculate": 22,
                "halstead.volume:App\\Service\\UserService::calculate": 384.5
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
bin/aimd check src/ --format=metrics-json --no-progress > metrics.json
```

!!! note
    Формат `metrics-json` экспортирует **все собранные метрики**, а не только те, которые вызвали нарушения. Это делает его полезным для отслеживания трендов метрик со временем, даже для кода, который проходит все правила.

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

## Сравнительная таблица

| Формат         | Читаемость | Машинный    | Группировка                    | Интеграция с CI            |
| -------------- | ---------- | ----------- | ------------------------------ | -------------------------- |
| `text`         | Хорошая    | Парсируемый | `--group-by`                   | Любой (код выхода)         |
| `text-verbose` | Лучшая     | Нет         | `--group-by` (по умолч.: file) | Любой (код выхода)         |
| `json`         | Нет        | Да          | Встроенная (по файлам)         | Скрипты                    |
| `metrics-json` | Нет        | Да          | Встроенная (по символам)       | Скрипты, дашборды          |
| `checkstyle`   | Нет        | Да          | Встроенная (по файлам)         | Jenkins, SonarQube         |
| `sarif`        | Нет        | Да          | Встроенная                     | GitHub, VS Code, JetBrains |
| `gitlab`       | Нет        | Да          | Плоский список                 | GitLab MR виджет           |

### Коды выхода

Все форматы используют одинаковые коды выхода:

| Код выхода | Значение                                                             |
| ---------- | -------------------------------------------------------------------- |
| 0          | Нет ошибок (предупреждения допускаются)                              |
| 1          | Хотя бы одно нарушение уровня error                                  |
| 2          | Ошибка выполнения (некорректная конфигурация, файл не найден и т.д.) |
