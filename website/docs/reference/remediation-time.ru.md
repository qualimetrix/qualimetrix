# Справочник по времени устранения

Каждая находка несёт оценку времени на устранение — она показывается в сводках долга и отчётах. Каждое правило объявляет собственную базовую оценку — среднее усилие на типичную находку такого рода. Когда находка также несёт значение метрики и порог, базовое время масштабируется по тому, насколько метрика превышает порог: `base * max(1, ln(overshoot))`. Небольшие превышения получают время, близкое к базовому; экстремальные -- намного больше.

Эта страница перечисляет базовую оценку каждого правила рядом друг с другом, чтобы читатель мог спросить, действительно ли SQL-инъекция заслуживает вчетверо больше времени, чем отладочный вывод. Она сгенерирована из тех же констант, которые объявляют сами правила -- см. `src/Analysis/Evidence/Prioritization/Debt/RemediationTimeRegistry.php`.

## Правила сложности (Complexity)

| Правило               | ID                      | Минуты |
| --------------------- | ----------------------- | ------ |
| Cyclomatic Complexity | `complexity.cyclomatic` | 30     |
| Cognitive Complexity  | `complexity.cognitive`  | 30     |
| NPath Complexity      | `complexity.npath`      | 30     |
| WMC                   | `complexity.wmc`        | 30     |

## Правила связанности (Coupling)

| Правило     | ID                     | Минуты |
| ----------- | ---------------------- | ------ |
| CBO         | `coupling.cbo`         | 45     |
| ClassRank   | `coupling.class-rank`  | 30     |
| Instability | `coupling.instability` | 30     |
| Distance    | `coupling.distance`    | 30     |

## Правила сцепления (Cohesion)

| Правило | ID              | Минуты |
| ------- | --------------- | ------ |
| LCOM    | `cohesion.lcom` | 45     |

## Правила дизайна (Design)

| Правило                    | ID                     | Минуты |
| -------------------------- | ---------------------- | ------ |
| DIT (глубина наследования) | `design.inheritance`   | 30     |
| NOC                        | `design.noc`           | 20     |
| Type Coverage              | `design.type-coverage` | 15     |
| Data Class                 | `design.data-class`    | 30     |
| God Class                  | `design.god-class`     | 120    |

## Правила размера (Size)

| Правило        | ID                    | Минуты |
| -------------- | --------------------- | ------ |
| Class Count    | `size.class-count`    | 30     |
| Method Count   | `size.method-count`   | 20     |
| Property Count | `size.property-count` | 15     |

## Правила сопровождаемости (Maintainability)

| Правило               | ID                      | Минуты |
| --------------------- | ----------------------- | ------ |
| Maintainability Index | `maintainability.index` | 60     |

## Code Smell правила

| Правило                    | ID                                     | Минуты |
| -------------------------- | -------------------------------------- | ------ |
| Constructor Over-injection | `code-smell.constructor-overinjection` | 60     |
| Boolean Argument           | `code-smell.boolean-argument`          | 10     |
| Debug Code                 | `code-smell.debug-code`                | 5      |
| Empty Catch                | `code-smell.empty-catch`               | 10     |
| eval()                     | `code-smell.eval`                      | 15     |
| exit()/die()               | `code-smell.exit`                      | 10     |
| goto                       | `code-smell.goto`                      | 15     |
| Superglobals               | `code-smell.superglobals`              | 15     |
| Error Suppression          | `code-smell.error-suppression`         | 10     |
| count() in Loop            | `code-smell.count-in-loop`             | 10     |
| Long Parameter List        | `code-smell.long-parameter-list`       | 20     |
| Unreachable Code           | `code-smell.unreachable-code`          | 10     |
| Unused Private             | `code-smell.unused-private`            | 15     |
| Identical Sub-expression   | `code-smell.identical-subexpression`   | 15     |

## Правила безопасности (Security)

| Правило               | ID                               | Минуты |
| --------------------- | -------------------------------- | ------ |
| Hardcoded Credentials | `security.hardcoded-credentials` | 30     |
| SQL Injection         | `security.sql-injection`         | 60     |
| XSS                   | `security.xss`                   | 45     |
| Command Injection     | `security.command-injection`     | 60     |
| Sensitive Parameter   | `security.sensitive-parameter`   | 10     |

## Правила дублирования (Duplication)

| Правило          | ID                             | Минуты |
| ---------------- | ------------------------------ | ------ |
| Code Duplication | `duplication.code-duplication` | 15     |

## Архитектурные правила

| Правило               | ID                                 | Минуты |
| --------------------- | ---------------------------------- | ------ |
| Circular Dependencies | `architecture.circular-dependency` | 120    |
| Layer Violations      | `architecture.layer-violation`     | 15     |

## Правила аннотаций

| Правило   | ID                     | Минуты |
| --------- | ---------------------- | ------ |
| Directive | `annotation.directive` | 15     |

## Вычисляемые метрики

| Правило         | ID                | Минуты |
| --------------- | ----------------- | ------ |
| Computed Metric | `computed.health` | 15     |

## Почему эти значения отличаются от пороговых значений по умолчанию

Эта страница -- про калибровку, а не про обнаружение. [Пороговые значения по умолчанию](default-thresholds.ru.md) говорят, *когда* правило срабатывает; эта страница говорит, *сколько времени* ожидаемо занимает исправление одного случая. `coupling.class-rank` масштабирует свои собственные пороги по размеру проекта и исключён из масштабирования по превышению, которое модель этой страницы применяет ко всем остальным magnitude-каналам -- см. [ClassRank](../rules/coupling.ru.md#classrank), почему.
