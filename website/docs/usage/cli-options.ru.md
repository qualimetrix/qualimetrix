# Опции CLI

Qualimetrix предоставляет команду `check` для анализа кода и несколько вспомогательных команд для работы с baseline, git-хуками и визуализацией графа зависимостей.

## Команда check

```bash
bin/qmx check [опции] [--] [<пути>...]
```

### Аргумент paths

Укажите одну или несколько директорий или файлов для анализа:

```bash
# Анализ конкретных директорий
bin/qmx check src/ lib/

# Анализ одного файла
bin/qmx check src/Service/UserService.php
```

Если пути не указаны, Qualimetrix автоматически определит их из секции `autoload` вашего `composer.json`.

---

## Опции файлов

### `--config`, `-c`

Путь к YAML-файлу конфигурации:

```bash
bin/qmx check src/ --config=qmx.yaml
```

### `--exclude`

Исключить директории из анализа. Можно указывать несколько раз:

```bash
bin/qmx check src/ --exclude=src/Generated --exclude=src/Legacy
```

### `--include-generated`

По умолчанию Qualimetrix автоматически пропускает файлы, содержащие аннотацию `@generated` в первых 2 КБ. Этот флаг переопределяет это поведение и включает сгенерированные файлы в анализ:

```bash
bin/qmx check src/ --include-generated
```

Также можно задать в `qmx.yaml`:

```yaml
include_generated: true
```

### `--exclude-path`

Подавить нарушения для файлов, соответствующих glob-паттерну. Файлы по-прежнему анализируются (их метрики учитываются при расчёте метрик пространства имён), но нарушения не выводятся. Можно указывать несколько раз:

```bash
bin/qmx check src/ --exclude-path="src/Entity/*" --exclude-path="src/DTO/*"
```

Объединяется с `exclude_paths` из `qmx.yaml` — оба источника суммируются.

