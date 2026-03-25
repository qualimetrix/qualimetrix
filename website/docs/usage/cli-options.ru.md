# Опции CLI

AI Mess Detector предоставляет команду `analyze` для анализа кода и несколько вспомогательных команд для работы с baseline, git-хуками и визуализацией графа зависимостей.

## Команда analyze

```bash
bin/aimd check [опции] [--] [<пути>...]
```

### Аргумент paths

Укажите одну или несколько директорий или файлов для анализа:

```bash
# Анализ конкретных директорий
bin/aimd check src/ lib/

# Анализ одного файла
bin/aimd check src/Service/UserService.php
```

Если пути не указаны, AIMD автоматически определит их из секции `autoload` вашего `composer.json`.

---

## Опции файлов

### `--config`, `-c`

Путь к YAML-файлу конфигурации:

```bash
bin/aimd check src/ --config=aimd.yaml
```

### `--exclude`

Исключить директории из анализа. Можно указывать несколько раз:

```bash
bin/aimd check src/ --exclude=src/Generated --exclude=src/Legacy
```

### `--include-generated`

По умолчанию AIMD автоматически пропускает файлы, содержащие аннотацию `@generated` в первых 2 КБ. Этот флаг переопределяет это поведение и включает сгенерированные файлы в анализ:

```bash
bin/aimd check src/ --include-generated
```

Также можно задать в `aimd.yaml`:

```yaml
include_generated: true
```

### `--exclude-path`

Подавить нарушения для файлов, соответствующих glob-паттерну. Файлы по-прежнему анализируются (их метрики учитываются при расчёте метрик пространства имён), но нарушения не выводятся. Можно указывать несколько раз:

```bash
bin/aimd check src/ --exclude-path="src/Entity/*" --exclude-path="src/DTO/*"
```

---

## Опции вывода

### `--format`, `-f`

Выбор формата вывода. По умолчанию: `summary`.

```bash
bin/aimd check src/ --format=json
bin/aimd check src/ --format=sarif
```

Доступные форматы: `summary`, `text`, `text-verbose`, `json`, `metrics`, `checkstyle`, `sarif`, `gitlab`, `github`, `health`.

Подробности о каждом формате смотрите в разделе [Форматы вывода](output-formats.md).

### `--group-by`

Группировка нарушений в выводе. Значение по умолчанию зависит от форматтера.

```bash
bin/aimd check src/ --format=text-verbose --group-by=rule
```

Доступные значения: `none`, `file`, `rule`, `severity`.

### `--format-opt`

Передача специфичных для форматтера опций в формате key=value. Можно указывать несколько раз:

```bash
bin/aimd check src/ --format-opt=key=value
```

**Опции формата JSON:**

| Опция               | По умолчанию | Описание                                |
| ------------------- | ------------ | --------------------------------------- |
| `violations=N\|all` | 50           | Макс. кол-во нарушений в выводе (0=нет) |
| `limit=N`           | 50           | Псевдоним для `violations`              |
| `top=N`             | 10           | Количество худших нарушителей           |

```bash
bin/aimd check src/ --format=json --format-opt=limit=100
bin/aimd check src/ --format=json --format-opt=violations=all
```

### `--fail-on`

Минимальный уровень нарушения, при котором возвращается ненулевой код выхода. По умолчанию: `error`.

```bash
# Поведение по умолчанию: ошибка только при error, предупреждения допускаются
bin/aimd check src/ --fail-on=error

# Ошибка и при warning (для строгого контроля качества)
bin/aimd check src/ --fail-on=warning
```

Предупреждения по-прежнему отображаются в выводе, но по умолчанию не приводят к ненулевому коду завершения. Используйте `--fail-on=warning`, если хотите, чтобы предупреждения также блокировали CI.

Также можно задать в `aimd.yaml`:

```yaml
fail_on: error
```

### `--exclude-health`

Исключить конкретные измерения здоровья из оценки. Исключённые измерения не отображаются в сводке здоровья и не влияют на общую оценку. Можно указывать несколько раз:

```bash
# Исключить типизацию из оценки здоровья
bin/aimd check src/ --exclude-health=typing

# Исключить несколько измерений
bin/aimd check src/ --exclude-health=typing --exclude-health=maintainability
```

Доступные измерения: `complexity`, `cohesion`, `coupling`, `typing`, `maintainability`.

Также можно задать в `aimd.yaml`:

```yaml
exclude_health:
  - typing
```

### `--detail`

Показать группированный список нарушений после сводки. Действует только на формат `summary`.

```bash
# Лимит по умолчанию (200 нарушений)
bin/aimd check src/ --detail

# Показать все нарушения (без лимита)
bin/aimd check src/ --detail=all

# Пользовательский лимит
bin/aimd check src/ --detail=50
```

