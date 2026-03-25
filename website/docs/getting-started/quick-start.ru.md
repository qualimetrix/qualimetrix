# Быстрый старт

Три способа интегрировать AI Mess Detector в ваш проект:

1. **Pre-commit хук** -- проверка перед каждым коммитом
2. **GitHub Action** -- автоматические проверки в CI/CD
3. **Docker** -- запуск без локальной установки PHP

---

## Первый запуск анализа

### Установка

```bash
composer require --dev fractalizer/ai-mess-detector
```

### Запуск анализа

```bash
vendor/bin/aimd check src/
```

<!-- llms:skip-begin -->
### Интерпретация результатов

По умолчанию выводится сводка состояния проекта с оценками по категориям:

```
AI Mess Detector — 127 files analyzed, 2.3s

Health ████████████████████░░░░░░░░░░ 67.2% Fair

  Complexity      ██████████████████████████░░░░ 85.1% Excellent
  Cohesion        ████████████░░░░░░░░░░░░░░░░░░ 42.3% Poor
  Coupling        █████████████████░░░░░░░░░░░░░ 55.8% Fair
  Typing          ████████████████████████████░░ 92.0% Excellent
  Maintainability ████████████████████░░░░░░░░░░ 64.5% Good

Worst namespaces
  38 App\Service (12 classes, 28 violations) — low cohesion, high coupling
  42 App\Repository (8 classes, 15 violations) — low cohesion

45 violations (12 errors, 33 warnings) | Tech debt: 2d 4h (8.5 min/kLOC)

Hints: --detail to see violations (top 200) | --namespace='App\Service' to drill down | --format=health -o report.html for full report
```

Каждая категория получает метку: **Excellent** (отлично), **Good** (хорошо), **Fair** (есть куда расти), **Poor** (требует внимания) или **Critical** (необходимы действия). Секция "Worst namespaces" показывает, на чём стоит сосредоточиться в первую очередь.

<!-- llms:skip-end -->

### Детализация по пространству имён

Изучите конкретное пространство имён, чтобы увидеть его классы и нарушения:

```bash
vendor/bin/aimd check src/ --namespace='App\Service'
```

### Просмотр детальных нарушений

Вывод отдельных нарушений с путями к файлам, номерами строк и рекомендациями по исправлению:

```bash
vendor/bin/aimd check src/ --detail
```

### Генерация HTML-отчёта

Для полного интерактивного отчёта с графиками и навигацией:

```bash
vendor/bin/aimd check src/ --format=health -o report.html
```

Откройте `report.html` в браузере для изучения результатов.

---

## 1. Pre-commit хук

Автоматическая проверка подготовленных (staged) файлов перед каждым коммитом.

### Установка

=== "Символическая ссылка (рекомендуется)"

    ```bash
    ln -s ../../scripts/pre-commit-hook.sh .git/hooks/pre-commit
    ```

    Автоматически обновляется при изменении скрипта, не нужно копировать при обновлениях.

=== "Копирование"

    ```bash
    cp scripts/pre-commit-hook.sh .git/hooks/pre-commit
    chmod +x .git/hooks/pre-commit
    ```

    Работает, если `.git/hooks` не поддерживает символические ссылки, можно модифицировать под проект.

=== "Встроенная команда"

    ```bash
    vendor/bin/aimd hook:install
    ```

### Использование

После установки хук запускается автоматически при каждом `git commit`:

```bash
git add src/MyClass.php
git commit -m "Add new feature"

# Хук запустится автоматически:
# Running AI Mess Detector on staged files...
# AI Mess Detector passed.
```

### Пропуск проверки

```bash
# Пропустить проверку для конкретного коммита
git commit --no-verify -m "WIP: work in progress"
```

### Настройка базовой линии (baseline)

Если проект уже содержит legacy-код с нарушениями:

```bash
# Создать базовую линию для существующих проблем
vendor/bin/aimd check src/ --generate-baseline=baseline.json

# Теперь хук будет игнорировать проблемы из базовой линии
git commit -m "Add feature"
```

### Удаление хука

```bash
rm .git/hooks/pre-commit
```

---

## 2. GitHub Action

