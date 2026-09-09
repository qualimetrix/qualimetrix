# Этап 02 — места броска капабилити `ComputedMetrics` с тривердиктом

Съёмка на `1513bf67`. Образец — `03-carrier-and-normalization.md` §4: каждому месту
броска один из трёх вердиктов — **отказ** (достижимо вводом и покидает капабилити),
**дефект** (недостижимо корректным вводом, остаётся кодом 1), **ловится внутри**.
Для «отказа» названы форма носителя (`at` / `aboutDocument` / `aboutInput`) и позиция.

## Чем получено и чего этот способ не видит

```
grep -rn 'throw ' src/Analysis/Evidence/ComputedMetrics --include=*.php
```

25 попаданий, из них одно (`Contract/Definition/ComputedMetricDefinition.php:107`) —
проза в докблоке, а не бросок. **Реальных мест броска 24.**

Чего способ не видит и как это пройдено:

- **бросок через хелпер** — прочитан каждый файл каталога; хелперов, бросающих за
  вызывающего, нет; `?? throw` в выражении грепом виден (`ComputedMetricProducerOptions:43`,
  `ComputedMetricOverrideReader:196`) и в счёте есть;
- **исключение, поднятое не `throw`, а рантаймом** — грепом не видно вовсе. В цепочке
  такое одно и оно ключевое: `TypeError` на строгой сигнатуре
  `ComputedMetricOverrideReader::mapLevel(string $level)` (`:193`), поднимаемый PHP из
  `array_map` на `:138`. Это измеренный крах M5, и он идёт отдельной строкой ниже;
- **исключение чужого владельца, пролетающее сквозь капабилити** — `SyntaxError`
  Expression Language ловится на месте (`ComputedMetricFormulaValidator:63`) и наружу
  не выходит;
- **строковый канал (DI, YAML) и рефлексия** — проверены чтением конфигуратора
  капабилити и грепом `ReflectionClass` по каталогу: мест броска не добавляют;
- **места броска вне каталога, достижимые вводом в эту секцию** — грепом по каталогу
  не видны по построению. Найдено чтением цепочки одно, и оно вне всех наборов
  этапов: `src/Infrastructure/Rule/ChannelUniverse.php:265` (см. последний раздел).

## Таблица

Столбец «сегодня» — код возврата под `bin/qmx check` на текущем дереве.

| #   | место                                                   | класс                                  | сегодня | вердикт          | форма и позиция носителя                                                                                                                     |
| --- | ------------------------------------------------------- | -------------------------------------- | ------- | ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `ComputedMetricProducerOptions.php:43`                  | `LogicException`                       | 1       | дефект           | —                                                                                                                                            |
| 2   | `ComputedMetricOverrideReader.php:174`                  | `ComputedMetricConfigurationException` | 3       | отказ            | `at`, `[computed_metrics, <имя>]`                                                                                                            |
| 3   | `ComputedMetricOverrideReader.php:196`                  | то же                                  | 3       | отказ            | `at`, `[computed_metrics, <имя>, levels]`                                                                                                    |
| 4   | `ComputedMetricOverrideReader.php:199`                  | то же                                  | 3       | отказ            | `at`, `[computed_metrics, <имя>, levels]`                                                                                                    |
| 5   | `ComputedMetricOverrideReader.php:236`                  | `InvalidArgumentException`             | 3       | отказ            | `at`, `[computed_metrics, <имя>, threshold]`, `accepted()` пуст, `isClosed()=false`                                                          |
| 6   | `ComputedMetricsConfigResolver.php:81`                  | `ComputedMetricConfigurationException` | 3       | отказ            | `at`, `[computed_metrics, <имя>]`, `accepted()` = шесть имён `HealthDimension`, `isClosed()=false`                                           |
| 7   | `ComputedMetricsConfigResolver.php:135`                 | `InvalidArgumentException`             | 3       | отказ            | то же, что #6 — **сводится с #6 в один отказ**                                                                                               |
| 8   | `ComputedMetricFormulaValidator.php:65`                 | `ComputedMetricConfigurationException` | 3       | отказ            | `at`, `[computed_metrics, <имя>, formulas, <уровень>]`                                                                                       |
| 9   | `ComputedMetricFormulaValidator.php:75`                 | то же                                  | 3       | отказ            | то же                                                                                                                                        |
| 10  | `ComputedMetricFormulaValidator.php:99`                 | то же                                  | 3       | отказ            | то же                                                                                                                                        |
| 11  | `ComputedMetricFormulaValidator.php:139`                | то же                                  | 3       | отказ            | `at`, `[computed_metrics, <узел цикла>]` — спорная строка, см. ниже                                                                          |
| 12  | `ComputedMetricFormulaValidator.php:184`                | то же                                  | 3       | отказ            | `at`, `[computed_metrics, <имя>, formulas, <уровень>]`                                                                                       |
| 13  | `ComputedMetricFormulaValidator.php:239`                | то же                                  | 3       | отказ            | то же                                                                                                                                        |
| 14  | `Configuration/ComputedMetricContributionReader.php:29` | `InvalidArgumentException`             | 3       | отказ            | `at`, `[computed_metrics]`, `accepted()` пуст, `isClosed()=false`                                                                            |
| 15  | `Configuration/ComputedMetricContributionReader.php:44` | то же                                  | 3       | отказ            | `at`, `[exclude_health]`                                                                                                                     |
| 16  | `Configuration/ComputedMetricContributionReader.php:49` | то же                                  | 3       | отказ            | `at`, `[exclude_health]`                                                                                                                     |
| 17  | `Contract/Evaluation/ComputedMetricEvaluator.php:150`   | `RuntimeException`                     | **1**   | дефект           | — спорная строка, см. ниже                                                                                                                   |
| 18  | `Contract/Definition/ComputedMetricDefinition.php:119`  | `InvalidArgumentException`             | 3       | **инвариант VO** | отказ ставится **до** конструктора, в читателе; сам бросок остаётся                                                                          |
| 19  | `Contract/Definition/ComputedMetricDefinition.php:144`  | `InvalidArgumentException`             | 3       | **инвариант VO** | то же                                                                                                                                        |
| 20  | `Contract/Evaluation/MetricLookup.php:44`               | `LogicException`                       | 1       | дефект           | —                                                                                                                                            |
| 21  | `Contract/Evaluation/MetricLookup.php:49`               | `LogicException`                       | 1       | дефект           | —                                                                                                                                            |
| 22  | `Health/Configuration/HealthFormulaExcluder.php:74`     | `InvalidArgumentException`             | 3       | отказ            | `at`, `[exclude_health]`, `accepted()` пуст (отвергается элемент списка, не ключ; шесть допустимых имён — в `summary()`), `isClosed()=false` |
| 23  | `Health/Configuration/HealthFormulaExcluder.php:163`    | `InvalidArgumentException`             | 3       | отказ            | `at`, `[computed_metrics, health.overall, formulas, <уровень>]`                                                                              |
| 24  | `Health/Score/ContributorRanker.php:34`                 | `InvalidArgumentException`             | 1       | дефект           | направление приходит из продуктового каталога `HealthDimensionCatalog`, не из конфигурации                                                   |