Автоматически включается при использовании `--namespace` или `--class`.

### `--namespace`

Фильтрация вывода по конкретному поддереву пространства имён. Использует сопоставление по префиксу с учётом границ.

```bash
bin/aimd check src/ --namespace=App\\Service
```

Фильтрует нарушения и худших нарушителей по указанному пространству имён. Показывает оценки здоровья поддерева. Автоматически включает `--detail`.

Взаимоисключающий с `--class`.

### `--class`

Фильтрация вывода по конкретному классу с точным совпадением FQCN.

```bash
bin/aimd check src/ --class=App\\Service\\UserService
```

Фильтрует нарушения по указанному классу. Автоматически включает `--detail`.

Взаимоисключающий с `--namespace`.

---

## Опции кэширования

AIMD кэширует разобранные AST-деревья для ускорения повторных запусков.

### `--no-cache`

Полностью отключить кэширование:

```bash
bin/aimd check src/ --no-cache
```

### `--cache-dir`

Указать директорию кэша. По умолчанию: `.aimd-cache`.

```bash
bin/aimd check src/ --cache-dir=/tmp/aimd-cache
```

### `--clear-cache`

Очистить кэш перед запуском анализа:

```bash
bin/aimd check src/ --clear-cache
```

---

## Опции baseline

Baseline позволяет игнорировать известные нарушения и сосредоточиться на новых. Полное руководство смотрите в разделе [Baseline](baseline.md).

### `--generate-baseline`

Запустить анализ и сохранить все текущие нарушения в файл baseline:

```bash
bin/aimd check src/ --generate-baseline=baseline.json
```

### `--baseline`

Отфильтровать нарушения, которые уже есть в файле baseline:

```bash
bin/aimd check src/ --baseline=baseline.json
```

### `--show-resolved`

Показать, сколько нарушений из baseline были исправлены:

```bash
bin/aimd check src/ --baseline=baseline.json --show-resolved
```

### `--baseline-ignore-stale`

По умолчанию AIMD выдаёт ошибку, если baseline ссылается на файлы, которых больше не существует. Этот флаг позволяет молча игнорировать устаревшие записи:

```bash
bin/aimd check src/ --baseline=baseline.json --baseline-ignore-stale
```

---

## Опции подавления

### `--show-suppressed`

Показать нарушения, подавленные тегами `@aimd-ignore`:

```bash
bin/aimd check src/ --show-suppressed
```

### `--no-suppression`

Игнорировать все теги `@aimd-ignore` и выводить все нарушения:

```bash
bin/aimd check src/ --no-suppression
```

---

## Опции области Git

Анализ или вывод нарушений только для изменённых файлов. Полное руководство смотрите в разделе [Интеграция с Git](git-integration.md).

### `--analyze`

Управление тем, какие файлы анализировать. Принимает git scope выражение:

```bash
bin/aimd check src/ --analyze=git:staged          # только файлы из staging
bin/aimd check src/ --analyze=git:main..HEAD       # только файлы, изменённые с main
```

### `--report`

Управление тем, какие нарушения выводить. Анализирует весь проект, но показывает только нарушения из изменённых файлов:

```bash
bin/aimd check src/ --report=git:main..HEAD
bin/aimd check src/ --report=git:origin/develop..HEAD
```

### `--report-strict`

В режиме diff показывать нарушения только из самих изменённых файлов. Без этого флага также выводятся нарушения из родительских пространств имён:

```bash
bin/aimd check src/ --report=git:main..HEAD --report-strict
```

---

## Опции выполнения

### `--workers`, `-w`

Управление параллельной обработкой. По умолчанию: автоопределение по количеству CPU.

```bash
# Отключить параллельную обработку (однопоточный режим)
bin/aimd check src/ --workers=0

# Использовать ровно 4 воркера
bin/aimd check src/ --workers=4
```

!!! tip "Совет"
    Используйте `--workers=0` для отладки или в окружениях, которые не поддерживают `ext-parallel`.

### `--log-file`

Записывать отладочный лог в файл:

```bash
bin/aimd check src/ --log-file=aimd.log
```

### `--log-level`

Установить минимальный уровень логирования. По умолчанию: `info`.

```bash
bin/aimd check src/ --log-file=aimd.log --log-level=debug
```

Доступные уровни: `debug`, `info`, `warning`, `error`.

### `--no-progress`

Отключить прогресс-бар. Полезно в CI-пайплайнах:

```bash
bin/aimd check src/ --no-progress
```

---

<!-- llms:skip-begin -->
## Опции профилирования

### `--profile`

Включить внутренний профайлер. Опционально можно указать файл для сохранения профиля:

```bash
<!-- llms:skip-end -->

# Показать сводку профилирования на экране
bin/aimd check src/ --profile

# Сохранить профиль в файл
bin/aimd check src/ --profile=profile.json
```

