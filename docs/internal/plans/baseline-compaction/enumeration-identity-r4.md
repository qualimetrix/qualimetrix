# Перечисления к редакции 4 раздела P2

Сняты заново по коду рабочего дерева на `90e341b8` (после влития P1), а не
унаследованы из [`enumeration-identity.md`](enumeration-identity.md) и
[`identity-map.md`](identity-map.md): те снимались на `257abf1a` под другой
вопрос. Расхождения с ними названы в тексте.

Каждая таблица сопровождается строкой «чем получено и чего этот способ не
видит».

## R1. Производители `DeclarationPath` и источник ординала после P2

`grep -rc "new DeclarationPath(" src` даёт **12** мест в 11 файлах.
Столбец «источник ординала» — то, что редакция 4 обязана назвать для каждого;
именно его отсутствие у двух последних строк отклонило редакцию 3.

| #   | Место                                                  | Что строит              | Источник ордината после P2                                              |
| --- | ------------------------------------------------------ | ----------------------- | ----------------------------------------------------------------------- |
| 1   | `Core/Symbol/MetricSubjectCodec.php:133`               | декодирование с провода | `collisionOrdinal` записи, отсутствует ⇒ 0 (единственный `fromWire`)    |
| 2   | `Measurement/Visitor/VisitorCallableMetadata.php:36`   | сам вызываемый          | `$scope->ordinal`, присвоенный `VisitorFileEntryScope::enterCallable`   |
| 3   | `Measurement/Visitor/VisitorCallableMetadata.php:27`   | лексический класс       | `$scope->classOrdinal`, присвоенный `VisitorFileEntryScope::enterClass` |
| 4   | `Design/TypeCoverageVisitor.php:129`                   | класс                   | поле записи карты, счётчик визитора                                     |
| 5   | `Design/InheritanceDepthCollector.php:103`             | класс                   | поле `InheritanceClassInfo`, счётчик `InheritanceDepthVisitor`          |
| 6   | `Cohesion/LcomCollector.php:101`                       | класс                   | поле `LcomClassData`, счётчик `LcomVisitor`                             |
| 7   | `Cohesion/TccLccCollector.php:137`                     | класс                   | поле `TccLccClassData`, счётчик `TccLccVisitor`                         |
| 8   | `CodeSmell/UnusedPrivateCollector.php:91`              | класс                   | поле `UnusedPrivateClassData`, счётчик `UnusedPrivateVisitor`           |
| 9   | `Coupling/RfcCollector.php:111`                        | класс                   | поле `ClassRfcData`, счётчик `RfcVisitor`                               |
| 10  | `Size/LocCollector.php:137`                            | класс                   | поле записи `classRanges`, счётчик `LocVisitor`                         |
| 11  | `Size/MethodCountCollector.php:205`                    | класс                   | поле `MethodCountMetrics`, счётчик `MethodCountVisitor`                 |
| 12  | `DependencyModel/Extraction/DependencyVisitor.php:181` | источник ребра          | собственный счётчик, инкремент в `enterNamedClassLike()`                |

В `tests/` — **291** вхождение в **98** файлах; из них позицию третьим
аргументом передаёт подавляющее большинство (точный разбор не требуется:
конструктор закрывается, и правки требуют все 291).

**Чем получено:** `grep -rn "new DeclarationPath(" src tests --include=*.php`,
затем чтение каждого из 12 мест в `src` с контекстом ±20 строк для определения
источника ординала.
**Чего не видит:** восстановление объекта минуя конструктор. Проверено
отдельно: `grep -rn "unserialize\|ReflectionClass" src` даёт 10 совпадений, ни
одно не строит `DeclarationPath` (`__unserialize` — у `AbsolutePath`/
`RelativePath`, рефлексия — у DIT-коллекторов для внешних классов). Но канал
существует: `DeclarationPath` пересекает границу воркера через нативную
сериализацию `amphp/parallel`, а `unserialize()` readonly-класса конструктор
**не вызывает**. Закрытый конструктор этот канал не закрывает; он и не обязан —
через него едут уже присвоенные значения, а не новые идентичности. Также не
видит конструирование в фикстурах, исключённых из PHPStan
(`phpstan.neon: excludePaths`, 6 каталогов): там ошибка типа ловится тестом, не
статикой.

