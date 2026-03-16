# Пороговые значения по умолчанию

На этой странице перечислены пороговые значения по умолчанию для каждого правила AI Mess Detector. Когда метрика превышает порог **warning**, выдается предупреждение. Когда превышает порог **error** -- ошибка.

## Правила сложности (Complexity)

Правила, которые измеряют, насколько сложно понять и протестировать код.

| Правило               | ID                      | Уровень       | Warning | Error | Область           |
| --------------------- | ----------------------- | ------------- | ------- | ----- | ----------------- |
| Cyclomatic Complexity | `complexity.cyclomatic` | Метод         | 10      | 20    | Метод             |
| Cyclomatic Complexity | `complexity.cyclomatic` | Класс (макс.) | 30      | 50    | Класс             |
| Cognitive Complexity  | `complexity.cognitive`  | Метод         | 15      | 30    | Метод             |
| Cognitive Complexity  | `complexity.cognitive`  | Класс (макс.) | 30      | 50    | Класс             |
| NPath Complexity      | `complexity.npath`      | Метод         | 200     | 1000  | Метод             |
| NPath Complexity      | `complexity.npath`      | Класс (макс.) | 200     | 1000  | Класс (отключено) |
| WMC                   | `complexity.wmc`        | -             | 50      | 80    | Класс             |

**Cyclomatic Complexity** подсчитывает количество независимых путей выполнения в методе. Метод с CCN равным 10 имеет 10 различных путей для тестирования.

**Cognitive Complexity** измеряет, насколько сложно читать код. В отличие от цикломатической сложности, вложенные конструкции штрафуются сильнее.

**NPath Complexity** подсчитывает количество возможных путей выполнения. Растет гораздо быстрее, чем цикломатическая сложность для кода с большим количеством условий.

**WMC (Weighted Methods per Class)** -- сумма цикломатических сложностей всех методов класса. Высокий WMC означает, что класс делает слишком много.

## Правила размера (Size)

Правила, которые проверяют, не стали ли классы и пространства имен слишком большими.

| Правило        | ID                    | Warning | Error | Область           |
| -------------- | --------------------- | ------- | ----- | ----------------- |
| Method Count   | `size.method-count`   | 20      | 30    | Класс             |
| Class Count    | `size.class-count`    | 15      | 25    | Пространство имен |
| Property Count | `size.property-count` | 15      | 20    | Класс             |

## Правила проектирования (Design)

Правила, которые проверяют дизайн классов и структуру наследования.

| Правило                  | ID                     | Warning   | Error     | Область |
| ------------------------ | ---------------------- | --------- | --------- | ------- |
| LCOM                     | `design.lcom`          | 3         | 5         | Класс   |
| NOC                      | `design.noc`           | 10        | 15        | Класс   |
| DIT                      | `design.inheritance`   | 4         | 6         | Класс   |
| Type Coverage (param)    | `design.type-coverage` | 80 (ниже) | 50 (ниже) | Класс   |
| Type Coverage (return)   | `design.type-coverage` | 80 (ниже) | 50 (ниже) | Класс   |
| Type Coverage (property) | `design.type-coverage` | 80 (ниже) | 50 (ниже) | Класс   |

**LCOM (Lack of Cohesion of Methods)** измеряет, насколько хорошо методы в классе связаны друг с другом. Высокий LCOM говорит о том, что класс стоит разделить.

**NOC (Number of Children)** подсчитывает прямых наследников. Слишком много наследников означает, что родительский класс может быть слишком общим.

**DIT (Depth of Inheritance Tree)** подсчитывает количество уровней наследования. Глубокие иерархии сложнее понимать и поддерживать.

**Type Coverage** измеряет процент типизированных объявлений. В отличие от большинства правил, нарушения фиксируются, когда значения падают **ниже** порога.

## Правила связанности (Coupling)

Правила, которые проверяют, насколько тесно классы и пространства имен связаны друг с другом.

| Правило     | ID                     | Warning | Error | Область           |
| ----------- | ---------------------- | ------- | ----- | ----------------- |
| CBO         | `coupling.cbo`         | 14      | 20    | Класс             |
| CBO         | `coupling.cbo`         | 14      | 20    | Пространство имен |
| Instability | `coupling.instability` | 0.8     | 0.95  | Класс             |
| Instability | `coupling.instability` | 0.8     | 0.95  | Пространство имен |
| Distance    | `coupling.distance`    | 0.3     | 0.5   | Пространство имен |
| ClassRank   | `coupling.class-rank`  | 0.02    | 0.05  | Класс             |

**CBO (Coupling Between Objects)** подсчитывает количество других классов, от которых зависит данный класс. Высокая связанность затрудняет внесение изменений.

**Instability** -- коэффициент от 0 (полностью стабильный) до 1 (полностью нестабильный). Класс, который зависит от многих других, но от которого никто не зависит -- нестабилен.

**Distance from the Main Sequence** измеряет, насколько хорошо пространство имен балансирует между абстрактностью и стабильностью. Значение, близкое к 0 -- идеально.

**ClassRank** использует алгоритм PageRank на графе зависимостей для определения наиболее "важных" классов. Высокий ClassRank означает, что класс является критическим узлом с широким влиянием на систему. Пороги автоматически адаптируются к размеру проекта через sqrt-масштабирование (калибровано для 100 классов).

