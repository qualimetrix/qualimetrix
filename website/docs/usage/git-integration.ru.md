# Интеграция с Git

Qualimetrix интегрируется с Git, позволяя анализировать только изменённый код. Это экономит время и помогает обнаружить проблемы до того, как они попадут в основную ветку.

## Зачем анализировать только изменённый код?

Запуск полного анализа на большой кодовой базе может занять время. Что ещё важнее, в legacy-проекте может быть сотни существующих нарушений, которые сейчас не ваша ответственность. Интеграция с Git решает обе проблемы:

- **Скорость** -- анализ только тех файлов, которые вы изменили
- **Фокус** -- вы видите только те нарушения, которые вы внесли
- **Постепенное внедрение** -- начните использовать Qualimetrix, не исправляя каждую старую проблему

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

---

## Workflow для PR с --report

Опция `--report` показывает нарушения только в файлах, изменённых относительно указанной Git-ссылки:

```bash
# Сравнение с веткой main
bin/qmx check src/ --report=git:main..HEAD

# Сравнение с конкретной веткой
bin/qmx check src/ --report=git:origin/develop..HEAD

# Сравнение с конкретным коммитом
bin/qmx check src/ --report=git:abc1234..HEAD
```

!!! note "Примечание"
    При использовании `--report` Qualimetrix по-прежнему анализирует всю кодовую базу (полные метрики нужны для правил уровня пространства имён). Он только *фильтрует вывод*, показывая нарушения из изменённых файлов.

---

## Как работает --report

Опция `--report` управляет тем, какие нарушения показываются в выводе. Qualimetrix по-прежнему анализирует всю кодовую базу (полные метрики нужны для правил уровня пространства имён), но выводит нарушения только из изменённых файлов:

```bash
# Анализировать всё, выводить только изменённые файлы
bin/qmx check src/ --report=git:main..HEAD
```

Это даёт точные метрики, но показывает только релевантные нарушения.

| Сценарий                        | Рекомендация              |
| ------------------------------- | ------------------------- |
| Pre-commit хук (важна скорость) | `bin/qmx hook:install`    |
| Ревью PR (важна точность)       | `--report=git:main..HEAD` |
| CI-пайплайн с полным анализом   | `--report=git:main..HEAD` |

---

## --report-strict

По умолчанию при использовании `--diff` или `--report` Qualimetrix также показывает нарушения из родительских пространств имён изменённых файлов. Это полезно, потому что добавление класса в пространство имён может привести к превышению лимитов размера.

Если вы хотите видеть нарушения только из самих изменённых файлов:

```bash
bin/qmx check src/ --report=git:main..HEAD --report-strict
```

---

## Синтаксис областей

Опция `--report` принимает выражения области:

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

# Строгий режим: только нарушения в ваших изменённых файлах
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