### `--profile-format`

Выбор формата экспорта профиля. По умолчанию: `json`.

```bash
bin/aimd check src/ --profile=profile.json --profile-format=chrome-tracing
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
bin/aimd check src/ --disable-rule=size.class-count

# Отключить все правила сложности
bin/aimd check src/ --disable-rule=complexity

# Отключить несколько
bin/aimd check src/ --disable-rule=complexity --disable-rule=design.lcom
```

!!! tip "Оптимизация памяти"
    Отключение `duplication.code-duplication` также полностью пропускает ресурсоёмкую фазу обнаружения дубликатов. На больших кодовых базах (500+ файлов) это может значительно снизить потребление памяти. Используйте `--disable-rule=duplication`, если возникают ошибки нехватки памяти.

### `--only-rule`

Запустить только указанные правила или группы. Можно указывать несколько раз:

```bash
# Запустить только правила сложности
bin/aimd check src/ --only-rule=complexity

# Запустить два конкретных правила
bin/aimd check src/ --only-rule=complexity.cyclomatic --only-rule=size.method-count
```

### `--rule-opt`

Переопределить опции правил из командной строки. Формат: `rule-name:option=value`. Можно указывать несколько раз:

```bash
bin/aimd check src/ --rule-opt=complexity.cyclomatic:method.warning=15
bin/aimd check src/ --rule-opt=complexity.cyclomatic:method.error=30
```

<!-- llms:skip-begin -->
### Быстрые флаги для правил

Для многих правил доступны специальные CLI-флаги для быстрой настройки пороговых значений:

=== "Сложность"

| Флаг                           | Правило               | Опция             |
| ------------------------------ | --------------------- | ----------------- |
| `--cyclomatic-warning=N`       | complexity.cyclomatic | method.warning    |
| `--cyclomatic-error=N`         | complexity.cyclomatic | method.error      |
| `--cyclomatic-class-warning=N` | complexity.cyclomatic | class.max_warning |
| `--cyclomatic-class-error=N`   | complexity.cyclomatic | class.max_error   |
| `--cognitive-warning=N`        | complexity.cognitive  | method.warning    |
| `--cognitive-error=N`          | complexity.cognitive  | method.error      |
| `--cognitive-class-warning=N`  | complexity.cognitive  | class.max_warning |
| `--cognitive-class-error=N`    | complexity.cognitive  | class.max_error   |
| `--npath-warning=N`            | complexity.npath      | method.warning    |
| `--npath-error=N`              | complexity.npath      | method.error      |
| `--npath-class-warning=N`      | complexity.npath      | class.max_warning |
| `--npath-class-error=N`        | complexity.npath      | class.max_error   |
| `--wmc-warning=N`              | complexity.wmc        | warning           |
| `--wmc-error=N`                | complexity.wmc        | error             |

=== "Связанность"

| Флаг                            | Правило              | Опция                 |
| ------------------------------- | -------------------- | --------------------- |
| `--cbo-warning=N`               | coupling.cbo         | class.warning         |
| `--cbo-error=N`                 | coupling.cbo         | class.error           |
| `--cbo-ns-warning=N`            | coupling.cbo         | namespace.warning     |
| `--cbo-ns-error=N`              | coupling.cbo         | namespace.error       |
| `--distance-warning=N`          | coupling.distance    | max_distance_warning  |
| `--distance-error=N`            | coupling.distance    | max_distance_error    |
| `--instability-class-warning=N` | coupling.instability | class.max_warning     |
| `--instability-class-error=N`   | coupling.instability | class.max_error       |
| `--instability-ns-warning=N`    | coupling.instability | namespace.max_warning |
| `--instability-ns-error=N`      | coupling.instability | namespace.max_error   |

=== "Размер"

| Флаг                       | Правило           | Опция   |
| -------------------------- | ----------------- | ------- |
| `--class-count-warning=N`  | size.class-count  | warning |
| `--class-count-error=N`    | size.class-count  | error   |
| `--method-count-warning=N` | size.method-count | warning |
| `--method-count-error=N`   | size.method-count | error   |

=== "Проектирование"

| Флаг                                 | Правило              | Опция               |
| ------------------------------------ | -------------------- | ------------------- |
| `--dit-warning=N`                    | design.inheritance   | warning             |
| `--dit-error=N`                      | design.inheritance   | error               |
| `--lcom-warning=N`                   | design.lcom          | warning             |
| `--lcom-error=N`                     | design.lcom          | error               |
| `--lcom-min-methods=N`               | design.lcom          | minMethods          |
| `--lcom-exclude-readonly`            | design.lcom          | excludeReadonly     |
| `--noc-warning=N`                    | design.noc           | warning             |
| `--noc-error=N`                      | design.noc           | error               |
| `--type-coverage-param-warning=N`    | design.type-coverage | param_warning       |
| `--type-coverage-param-error=N`      | design.type-coverage | param_error         |
| `--type-coverage-return-warning=N`   | design.type-coverage | return_warning      |
| `--type-coverage-return-error=N`     | design.type-coverage | return_error        |
| `--type-coverage-property-warning=N` | design.type-coverage | property_warning    |
| `--type-coverage-property-error=N`   | design.type-coverage | property_error      |
| `--property-exclude-readonly`        | size.property-count  | excludeReadonly     |
| `--property-exclude-promoted-only`   | size.property-count  | excludePromotedOnly |

