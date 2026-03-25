# Конфигурация

AI Mess Detector работает из коробки с разумными настройками по умолчанию. Файл конфигурации позволяет настроить пороговые значения, отключить правила и исключить пути, чтобы адаптировать инструмент под ваш проект.

---

## Файл конфигурации

Создайте файл `aimd.yaml` в корне проекта. AI Mess Detector автоматически ищет этот файл.

Также можно указать файл явно:

```bash
vendor/bin/aimd check src/ --config=my-config.yaml
```

---

## Секции конфигурации

### Пути для анализа (paths)

Директории для анализа:

```yaml
paths:
  - src/
```

!!! note "Примечание"
    Если вы передаёте пути через аргументы командной строки (например, `vendor/bin/aimd check src/ lib/`), они имеют приоритет над конфигурационным файлом.

### Исключения (exclude)

Директории, которые полностью пропускаются. Файлы в этих директориях не анализируются вообще:

```yaml
exclude:
  - vendor/
  - tests/Fixtures/
```

### Включение сгенерированных файлов (include_generated)

По умолчанию файлы с аннотацией `@generated` в первых 2 КБ автоматически пропускаются при анализе. Чтобы включить их:

```yaml
include_generated: true
```

Эквивалент в CLI: `--include-generated`

### Исключение путей из отчёта (exclude_paths)

Glob-паттерны для подавления нарушений. В отличие от `exclude`, эти файлы **всё равно анализируются** (их метрики собираются), но нарушения не выводятся в отчёт. Это полезно для сгенерированного кода, простых классов данных или файлов сущностей, где правила сложности не имеют смысла:

```yaml
exclude_paths:
  - src/Entity/*
  - src/DTO/*
```

### Правила (rules)

Управление активными правилами и настройка пороговых значений.

**Полное отключение правила:**

```yaml
rules:
  code-smell.boolean-argument:
    enabled: false
```

**Переопределение пороговых значений:**

Каждое правило определяет уровни серьёзности. Когда метрика превышает порог, фиксируется нарушение соответствующего уровня. Например, правило цикломатической сложности имеет пороги для методов:

```yaml
rules:
  complexity.cyclomatic:
    method:
      warning: 15
      error: 25
```

Это означает: выдавать **предупреждение** (warning), когда цикломатическая сложность метода достигает 15, и **ошибку** (error), когда достигает 25.

**Сокращённая запись `threshold`:**

Если вам нужен простой порог "прошёл/не прошёл" (все нарушения — ошибки), используйте `threshold` вместо отдельных `warning` и `error`:

```yaml
rules:
  complexity.cyclomatic:
    method:
      threshold: 15   # warning=15 и error=15 → все нарушения становятся ошибками
```

Это эквивалентно `warning: 15` + `error: 15`. Полезно в CI, где нужен бинарный результат.

!!! warning "Ограничение"
    Нельзя смешивать `threshold` с явными ключами `warning`/`error` в одном правиле. Используйте либо `threshold`, либо пару `warning`/`error`.

Для правила `design.type-coverage` доступны специализированные варианты: `param_threshold`, `return_threshold`, `property_threshold`.

Вычисляемые метрики (computed metrics) также поддерживают `threshold`.

**Исключение неймспейсов из правила:**

Любое правило может исключить конкретные неймспейсы по префиксу. Нарушения из совпадающих неймспейсов подавляются:

```yaml
rules:
  complexity.cyclomatic:
    exclude_namespaces:
      - App\Tests
      - App\Legacy
    method:
      warning: 15
      error: 25

  coupling.cbo:
    exclude_namespaces:
      - App\Tests
```

Это полезно, когда определённые неймспейсы (например, тесты, сгенерированный код, legacy-модули) не должны вызывать нарушения для конкретного правила, но при этом всё равно анализируются для сбора метрик.

### Отключение правил (disabled_rules)

Отключение конкретных правил или целых групп:

```yaml
disabled_rules:
  - code-smell.boolean-argument
  - duplication
```

Эквивалент в CLI: `--disable-rule=code-smell.boolean-argument --disable-rule=duplication`

### Только указанные правила (only_rules)

Запустить только указанные правила (все остальные отключаются):

```yaml
only_rules:
  - complexity.cyclomatic
  - complexity.cognitive
```

Эквивалент в CLI: `--only-rule=complexity.cyclomatic --only-rule=complexity.cognitive`

### Условие завершения с ошибкой (fail_on)

Управление тем, какие уровни серьёзности приводят к ненулевому коду завершения:

```yaml
fail_on: error    # Завершение с ошибкой только при error (по умолчанию)
# fail_on: warning  # Завершение с ошибкой и при warning
# fail_on: none     # Никогда не завершаться с ошибкой из-за нарушений
```

!!! note "Примечание"
    По умолчанию `fail_on` установлен в `error`. Предупреждения по-прежнему отображаются в выводе, но не приводят к ненулевому коду завершения. Для старого поведения (падение при предупреждениях) используйте `fail_on: warning`.

### Исключение измерений здоровья (exclude_health)

Исключить конкретные измерения здоровья из оценки. Исключённые измерения не отображаются в сводке здоровья и не влияют на общую оценку:

```yaml
exclude_health:
  - typing
  - maintainability
```

Эквивалент в CLI: `--exclude-health=typing --exclude-health=maintainability`

### Формат вывода (format)

Формат отчёта по умолчанию:

```yaml
format: summary   # По умолчанию
# format: json
# format: html
```

---

## Полный пример

```yaml
paths:
  - src/

exclude:
  - vendor/
  - tests/Fixtures/

exclude_paths:
  - src/Entity/*
  - src/DTO/*

include_generated: false

format: summary
fail_on: error

exclude_health:
  - typing

disabled_rules:
  - code-smell.boolean-argument
  - duplication

rules:
  complexity.cyclomatic:
    exclude_namespaces:
      - App\Tests
    method:
      warning: 15
      error: 25

  size.method-count:
    warning: 25
    error: 40
```

---

## Параметры CLI имеют приоритет

Параметры командной строки всегда имеют приоритет над значениями из конфигурационного файла. Например:

```bash
# В конфиге указано paths: [src/], но CLI переопределяет
vendor/bin/aimd check lib/

# Добавить дополнительные исключения поверх конфига
vendor/bin/aimd check src/ --exclude-path='src/Generated/*'
```

Это позволяет экспериментировать без редактирования файла конфигурации.

---

## Что дальше?

Смотрите [справочник параметров CLI](../usage/cli-options.md) для полного списка параметров командной строки.
