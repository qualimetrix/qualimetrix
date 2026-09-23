# Оценки здоровья

Qualimetrix вычисляет 6 оценок здоровья для каждого класса, пространства имён и проекта — от 0 (худшее) до 100 (лучшее). Оценки здоровья сводят десятки метрик в быструю картину качества, позволяя моментально определить, какие участки кодовой базы требуют внимания.

Определения разрешаются отдельно для каждого запуска анализа и вычисляются
после агрегации исходных метрик. При повторном запуске в одном процессе набор
определений заменяется атомарно, поэтому конфигурация предыдущего запуска не
может протечь в следующий.

**Rule ID:** `computed` — пользовательские вычисляемые метрики.

Каждое встроенное измерение — самостоятельный производитель и публикует свои
находки под собственным rule ID:

- **Rule ID:** `health.complexity`
- **Rule ID:** `health.cohesion`
- **Rule ID:** `health.coupling`
- **Rule ID:** `health.typing`
- **Rule ID:** `health.maintainability`
- **Rule ID:** `health.overall`

---

## Измерения

| Измерение                | Что измеряет                                     | Ключевые метрики                                                                          | Пороги (warning / error) |
| ------------------------ | ------------------------------------------------ | ----------------------------------------------------------------------------------------- | ------------------------ |
| `health.complexity`      | Сложность методов и классов                      | CCN (avg, max, p95), Cognitive Complexity                                                 | 50 / 25                  |
| `health.cohesion`        | Связность методов внутри класса                  | TCC, LCOM4, количество методов                                                            | 50 / 25                  |
| `health.coupling`        | Зависимости между классами и пространствами имён | Efferent coupling (Ce, Ce packages), Distance from Main Sequence, CBO (на уровне проекта) | 50 / 25                  |
| `health.typing`          | Покрытие типами                                  | Типы параметров, возвращаемых значений, свойств                                           | 80 / 50                  |
| `health.maintainability` | Лёгкость безопасной модификации                  | Maintainability Index (avg, p5, min)                                                      | 50 / 25                  |
| `health.overall`         | Взвешенное среднее всех измерений                | Все вышеперечисленные                                                                     | 50 / 30                  |

---

## Уровни оценки

Каждой оценке присваивается текстовый уровень на основе значения относительно порогов warning (W) и error (E):

- **Excellent**: score > W + (100 − W) × 0.6
- **Good**: score > W + (100 − W) × 0.3
- **Fair**: score > W
- **Poor**: score > E
- **Critical**: score ≤ E

Для стандартных измерений (W=50, E=25):

| Уровень   | Диапазон |
| --------- | -------- |
| Excellent | > 80     |
| Good      | 65 – 80  |
| Fair      | 50 – 65  |
| Poor      | 25 – 50  |
| Critical  | ≤ 25     |

!!! note "Пороги `health.typing` отличаются"
    У `health.typing` пороги по умолчанию W=80, E=50, поэтому границы уровней сдвинуты: Excellent > 92, Good > 86, Fair > 80, Poor > 50, Critical ≤ 50.

---

<!-- llms:skip-begin -->

## Как работают оценки

Все оценки здоровья используют **штрафной подход**: оценка начинается со 100 и уменьшается при обнаружении проблем. Результат ограничивается диапазоном 0–100 функцией `clamp`. Такой подход обеспечивает хорошую **дифференциацию** — проекты с умеренными проблемами не скатываются сразу в ноль, а различия между «хорошим» и «отличным» кодом остаются видны.

### health.complexity

Штрафует за высокую цикломатическую и когнитивную сложность. На уровне класса учитывается средняя и максимальная сложность методов. На уровне пространства имён дополнительно анализируется p95 (95-й перцентиль), что позволяет обнаруживать выбросы — отдельные аномально сложные методы. Максимальная сложность масштабируется через квадратный корень, чтобы один метод-монстр не обрушивал оценку всего пространства имён.

!!! info "Методы интерфейсов включены в агрегацию"
    Методы интерфейсов имеют минимальную сложность (CCN=1, cognitive=0, NPath=1) и включены в расчёт `.avg` и `.p95` на уровне пространства имён. В проектах с большим количеством интерфейсов средняя сложность может оказаться ниже ожидаемой. Это сделано намеренно — интерфейсы являются частью кодовой базы — но добавление интерфейсов может немного улучшить оценку сложности без реальных изменений логики.

