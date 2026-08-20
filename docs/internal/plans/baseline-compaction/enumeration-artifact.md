# Перечисление: потребители артефакта бейзлайна

**Чем получено.** `git grep -In` по **отслеживаемым** файлам (это важно: обычный `grep` с фильтром по каталогам ранее срезал ведущую точку у `.github/`, и таблица печатала несуществующий путь). Исключён только каталог самого плана. Таблицы приводятся **целиком, без сокращений** — предыдущая редакция скрывала строки за «…ещё N файлов», и за ними оказались ровно те потребители, что роняют сборку.

**Чего этот способ не видит:** CI-конфигурации и скрипты у потребителей за пределами репозитория; пути, склеенные во время выполнения, — они закрыты перечислением классов-владельцев ниже, а не литералов; содержимое `vendor/` (артефакт туда не попадает, `.gitattributes` помечает каталоги `export-ignore`).

## Файлы, называющие артефакт или опцию: 57 (вхождений 144)

### 1. Производственный код

| Файл                                                               | Вхождений |
| ------------------------------------------------------------------ | --------- |
| `src/Infrastructure/Console/Command/BaselineCommandDefinition.php` | 1         |
| `src/Infrastructure/Console/README.md`                             | 3         |
| `src/Infrastructure/Git/README.md`                                 | 1         |
| `src/Reporting/FindingProjection/FindingProjectionResult.php`      | 1         |

### 2. Тесты

| Файл                                                                                      | Вхождений |
| ----------------------------------------------------------------------------------------- | --------- |
| `tests/Analysis/Policy/Baseline/Fixtures/BaselineCliFixture.php`                          | 1         |
| `tests/Analysis/Policy/Baseline/Fixtures/BaselineV10/memory-ceiling.json`                 | 1         |
| `tests/Analysis/Policy/Baseline/Functional/BaselineCleanupCommandTest.php`                | 1         |
| `tests/Analysis/Policy/Baseline/Functional/BaselineCommandOptionSurfaceTest.php`          | 4         |
| `tests/Analysis/Policy/Baseline/Functional/BaselineExplainCommandTest.php`                | 4         |
| `tests/Analysis/Policy/Baseline/Functional/BaselineGenerateCommandTest.php`               | 1         |
| `tests/Analysis/Policy/Baseline/Functional/BaselineIncompleteAnalysisTest.php`            | 1         |
| `tests/Analysis/Policy/Baseline/Functional/BaselineLifecycleTest.php`                     | 3         |
| `tests/Analysis/Policy/Baseline/Functional/BaselineMeasuredSetSeamTest.php`               | 5         |
| `tests/Analysis/Policy/Baseline/Functional/BaselineMigrateCommandTest.php`                | 1         |
| `tests/Analysis/Policy/Baseline/Functional/BaselineRunBeforeLoadTest.php`                 | 2         |
| `tests/Analysis/Policy/Baseline/Functional/BaselineUpdateCommandTest.php`                 | 1         |
| `tests/Analysis/Policy/Baseline/Integration/CboAggregateBreachTest.php`                   | 1         |
| `tests/Analysis/Policy/Baseline/Integration/MemoryCeilingManifestTest.php`                | 1         |
| `tests/Infrastructure/Console/Functional/Command/CheckCommandBaselineTest.php`            | 5         |
| `tests/Infrastructure/Console/Functional/Command/CheckCommandConfigErrorExitCodeTest.php` | 2         |
| `tests/Infrastructure/Console/Functional/Command/CheckCommandTest.php`                    | 1         |
| `tests/Infrastructure/Console/Unit/ViolationFilterOrchestratorBaselineReportingTest.php`  | 7         |
| `tests/Infrastructure/Console/Unit/ViolationFilterOrchestratorTest.php`                   | 2         |
| `tests/System/DocumentationConsistency/Integration/DocumentationConsistencyTest.php`      | 4         |

### 3. Сборка, CI и корневые артефакты

| Файл                         | Вхождений |
| ---------------------------- | --------- |
| `.github/workflows/qmx.yml`  | 1         |
| `.gitignore`                 | 2         |
| `AGENTS.md`                  | 3         |
| `CHANGELOG.md`               | 1         |
| `README.md`                  | 1         |
| `action.yml`                 | 1         |
| `composer.json`              | 1         |
| `docker-compose.yml`         | 4         |
| `qmx.yaml`                   | 2         |
| `scripts/pre-commit-hook.sh` | 1         |

### 4. Сайт

| Файл                                              | Вхождений |
| ------------------------------------------------- | --------- |
| `website/docs/ci-cd/other-ci.md`                  | 3         |
| `website/docs/ci-cd/other-ci.ru.md`               | 3         |
| `website/docs/getting-started/installation.md`    | 1         |
| `website/docs/getting-started/installation.ru.md` | 1         |
| `website/docs/getting-started/quick-start.md`     | 4         |
| `website/docs/getting-started/quick-start.ru.md`  | 4         |
| `website/docs/llms.txt`                           | 1         |
| `website/docs/usage/baseline.md`                  | 5         |
| `website/docs/usage/baseline.ru.md`               | 5         |
| `website/docs/usage/cli-options.md`               | 5         |
| `website/docs/usage/cli-options.ru.md`            | 5         |

### 5. Внутренние доки

| Файл                                                                           | Вхождений |
| ------------------------------------------------------------------------------ | --------- |
| `docs/internal/SCANNER_VALIDATION_ROUND_2_PLAN.md`                             | 6         |
| `docs/internal/plans/channel-identity-substrate.md`                            | 3         |
| `docs/internal/plans/client-requests/architecture-unassigned-class.md`         | 1         |
| `docs/internal/plans/diff-mode-new-only.md`                                    | 3         |
| `docs/internal/plans/modular-architecture/p0-governance.md`                    | 1         |
| `docs/internal/plans/modular-architecture/p1-duplication.md`                   | 2         |
| `docs/internal/plans/modular-architecture/p2-dependency-model.md`              | 2         |
| `docs/internal/plans/modular-architecture/p3-run-measurement-configuration.md` | 4         |
| `docs/internal/plans/modular-architecture/p4-architecture-policy.md`           | 2         |
| `docs/internal/plans/modular-architecture/p5-computed-metrics.md`              | 6         |
| `docs/internal/plans/modular-architecture/p6-finding-policy.md`                | 4         |
| `docs/internal/plans/phpdoc-dependencies.md`                                   | 2         |

## Потребители, которые ломаются молча

Отмечены отдельно, потому что их не видно в диффе пакета, а падают они позже:

| Файл                                                                                 | Что делает                                       | Почему опасен                                              |
| ------------------------------------------------------------------------------------ | ------------------------------------------------ | ---------------------------------------------------------- |
| `composer.json`                                                                      | `selfcheck` зовёт `--baseline=qmx-baseline.json` | обязательный `composer check` продолжит звать старый путь  |
| `.github/workflows/qmx.yml`                                                          | вход `baseline: 'qmx-baseline.json'` для Action  | smoke-тест Action зелёный на старом пути, красный на новом |
| `action.yml`                                                                         | пробрасывает вход в `--baseline=`                | контракт Action, публичная поверхность                     |
| `tests/System/DocumentationConsistency/Integration/DocumentationConsistencyTest.php` | декодирует файл и требует объект `entries`       | тест знает форму артефакта, а не только путь               |
| `scripts/pre-commit-hook.sh`                                                         | локальный хук                                    | ломает коммит у разработчика, не в CI                      |
| `docker-compose.yml`, `.gitignore`                                                   | пути и игноры                                    | тихо расходятся с реальностью                              |
