# Перечисления к плану «идентичность субъекта и значения записи»

Снято по коду в рабочем дереве на `257abf1a`. Каждая таблица сопровождается
строкой «чем получено и чего этот способ не видит» — перечисление ломается
тихо, и названный инструмент дороже лишней таблицы.

## E1. Производители идентичности объявления

Множество определяет границы пакетов P2/P3: каждый элемент сегодня передаёт
в идентичность байтовое смещение узла и должен будет передавать порядковый
номер.

| #   | Место                                                                        | Что строит                                    |
| --- | ---------------------------------------------------------------------------- | --------------------------------------------- |
| 1   | `src/Core/Symbol/MetricSubjectCodec.php:133`                                 | декодирование компонентов в `DeclarationPath` |
| 2   | `src/Analysis/Evidence/Measurement/Visitor/VisitorCallableMetadata.php:27`   | класс-контейнер вызываемого                   |
| 3   | `src/Analysis/Evidence/Measurement/Visitor/VisitorCallableMetadata.php:36`   | сам вызываемый (уже с `ordinal`)              |
| 4   | `src/Analysis/Evidence/Measurement/Visitor/VisitorFileEntryScope.php:81`     | `encodeClass`                                 |
| 5   | `src/Analysis/Evidence/Measurement/Visitor/VisitorFileEntryScope.php:217`    | `encodeMethod`                                |
| 6   | `src/Analysis/Evidence/Measurement/Visitor/VisitorFileEntryScope.php:218`    | `encodeFunction`                              |
| 7   | `src/Analysis/Evidence/Design/InheritanceDepthCollector.php:103`             | класс                                         |
| 8   | `src/Analysis/Evidence/Design/TypeCoverageVisitor.php:129`                   | класс                                         |
| 9   | `src/Analysis/Evidence/Cohesion/LcomCollector.php:101`                       | класс                                         |
| 10  | `src/Analysis/Evidence/Cohesion/TccLccCollector.php:137`                     | класс                                         |
| 11  | `src/Analysis/Evidence/CodeSmell/UnusedPrivateCollector.php:91`              | класс                                         |
| 12  | `src/Analysis/Evidence/Coupling/RfcCollector.php:111`                        | класс                                         |
| 13  | `src/Analysis/Evidence/Size/LocCollector.php:137`                            | класс                                         |
| 14  | `src/Analysis/Evidence/Size/MethodCountCollector.php:205`                    | класс                                         |
| 15  | `src/Analysis/Evidence/DependencyModel/Extraction/DependencyVisitor.php:181` | источник ребра зависимости                    |

**Чем получено:** `grep -rn "new DeclarationPath(\|MetricSubjectCodec::encode" src`.
**Чего не видит:** строковые ключи, собранные вручную (см. E2), рефлексию и DI.
Канал прямого конструирования закрывается не перечислением, а типом: `ordinal`
получает выделенный VO `DeclarationOrdinal`, и старый вызов с `int` становится
ошибкой типов. Проверено ревью: под сигнатурой `(SymbolPath, RelativePath, int)`
11 из 15 мест компилируются без правки и молча передали бы смещение как номер.

### E1-bis. Читатели `DeclarationPath::$startFilePos`

В прошлой редакции перечисления не было — перечислялись только производители.

| Место                                                                                | Роль                                                        |
| ------------------------------------------------------------------------------------ | ----------------------------------------------------------- |
| `src/Analysis/Policy/Inline/Extraction/DeclarationControlBindings.php:71,73,110,114` | ключ соединения инлайновых директив с диапазонами узлов AST |
| 4 файла в `tests/`                                                                   | фикстуры                                                    |

**Чем получено:** `grep -rn "declarationPath->startFilePos\|)->startFilePos" src tests`.
**Чего не видит:** чтение через промежуточную переменную и через `MetricSubject`
без явного `->startFilePos` в той же строке.

## E2. Канал, невидимый компилятору: литеральные ключи субъектов

119 вхождений `declaration:callable:` / `declaration:class:` в 32 тестовых файлах,
2 страницах сайта (EN+RU `usage/output-formats`) и 3 внутренних документах.
Полный список — `git grep -ln 'declaration:\(callable\|class\):' tests website docs`.

К ним добавляется форма `declaration:func:` — 5 вхождений в `tests/`, пропущенных
первой редакцией: обход шёл по трём префиксам, а их четыре.

**Чем получено:** `grep` по четырём префиксам канонической формы.
**Чего не видит:** ключи, собираемые в тестах конкатенацией или `sprintf`, и ключи
внутри JSON-фикстур бейзлайна, где префикс тот же, но строка собрана писателем.