!!! warning "Не действует на правила `architecture.*`"
    Нарушения `architecture.layer-violation` и `architecture.circular-dependency` эта опция
    никогда не подавляет — почему и какие есть альтернативы, см.
    [Исключение путей из отчёта](../getting-started/configuration.ru.md#исключение-путей-из-отчёта-exclude_paths).

### `--exclude-namespace`

Подавить нарушения для классов в пространствах имён, соответствующих префиксу или glob-паттерну. Классы по-прежнему анализируются (их метрики учитываются в агрегированных расчётах), но нарушения не выводятся. Можно указывать несколько раз:

```bash
bin/qmx check src/ --exclude-namespace="App\Entity" --exclude-namespace="App\DTO\*"
```

Объединяется с `exclude_namespaces` из `qmx.yaml` — оба источника суммируются.

!!! warning "Не действует на правила `architecture.*`"
    Нарушения `architecture.layer-violation` и `architecture.circular-dependency` эта опция
    никогда не подавляет — почему и какие есть альтернативы, см.
    [Исключение неймспейсов](../getting-started/configuration.ru.md#исключение-неймспейсов-exclude_namespaces).

---

## Опции пресетов

### `--preset`

Применить именованный пресет или пользовательский YAML-файл. Можно указывать несколько раз или через запятую:

```bash
# Встроенные пресеты
bin/qmx check src/ --preset=strict
bin/qmx check src/ --preset=legacy

# Комбинирование пресетов (объединяются слева направо)
bin/qmx check src/ --preset=strict,ci
bin/qmx check src/ --preset=strict --preset=ci

# Пользовательский файл пресета
bin/qmx check src/ --preset=./my-preset.yaml
```

Доступные встроенные пресеты: `strict`, `legacy`, `ci`.

Пресеты применяются после автоопределения `composer.json`, но до `qmx.yaml`, поэтому ваш файл конфигурации всегда имеет приоритет. Подробности смотрите в разделе [Конфигурация > Пресеты](../getting-started/configuration.ru.md#пресеты).

---

## Опции вывода

### `--format`, `-f`

Выбор формата вывода. По умолчанию: `summary`.

```bash
bin/qmx check src/ --format=json
bin/qmx check src/ --format=sarif
```

Доступные форматы: `summary`, `text`, `text-verbose`, `json`, `metrics`, `checkstyle`, `sarif`, `gitlab`, `github`, `health`, `html`.

Подробности о каждом формате смотрите в разделе [Форматы вывода](output-formats.md).

### `--group-by`

Группировка нарушений в выводе. Значение по умолчанию зависит от форматтера.

```bash
bin/qmx check src/ --format=text-verbose --group-by=rule
```

Доступные значения: `none`, `file`, `rule`, `severity`, `class`, `namespace`.

### `--format-opt`

Передача специфичных для форматтера опций в формате key=value. Можно указывать несколько раз:

```bash
bin/qmx check src/ --format-opt=key=value
```

**Опции формата JSON:**

| Опция               | По умолчанию | Описание                                |
| ------------------- | ------------ | --------------------------------------- |
| `violations=N\|all` | all          | Макс. кол-во нарушений в выводе (0=нет) |
| `limit=N`           | all          | Псевдоним для `violations`              |
| `top=N`             | 10           | Количество худших нарушителей           |

```bash
bin/qmx check src/ --format=json --format-opt=limit=100
bin/qmx check src/ --format=json --format-opt=violations=all
```

### `--fail-on`

Минимальный уровень нарушения, при котором возвращается ненулевой код выхода. По умолчанию: `error`.

```bash
# Поведение по умолчанию: ошибка только при error, предупреждения допускаются
bin/qmx check src/ --fail-on=error

# Ошибка и при warning (для строгого контроля качества)
bin/qmx check src/ --fail-on=warning
```

Предупреждения по-прежнему отображаются в выводе, но по умолчанию не приводят к ненулевому коду завершения. Используйте `--fail-on=warning`, если хотите, чтобы предупреждения также блокировали CI.

Также можно задать в `qmx.yaml`:

```yaml
fail_on: error
```

### `--exclude-health`

Исключить конкретные измерения здоровья из оценки. Исключённые измерения не отображаются в сводке здоровья и не влияют на общую оценку. Можно указывать несколько раз:

```bash
# Исключить типизацию из оценки здоровья
bin/qmx check src/ --exclude-health=typing

# Исключить несколько измерений
bin/qmx check src/ --exclude-health=typing --exclude-health=maintainability
```

Доступные измерения: `complexity`, `cohesion`, `coupling`, `typing`, `maintainability`.

Также можно задать в `qmx.yaml`:

```yaml
exclude_health:
  - typing
```

### `--detail`

Показать группированный список нарушений после сводки. Действует только на формат `summary`.

```bash
# Лимит по умолчанию (200 нарушений)
bin/qmx check src/ --detail

# Показать все нарушения (без лимита)
bin/qmx check src/ --detail=all

# Пользовательский лимит
bin/qmx check src/ --detail=50
```

Автоматически включается при использовании `--namespace` или `--class`.

### `--all`

Показать все нарушения без усечения. Сокращение для `--format-opt=violations=all --detail=all`.

```bash
# Все нарушения в формате JSON
bin/qmx check src/ --format=json --all

# Все нарушения в формате summary
bin/qmx check src/ --all
```

Не может быть объединён с `--format-opt=violations=N` (числовой лимит) — это вызовет ошибку. Совместное использование `--all` с `--format-opt=violations=all` допустимо (они синонимы).

### `--namespace`

Фильтрация вывода по поддереву пространства имён. Значение — это *паттерн* пространства имён, а не буквальный префикс:

- Без glob-символов сопоставление идёт по границам: `App\Service` совпадает с `App\Service` и всем, что под ним, но не с `App\ServiceBus`.
- При наличии `*`, `?` или `[` значение трактуется как glob: `App\*\Order` выбирает `App\Billing\Order` и `App\Sales\Order`, а не пространство имён, буквально написанное со звёздочкой.
- Завершающий `\` косметический: `App\Service\` и `App\Service` — один и тот же паттерн.
- Пустое значение не совпадает ни с чем, включая глобальное пространство имён.

```bash
bin/qmx check src/ --namespace=App\\Service
bin/qmx check src/ --namespace='App\*\Order'
```

Фильтрует нарушения и худших нарушителей по выбранным пространствам имён. Показывает оценки здоровья поддерева. Автоматически включает `--detail`.

Находки уровня проекта (`architecture.coverage` и прочие диагностики, судящие о прогоне целиком) не выбираются паттерном пространства имён никогда, включая `*`: они не принадлежат ни одному пространству имён.

То же правило сопоставления действует для drill-down по здоровью и списков худших нарушителей, которые включает эта опция, и для опции `include_namespaces` правила `coupling.distance`.

Взаимоисключающий с `--class`.

### `--class`

Фильтрация вывода по конкретному классу с точным совпадением FQCN.

```bash
bin/qmx check src/ --class=App\\Service\\UserService
```

Фильтрует нарушения по указанному классу. Автоматически включает `--detail`.

Взаимоисключающий с `--namespace`.

---

## Опции кэширования

Qualimetrix кэширует разобранные AST-деревья для ускорения повторных запусков.

### `--no-cache`

Полностью отключить кэширование:

```bash
bin/qmx check src/ --no-cache
```

### `--cache-dir`

Указать директорию кэша. По умолчанию: `.qmx-cache`.

```bash
bin/qmx check src/ --cache-dir=/tmp/qmx-cache
```

### `--clear-cache`

Очистить кэш перед запуском анализа:

```bash
bin/qmx check src/ --clear-cache
```

---

## Опции baseline

Полный жизненный цикл и формат файла описаны в [Baseline](baseline.ru.md).

### `--baseline=BASELINE`

Использовать файл baseline, чтобы применить принятые потолки к текущим нарушениям:

```bash
bin/qmx check src/ --baseline=baseline.json
```

### `--show-resolved`

Посчитать записи, чья полная идентичность больше не появляется в измеряемом наборе:

```bash
bin/qmx check src/ --baseline=baseline.json --show-resolved
```

Stale- и inert-записи сообщаются, но не завершают прогон ошибкой и не отключают другие записи baseline. Группа, которая всё ещё срабатывает с меньшим числом элементов, не считается resolved.

### Lifecycle-команды baseline

Ниже приведена полная поверхность команд для записи и проверки baseline:

```text
bin/qmx baseline:generate <baseline> [<paths>...] [--mode=MODE] [--force]
bin/qmx baseline:update   <baseline> [<paths>...] [--force]
bin/qmx baseline:cleanup  <baseline> [<paths>...] [--remove=REMOVE]... [--force]
bin/qmx baseline:explain  <symbol> [<paths>...] [--baseline=BASELINE] [--channel=CHANNEL]
```

Все четыре команды принимают `--config=CONFIG`, `--preset=PRESET`, `--disable-rule=DISABLE-RULE`, `--only-rule=ONLY-RULE` и `--rule-opt=RULE-OPT`. Ни одна не принимает опции исключения или suppression.

- `baseline:generate` захватывает текущие измеряемые нарушения. По умолчанию используется `--mode=ratchet`; `--mode=suppress` записывает безусловное принятие захваченных идентичностей. Его `--force` перезаписывает существующий файл.
- `baseline:update` только ужесточает существующие записи. Его `--force` снимает проверку покрытия записанной области.
- `baseline:cleanup` по умолчанию выводит кандидатов и удаляет только повторяемые селекторы `--remove=REMOVE`. Его `--force` также снимает проверку области.
- `baseline:explain` показывает порог из конфигурации, принятую величину baseline и override из исходника для канонического символа; `--channel=CHANNEL` сужает ответ.

Все lifecycle-команды отказываются интерпретировать или записывать baseline при
неполном анализе и завершаются с кодом 4. `--force` снимает только ограничения
файла/области; он не делает частичный набор измерений допустимым. Существующий
файл остаётся побайтово неизменным, а `baseline:generate` не создаёт отсутствующий файл.

Загружаемые версии baseline и процедура миграции старого файла описаны в
разделе [Замена старого baseline](baseline.ru.md#замена-старого-baseline).

Удалённые опции `--generate-baseline` и `--baseline-ignore-stale` не имеют алиасов. Используй вместо них `baseline:generate` и явный `baseline:cleanup --remove`.

---

## Опции подавления

### `--show-suppressed`

Показать нарушения, подавленные тегами `@qmx-ignore`, а также нарушения, подавленные записью
`exclude_namespaces` / `exclude_namespace_channels` / `exclude_paths` на уровне правила в `qmx.yaml` (см.
[«Правила»](../getting-started/configuration.ru.md#правила-rules)):

```bash
bin/qmx check src/ --show-suppressed
```

Независимо от `--show-suppressed`, запуск с `-v` печатает разбивку по правилам — сколько
нарушений подавлено таким образом. Namespace-бакет включает обе опции неймспейсов и выводится
отдельно от `exclude_paths`; каждый бакет разбит по имени правила. В отличие от `@qmx-ignore`, в остальном это подавление проходит
незаметно — ничто в стандартном выводе не сигнализирует о том, что оно произошло.

### `--no-suppression-annotations`

Выводить все нарушения, включая те, которые подавлены тегами `@qmx-ignore`:

```bash
bin/qmx check src/ --no-suppression-annotations
```

!!! note "Опция не меняет то, что измеряет baseline"

    Флаг влияет только на отчёт. Baseline измеряет те нарушения, которые
    оставляют конфигурация и аннотации в исходниках, поэтому нарушение,
    убранное тегом `@qmx-ignore`, никогда не попадает в baseline и никогда не
    сравнивается с ним — независимо от того, передан ли этот флаг.

    Видимое следствие: под этим флагом подавленное аннотацией нарушение
    показывается со **своей собственной** серьёзностью и никогда не повышается
    до ошибки, потому что ни одна запись baseline его не покрывает. Флаг может
    сузить измеряемое baseline множество (`--exclude-path`,
    `--exclude-namespace`), но расширить его не может ни один.

---

## Опции области Git

Вывод нарушений только для изменённых файлов. Полное руководство смотрите в разделе [Интеграция с Git](git-integration.md).

### `--report`

Управление тем, какие нарушения выводить. Анализирует весь проект, но показывает только нарушения из изменённых файлов:

```bash
bin/qmx check src/ --report=git:main..HEAD
bin/qmx check src/ --report=git:origin/develop..HEAD
```

### `--report-strict`

В режиме diff показывать нарушения только из самих изменённых файлов. Без этого флага также выводятся нарушения из родительских пространств имён:

```bash
bin/qmx check src/ --report=git:main..HEAD --report-strict
```

---

## Опции выполнения

### `--workers`, `-w`

Управление параллельной обработкой. По умолчанию: автоопределение по количеству CPU.

```bash
# Отключить параллельную обработку (один процесс)
bin/qmx check src/ --workers=1

# Отключить параллельную обработку (последовательно)
bin/qmx check src/ --workers=0

# Использовать ровно 4 воркера
bin/qmx check src/ --workers=4
```

!!! tip "Совет"
    Используйте `--workers=1` для отладки или в однопроцессном окружении. `--workers=0` отключает параллелизм (последовательное выполнение); автоопределение — это поведение по умолчанию, когда опция не задана.

### `--memory-limit`

Установить лимит памяти PHP для анализа. По умолчанию используется значение `memory_limit` из `php.ini`.

```bash
# Установить лимит памяти 1ГБ для больших проектов
bin/qmx check src/ --memory-limit=1G

# Без ограничений памяти
bin/qmx check src/ --memory-limit=-1
```

Допустимые форматы: `-1` (без ограничений) или положительное целое число с опциональным суффиксом `K`/`M`/`G` (например, `512M`, `2G`).

Эквивалент в YAML: `memory_limit: 1G`

### `--log-file`

Записывать отладочный лог в файл:

```bash
bin/qmx check src/ --log-file=qmx.log
```

### `--log-level`

Установить минимальный уровень логирования. По умолчанию: `info`.

```bash
bin/qmx check src/ --log-file=qmx.log --log-level=debug
```

Доступные уровни: `debug`, `info`, `warning`, `error`.

### `--no-progress`

Отключить прогресс-бар. Полезно в CI-пайплайнах:

```bash
bin/qmx check src/ --no-progress
```

---

<!-- llms:skip-begin -->
## Опции профилирования

### `--profile`

Включить внутренний профайлер. Опционально можно указать файл для сохранения профиля:

```bash
<!-- llms:skip-end -->

# Показать сводку профилирования на экране
bin/qmx check src/ --profile

# Сохранить профиль в файл
bin/qmx check src/ --profile=profile.json
```

### `--profile-format`

Выбор формата экспорта профиля. По умолчанию: `json`.

```bash
bin/qmx check src/ --profile=profile.json --profile-format=chrome-tracing
```

Доступные форматы: `json`, `chrome-tracing`.

!!! tip "Совет"
    Используйте формат `chrome-tracing` и откройте файл в Chrome DevTools (chrome://tracing) для визуального анализа производительности.

---

## Опции правил

### `--disable-rule`

Отключить правило-производитель, целую группу или отдельный канал нарушения. Селектор — это
либо **точное** имя (правило-производитель, группа вроде `complexity` или канал), либо
`X.*` строго для **потомков** `X` — сам `X` в это не входит. Голый префикс без звёздочки —
ошибка. Селектор канала можно сузить до одного уровня дерева агрегации через `:level`, как и у
`--only-rule`. Отключение одного канала не останавливает производителя, чтобы остальные его
каналы продолжали попадать в отчёт. Опцию можно указывать несколько раз:

```bash
# Отключить одно правило
bin/qmx check src/ --disable-rule=size.class-count

# Отключить все правила сложности
bin/qmx check src/ --disable-rule=complexity.*

# Отключить несколько
bin/qmx check src/ --disable-rule=complexity.* --disable-rule=cohesion.lcom

# Отключить только один канал computed finding
bin/qmx check src/ --disable-rule=health.complexity
```

!!! tip "Оптимизация памяти"
    Отключение правила `duplication.code-duplication` также полностью пропускает ресурсоёмкую фазу обнаружения дубликатов. На больших кодовых базах (500+ файлов) это может значительно снизить потребление памяти. Используйте `--disable-rule=duplication.code-duplication`, если возникают ошибки нехватки памяти. Написание с уровнем — `--disable-rule=duplication.code-duplication:project` — пропускает её так же: канал сообщает ровно на этом уровне, поэтому погасить уровень значит погасить правило. Производитель останавливается, как только селекторы отключения вместе накрывают каждый уровень каждого его канала; один уровень двухуровневого канала оставляет его работать, потому что второму уровню ещё есть о чём сообщить.

### `--only-rule`

Запустить только подходящие правила-производители или каналы нарушений. Селектор — это либо
**точное** имя (правило-производитель, группа вроде `complexity` или канал), либо `X.*` строго
для его **потомков**; каждое из них можно сузить до одного уровня дерева агрегации через
`:level`. Селектор с уровнем не останавливает своего производителя: отфильтрованный
производитель никогда не выдал бы запрошенный уровень. Опцию можно указывать несколько раз:

```bash
# Запустить только правила сложности
bin/qmx check src/ --only-rule=complexity.*

# Запустить два конкретных правила
bin/qmx check src/ --only-rule=complexity.cyclomatic --only-rule=size.method-count

# Выбрать один канал встроенного измерения здоровья: производитель и канал
# называются одинаково, потому что каждое из шести измерений — сам себе производитель
bin/qmx check src/ --only-rule=health.complexity
```

Селектор должен точно совпасть с зарегистрированным producer, группой или выводимым каналом,
либо `X.*` должен разрешиться хотя бы в одного потомка. Неизвестный селектор — включая голый
префикс без звёздочки или `X.*`, не совпавший ни с чем, — завершается с кодом 3 до записи
report-payload в stdout:

```text
Rule selector "complexity" does not match any registered producer, group, or channel.
```

Аналогично, владелец перед `:` в `--rule-opt=RULE:OPTION=VALUE` должен быть точным
producer-rule, а не группой или каналом — группа или канал здесь являются ошибкой. То же
правило действует и для ключей секции `rules:` в YAML.

### `--rule-opt`

Переопределить опции правил из командной строки. Формат: `rule-name:option=value`, где
`rule-name` должен быть точным producer-rule — никогда группой, никогда каналом и никогда
wildcard. Это то же ограничение, что действует для владельца перед `:` в
`--only-rule`/`--disable-rule` и для ключей секции `rules:` в YAML. Можно указывать несколько раз:

```bash
bin/qmx check src/ --rule-opt=complexity.cyclomatic:callable.warning=15
bin/qmx check src/ --rule-opt=complexity.cyclomatic:callable.error=30
```

`exclude_namespace_channels` настраивается в YAML, а не через `--rule-opt`: каждому селектору
нужен непустой список паттернов неймспейсов, тогда как `--rule-opt` передаёт скалярные значения.
Его ключи — это селекторы каналов, подчиняющиеся тому же правилу «точное имя или `X.*`», что
и `@qmx-ignore`: голый префикс вроде `health` теперь ошибка, а не сокращение для `health.*`.
Ключ может добавить `:namespace` и никакой другой уровень: опции достаются только агрегаты по
неймспейсам, поэтому любой другой уровень назвал бы фильтр, который не сработает никогда.

<!-- llms:skip-begin -->
### Быстрые флаги для правил

Для многих правил доступны специальные CLI-флаги для быстрой настройки опций:

=== "Сложность"

| Флаг                           | Правило               | Опция             |
| ------------------------------ | --------------------- | ----------------- |
| `--cyclomatic-warning=N`       | complexity.cyclomatic | callable.warning  |
| `--cyclomatic-error=N`         | complexity.cyclomatic | callable.error    |
| `--cyclomatic-class-warning=N` | complexity.cyclomatic | class.max_warning |
| `--cyclomatic-class-error=N`   | complexity.cyclomatic | class.max_error   |
| `--cognitive-warning=N`        | complexity.cognitive  | callable.warning  |
| `--cognitive-error=N`          | complexity.cognitive  | callable.error    |
| `--cognitive-class-warning=N`  | complexity.cognitive  | class.max_warning |
| `--cognitive-class-error=N`    | complexity.cognitive  | class.max_error   |
| `--npath-warning=N`            | complexity.npath      | callable.warning  |
| `--npath-error=N`              | complexity.npath      | callable.error    |
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

| Флаг                                 | Правило                       | Опция               |
| ------------------------------------ | ----------------------------- | ------------------- |
| `--dit-warning=N`                    | design.inheritance            | warning             |
| `--dit-error=N`                      | design.inheritance            | error               |
| `--lcom-warning=N`                   | cohesion.lcom                 | warning             |
| `--lcom-error=N`                     | cohesion.lcom                 | error               |
| `--lcom-min-methods=N`               | cohesion.lcom                 | minMethods          |
| `--lcom-exclude-readonly`            | cohesion.lcom                 | excludeReadonly     |
| `--noc-warning=N`                    | design.noc                    | warning             |
| `--noc-error=N`                      | design.noc                    | error               |
| `--param-type-coverage-warning=N`    | design.type-coverage.param    | warning             |
| `--param-type-coverage-error=N`      | design.type-coverage.param    | error               |
| `--return-type-coverage-warning=N`   | design.type-coverage.return   | warning             |
| `--return-type-coverage-error=N`     | design.type-coverage.return   | error               |
| `--property-type-coverage-warning=N` | design.type-coverage.property | warning             |
| `--property-type-coverage-error=N`   | design.type-coverage.property | error               |
| `--property-exclude-readonly`        | size.property-count           | excludeReadonly     |
| `--property-exclude-promoted-only`   | size.property-count           | excludePromotedOnly |

=== "Сопровождаемость"

| Флаг                    | Правило               | Опция         |
| ----------------------- | --------------------- | ------------- |
| `--mi-warning=N`        | maintainability.index | warning       |
| `--mi-error=N`          | maintainability.index | error         |
| `--mi-min-statements=N` | maintainability.index | minStatements |
| `--mi-exclude-tests`    | maintainability.index | excludeTests  |

=== "Запахи кода"

| Флаг                                    | Правило                              | Опция               |
| --------------------------------------- | ------------------------------------ | ------------------- |
| `--constructor-overinjection-warning=N` | code-smell.constructor-overinjection | warning             |
| `--constructor-overinjection-error=N`   | code-smell.constructor-overinjection | error               |
| `--data-class-woc-threshold=N`          | design.data-class                    | wocThreshold        |
| `--data-class-wmc-threshold=N`          | design.data-class                    | wmcThreshold        |
| `--data-class-min-members=N`            | design.data-class                    | minMembers          |
| `--data-class-exclude-readonly`         | design.data-class                    | excludeReadonly     |
| `--data-class-exclude-promoted-only`    | design.data-class                    | excludePromotedOnly |
| `--god-class-wmc-threshold=N`           | design.god-class                     | wmcThreshold        |
| `--god-class-lcom-threshold=N`          | design.god-class                     | lcomThreshold       |
| `--god-class-tcc-threshold=N`           | design.god-class                     | tccThreshold        |
| `--god-class-class-loc-threshold=N`     | design.god-class                     | classLocThreshold   |
| `--god-class-min-criteria=N`            | design.god-class                     | minCriteria         |
| `--god-class-min-methods=N`             | design.god-class                     | minMethods          |
| `--god-class-exclude-readonly`          | design.god-class                     | excludeReadonly     |
| `--long-parameter-list-warning=N`       | code-smell.long-parameter-list       | warning             |
| `--long-parameter-list-error=N`         | code-smell.long-parameter-list       | error               |
| `--long-parameter-list-vo-warning=N`    | code-smell.long-parameter-list       | vo-warning          |
| `--long-parameter-list-vo-error=N`      | code-smell.long-parameter-list       | vo-error            |
| `--unreachable-code-warning=N`          | code-smell.unreachable-code          | warning             |
| `--unreachable-code-error=N`            | code-smell.unreachable-code          | error               |

=== "Архитектура"

| Флаг                                  | Правило                          | Опция        |
| ------------------------------------- | -------------------------------- | ------------ |
| `--circular-deps`                     | architecture.circular-dependency | enabled      |
| `--max-cycle-size=N`                  | architecture.circular-dependency | maxCycleSize |
| `--layer-violation`                   | architecture.layer-violation     | enabled      |
| `--layer-violation-severity=SEVERITY` | architecture.layer-violation     | severity     |
| `--unassigned-class-mode=MODE`        | architecture.unassigned-class    | mode         |

---

<!-- llms:skip-end -->

## Другие команды

### baseline:cleanup

Проверить stale-кандидатов в baseline. Без `--remove` команда только выводит их и никогда не меняет файл; удалить явно проверенный selector можно так, как описано в [Baseline](baseline.ru.md):

```bash
bin/qmx baseline:cleanup baseline.json src/
bin/qmx baseline:cleanup baseline.json src/ --remove=<selector>
```

### debug:layer-assignment

Показать, к какому слою архитектуры отнесён класс, и перечислить все остальные слои, чьи критерии тоже совпали бы (потенциальный источник затенения). Полное описание — в разделе [Инспекция назначения слоя для одного класса](../rules/architecture.ru.md#debug-layer-assignment).

```bash
bin/qmx debug:layer-assignment 'App\Service\Foo'
bin/qmx debug:layer-assignment 'App\Service\Foo' --config qmx.yaml

# Машиночитаемый вывод — для агентов и скриптов, не для парсинга текстового отчёта
bin/qmx debug:layer-assignment 'App\Service\Foo' --format=json
```

| Опция                 | Описание                                                          |
| --------------------- | ----------------------------------------------------------------- |
| `-c`, `--config=FILE` | Путь к `qmx.yaml` (по умолчанию: `qmx.yaml` в текущей директории) |
| `--format=FORMAT`     | `text` (по умолчанию) или `json`                                  |

`--format=json` сериализует тот же результат разрешения, что рендерит текстовый отчёт, — отдельной проверки не вводится, поэтому исхода «класс не найден» нет: любой синтаксически корректный FQN классифицируется. Схема:

```json
{
  "fqn": "App\\Service\\Foo",
  "assigned": { "layer": "any-foo", "criteria": ["pattern \"App\\**\\Foo\""] },
  "shadowed": [
    { "layer": "service", "criteria": ["pattern \"App\\Service\\**\""] }
  ],
  "hasLayers": true
}
```

- `assigned` — `null`, если ни один слой не совпал (в этом случае `shadowed` пуст).
- `shadowed` перечисляет все остальные совпавшие слои в порядке объявления — каждый из них получил бы класс, будь он объявлен раньше `assigned`.
- `hasLayers` различает «слои не объявлены» (`false`) и «слои объявлены, но ни один не совпал с этим классом» (`true` при `assigned: null`).
- При ошибке `--format=json` печатает в stdout `{"error": "...", "exit_code": N}` вместо человекочитаемой строки `<error>`; неизвестное значение `--format` завершается кодом 2 независимо от формата.

### graph:export

Экспортировать граф зависимостей для визуализации:

```bash
# Экспорт в формате DOT (по умолчанию)
bin/qmx graph:export src/ -o graph.dot

# Экспорт в формате JSON (агрегированный список смежности с метаданными)
bin/qmx graph:export src/ --format=json -o graph.json

# Фильтрация по пространству имён
bin/qmx graph:export src/ --namespace=App\\Service --namespace=App\\Repository

# Исключение пространств имён
bin/qmx graph:export src/ --exclude-namespace=App\\Generated

# Изменение направления графа
bin/qmx graph:export src/ --direction=TB

# Отключение группировки по пространствам имён
bin/qmx graph:export src/ --no-clusters
```

| Опция                    | Описание                                                       |
| ------------------------ | -------------------------------------------------------------- |
| `-o`, `--output=FILE`    | Выходной файл (по умолчанию: stdout)                           |
| `-f`, `--format=FORMAT`  | `dot` (по умолчанию) или `json`                                |
| `-d`, `--direction=DIR`  | Направление графа: `LR`, `TB`, `RL`, `BT` (по умолчанию: `LR`) |
| `--no-clusters`          | Не группировать узлы по пространствам имён                     |
| `--namespace=NS`         | Включить только указанные пространства имён (можно повторять)  |
| `--exclude-namespace=NS` | Исключить указанные пространства имён (можно повторять)        |

Если хотя бы один обнаруженный файл не удалось разобрать или обработать,
`graph:export` завершается с кодом 4 и не выводит частичный граф. Команда не создаёт
отсутствующий output-файл и побайтово сохраняет существующий.

### hook:install

Установить git-хук pre-commit:

```bash
bin/qmx hook:install

# Перезаписать существующий хук
bin/qmx hook:install --force
```

### hook:status

Показать текущий статус хука pre-commit:

```bash
bin/qmx hook:status
```

### hook:uninstall

Удалить хук pre-commit:

```bash
bin/qmx hook:uninstall

# Восстановить оригинальный хук из резервной копии
bin/qmx hook:uninstall --restore-backup
```

### rules

Вывести список всех доступных правил с описаниями и опциями CLI:

```bash
# Показать все правила
bin/qmx rules

# Фильтр по группе
bin/qmx rules --group=complexity
```

**Пример вывода** (для `--group=complexity`):

```
4 rules available

Complexity
  complexity.cognitive                     Checks cognitive complexity at method and class levels
    --cognitive-warning (--rule-opt=complexity.cognitive:callable.warning=...)
    --cognitive-error (--rule-opt=complexity.cognitive:callable.error=...)
    --cognitive-class-warning (--rule-opt=complexity.cognitive:class.max_warning=...)
    --cognitive-class-error (--rule-opt=complexity.cognitive:class.max_error=...)
  complexity.cyclomatic                    Checks cyclomatic complexity at method and class levels
    --cyclomatic-warning (--rule-opt=complexity.cyclomatic:callable.warning=...)
    --cyclomatic-error (--rule-opt=complexity.cyclomatic:callable.error=...)
    --cyclomatic-class-warning (--rule-opt=complexity.cyclomatic:class.max_warning=...)
    --cyclomatic-class-error (--rule-opt=complexity.cyclomatic:class.max_error=...)
  ...

Usage: bin/qmx check --disable-rule=<name> | --only-rule=<name>
        bin/qmx check --rule-opt=<name>:<option>=<value>
```

Правила сгруппированы по категориям, рядом с каждым CLI-алиасом показана
длинная форма `--rule-opt`, в которую он разворачивается. Значений порогов по
умолчанию в этом выводе нет — они описаны в
[Пороговых значениях по умолчанию](../reference/default-thresholds.ru.md).
