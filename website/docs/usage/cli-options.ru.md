# Опции CLI

AI Mess Detector предоставляет команду `analyze` для анализа кода и несколько вспомогательных команд для работы с baseline, git-хуками и визуализацией графа зависимостей.

## Команда analyze

```bash
bin/aimd analyze [опции] [--] [<пути>...]
```

### Аргумент paths

Укажите одну или несколько директорий или файлов для анализа:

```bash
# Анализ конкретных директорий
bin/aimd analyze src/ lib/

# Анализ одного файла
bin/aimd analyze src/Service/UserService.php
```

Если пути не указаны, AIMD автоматически определит их из секции `autoload` вашего `composer.json`.

---

## Опции файлов

### `--config`, `-c`

Путь к YAML-файлу конфигурации:

```bash
bin/aimd analyze src/ --config=aimd.yaml
```

### `--exclude`

Исключить директории из анализа. Можно указывать несколько раз:

```bash
bin/aimd analyze src/ --exclude=src/Generated --exclude=src/Legacy
```

### `--exclude-path`

Подавить нарушения для файлов, соответствующих glob-паттерну. Файлы по-прежнему анализируются (их метрики учитываются при расчёте метрик пространства имён), но нарушения не выводятся. Можно указывать несколько раз:

```bash
bin/aimd analyze src/ --exclude-path="src/Entity/*" --exclude-path="src/DTO/*"
```

---

## Опции вывода

### `--format`, `-f`

Выбор формата вывода. По умолчанию: `text`.

```bash
bin/aimd analyze src/ --format=json
bin/aimd analyze src/ --format=sarif
```

Доступные форматы: `text`, `text-verbose`, `json`, `checkstyle`, `sarif`, `gitlab`.

Подробности о каждом формате смотрите в разделе [Форматы вывода](output-formats.md).

### `--group-by`

Группировка нарушений в выводе. Значение по умолчанию зависит от форматтера.

```bash
bin/aimd analyze src/ --format=text-verbose --group-by=rule
```

Доступные значения: `none`, `file`, `rule`, `severity`.

### `--format-opt`

Передача специфичных для форматтера опций в формате key=value. Можно указывать несколько раз:

```bash
bin/aimd analyze src/ --format-opt=key=value
```

---

## Опции кэширования

AIMD кэширует разобранные AST-деревья для ускорения повторных запусков.

### `--no-cache`

Полностью отключить кэширование:

```bash
bin/aimd analyze src/ --no-cache
```

### `--cache-dir`

Указать директорию кэша. По умолчанию: `.aimd-cache`.

```bash
bin/aimd analyze src/ --cache-dir=/tmp/aimd-cache
```

### `--clear-cache`

Очистить кэш перед запуском анализа:

```bash
bin/aimd analyze src/ --clear-cache
```

---

## Опции baseline

Baseline позволяет игнорировать известные нарушения и сосредоточиться на новых. Полное руководство смотрите в разделе [Baseline](baseline.md).

### `--generate-baseline`

Запустить анализ и сохранить все текущие нарушения в файл baseline:

```bash
bin/aimd analyze src/ --generate-baseline=baseline.json
```

### `--baseline`

Отфильтровать нарушения, которые уже есть в файле baseline:

```bash
bin/aimd analyze src/ --baseline=baseline.json
```

### `--show-resolved`

Показать, сколько нарушений из baseline были исправлены:

```bash
bin/aimd analyze src/ --baseline=baseline.json --show-resolved
```

### `--baseline-ignore-stale`

По умолчанию AIMD выдаёт ошибку, если baseline ссылается на файлы, которых больше не существует. Этот флаг позволяет молча игнорировать устаревшие записи:

```bash
bin/aimd analyze src/ --baseline=baseline.json --baseline-ignore-stale
```

---

## Опции подавления

### `--show-suppressed`

Показать нарушения, подавленные тегами `@aimd-ignore`:

```bash
bin/aimd analyze src/ --show-suppressed
```

### `--no-suppression`

Игнорировать все теги `@aimd-ignore` и выводить все нарушения:

```bash
bin/aimd analyze src/ --no-suppression
```

---

## Опции области Git

Анализ или вывод нарушений только для изменённых файлов. Полное руководство смотрите в разделе [Интеграция с Git](git-integration.md).

### `--staged`

Анализировать только файлы, добавленные в staging (индекс). Сокращение для `--analyze=git:staged`:

```bash
bin/aimd analyze src/ --staged
```

### `--diff=REF`

Выводить нарушения только для файлов, изменённых по сравнению с указанной git-ссылкой. Сокращение для `--report=git:REF..HEAD`:

```bash
bin/aimd analyze src/ --diff=main
bin/aimd analyze src/ --diff=origin/develop
```

### `--analyze`

Точное управление тем, какие файлы анализировать:

```bash
bin/aimd analyze src/ --analyze=git:staged
bin/aimd analyze src/ --analyze=git:main..HEAD
```

### `--report`

Точное управление тем, какие нарушения выводить:

```bash
bin/aimd analyze src/ --report=git:main..HEAD
```

### `--report-strict`

В режиме diff показывать нарушения только из самих изменённых файлов. Без этого флага также выводятся нарушения из родительских пространств имён:

```bash
bin/aimd analyze src/ --diff=main --report-strict
```

---

## Опции выполнения

### `--workers`, `-w`

Управление параллельной обработкой. По умолчанию: автоопределение по количеству CPU.

```bash
# Отключить параллельную обработку (однопоточный режим)
bin/aimd analyze src/ --workers=0

# Использовать ровно 4 воркера
bin/aimd analyze src/ --workers=4
```

!!! tip "Совет"
    Используйте `--workers=0` для отладки или в окружениях, которые не поддерживают `ext-parallel`.

### `--log-file`

Записывать отладочный лог в файл:

```bash
bin/aimd analyze src/ --log-file=aimd.log
```

### `--log-level`

Установить минимальный уровень логирования. По умолчанию: `info`.

```bash
bin/aimd analyze src/ --log-file=aimd.log --log-level=debug
```

Доступные уровни: `debug`, `info`, `warning`, `error`.

### `--no-progress`

Отключить прогресс-бар. Полезно в CI-пайплайнах:

```bash
bin/aimd analyze src/ --no-progress
```

---

## Опции профилирования

### `--profile`

Включить внутренний профайлер. Опционально можно указать файл для сохранения профиля:

```bash
# Показать сводку профилирования на экране
bin/aimd analyze src/ --profile

# Сохранить профиль в файл
bin/aimd analyze src/ --profile=profile.json
```

### `--profile-format`

Выбор формата экспорта профиля. По умолчанию: `json`.

```bash
bin/aimd analyze src/ --profile=profile.json --profile-format=chrome-tracing
```

Доступные форматы: `json`, `chrome-tracing`.

