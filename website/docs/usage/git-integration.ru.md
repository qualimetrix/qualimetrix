# Интеграция с Git

Git reporting сохраняет выбранные пути анализа и ограничивает публикацию находок относительно Git-ссылки. Это помогает сосредоточиться на изменённом коде, сохраняя контекст метрик.

## Зачем ограничивать отчёт изменениями?

`--report` фильтрует публикацию после анализа выбранных путей. Он не обещает
ускорение collection и не определяет, что нарушение было внесено именно этим
коммитом. Находки по изменённым файлам сохраняются вместе с нужными namespace/
project результатами и объявленными project-scoped диагностическими находками,
включая проектные циклы архитектуры. Нарушения слоёв относятся к source-файлу.

---

## Быстрый старт

Два самых распространённых сценария:

```bash
# Перед коммитом: установка pre-commit хука
bin/qmx hook:install

# Перед мержем: проверка изменений относительно main
bin/qmx check src/ --report=git:main..HEAD
```

---

## Workflow pre-commit

Установите Git-хук для автоматической проверки staged-файлов перед каждым коммитом:

```bash
bin/qmx hook:install
```

Это создаёт скрипт `.git/hooks/pre-commit`, который автоматически запускает Qualimetrix на staged-файлах перед каждым коммитом. Анализируются только PHP-файлы, подготовленные к коммиту, что делает проверку быстрой. Если обнаружены нарушения с уровнем `error`, коммит блокируется.

Проверить статус хука:

```bash
bin/qmx hook:status
```

Удалить хук:

```bash
bin/qmx hook:uninstall
```

Если у вас уже был pre-commit хук, Qualimetrix сделает его резервную копию. Чтобы восстановить его:

```bash
bin/qmx hook:uninstall --restore-backup
```

!!! warning "Внимание"
    `hook:install` не перезапишет существующий хук без флага `--force`.

Резервная копия — одна ячейка, `pre-commit.backup`, потому что `--restore-backup`
восстанавливает именно из неё. Если в ячейке уже лежит *другой* хук, `hook:install --force`
отказывает, а не затирает его: сначала перенесите старую копию. Повторный `--force` с тем же
хуком или замена хука Qualimetrix оставляют копию как есть.

Когда hook-команда не может выполнить запрошенное — это не git-репозиторий, хук чужой,
ячейка копии занята, — она завершается с кодом `3` и пишет причину в stderr, как и при любом
другом отклонённом вводе. `--working-dir` работает со всеми тремя командами, в том числе когда
бинарь запущен по относительному пути (`php vendor/bin/qmx hook:install -d ../app`).

Копия сохраняет права исходного хука. `--restore-backup` переносит inode
копии на имя хука и освобождает ячейку копии. Нечитаемый хук и ошибка файловой
операции дают отказ среды с кодом 3; такой хук не объявляется исправным.
Назначения и копии проверяются по той же политике ссылок, что и файловый
вывод; возможность изменения пути сообщается один раз для каждого адресата.

---

## Workflow для PR с --report

Опция `--report` ограничивает публикацию относительно Git-ссылки, сохраняя описанные ниже namespace/project результаты и диагностику конфигурации:

```bash
# Сравнение с веткой main
bin/qmx check src/ --report=git:main..HEAD

# Сравнение с конкретной веткой
bin/qmx check src/ --report=git:origin/develop..HEAD

# Сравнение с конкретным коммитом
bin/qmx check src/ --report=git:abc1234..HEAD
```

!!! note "Примечание"
    `--report` сохраняет выбранные пути анализа и только фильтрует публикацию. Охват определяется фактическим анализом, а не Git-фильтром.

---

## Как работает --report

Находка с code file location сохраняется по этому файлу, включая duplication.
В обычном режиме также сохраняются namespace-находки в namespace изменённого
PHP и его предках и project-находки без location при непустом наборе changed PHP.
Объявленные project-scoped каналы сохраняются независимо от changed files в
обоих режимах. Сейчас к ним относятся находки `architecture.circular-dependency`
о циклах и project-scoped diagnostics, в том числе в strict-режиме.
`architecture.layer-violation` сохраняется только при изменении source-файла
в обоих режимах. Изменение только цели не сохраняет исходящее нарушение.
Список каналов и два вопроса описаны в
[охвате проекта](output-formats.ru.md#project-scope-in-every-format).
Namespace query не читает source через file links.

## --report-strict

Strict ограничивает file-scoped находки изменёнными файлами и убирает
namespace/project расширение. Объявленные project-scoped находки, включая
циклы архитектуры, сохраняются. Нарушения слоёв фильтруются по source-файлу:

```bash
bin/qmx check src/ --report=git:main..HEAD --report-strict
```

---

## Синтаксис областей

Опция `--report` принимает выражения области:

Пустая сторона диапазона заменяется `HEAD` до проверки refs: `git:..main` —
`HEAD..main`, `git:main..` — `main..HEAD`. Diff выдаёт пути от корня репозитория
через `--no-relative`; запуск из подкаталога не меняет их смысл. Требуется Git 2.28
с поддержкой этого флага; его отказ сообщается без второго version probe.


| Выражение                  | Значение                                        |
| -------------------------- | ----------------------------------------------- |
| `git:staged`               | Файлы, подготовленные к коммиту                 |
| `git:main..HEAD`           | Файлы, изменённые между main и HEAD             |
| `git:origin/develop..HEAD` | Файлы, изменённые между удалённой веткой и HEAD |
| `git:abc1234..HEAD`        | Файлы, изменённые с указанного коммита          |

---

## Примеры рабочих процессов

### Локальная разработка

```bash
# Одноразовая настройка
bin/qmx hook:install

# Теперь каждый коммит проверяется автоматически
git add src/Service/UserService.php
git commit -m "refactor: simplify UserService"
# Qualimetrix запускается автоматически на staged-файлах, блокирует коммит при ошибках
```

### Ревью пулл-реквеста

```bash
# На feature-ветке, проверка относительно main
bin/qmx check src/ --report=git:main..HEAD

# Strict: code-находки изменённых файлов и project-scoped диагностика
bin/qmx check src/ --report=git:main..HEAD --report-strict

# С JSON-выводом для CI
bin/qmx check src/ --report=git:main..HEAD --format=json --no-progress
```

### CI-пайплайн (GitHub Actions)

```yaml
- name: Run Qualimetrix
  run: bin/qmx check src/ --report=git:origin/main..HEAD --format=sarif --no-progress > results.sarif

- name: Upload SARIF
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: results.sarif
```

### CI-пайплайн (GitLab CI)

```yaml
code_quality:
  script:
    - bin/qmx check src/ --report=git:origin/main..HEAD --format=gitlab --no-progress > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```
