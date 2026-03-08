# Пороговые значения по умолчанию

На этой странице перечислены пороговые значения по умолчанию для каждого правила AI Mess Detector. Когда метрика превышает порог **warning**, выдается предупреждение. Когда превышает порог **error** -- ошибка.

## Правила сложности (Complexity)

Правила, которые измеряют, насколько сложно понять и протестировать код.

| Правило | ID | Уровень | Warning | Error | Область |
|---------|-----|---------|---------|-------|---------|
| Cyclomatic Complexity | `complexity.cyclomatic` | Метод | 10 | 20 | Метод |
| Cyclomatic Complexity | `complexity.cyclomatic` | Класс (макс.) | 30 | 50 | Класс |
| Cognitive Complexity | `complexity.cognitive` | Метод | 15 | 30 | Метод |
| Cognitive Complexity | `complexity.cognitive` | Класс (макс.) | 30 | 50 | Класс |
| NPath Complexity | `complexity.npath` | Метод | 200 | 1000 | Метод |
| NPath Complexity | `complexity.npath` | Класс (макс.) | 200 | 1000 | Класс (отключено) |
| WMC | `complexity.wmc` | - | 50 | 80 | Класс |

**Cyclomatic Complexity** подсчитывает количество независимых путей выполнения в методе. Метод с CCN равным 10 имеет 10 различных путей для тестирования.

**Cognitive Complexity** измеряет, насколько сложно читать код. В отличие от цикломатической сложности, вложенные конструкции штрафуются сильнее.

**NPath Complexity** подсчитывает количество возможных путей выполнения. Растет гораздо быстрее, чем цикломатическая сложность для кода с большим количеством условий.

**WMC (Weighted Methods per Class)** -- сумма цикломатических сложностей всех методов класса. Высокий WMC означает, что класс делает слишком много.

## Правила размера (Size)

Правила, которые проверяют, не стали ли классы и пространства имен слишком большими.

| Правило | ID | Warning | Error | Область |
|---------|-----|---------|-------|---------|
| Method Count | `size.method-count` | 20 | 30 | Класс |
| Class Count | `size.class-count` | 15 | 25 | Пространство имен |
| Property Count | `size.property-count` | 15 | 20 | Класс |

## Правила проектирования (Design)

Правила, которые проверяют дизайн классов и структуру наследования.

| Правило | ID | Warning | Error | Область |
|---------|-----|---------|-------|---------|
| LCOM | `design.lcom` | 3 | 5 | Класс |
| NOC | `design.noc` | 10 | 15 | Класс |
| DIT | `design.inheritance` | 4 | 6 | Класс |

**LCOM (Lack of Cohesion of Methods)** измеряет, насколько хорошо методы в классе связаны друг с другом. Высокий LCOM говорит о том, что класс стоит разделить.

**NOC (Number of Children)** подсчитывает прямых наследников. Слишком много наследников означает, что родительский класс может быть слишком общим.

**DIT (Depth of Inheritance Tree)** подсчитывает количество уровней наследования. Глубокие иерархии сложнее понимать и поддерживать.

## Правила связанности (Coupling)

Правила, которые проверяют, насколько тесно классы и пространства имен связаны друг с другом.

| Правило | ID | Warning | Error | Область |
|---------|-----|---------|-------|---------|
| CBO | `coupling.cbo` | 14 | 20 | Класс |
| CBO | `coupling.cbo` | 14 | 20 | Пространство имен |
| Instability | `coupling.instability` | 0.8 | 0.95 | Класс |
| Instability | `coupling.instability` | 0.8 | 0.95 | Пространство имен |
| Distance | `coupling.distance` | 0.3 | 0.5 | Пространство имен |

**CBO (Coupling Between Objects)** подсчитывает количество других классов, от которых зависит данный класс. Высокая связанность затрудняет внесение изменений.

**Instability** -- коэффициент от 0 (полностью стабильный) до 1 (полностью нестабильный). Класс, который зависит от многих других, но от которого никто не зависит -- нестабилен.

**Distance from the Main Sequence** измеряет, насколько хорошо пространство имен балансирует между абстрактностью и стабильностью. Значение, близкое к 0 -- идеально.

## Правила сопровождаемости (Maintainability)

Эти правила работают **наоборот**: нарушение фиксируется, когда метрика падает **ниже** порога, а не превышает его.

| Правило | ID | Warning (ниже) | Error (ниже) | Область |
|---------|-----|---------|-------|---------|
| Maintainability Index | `maintainability.index` | 40 | 20 | Метод |

**Maintainability Index** объединяет сложность, количество строк кода и метрики Холстеда в единую оценку от 0 до 100. Чем выше -- тем лучше. Оценка ниже 20 означает, что код очень сложно поддерживать.

## Правила запахов кода (Code Smell)

Эти правила обнаруживают конкретные паттерны, которые обычно являются плохой практикой. У них нет числовых порогов -- они либо находят паттерн, либо нет.

| Правило | ID | Серьезность | По умолчанию |
|---------|-----|-------------|--------------|
| Boolean Argument | `code-smell.boolean-argument` | Warning | включено |
| count() in Loop | `code-smell.count-in-loop` | Warning | включено |
| Debug Code | `code-smell.debug-code` | Error | включено |
| Empty Catch | `code-smell.empty-catch` | Error | включено |
| Error Suppression | `code-smell.error-suppression` | Warning | включено |
| eval() | `code-smell.eval` | Error | включено |
| exit()/die() | `code-smell.exit` | Warning | включено |
| goto | `code-smell.goto` | Error | включено |
| Superglobals | `code-smell.superglobals` | Warning | включено |

## Как настроить пороговые значения

### С помощью YAML-файла конфигурации

Создайте файл `aimd.yaml` в корне вашего проекта:

```yaml
rules:
  complexity.cyclomatic:
    method_warning: 15
    method_error: 30
    class_warning: 40
    class_error: 60

  size.method-count:
    warning: 25
    error: 40

  coupling.cbo:
    warning: 18
    error: 25

  maintainability.index:
    warning: 30
    error: 15
```

Затем запустите анализ с указанием файла конфигурации:

```bash
vendor/bin/aimd analyze src/ --config=aimd.yaml
```

### Отключение правил

Чтобы полностью отключить правило, установите `enabled: false`:

```yaml
rules:
  code-smell.boolean-argument:
    enabled: false
```

### Отключение группы правил

Вы можете отключить все правила в группе через CLI:

```bash
vendor/bin/aimd analyze src/ --disable-rule=code-smell
```

Это отключит все правила, ID которых начинается с `code-smell.`.

### Через командную строку

Переопределяйте настройки из командной строки:

```bash
vendor/bin/aimd analyze src/ --disable-rule=complexity.npath
```

### Подавление отдельных нарушений

Добавьте `@aimd-ignore` в docblock, чтобы подавить конкретное нарушение:

```php
/**
 * @aimd-ignore complexity.cyclomatic
 */
function complexButNecessary(): void
{
    // ...
}
```

Можно также подавить все правила в группе:

```php
/**
 * @aimd-ignore complexity
 */
```