!!! tip "Совет"
    Используйте формат `chrome-tracing` и откройте файл в Chrome DevTools (chrome://tracing) для визуального анализа производительности.

---

## Опции правил

### `--disable-rule`

Отключить конкретное правило или целую группу по префиксу. Можно указывать несколько раз:

```bash
# Отключить одно правило
bin/aimd analyze src/ --disable-rule=size.class-count

# Отключить все правила сложности
bin/aimd analyze src/ --disable-rule=complexity

# Отключить несколько
bin/aimd analyze src/ --disable-rule=complexity --disable-rule=design.lcom
```

### `--only-rule`

Запустить только указанные правила или группы. Можно указывать несколько раз:

```bash
# Запустить только правила сложности
bin/aimd analyze src/ --only-rule=complexity

# Запустить два конкретных правила
bin/aimd analyze src/ --only-rule=complexity.cyclomatic --only-rule=size.method-count
```

### `--rule-opt`

Переопределить опции правил из командной строки. Формат: `rule-name:option=value`. Можно указывать несколько раз:

```bash
bin/aimd analyze src/ --rule-opt=complexity.cyclomatic:method.warning=15
bin/aimd analyze src/ --rule-opt=complexity.cyclomatic:method.error=30
```

### Быстрые флаги для правил

Для многих правил доступны специальные CLI-флаги для быстрой настройки пороговых значений:

=== "Сложность"

    | Флаг | Правило | Опция |
    |------|---------|-------|
    | `--cc-warning=N` | complexity.cyclomatic | method.warning |
    | `--cc-error=N` | complexity.cyclomatic | method.error |
    | `--cc-class-warning=N` | complexity.cyclomatic | class.max_warning |
    | `--cc-class-error=N` | complexity.cyclomatic | class.max_error |
    | `--cognitive-warning=N` | complexity.cognitive | method.warning |
    | `--cognitive-error=N` | complexity.cognitive | method.error |
    | `--cognitive-class-warning=N` | complexity.cognitive | class.max_warning |
    | `--cognitive-class-error=N` | complexity.cognitive | class.max_error |
    | `--npath-warning=N` | complexity.npath | method.warning |
    | `--npath-error=N` | complexity.npath | method.error |
    | `--npath-class-warning=N` | complexity.npath | class.max_warning |
    | `--npath-class-error=N` | complexity.npath | class.max_error |
    | `--wmc-warning=N` | complexity.wmc | warning |
    | `--wmc-error=N` | complexity.wmc | error |

=== "Связанность"

    | Флаг | Правило | Опция |
    |------|---------|-------|
    | `--cbo-class-warning=N` | coupling.cbo | class.warning |
    | `--cbo-class-error=N` | coupling.cbo | class.error |
    | `--cbo-ns-warning=N` | coupling.cbo | namespace.warning |
    | `--cbo-ns-error=N` | coupling.cbo | namespace.error |
    | `--distance-warning=N` | coupling.distance | max_distance_warning |
    | `--distance-error=N` | coupling.distance | max_distance_error |
    | `--coupling-class-warning=N` | coupling.instability | class.max_warning |
    | `--coupling-class-error=N` | coupling.instability | class.max_error |
    | `--coupling-ns-warning=N` | coupling.instability | namespace.max_warning |
    | `--coupling-ns-error=N` | coupling.instability | namespace.max_error |

=== "Размер"

    | Флаг | Правило | Опция |
    |------|---------|-------|
    | `--ns-warning=N` | size.class-count | warning |
    | `--ns-error=N` | size.class-count | error |
    | `--size-class-warning=N` | size.method-count | warning |
    | `--size-class-error=N` | size.method-count | error |

=== "Проектирование"

    | Флаг | Правило | Опция |
    |------|---------|-------|
    | `--dit-warning=N` | design.inheritance | warning |
    | `--dit-error=N` | design.inheritance | error |
    | `--lcom-warning=N` | design.lcom | warning |
    | `--lcom-error=N` | design.lcom | error |
    | `--lcom-min-methods=N` | design.lcom | minMethods |
    | `--noc-warning=N` | design.noc | warning |
    | `--noc-error=N` | design.noc | error |

=== "Сопровождаемость"

    | Флаг | Правило | Опция |
    |------|---------|-------|
    | `--mi-warning=N` | maintainability.index | warning |
    | `--mi-error=N` | maintainability.index | error |
    | `--mi-min-loc=N` | maintainability.index | minLoc |

---

## Другие команды

### baseline:cleanup

Удалить устаревшие записи (ссылки на файлы, которых больше нет) из файла baseline:

```bash
bin/aimd baseline:cleanup baseline.json
```

### graph:export

Экспортировать граф зависимостей для визуализации:

```bash
# Экспорт в формате DOT (по умолчанию)
bin/aimd graph:export src/ -o graph.dot

# Экспорт в формате Mermaid
bin/aimd graph:export src/ --format=mermaid -o graph.md

# Фильтрация по пространству имён
bin/aimd graph:export src/ --namespace=App\\Service --namespace=App\\Repository

# Исключение пространств имён
bin/aimd graph:export src/ --exclude-namespace=App\\Generated

# Изменение направления графа
bin/aimd graph:export src/ --direction=TB

# Отключение группировки по пространствам имён
bin/aimd graph:export src/ --no-clusters
```

| Опция | Описание |
|-------|----------|
| `-o`, `--output=FILE` | Выходной файл (по умолчанию: stdout) |
| `-f`, `--format=FORMAT` | `dot` (по умолчанию) или `mermaid` |
| `-d`, `--direction=DIR` | Направление графа: `LR`, `TB`, `RL`, `BT` (по умолчанию: `LR`) |
| `--no-clusters` | Не группировать узлы по пространствам имён |
| `--namespace=NS` | Включить только указанные пространства имён (можно повторять) |
| `--exclude-namespace=NS` | Исключить указанные пространства имён (можно повторять) |

### hook:install

Установить git-хук pre-commit:

```bash
bin/aimd hook:install

# Перезаписать существующий хук
bin/aimd hook:install --force
```

### hook:status

Показать текущий статус хука pre-commit:

```bash
bin/aimd hook:status
```

### hook:uninstall

Удалить хук pre-commit:

```bash
bin/aimd hook:uninstall

# Восстановить оригинальный хук из резервной копии
bin/aimd hook:uninstall --restore-backup
```
