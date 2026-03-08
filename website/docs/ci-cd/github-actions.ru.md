# Интеграция с GitHub Actions

AI Mess Detector предоставляет готовый GitHub Action для простой интеграции в ваши CI/CD-пайплайны.

## Быстрый старт

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

## Входные параметры

| Параметр            | Описание                                         | Обязательный | По умолчанию |
| ------------------- | ------------------------------------------------ | ------------ | ------------ |
| `paths`             | Пути для анализа (через пробел)                  | Нет          | `src/`       |
| `baseline`          | Путь к файлу baseline                            | Нет          | -            |
| `config`            | Путь к файлу конфигурации                        | Нет          | -            |
| `format`            | Формат вывода: `text`, `json`, `sarif`, `gitlab` | Нет          | `text`       |
| `php-version`       | Используемая версия PHP                          | Нет          | `8.4`        |
| `working-directory` | Рабочая директория для анализа                   | Нет          | `.`          |

## Выходные параметры

| Параметр     | Описание                                        |
| ------------ | ----------------------------------------------- |
| `violations` | Количество найденных нарушений                  |
| `exit-code`  | Код возврата (0 = успех, 1 = найдены нарушения) |

## Примеры

### С Baseline

```yaml
- name: Run AI Mess Detector
  uses: fractalizer/ai-mess-detector@v1
  with:
    paths: 'src/'
    baseline: 'baseline.json'
```

### Несколько путей

```yaml
- name: Run AI Mess Detector
  uses: fractalizer/ai-mess-detector@v1
  with:
    paths: 'src/ lib/ app/'
    config: 'aimd.yaml'
```

### SARIF-вывод для вкладки Security в GitHub

```yaml
jobs:
  aimd:
    runs-on: ubuntu-latest
    permissions:
      security-events: write
      contents: read

    steps:
      - uses: actions/checkout@v4

      - name: Run AI Mess Detector
        id: aimd
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
          format: 'sarif'
        continue-on-error: true

      - name: Upload SARIF to GitHub Security
        uses: github/codeql-action/upload-sarif@v3
        if: always()
        with:
          sarif_file: results.sarif
          category: aimd

      - name: Fail if violations found
        if: steps.aimd.outputs.exit-code != '0'
        run: exit ${{ steps.aimd.outputs.exit-code }}
```

### JSON-вывод с артефактами

```yaml
- name: Run AI Mess Detector
  uses: fractalizer/ai-mess-detector@v1
  with:
    paths: 'src/'
    format: 'json'

- name: Upload results
  if: always()
  uses: actions/upload-artifact@v4
  with:
    name: aimd-results
    path: aimd-results.json
```

### Использование выходных параметров

```yaml
- name: Run AI Mess Detector
  id: aimd
  uses: fractalizer/ai-mess-detector@v1
  with:
    paths: 'src/'
  continue-on-error: true

- name: Comment on PR
  if: github.event_name == 'pull_request'
  uses: actions/github-script@v7
  with:
    script: |
      github.rest.issues.createComment({
        issue_number: context.issue.number,
        owner: context.repo.owner,
        repo: context.repo.repo,
        body: `## AI Mess Detector Results\n\n` +
              `Violations found: ${{ steps.aimd.outputs.violations }}\n` +
              `Exit code: ${{ steps.aimd.outputs.exit-code }}`
      })
```

### Матричное тестирование

```yaml
jobs:
  aimd:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php-version: ['8.3', '8.4']

    steps:
      - uses: actions/checkout@v4

      - name: Run AI Mess Detector
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
          php-version: ${{ matrix.php-version }}
```

## Полный пример workflow

```yaml
name: Code Quality

on:
  push:
    branches: [main, master, develop]
  pull_request:
    branches: [main, master, develop]

jobs:
  aimd-basic:
    name: AI Mess Detector
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Run AI Mess Detector
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
          baseline: 'baseline.json'
          format: 'text'

  aimd-sarif:
    name: AI Mess Detector (SARIF)
    runs-on: ubuntu-latest
    permissions:
      security-events: write
      contents: read
    steps:
      - uses: actions/checkout@v4

      - name: Run AI Mess Detector
        id: aimd
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
          baseline: 'baseline.json'
          format: 'sarif'
        continue-on-error: true

      - name: Upload SARIF results
        uses: github/codeql-action/upload-sarif@v3
        if: always()
        with:
          sarif_file: results.sarif
          category: aimd

      - name: Fail if violations found
        if: steps.aimd.outputs.exit-code != '0'
        run: exit ${{ steps.aimd.outputs.exit-code }}
```

## Интеграция с другими инструментами

### С PHPStan

```yaml
jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install dependencies
        run: composer install

      - name: Run PHPStan
        run: vendor/bin/phpstan analyse

      - name: Run AI Mess Detector
        uses: fractalizer/ai-mess-detector@v1
        with:
          paths: 'src/'
```

## Решение проблем

### Action падает с ошибкой "AIMD binary not found"

Action ищет AIMD в следующем порядке:

1. `vendor/bin/aimd` -- если установлен как зависимость проекта
2. `bin/aimd` -- если запускается в репозитории самого AIMD
3. В крайнем случае -- глобальная установка через `composer global require`

Убедитесь, что ваш `composer.json` содержит AIMD как dev-зависимость:

```json
{
  "require-dev": {
    "fractalizer/ai-mess-detector": "^1.0"
  }
}
```

### Загрузка SARIF не работает

Убедитесь, что указаны правильные permissions:

```yaml
permissions:
  security-events: write
  contents: read
```

### Проблемы с рабочей директорией

Если ваш PHP-проект находится в подкаталоге:

```yaml
with:
  working-directory: './backend'
  paths: 'src/'
```

## Советы по производительности

1. **Используйте кэширование** зависимостей composer:

    ```yaml
    - name: Cache composer dependencies
      uses: actions/cache@v4
      with:
        path: ~/.composer/cache
        key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
    ```

2. **Используйте baseline**, чтобы проверять только новые проблемы
3. **Ограничивайте пути** только нужными директориями с исходным кодом