Вердикта **«ловится внутри»** в этом каталоге нет ни одного: `catch` в капабилити
единственный — `ComputedMetricFormulaValidator:63` вокруг `SyntaxError`, и он тут же
перебрасывает (строка 8), то есть это место броска, а не перехват.

**Крах, которого нет в таблице, потому что это не `throw`:** `TypeError` на
`ComputedMetricOverrideReader::mapLevel(string)` `:193`, поднимаемый PHP из `array_map`
на `:138`, когда `levels:` — карта. Сегодня exit 1, stdout пуст. Вердикт: **отказ**,
и он обязан быть поставлен **до** `array_map`, в `levels()` `:134-139`, формой
`at`, позиция `[computed_metrics, <имя>, levels]`.

## Спорные строки

- **#11, цикл зависимостей.** Отказ называет цепочку целиком (`a -> b -> a`), а позиция
  у носителя одна. Решение: позиция — узел, на котором цикл замкнулся (тот, что уже
  в `$inStack`), цепочка целиком остаётся в `summary()`. Отвергнуто `aboutInput`
  («у отказа нет позиции»): позиция есть и она полезна — пользователь правит запись
  этого имени.
- **#17, `ComputedMetricEvaluator:150`.** Формула — пользовательский ввод, и сообщение
  прямо говорит «Check the formula», что тянет на отказ. Вердикт всё же **дефект**:
  конфигурационная проверка `validateMetricKeyExistence` существует ровно для того,
  чтобы это место было недостижимо; вход, который до него доезжает, — дыра в той
  проверке, а не новый род отказа. Правило 2 обзора запрещает переклассифицировать
  такое перехватом. Слепое пятно названо: измеренного входа, доезжающего сюда, нет —
  ни одна из 32 съёмок M5 его не подняла.
- **#18, #19 — инварианты VO.** `levels: [class, class]` и негодное имя достижимы
  вводом, но бросает их конструктор `ComputedMetricDefinition`. Позиция
  `03-carrier-and-normalization.md` §4 (случай `FindingChannel`) применяется дословно:
  инвариант VO остаётся `InvalidArgumentException`, а отказ ставится на месте вызова —
  в читателе записи, до конструирования. Чтобы правило имени не было переписано дважды,
  VO получает предикат-читатель имени, и оба спрашивают его.

## Место броска вне всех наборов — возврат оркестратору

`src/Infrastructure/Rule/ChannelUniverse.php:265`, `InvalidArgumentException`:
пятый запрет на имя метрики (имя занято зарегистрированным правилом или объявленным
каналом). Достижим вводом — докблок метода это утверждает прямо, — сегодня даёт exit 3
только потому, что `CheckCommand` ловит `InvalidArgumentException` общим `catch`.

Владелец: `Infrastructure.Rule`. Это **не** `src/Infrastructure/Console/**` (набор 01),
не `src/Analysis/**` (наборы 02 и 03) и не строка сведённой таблицы `m6-routes-merged.md`.
Если этап 01 снимет общий `catch (InvalidArgumentException)`, отказ станет кодом 1
без шапки. Этап 02 его не трогает: файл вне набора.