Автоматический анализ при push и pull request. Подробная настройка описана в [руководстве по GitHub Actions](../ci-cd/github-actions.md).

### Быстрая настройка

```yaml
# .github/workflows/quality.yml
name: Code Quality

on: [push, pull_request]

jobs:
  aimd:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Run AI Mess Detector
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
          baseline: 'baseline.json'
```

---

<!-- llms:skip-begin -->
## 3. Docker

Запуск анализа в контейнере без локальной установки PHP.

### Сборка образа

```bash
docker build -t aimd .
```

### Использование

```bash
<!-- llms:skip-end -->

# Анализ текущей директории
docker run --rm -v $(pwd):/app aimd check src/

# С базовой линией
docker run --rm -v $(pwd):/app aimd check src/ --baseline=baseline.json

# С конфигурацией
docker run --rm -v $(pwd):/app aimd check src/ --config=aimd.yaml

# Вывод в формате JSON
docker run --rm -v $(pwd):/app aimd check src/ --format=json
```

### Docker Compose

```yaml
# docker-compose.yml
services:
  aimd:
    image: aimd:latest
    volumes:
      - .:/app:ro
      - ./baseline.json:/app/baseline.json
    command: check src/ --baseline=baseline.json
```

```bash
docker-compose run --rm aimd
```

### CI/CD с Docker

=== "GitLab CI"

    ```yaml
    # .gitlab-ci.yml
    aimd:
      stage: test
      image: aimd:latest
      script:
        - aimd check src/ --baseline=baseline.json
      artifacts:
        when: on_failure
        paths:
          - aimd-results.json
    ```

=== "Jenkins"

    ```groovy
    // Jenkinsfile
    pipeline {
        agent any
        stages {
            stage('AIMD Analysis') {
                steps {
                    script {
                        docker.image('aimd:latest').inside('-v $WORKSPACE:/app') {
                            sh 'aimd check src/ --baseline=baseline.json'
                        }
                    }
                }
            }
        }
    }
    ```

---

## Исключение путей

Подавление нарушений для файлов, соответствующих glob-паттернам. Полезно для сгенерированного кода, DTO или классов сущностей.

!!! note "Примечание"
    Исключённые файлы всё равно анализируются (метрики собираются) -- подавляется только вывод нарушений.

=== "YAML-конфигурация"

    ```yaml
    # aimd.yaml
    exclude_paths:
      - src/Entity/*
      - src/DTO/*
    ```

=== "CLI"

    ```bash
    vendor/bin/aimd check src/ --exclude-path='src/Entity/*' --exclude-path='*/DTO/*'
    ```

CLI-паттерны объединяются с паттернами из конфигурационного файла.

---

<!-- llms:skip-begin -->
## Сравнение методов

| Метод              | Когда использовать   | Преимущества                                         | Недостатки                       |
| ------------------ | -------------------- | ---------------------------------------------------- | -------------------------------- |
| **Pre-commit хук** | Локальная разработка | Быстрая обратная связь, предотвращает плохие коммиты | Можно обойти через `--no-verify` |
| **GitHub Action**  | CI/CD пайплайн       | Автоматически для всех PR, нельзя обойти             | Медленнее локального             |
| **Docker**         | Чистое окружение     | Не нужен локальный PHP, воспроизводимость            | Требует Docker, медленнее        |

### Рекомендуемая стратегия

- **Маленькие команды (1-5):** Pre-commit хук + GitHub Action
- **Средние команды (5-20):** GitHub Action (обязательно) + Pre-commit хук (опционально) + Docker для разработчиков без PHP
- **Большие команды (20+):** GitHub Action с baseline (обязательно) + Docker

---

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
## Решение проблем

### Pre-commit хук не работает

**Хук не запускается при коммите:**

```bash
<!-- llms:skip-end -->

# Проверьте, что хук существует и является исполняемым
ls -la .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

**`aimd binary not found`:**

```bash
composer install
ls -la bin/aimd
```

### Проблемы с правами в Docker

```bash
# Linux с SELinux: добавьте флаг :z
docker run --rm -v $(pwd):/app:z aimd check src/
```