## Правила сопровождаемости (Maintainability)

Эти правила работают **наоборот**: нарушение фиксируется, когда метрика падает **ниже** порога, а не превышает его.

| Правило               | ID                      | Warning (ниже) | Error (ниже) | Область |
| --------------------- | ----------------------- | -------------- | ------------ | ------- |
| Maintainability Index | `maintainability.index` | 40             | 20           | Метод   |

**Maintainability Index** объединяет сложность, количество строк кода и метрики Холстеда в единую оценку от 0 до 100. Чем выше -- тем лучше. Оценка ниже 20 означает, что код очень сложно поддерживать.

## Правила запахов кода (Code Smell)

Эти правила обнаруживают конкретные паттерны, которые обычно являются плохой практикой. У большинства нет числовых порогов -- они либо находят паттерн, либо нет. Два правила (Long Parameter List и Unreachable Code) используют числовые пороги.

| Правило                    | ID                                     | Warning                                            | Error     | Статус   |
| -------------------------- | -------------------------------------- | -------------------------------------------------- | --------- | -------- |
| Constructor Over-injection | `code-smell.constructor-overinjection` | 8 params                                           | 12 params | включено |
| Data Class                 | `code-smell.data-class`                | WOC ≥ 80%, WMC ≤ 10                                | —         | включено |
| God Class                  | `code-smell.god-class`                 | WMC ≥ 47, TCC < 0.33, LCOM ≥ 3, LOC ≥ 300 (3 of 4) | —         | включено |
| Boolean Argument           | `code-smell.boolean-argument`          | —                                                  | —         | включено |
| count() in Loop            | `code-smell.count-in-loop`             | —                                                  | —         | включено |
| Debug Code                 | `code-smell.debug-code`                | —                                                  | всегда    | включено |
| Empty Catch                | `code-smell.empty-catch`               | —                                                  | всегда    | включено |
| Error Suppression          | `code-smell.error-suppression`         | всегда                                             | —         | включено |
| eval()                     | `code-smell.eval`                      | —                                                  | всегда    | включено |
| exit()/die()               | `code-smell.exit`                      | всегда                                             | —         | включено |
| goto                       | `code-smell.goto`                      | —                                                  | всегда    | включено |
| Superglobals               | `code-smell.superglobals`              | всегда                                             | —         | включено |
| Long Parameter List        | `code-smell.long-parameter-list`       | 4 params                                           | 6 params  | включено |
| Unreachable Code           | `code-smell.unreachable-code`          | 1                                                  | 2         | включено |
| Unused Private             | `code-smell.unused-private`            | всегда                                             | —         | включено |
| Identical Sub-expression   | `code-smell.identical-subexpression`   | всегда                                             | —         | включено |

## Правила дупликации (Duplication)

Правила, которые обнаруживают дублированный код.

| Правило          | ID                             | Warning   | Error      | Область |
| ---------------- | ------------------------------ | --------- | ---------- | ------- |
| Code Duplication | `duplication.code-duplication` | <50 строк | >=50 строк | Метод   |

**Code Duplication** обнаруживает дублированные блоки кода. Настраивается через `min_lines: 5` и `min_tokens: 70` -- блоки, не достигающие этих порогов, игнорируются. Дубликаты менее 50 строк выдают предупреждение; 50 строк и более -- ошибку.

## Правила безопасности (Security)

Правила, которые обнаруживают потенциальные уязвимости безопасности.

| Правило               | ID                               | Серьезность | По умолчанию |
| --------------------- | -------------------------------- | ----------- | ------------ |
| Hardcoded Credentials | `security.hardcoded-credentials` | Error       | включено     |
| SQL Injection         | `security.sql-injection`         | Error       | включено     |
| XSS                   | `security.xss`                   | Error       | включено     |
| Command Injection     | `security.command-injection`     | Error       | включено     |
| Sensitive Parameter   | `security.sensitive-parameter`   | Warning     | включено     |

**Hardcoded Credentials** обнаруживает пароли, API-ключи и токены, захардкоженные непосредственно в исходном коде.

**SQL Injection** обнаруживает использование суперглобальных переменных при построении SQL-запросов без параметризации.

**XSS** обнаруживает вывод суперглобальных переменных без экранирования (`htmlspecialchars` и т.д.).

**Command Injection** обнаруживает использование суперглобальных переменных в функциях выполнения команд без санитизации.

**Sensitive Parameter** обнаруживает параметры с чувствительными именами без атрибута `#[\SensitiveParameter]`.

## Как настроить пороговые значения

### С помощью YAML-файла конфигурации

Создайте файл `aimd.yaml` в корне вашего проекта:

```yaml
rules:
  complexity.cyclomatic:
    method:
      warning: 15
      error: 30
    class:
      max_warning: 40
      max_error: 60

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
vendor/bin/aimd check src/ --config=aimd.yaml
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
vendor/bin/aimd check src/ --disable-rule=code-smell
```

Это отключит все правила, ID которых начинается с `code-smell.`.

### Через командную строку

Переопределяйте настройки из командной строки:

```bash
vendor/bin/aimd check src/ --disable-rule=complexity.npath
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