## E3. Артефакты формата на диске

`tests/Analysis/Policy/Baseline/Unit/{BaselineLoaderTest,BaselineWriterTest,
BaselineMigratorTest,V5BaselineReaderTest}.php`,
`tests/Infrastructure/Console/Functional/Command/CheckCommandConfigErrorExitCodeTest.php`,
корневой `qmx-baseline.json` (+ `.lock`).

Первая редакция искала только JSON-форму и пропустила PHP-массивную
(`'version' => 11`) — ещё 7 файлов, среди них
`tests/Reporting/FindingProjection/Unit/{ConfigurationErrorProjection,FindingProjector}Test.php`
и `tests/Infrastructure/Console/Unit/ViolationFilterOrchestrator*Test.php`.

**Чем получено:** `grep -rln '"version": *1[01]' tests docs website` **и**
`grep -rln "'version' *=> *1[01]" tests`.
**Чего не видит:** фикстуры, где версия подставляется переменной, и файлы
бейзлайна, создаваемые тестами на лету.

## E4. Производители occurrence-ключа и места его публикации

Производители (8): `SecurityPatternFinding:55`, `HardcodedCredentialsRule:116`,
`SensitiveParameterRule:116`, `CircularDependencyRule:117`, `CodeSmellFinding:57`,
`IdenticalSubExpressionRule:114`, `CodeDuplicationRule:144`,
`LayerViolationFinding:92` — все через единственную фабрику
`OccurrenceKey::semantic()`.

Публикация за пределами бейзлайна — шире, чем в первой редакции. Прямая:
`JsonViolationSection.php:84` и `:154`, `HtmlViolationPartitioner.php:103`.
**Косвенная, пропущенная первой редакцией:** через
`Violation::getFingerprint()` → `GitLabCodeQualityFormatter::generateFingerprint()`
(md5, отслеживание находок между MR) и `SarifFormatter`
(`partialFingerprints.primaryLocationLineHash`, идентичность алерта GitHub code
scanning). Докблок `getFingerprint()` при этом утверждает «Not used in production
code» — утверждение неверно и правится в P1.

**Чем получено:** `grep -rn "OccurrenceKey::semantic\|occurrenceKey" src`.
**Чего не видит:** потребителей JSON-вывода за пределами репозитория — их
существование не проверяемо кодом, поэтому укорочение объявляется ломающим
изменением вывода, а не внутренней деталью.

## E5. Каналы, объявленные статически

57 штук, `tests/Analysis/Finding/Fixtures/Channels/declared.txt`: 44 в форме
`code == ruleName`, 13 в форме `ruleName + "." + <level>`, 0 в форме, где код
не связан с именем правила. Суффиксов шесть: `callable, class, namespace,
param, property, return`.

Соответствие суффикса виду субъекта в текущем ратчете: 109 `callable/callable`,
14 `class/class`, 4 `ns/namespace`, и 2 исключения — `design.type-coverage.param`
и `.property` на субъекте-классе.

**Чем получено:** разбор трекаемой фикстуры + разбор `qmx-baseline.json`.
**Чего не видит:** семейство `computed.*` / `health.*`, у которого нет
фиксированного набора и которое объявляется в рантайме; в ратчете оно
представлено `computed.health#health.cohesion` и `#health.typing`.

## E6. Выводимость пути из FQN в текущем ратчете

199 субъектов: 182 объявления, 17 неймспейсов, 1 проектный. Из 182 объявлений
**181 выводимо** по карте `Qualimetrix\ → src/`; невыводимо одно:

```
declaration:class:Qualimetrix\Analysis\Evidence\Coupling\ClassRfcData@src/Analysis/Evidence/Coupling/RfcVisitor.php
```

— второй класс в файле другого класса. Это подтверждает необходимость
литерального отката на стороне писателя, а не подтверждает его редкость: один
контрпример нашёлся в собственном репозитории.

**Чем получено:** разбор `qmx-baseline.json` скриптом.
**Чего не видит:** чужие проекты — classmap-автолоад, несколько классов в файле,
имя файла, не совпадающее с именем класса; и **глобальные функции**, у которых
канонический вид `func::name` класса не содержит, поэтому PSR-4 не выводит путь
даже в принципе (`SymbolPath::buildFunctionCanonical()`). В текущем ратчете таких
записей нет, что и скрыло случай от первой редакции.

**Статус:** перечисление сохранено для будущего возврата к вопросу; выводимый путь
снят из плана решением владельца.