## R2. Читатели `DeclarationPath::$startFilePos`

Ровно **5** мест в production-коде, все в одном файле, плюс сам
`toCanonical()`:

| Место                                                 | Роль                                          |
| ----------------------------------------------------- | --------------------------------------------- |
| `Policy/Inline/.../DeclarationControlBindings.php:71` | индекс callable-субъектов по позиции          |
| `...:73`                                              | `start` элемента `$callableStarts`            |
| `...:82`                                              | индекс класс-субъектов по позиции             |
| `...:110`                                             | `start` во втором проходе                     |
| `...:114`                                             | `start` класс-субъекта во втором проходе      |
| `Core/Symbol/DeclarationPath.php:43`                  | подстановка в канонический ключ (устраняется) |

В `tests/` слово `startFilePos` встречается 79 раз в 24 файлах.

**Чем получено:** `grep -rn "startFilePos" src` (59 строк, прочитаны все) и
`grep -rn -- "->startFilePos" tests`.
**Чего не видит:** чтение через промежуточную переменную в другой строке
(при чтении всех 59 строк не встретилось) и чтение через `MetricSubject`
без явного `->startFilePos` в той же строке.

## R3. Восемь карт класс-уровня, ключуемых FQN

Все восемь перезаписывают запись безусловно (**last-wins**): при двух
объявлениях одного FQN в файле сохраняются данные **второго**. Столбец «какие
узлы видит» — тот самый признак, по которому счётчики могут разойтись между
собой, если считать только то, что визитор хранит.

| Визитор                   | Место записи | Тип записи               | Какие именованные узлы доходят до записи                                                                              |
| ------------------------- | ------------ | ------------------------ | --------------------------------------------------------------------------------------------------------------------- |
| `LcomVisitor`             | :83-89       | `LcomClassData`          | только `Class_` (фильтр `extractClassLikeName`)                                                                       |
| `TccLccVisitor`           | :96-101      | `TccLccClassData`        | только `Class_`: `Interface_` и `Enum_` отсеиваются гейтом :84-89, `Trait_` не проходит `extractClassLikeName` трейта |
| `UnusedPrivateVisitor`    | :238-243     | `UnusedPrivateClassData` | набор `match` в `enterClassLike()`                                                                                    |
| `RfcVisitor`              | :132-137     | `ClassRfcData`           | любой именованный `ClassLike` (`extractClassLikeName` :327-336 покрывает все четыре вида)                             |
| `MethodCountVisitor`      | :163-168     | `MethodCountMetrics`     | любой именованный `ClassLike`                                                                                         |
| `InheritanceDepthVisitor` | :118-123     | `InheritanceClassInfo`   | только `Class_`                                                                                                       |
| `TypeCoverageVisitor`     | :150-156     | массив `classInfos`      | любой именованный `ClassLike`                                                                                         |
| `LocVisitor`              | :54-60       | массив `classRanges`     | только `Class_`                                                                                                       |

**Правка после раунда 4:** две строки исправлены по коду (`TccLccVisitor` — только
`Class_`; `RfcVisitor` — все четыре вида). Первая редакция таблицы определяла их
по ветке записи, не дочитывая гейт и трейт.

**Следствие для плана:** универсум подсчёта у восьми визиторов **различен**, и
счётчик, поставленный внутрь ветки записи, выдаст для одного и того же
объявления разные номера у разных визиторов. Сегодня расхождение невозможно,
потому что все восемь берут абсолютный `getStartFilePos()`. Это было источником инварианта
«считать до собственного фильтра» в редакции 4. В редакции 5 таблица —
справочная: множество позиций собирается объединением регистраций, и фильтр
отдельного визитора на номер не влияет.

**Чем получено:** `grep -rn "startFilePos" src` дал 8 мест записи; каждое
прочитано с контекстом −16/+4 строки, тип узла определён по условию ветки.
**Чего не видит:** визиторы, которые строят класс-уровневый субъект, не
записывая `startFilePos` (таких нет: множество производителей R1 закрыто по
`new DeclarationPath(`), и визиторы за пределами
`ClassMetricsProviderInterface`, которые могли бы завести класс-карту в
будущем — их появление ловится только drift-guard-тестом, не grep'ом.