=== "Сопровождаемость"

| Флаг                 | Правило               | Опция        |
| -------------------- | --------------------- | ------------ |
| `--mi-warning=N`     | maintainability.index | warning      |
| `--mi-error=N`       | maintainability.index | error        |
| `--mi-min-loc=N`     | maintainability.index | minLoc       |
| `--mi-exclude-tests` | maintainability.index | excludeTests |

=== "Запахи кода"

| Флаг                                    | Правило                              | Опция               |
| --------------------------------------- | ------------------------------------ | ------------------- |
| `--constructor-overinjection-warning=N` | code-smell.constructor-overinjection | warning             |
| `--constructor-overinjection-error=N`   | code-smell.constructor-overinjection | error               |
| `--data-class-woc-threshold=N`          | code-smell.data-class                | wocThreshold        |
| `--data-class-wmc-threshold=N`          | code-smell.data-class                | wmcThreshold        |
| `--data-class-min-methods=N`            | code-smell.data-class                | minMethods          |
| `--data-class-exclude-readonly`         | code-smell.data-class                | excludeReadonly     |
| `--data-class-exclude-promoted-only`    | code-smell.data-class                | excludePromotedOnly |
| `--god-class-wmc-threshold=N`           | code-smell.god-class                 | wmcThreshold        |
| `--god-class-lcom-threshold=N`          | code-smell.god-class                 | lcomThreshold       |
| `--god-class-tcc-threshold=N`           | code-smell.god-class                 | tccThreshold        |
| `--god-class-class-loc-threshold=N`     | code-smell.god-class                 | classLocThreshold   |
| `--god-class-min-criteria=N`            | code-smell.god-class                 | minCriteria         |
| `--god-class-min-methods=N`             | code-smell.god-class                 | minMethods          |
| `--god-class-exclude-readonly`          | code-smell.god-class                 | excludeReadonly     |
| `--long-parameter-list-warning=N`       | code-smell.long-parameter-list       | warning             |
| `--long-parameter-list-error=N`         | code-smell.long-parameter-list       | error               |
| `--unreachable-code-warning=N`          | code-smell.unreachable-code          | warning             |
| `--unreachable-code-error=N`            | code-smell.unreachable-code          | error               |

=== "Архитектура"

| Флаг                 | Правило                          | Опция        |
| -------------------- | -------------------------------- | ------------ |
| `--circular-deps`    | architecture.circular-dependency | enabled      |
| `--max-cycle-size=N` | architecture.circular-dependency | maxCycleSize |

---

<!-- llms:skip-end -->

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

# Экспорт в формате JSON (агрегированный список смежности с метаданными)
bin/aimd graph:export src/ --format=json -o graph.json

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

| Опция                    | Описание                                                       |
| ------------------------ | -------------------------------------------------------------- |
| `-o`, `--output=FILE`    | Выходной файл (по умолчанию: stdout)                           |
| `-f`, `--format=FORMAT`  | `dot` (по умолчанию), `json` или `mermaid`                     |
| `-d`, `--direction=DIR`  | Направление графа: `LR`, `TB`, `RL`, `BT` (по умолчанию: `LR`) |
| `--no-clusters`          | Не группировать узлы по пространствам имён                     |
| `--namespace=NS`         | Включить только указанные пространства имён (можно повторять)  |
| `--exclude-namespace=NS` | Исключить указанные пространства имён (можно повторять)        |

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

### rules

Вывести список всех доступных правил с описаниями и опциями CLI:

```bash
# Показать все правила
bin/aimd rules

# Фильтр по группе
bin/aimd rules --group=complexity
```

**Пример вывода:**

```
complexity.cyclomatic    Cyclomatic complexity (McCabe)
  --cyclomatic-warning=N         method.warning (default: 10)
  --cyclomatic-error=N           method.error (default: 20)
  --cyclomatic-class-warning=N   class.max_warning (default: 30)
  --cyclomatic-class-error=N     class.max_error (default: 50)

complexity.cognitive     Cognitive complexity (SonarSource)
  --cognitive-warning=N          method.warning (default: 15)
  --cognitive-error=N            method.error (default: 30)
  ...
```
