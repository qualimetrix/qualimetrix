# Интеграция с GitHub Actions

Qualimetrix предоставляет готовый GitHub Action для простой интеграции в ваши CI/CD-пайплайны.

## Быстрый старт

```yaml
# .github/workflows/quality.yml
name: Code Quality

on: [push, pull_request]

jobs:
  qmx:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Run Qualimetrix
        uses: qualimetrix/qualimetrix@v1
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

| Параметр     | Описание                                                 |
| ------------ | -------------------------------------------------------- |
| `violations` | Количество найденных нарушений                           |
| `exit-code`  | Код возврата (0 = чисто, 1 = предупреждения, 2 = ошибки) |

## Примеры

### С Baseline

```yaml
- name: Run Qualimetrix
  uses: qualimetrix/qualimetrix@v1
  with:
    paths: 'src/'
    baseline: 'baseline.json'
```

### Несколько путей

```yaml
- name: Run Qualimetrix
  uses: qualimetrix/qualimetrix@v1
  with:
    paths: 'src/ lib/ app/'
    config: 'qmx.yaml'
```

### SARIF-вывод для вкладки Security в GitHub

```yaml
jobs:
  qmx:
    runs-on: ubuntu-latest
    permissions:
      security-events: write
      contents: read

    steps:
      - uses: actions/checkout@v4

      - name: Run Qualimetrix
        id: qmx
        uses: qualimetrix/qualimetrix@v1
        with:
          paths: 'src/'
          format: 'sarif'
        continue-on-error: true

      - name: Upload SARIF to GitHub Security
        uses: github/codeql-action/upload-sarif@v3
        if: always()
        with:
          sarif_file: results.sarif
          category: qmx

      - name: Fail if violations found
        if: steps.qmx.outputs.exit-code != '0'
        run: exit ${{ steps.qmx.outputs.exit-code }}
```

### Инлайн-аннотации в PR (рекомендуется)

Самый простой способ видеть нарушения прямо в диффе PR. Не требует дополнительных шагов загрузки.

```yaml
jobs:
  qmx:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install dependencies
        run: composer install --no-dev

      - name: Run Qualimetrix
        run: vendor/bin/qmx check src/ --format=github --no-progress
```

Нарушения отображаются как аннотации warning и error прямо на изменённых строках. По умолчанию `--fail-on=error` — предупреждения не приводят к падению сборки. Добавьте `--fail-on=warning` для более строгого контроля.

!!! tip
    Для одновременного получения инлайн-аннотаций И результатов во вкладке Security запустите Qualimetrix дважды — один раз с `--format=github` и один раз с `--format=sarif`.

### JSON-вывод с артефактами

```yaml
- name: Run Qualimetrix
  uses: qualimetrix/qualimetrix@v1
  with:
    paths: 'src/'
    format: 'json'

- name: Upload results
  if: always()
  uses: actions/upload-artifact@v4
  with:
    name: qmx-results
    path: qmx-results.json
```

### Использование выходных параметров

```yaml
- name: Run Qualimetrix
  id: qmx
  uses: qualimetrix/qualimetrix@v1
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
        body: `## Qualimetrix Results\n\n` +
              `Violations found: ${{ steps.qmx.outputs.violations }}\n` +
              `Exit code: ${{ steps.qmx.outputs.exit-code }}`
      })
```

### Матричное тестирование

```yaml
jobs:
  qmx:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php-version: ['8.3', '8.4']

    steps:
      - uses: actions/checkout@v4

      - name: Run Qualimetrix
        uses: qualimetrix/qualimetrix@v1
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
  qmx-basic:
    name: Qualimetrix
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Run Qualimetrix
        uses: qualimetrix/qualimetrix@v1
        with:
          paths: 'src/'
          baseline: 'baseline.json'
          format: 'text'

  qmx-sarif:
    name: Qualimetrix (SARIF)
    runs-on: ubuntu-latest
    permissions:
      security-events: write
      contents: read
    steps:
      - uses: actions/checkout@v4

      - name: Run Qualimetrix
        id: qmx
        uses: qualimetrix/qualimetrix@v1
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
          category: qmx

      - name: Fail if violations found
        if: steps.qmx.outputs.exit-code != '0'
        run: exit ${{ steps.qmx.outputs.exit-code }}
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

      - name: Run Qualimetrix
        uses: qualimetrix/qualimetrix@v1
        with:
          paths: 'src/'
```

## Решение проблем

### Action падает с ошибкой "Qualimetrix binary not found"

Action ищет Qualimetrix в следующем порядке:

1. `vendor/bin/qmx` -- если установлен как зависимость проекта
2. `bin/qmx` -- если запускается в репозитории самого Qualimetrix
3. В крайнем случае -- глобальная установка через `composer global require`

Убедитесь, что ваш `composer.json` содержит Qualimetrix как dev-зависимость:

```json
{
  "require-dev": {
    "qualimetrix/qualimetrix": "^1.0"
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
