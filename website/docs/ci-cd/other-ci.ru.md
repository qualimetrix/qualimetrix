# Другие CI-системы

Qualimetrix работает с любой CI-системой, способной запускать PHP. На этой странице показано, как настроить его в самых популярных из них.

## GitLab CI

В GitLab есть встроенный виджет Code Quality, который показывает проблемы прямо в merge request-ах. Qualimetrix поддерживает формат GitLab из коробки.

```yaml
# .gitlab-ci.yml
code_quality:
  stage: test
  image: php:8.4-cli
  before_script:
    - composer install --no-dev
  script:
    - vendor/bin/qmx check src/ --format=gitlab > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

Флаг `--format=gitlab` генерирует вывод в формате GitLab Code Quality. После настройки вы увидите результаты анализа прямо в диффах merge request-ов.

### Использование Baseline

Чтобы игнорировать известные проблемы в legacy-проекте:

```yaml
code_quality:
  stage: test
  image: php:8.4-cli
  before_script:
    - composer install --no-dev
  script:
    - vendor/bin/qmx check src/ --format=gitlab --baseline=baseline.json > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

## Jenkins

Jenkins хорошо работает с форматом Checkstyle. Плагин [Warnings Next Generation](https://plugins.jenkins.io/warnings-ng/) умеет парсить Checkstyle XML и отображать результаты в интерфейсе Jenkins.

```groovy
pipeline {
    agent any
    stages {
        stage('Code Quality') {
            steps {
                sh 'vendor/bin/qmx check src/ --format=checkstyle > checkstyle-result.xml'
                recordIssues tools: [checkStyle(pattern: 'checkstyle-result.xml')]
            }
        }
    }
}
```

Убедитесь, что плагин Warnings Next Generation установлен. Именно он предоставляет шаг `recordIssues`, используемый выше.

## CircleCI

```yaml
# .circleci/config.yml
version: 2.1

jobs:
  code-quality:
    docker:
      - image: cimg/php:8.4
    steps:
      - checkout
      - run: composer install --no-dev
      - run: vendor/bin/qmx check src/
```

### Сохранение результатов как артефактов

```yaml
jobs:
  code-quality:
    docker:
      - image: cimg/php:8.4
    steps:
      - checkout
      - run: composer install --no-dev
      - run: vendor/bin/qmx check src/ --format=json > qmx-results.json
      - store_artifacts:
          path: qmx-results.json
          destination: code-quality
```

## Bitbucket Pipelines

```yaml
# bitbucket-pipelines.yml
pipelines:
  default:
    - step:
        name: Code Quality
        image: php:8.4-cli
        script:
          - composer install --no-dev
          - vendor/bin/qmx check src/
```

### С кэшированием

```yaml
pipelines:
  default:
    - step:
        name: Code Quality
        image: php:8.4-cli
        caches:
          - composer
        script:
          - composer install --no-dev
          - vendor/bin/qmx check src/
```

## Универсальная настройка (любая CI-система)

Независимо от используемой CI-системы, настройка следует одним и тем же шагам:

### 1. Установите зависимости

```bash
composer install --no-dev
```

### 2. Запустите анализ

```bash
vendor/bin/qmx check src/
```

### 3. Выберите подходящий формат

Выберите формат вывода, который лучше всего подходит для вашей CI-системы:

| Формат     | Флаг                  | Лучше всего подходит для                         |
| ---------- | --------------------- | ------------------------------------------------ |
| Text       | `--format=text`       | Консольный вывод, простые CI                     |
| JSON       | `--format=json`       | Пользовательские интеграции, скрипты             |
| Checkstyle | `--format=checkstyle` | Jenkins, инструменты с поддержкой Checkstyle XML |
| SARIF      | `--format=sarif`      | GitHub, VS Code, дашборды безопасности           |
| GitHub     | `--format=github`     | Инлайн-аннотации в GitHub Actions                |
| GitLab     | `--format=gitlab`     | Виджет Code Quality в GitLab                     |

### 4. Обработайте коды возврата

Qualimetrix использует стандартные коды возврата:

| Код возврата | Значение                               |
| ------------ | -------------------------------------- |
| `0`          | Нет нарушений                          |
| `1`          | Найдены предупреждения (но нет ошибок) |
| `2`          | Найдены ошибки                         |
| `3`          | Ошибка конфигурации или входных данных |

Большинство CI-систем считают ненулевой код возврата ошибкой. По умолчанию `--fail-on=error` — предупреждения отображаются, но не приводят к ненулевому коду возврата. Для строгого контроля используйте `--fail-on=warning`:

```bash
vendor/bin/qmx check src/ --fail-on=warning
```

Чтобы продолжить пайплайн независимо от нарушений:

```bash
vendor/bin/qmx check src/ || true
```

### 5. Используйте Baseline для legacy-проектов

Если вы внедряете Qualimetrix в проект, в котором уже много проблем, сначала сгенерируйте baseline:

```bash
vendor/bin/qmx check src/ --generate-baseline=baseline.json
```

Затем используйте его в CI, чтобы получать отчеты только о новых нарушениях:

```bash
vendor/bin/qmx check src/ --baseline=baseline.json
```

### 6. Используйте файл конфигурации

Для единообразных настроек в локальном окружении и CI используйте YAML-файл конфигурации:

```bash
vendor/bin/qmx check src/ --config=qmx.yaml
```

## Советы

- **Кэшируйте зависимости composer** в вашей CI-системе для ускорения сборок.
- **Используйте `--no-dev`** при установке пакетов composer в CI -- Qualimetrix не нуждается в dev-зависимостях для работы.
- **Запускайте Qualimetrix параллельно с другими проверками** (PHPStan, PHP-CS-Fixer) для экономии времени.
- **Используйте baseline** при внедрении Qualimetrix в существующий проект, чтобы CI не падал из-за уже существующих проблем.