## R4. Проводная грамматика `MetricSubjectCodec`

- `ENTRY_KEYS` (:19-27) — 7 ключей, среди них `startFilePos` и
  `collisionOrdinal`;
- три `required/allowed`-набора в `decode()` (:95-121) — по два набора на
  каждый из `class`/`method`/`function`, `startFilePos` обязателен во всех
  трёх;
- три фабрики `encodeClass`/`encodeMethod`/`encodeFunction` (:36-60), каждая
  принимает `int $startFilePos` и **неиспользуемый** `?int $collisionOrdinal`;
- вызовов `MetricSubjectCodec::encode*` в `src` — **4**
  (`VisitorFileEntryScope.php:81,196,217,218`); ни один не передаёт
  `collisionOrdinal` (он подставляется позже, в `subjectComponents():203-205`).

**Чем получено:** чтение `MetricSubjectCodec.php` целиком и
`grep -rn "MetricSubjectCodec::encode" src tests`.
**Чего не видит:** записи в бейзлайне и фикстурах, где компоненты собраны
писателем как литеральный массив, а не через фабрики, — они ловятся
`decode()` в рантайме, а не компилятором.

## R5. Транспорт, куда переезжает роль А

| Контракт              | Конструирований в `src`                                                          | В `tests` | Как передаётся                              |
| --------------------- | -------------------------------------------------------------------------------- | --------- | ------------------------------------------- |
| `CallableWithMetrics` | 2 (`VisitorCallableMetadata:35`, `DerivedCollectorRunner:136`)                   | 72        | VO целиком пересекает границу процесса      |
| `ClassWithMetrics`    | 10 (8 коллекторов + `DerivedCollectorRunner:164` + `CollectionOrchestrator:214`) | 20        | VO разбирается в тройку и собирается заново |

Докблок-тип тройки `array{subject, metrics, line}` объявлен в **7** местах:
`FileProcessor.php:163`, `FileProcessingResult.php:62`,
`SuccessfulFileProcessing.php:21`, `SourceControlExtractorInterface.php:18`,
`SourceControlExtractor.php:69`, `DeclarationControlBindings.php:61,104`.

**Чем получено:** `grep -rn "new ClassWithMetrics\|new CallableWithMetrics" src tests`
и `grep -rn "subject: MetricSubject, metrics" src`.
**Чего не видит:** последний grep пропустил `FileProcessor.php:163` — там тип
записан полным именем `\Qualimetrix\Core\Symbol\MetricSubject`. Найдено
дополнительным `grep -rn "metrics: MetricBag, line: int" src`. Это и есть
образец слепого пятна: докблок-тип не имеет канонической формы записи.

## R6. Ключи текущего ратчета

199 записей: 182 объявления, 16 неймспейсов, 1 проектная. Все 182 оканчиваются
на `.php:<offset>`. Вхождений `{anonymous@` — **0**, `{closure#` — **0**,
суффикса `#<ординал>` — **0**. Каналов 22, самый частый
`complexity.cyclomatic#complexity.cyclomatic.callable` (62).

**Чем получено:** разбор `qmx-baseline.json` python-скриптом (`json.load`,
регулярные выражения по ключам), а не `grep`.
**Чего не видит:** записи, которые появятся при регенерации после P2, — по ним
утверждать что-либо до прогона нельзя.

## R7. Позиция, остающаяся в ключе после P2 — измерено

Имя анонимного класса содержит байтовое смещение и **доходит** до ключа
субъекта нарушения. Фикстура: метод внутри `new class {...}`, прогон
`bin/qmx check --only-rule=complexity.cyclomatic` с нулевыми порогами:

```
declaration:callable:Fixture\Anon\{anonymous@71}::branchy@.../Anon.php:87
declaration:func:Fixture\Anon::make@.../Anon.php:30
```

То есть утверждение прошлых редакций «до бейзлайна не доходит» верно только
про **сегодняшний состав** ратчета (R6: ноль вхождений), но не про механизм.

**Чем получено:** прогон `bin/qmx` на фикстуре в scratchpad, вывод выше.
**Чего не видит:** случаи, где анонимный класс объявлен внутри другого
анонимного (`anonymousClassContext`), — там субъект схлопывается в `file` и
до ключа объявления не доходит вовсе.