### health.cohesion

Оценивает, насколько методы класса работают с общими данными. Основана на TCC (Tight Class Cohesion) и LCOM4. Формула корректирует «чистые» методы (без обращения к свойствам) — такие методы завышают LCOM и занижают TCC, не являясь реальной проблемой. Для классов с менее чем 6 методами применяется смягчённая оценка.

### health.coupling

Измеряет зависимости на уровне класса, пространства имён и проекта. Везде применяется гиперболическое затухание: зависимости за пределами порога снижают оценку, но каждая следующая зависимость влияет слабее предыдущей.

- **На уровне класса** смешиваются `coupling.ce-packages` (количество внешних пакетов) и сглаженный raw `coupling.ce` (efferent coupling).
- **На уровне пространства имён** используются **только efferent-сигналы**: средняя `coupling.ce.avg` и `coupling.ce-packages.avg` по классам, выброс отдельного класса (`coupling.ce.max`), а также общая исходящая ширина пространства имён (`coupling.ce`), плюс Distance from Main Sequence. Двунаправленный CBO здесь намеренно не используется — он смешивает Ca с Ce и несправедливо штрафует «контрактные» пространства имён со стабильно высоким Ca и низким Ce.
- **На уровне проекта** оставлены агрегаты двунаправленного CBO (`coupling.cbo.avg`, `coupling.cbo.p95`, `coupling.cbo.max`): на уровне проекта Σ Ca = Σ Ce, потому что каждое внутреннее ребро вносит вклад в обе стороны, поэтому CBO симметричен и пропорционален Ce. Слагаемое Distance здесь читает `coupling.distance-own.avg`, а не `coupling.distance.avg`, — почему проектная свёртка берётся по собственным областям, написано в разделе [Distance from Main Sequence](../rules/coupling.ru.md#distance-from-main-sequence).

### health.typing

Непосредственно отражает процент покрытия типами (Type Coverage): параметры, возвращаемые значения и свойства. На уровне пространства имён агрегирует суммы по всем классам.

### health.maintainability

Основана на Maintainability Index. На уровне класса штрафует за низкий средний MI и за отдельные методы с экстремально низким MI (масштабирование через квадратный корень). На уровне пространства имён основные дифференциаторы — p5 (5-й перцентиль) и минимальное значение. Границы штрафов взяты из опубликованных значений Coleman: 85 — «highly maintainable», 65 — «difficult to maintain». На калибровочном корпусе из семнадцати проектов измерение принимает значения от 33.7 до 100.0 на уровне проекта.

### health.overall

Взвешенное среднее всех измерений. Веса различаются по уровням:

- **Класс:** complexity 35%, cohesion 25%, coupling 25%, typing 15% (maintainability исключена — MI является метрикой уровня метода и её сигнал уже учтён через complexity и cohesion)
- **Пространство имён / проект:** complexity 30%, cohesion 20%, coupling 20%, typing 10%, maintainability 20%

<!-- llms:skip-end -->

---

## Чтение оценок здоровья

Оценки здоровья доступны в нескольких форматах вывода:

**summary** (по умолчанию) — прогресс-бары в терминале:

```
Qualimetrix — 45 files analyzed, 1.23s

  Complexity     ████████████████░░░░  78 Excellent
  Cohesion       ██████████████░░░░░░  68 Good
  Coupling       ████████████░░░░░░░░  59 Fair
  Typing         ██████████████████░░  88 Excellent
  Maintainability████████████████░░░░  80 Good
  Overall        ██████████████░░░░░░  72 Good
```

**json** — структурированные данные для CI/CD:

```bash
bin/qmx check src/ --format=json
```

**health** — текстовая таблица оценок здоровья в терминале:

```bash
bin/qmx check src/ --format=health
```

**html** — интерактивный отчёт с drill-down по пространствам имён и классам:

```bash
bin/qmx check src/ --format=html -o report.html
```

Подробнее о форматах вывода — в разделе [Форматы вывода](../usage/output-formats.md).

### Что именно покрывает оценка { #what-a-score-covers }

Оценка — утверждение лишь о той части кода, на которой её входы вообще удалось
измерить. Связность (cohesion) не определена для класса менее чем с двумя
методами, поэтому оценка cohesion обычно описывает от четверти до половины
классов проекта — и до сих пор об этом не сообщала.

Поэтому каждое измерение здоровья публикует рядом с оценкой поле `coverage`:
`.count` самого узкого входного агрегата, размер популяции, долей которой этот
счёт является, и имя самого `.count` (`basis`). Оценка при этом **не**
приглушается покрытием: число публикуется, чтобы читатель мог судить сам, а не
подмешивается в оценку (см. [ADR 0062](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0062-health-scores-measure-what-they-cover.md)).

Там, где покрытие не определено, поле говорит об этом явно и с причиной, а не
показывает ноль: `health.overall` складывается из остальных измерений,
`health.typing` считается из сумм typed/total, для которых `.count` не
публикуется, а оценка уровня класса или отфильтрованного пространства имён
вообще не является агрегатом по символам.

Покрытие выводится в `--format=json` (объект `coverage` у каждого измерения), в
`--format=health` (колонка `Coverage` плюс строка на измерение в декомпозиции), в
`--format=summary` (строка под каждой оценкой) и в `--format=html` (объект
`summary.healthCoverage` рядом с `summary.healthScores`, отрисованный под барами
здоровья).

Покрытие меньше 100% — не обязательно дефект прогона. Часть разрывов постоянна по
построению: связность не определена для класса менее чем с двумя методами, а у
пространства имён, объявляющего одни только голые enum, нет собственной
абстрактности — голый enum намеренно вне этого знаменателя (см.
[Distance from Main Sequence](../rules/coupling.ru.md#distance-from-main-sequence)),
— поэтому собственная distance для него не публикуется, хотя популяция его
считает: тип он объявляет. Знаменатель намеренно **не** сужен до тех пространств
имён, до которых агрегат дошёл: знаменатель, равный собственному обходу агрегата,
печатает 100% по построению и не способен показать, куда обход не добрался, — а
ради этого строка и существует.


---

## Настройка

### Допустимые ключи

Каждая запись `computed_metrics:` принимает ровно девять ключей: `formula`,
`formulas`, `levels`, `description`, `inverted`, `threshold`, `warning`,
`error` и `enabled`. `threshold` задаёт `warning` и `error` одним и тем же
значением и не сочетается ни с одним из них. Внутри `formulas:` допустимы
только три слова уровня: `class`, `namespace` и `project`.

Неизвестный ключ, значение неверного типа или имя `health.*` вне шести
встроенных измерений (`health.complexity`, `health.cohesion`,
`health.coupling`, `health.typing`, `health.maintainability`,
`health.overall`) отклоняются с кодом возврата 3 и сообщением, называющим
написанное и допустимое — ни один из этих случаев не игнорируется молча.

### Настройка порогов

```yaml
# qmx.yaml
computed_metrics:
  health.complexity:
    warning: 60    # Stricter than default 50
    error: 30      # Stricter than default 25
```

### Отключение измерения

```yaml
computed_metrics:
  health.typing:
    enabled: false
```

Или через CLI:

```bash
bin/qmx check src/ --exclude-health=typing
```

Оба пути дают одинаковый результат: измерение убирается из пайплайна И веса `health.overall` ренормализуются по оставшимся измерениям (отключённое измерение не учитывается как нейтральный вклад в 75 баллов). Если вы переопределили `health.overall` нестандартной формулой (например, `min(...)` или условным выражением), исключение измерений выбросит явную ошибку — обработайте отключённое измерение через `??`-фолбэки в собственной формуле.

!!! warning "Два похожих переключателя, которые делают разное"
    Каждое встроенное измерение — сам себе производитель, поэтому его можно выключить двумя способами, читающимися почти одинаково:

    - `rules: { health.cohesion: { enabled: false } }` останавливает **публикацию находок** производителем `health.cohesion`. Измерение всё равно вычисляется и продолжает участвовать в `health.overall`.
    - `computed_metrics: { health.cohesion: { enabled: false } }` **убирает само измерение** — это переключатель из раздела «Отключение измерения» выше. Веса `health.overall` ренормализуются по оставшимся измерениям.

    У измерения, убранного вторым способом, у производителя не остаётся ни одного канала. Ключ `suppress_namespace_channels`, который раньше адресовал `health.cohesion`, после этого отвергается: ключ обязан называть канал, который правило под ним действительно эмитит, а после удаления оно не эмитит ни одного.

### Переопределение формул

```yaml
computed_metrics:
  health.maintainability:
    # Same formula for all levels
    formula: "clamp(m['maintainability.mi.avg'], 0, 100)"
```

Формула — это выражение, записанное строкой, а константа — тоже выражение:
`formula: "80"` задаёт метрику, равную 80 везде. Кавычки обязательны: `80` без
них — число, а число формулой не является.

```yaml
computed_metrics:
  health.maintainability:
    # Different formulas per level
    formulas:
      class: "clamp(m['maintainability.mi.avg'], 0, 100)"
      namespace: "clamp(m['maintainability.mi.avg'] * 0.7 + m['maintainability.mi.p5'] * 0.3, 0, 100)"
      project: "clamp(m['maintainability.mi.avg'] * 0.7 + m['maintainability.mi.p5'] * 0.3, 0, 100)"
```

### Пользовательские вычисляемые метрики

```yaml
computed_metrics:
  computed.code-density:
    formula: "clamp((m['size.lloc'] ?? 0) / max(m['size.loc'] ?? 1, 1) * 100, 0, 100)"
    description: "Ratio of logical to physical lines (higher = denser code)"
    levels: [namespace]   # size.lloc / size.loc — сырые ключи только на уровне namespace
    warning: 80
    error: 90
    inverted: false   # Higher values trigger violations
```

!!! note "Именование метрик"
    Имя пользовательской метрики обязано начинаться с `health.` или `computed.` — другие префиксы не принимаются. Рекомендуемое соглашение для собственных метрик — `computed.*`; `health.*` зарезервирован за шестью встроенными измерениями. Оба префикса требуют строчных kebab-case сегментов после точки (например, `computed.code-density`); подчёркивания и заглавные буквы отвергаются, а последний сегмент не может совпадать с именем стратегии агрегации (`sum`, `avg`, `max`, `min`, `count`, `p95`, `p5` — например, `computed.sum` отклоняется).

### Доступные переменные

Формулы обращаются к метрикам через единственный массив `m`, индексированный настоящим ключом метрики: `m["complexity.ccn.avg"]`. Отдельного «имени переменной» запоминать не нужно — ключ, который вы видите в выводе `--format=metrics`/`--format=json`, и есть индекс.

| Ключ метрики                              | Доступен на уровне        |
| ----------------------------------------- | ------------------------- |
| `complexity.ccn.avg`                      | class, namespace, project |
| `complexity.ccn.max`                      | class, namespace, project |
| `complexity.ccn.sum`                      | namespace, project        |
| `complexity.ccn.p95`                      | namespace, project        |
| `complexity.cognitive.avg`                | class, namespace, project |
| `complexity.cognitive.max`                | class, namespace, project |
| `complexity.cognitive.sum`                | namespace, project        |
| `complexity.cognitive.p95`                | namespace, project        |
| `cohesion.tcc`                            | class                     |
| `cohesion.tcc.avg`                        | namespace, project        |
| `cohesion.lcom`                           | class                     |
| `cohesion.lcom.avg`                       | namespace, project        |
| `coupling.cbo.avg`                        | namespace, project        |
| `coupling.cbo.max`                        | namespace, project        |
| `coupling.cbo.p95`                        | namespace, project        |
| `coupling.ce`                             | class, namespace          |
| `coupling.ce.avg`                         | namespace, project        |
| `coupling.ce.max`                         | namespace, project        |
| `coupling.ce-packages`                    | class                     |
| `coupling.ce-packages.avg`                | namespace, project        |
| `coupling.abstractness`                   | namespace                 |
| `coupling.distance`                       | namespace                 |
| `coupling.ca-own`                         | namespace                 |
| `coupling.ce-own`                         | namespace                 |
| `coupling.instability-own`                | namespace                 |
| `coupling.abstractness-own`               | namespace                 |
| `coupling.distance-own`                   | namespace                 |
| `coupling.distance-own.avg`               | project                   |
| `size.symbol-declaring-namespace-count`   | project                   |
| `maintainability.mi.avg`                  | class, namespace, project |
| `maintainability.mi.min`                  | class, namespace, project |
| `maintainability.mi.p5`                   | namespace, project        |
| `design.type-coverage.all`                | class                     |
| `design.type-coverage.param.total.sum`    | namespace, project        |
| `design.type-coverage.param.typed.sum`    | namespace, project        |
| `design.type-coverage.return.total.sum`   | namespace, project        |
| `design.type-coverage.return.typed.sum`   | namespace, project        |
| `design.type-coverage.property.total.sum` | namespace, project        |
| `design.type-coverage.property.typed.sum` | namespace, project        |
| `size.method-count`                       | class                     |
| `size.symbol-method-count`                | class, namespace, project |
| `cohesion.pure-method-count`              | class                     |
| `size.loc`                                | namespace                 |
| `size.lloc`                               | namespace                 |
| `health.complexity`                       | class, namespace, project |
| `health.cohesion`                         | class, namespace, project |
| `health.coupling`                         | class, namespace, project |
| `health.typing`                           | class, namespace, project |
| `health.maintainability`                  | class, namespace, project |

Частые агрегатные суффиксы у ключа: `.avg`, `.min`, `.max`, `.sum`, `.p5`, `.p95`.

Это не исчерпывающий список — в формулах можно использовать любую метрику, собираемую Qualimetrix, по её ключу. Команда `bin/qmx check src/ --format=metrics` покажет все доступные метрики и их точные ключи для вашего проекта.

!!! warning "Неизвестные ссылки на метрики"
    Если формула ссылается на несуществующий ключ метрики (например, опечатка `m["complexity.ccn.abg"]` вместо `m["complexity.ccn.avg"]`), Qualimetrix выдаст явную ошибку вместо молчаливого возврата нуля. Всегда используйте оператор `??` для метрик, которые могут обоснованно отсутствовать: `(m["complexity.ccn.avg"] ?? 0)`.

!!! warning "Метрики, которых нет на уровне"
    Формула выполняется на каждом из своих `levels:`, и ключ проверяется на этом уровне:

    - **Ни один символ уровня не несёт ключ**, а формула читает его без `??` — ошибка конфигурации (код выхода 3). Сюда относится и чтение другой computed-метрики на уровне, которого нет в её собственных `levels:`: `computed.a` с `levels: [class]` нельзя читать без `??` в формуле уровня `project`, и ошибка называет уровни, на которых `computed.a` публикуется. Уровень `project`, унаследовавший формулу `namespace`, проверяется как `project`.
    - **Ключ есть у части символов** — символы без него не получают значения вместо сфабрикованного 0, а прогон пишет одно предупреждение на метрику и уровень: число пропущенных символов и недостающие ключи.

    `m["a"] ?? m["b"]` читает `b` только там, где нет `a`, поэтому символ пропускается, лишь когда у него нет ни того, ни другого. Чтобы значение получил каждый символ, завершите цепочку литералом: `m["a"] ?? m["b"] ?? 0`.

### Доступные функции

| Функция                  | Описание                                                  |
| ------------------------ | --------------------------------------------------------- |
| `min(a, b)`              | Минимум из двух значений                                  |
| `max(a, b)`              | Максимум из двух значений                                 |
| `abs(x)`                 | Модуль числа                                              |
| `sqrt(x)`                | Квадратный корень                                         |
| `log(x)`                 | Натуральный логарифм                                      |
| `log10(x)`               | Десятичный логарифм                                       |
| `clamp(value, min, max)` | Ограничение значения диапазоном [min, max]                |
| `??`                     | Null coalescing (значение по умолчанию, если метрики нет) |
| `**`                     | Возведение в степень                                      |

!!! tip "Всегда используйте оператор `??`"
    Метрики могут отсутствовать для некоторых символов (например, у класса без методов нет `complexity.ccn`). Всегда задавайте значения по умолчанию через `??`: `(m["complexity.ccn.avg"] ?? 1)` вместо `m["complexity.ccn.avg"]`.
