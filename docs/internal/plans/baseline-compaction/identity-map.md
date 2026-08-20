# Карта идентичности объявления в измерительном слое

Снято по коду в рабочем дереве на `257abf1a` (ветка `claude/baseline-identity-plan`).
Материал разведки к разделу P2 плана `PLAN-IDENTITY.md`. Только факты с
якорями `файл:строка`; выводов и предложений по дизайну здесь нет.

## 1. Счётчики порядка и коллизий

Четыре счётчика, все внутри `Analysis\Evidence\Measurement\Visitor`. Три из
четырёх группируют по ключу, включающему `startFilePos`; один — глобальный
счётчик без группировки вовсе.

| #   | Место                                                                                    | Группа (ключ)                                                                                                                                                                                 | Что нумерует                                                                                                                                                                                                          |
| --- | ---------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `VisitorFileEntryScope::$callableTraversalOrdinals`, объявление :24, запись :138-139     | `logicalFqn . '@' . startFilePos` (:137, `$base`)                                                                                                                                             | номер обхода вызываемого внутри одной логической идентичности на одной позиции; входит в `VisitorCallableScope::$traversalKey` (:147)                                                                                 |
| 2   | `VisitorFileEntryScope::$groupCounts`, объявление :21, инкремент в `register()` :230-238 | для класса: `implode("\0", ['class', namespace, class, startFilePos])` (:82); для callable-уровня file-entry-субъекта: `implode("\0", [kind, namespace, class, member, startFilePos])` (:219) | `collisionOrdinal`, добавляемый в MetricSubjectCodec-компоненты только когда `groupCounts[group] > 1` (:203-205)                                                                                                      |
| 3   | `VisitorCallableMetadata::collisionOrdinals()` :51-67                                    | `implode("\0", [namespace, class, member, startFilePos, kind])` (:55)                                                                                                                         | `?int $ordinal`, передаваемый в `DeclarationPath` через `create()` (:36); отдельный от счётчика №1 — пересчитывается заново из `array<string, VisitorCallableScope> $scopes`, ключ которого — `traversalKey` (см. §5) |
| 4   | `VisitorFileEntryScope::$closureCounter`, объявление :43, инкремент :126                 | нет группы — один монотонный счётчик на файл                                                                                                                                                  | синтетическое имя члена `'{closure#' . N . '}'`, вставляемое в `$member` до всякой группировки; входит потом в ключи счётчиков №1-3                                                                                   |

Счётчик №3 вызывается семью визиторами callable-уровня, каждый со своим
массивом `$this->scopes`, ключ которого — `traversalKey` (счётчик №1), а не
сырой FQN: `CyclomaticComplexityVisitor.php:91`, `NpathComplexityVisitor.php:128`,
`CognitiveComplexityVisitor.php:131`, `HalsteadVisitor.php:111`,
`UnreachableCodeVisitor.php:80`, `ParameterCountVisitor.php:94`,
`MethodStatementCountVisitor.php:61`. Проверено: `CyclomaticComplexityVisitor::startMethod()`
(:124-131) кладёт scope под ключом `$scope->traversalKey`, то есть счётчик №3
работает поверх результата счётчика №1, а не вместо него.

**Чем получено:** `grep -rn "Ordinal\|ordinal\|Counter\|counter\|collision\|Collision" src/Analysis/Evidence/Measurement/Visitor src/Core/Symbol`, затем чтение полного текста `VisitorFileEntryScope.php` и `VisitorCallableMetadata.php`, затем `grep -rn "callableCollisionOrdinals\|createCallableWithMetrics" src`.
**Чего не видит:** счётчики, реализованные без слов «ordinal/counter/collision» в имени (проверено дополнительным поиском по `\$fqn\] = new` и по `getStartFilePos` — новых не нашлось, см. §2-3); счётчики в тестовых фикстурах.

## 2. Визиторы, ключующие карты класса по FQN

Восемь визиторов класс-уровня хранят карту `array<string, ...>`, ключ которой
строит приватный метод `buildClassFqn()`/`buildFqn()` — конкатенация
`namespace . '\\' . className`, без позиции. Второе объявление того же FQN в
одном файле перезаписывает первую запись карты **до** всякого присвоения
идентичности (до `MetricSubjectCodec`/`DeclarationPath`).

| #   | Место                                                                                       | Метод построения ключа                                                            |
| --- | ------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| 1   | `LcomVisitor.php:83,111,137` (`$this->classData[$fqn]`)                                     | `ClassVisitorStackTrait::buildClassFqn()` :63-69                                  |
| 2   | `LocVisitor.php:54` (`$this->classRanges[$fqn]`)                                            | собственный `buildClassFqn()` :91-97                                              |
| 3   | `MethodCountVisitor.php:163` (`$this->classMetrics[$fqn]`)                                  | собственный `buildClassFqn()` :329-335                                            |
| 4   | `TypeCoverageVisitor.php:150-151` (`$this->classTypeInfo[$fqn]`, `$this->classInfos[$fqn]`) | собственный `buildClassFqn()` :280-286                                            |
| 5   | `RfcVisitor.php:132` (`$this->classes[$fqn]`)                                               | собственный `buildClassFqn()` :374-380                                            |
| 6   | `UnusedPrivateVisitor.php:238` (`$this->classData[$fqn]`)                                   | `buildFqn()` (вызов :235), та же схема — namespace + `\\` + имя, без позиции      |
| 7   | `TccLccVisitor.php:96` (`$this->classData[$fqn]`)                                           | `ClassVisitorStackTrait::buildClassFqn()` (та же трейта, что и LcomVisitor)       |
| 8   | `InheritanceDepthVisitor.php:118` (`$this->classInfo[$classFqn]`)                           | инлайн `$this->currentNamespace . '\\' . $className` в `enterNode()`, без позиции |

**Не входит в перечень:** `HalsteadVisitor.php:164,183` (`$this->metrics[$fqn]`,
`$this->scopes[$fqn]`) — здесь `$fqn` есть `$scope->traversalKey`
(позиция + ordinal уже в ключе), это callable-уровень, а не класс-уровень;
дубликат класса ему не грозит тем же механизмом (проверено фикстурой, §6).
`DependencyVisitor.php` не строит карту по FQN вовсе: у него один
`$this->currentClass` — скаляр текущего контекста обхода, перезаписываемый на
`enterNamedClassLike()`/`leaveClassLike()`, а не персистентная карта.

**Чем получено:** `grep -rn "\$fqn\] = new\|classFqn\] = new\|\$className\] = new" src` — нашёл ровно 1 прямое совпадение (InheritanceDepthVisitor); остальные семь найдены через `find` по именам `*Visitor.php` для восьми имён из задания и чтение каждого файла на предмет `private array \$class...` + `buildClassFqn`.
**Чего не видит:** карты, ключуемые сгенерированным именем переменной без слова
«class» в нём, и карты в визиторах вне восьми проверенных имён (Security,
CodeSmell помимо UnusedPrivate, Duplication) — не проверялись целиком, потому
что они не строят класс-уровневый `ClassMetricsProviderInterface`-транспорт
(см. §5) и не участвуют в `MetricSubjectCodec::encodeClass`.

## 3. Позиция в файле как часть ЛОГИЧЕСКОГО имени

Ровно два места; третьего не найдено при целевом поиске.

| #   | Место                           | Форма                                                                                                                                                                                |
| --- | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | `VisitorFileEntryScope.php:77`  | `$class = $name ?? '{anonymous@' . $position . '}'` — байтовое смещение узла напрямую в строке имени анонимного класса                                                               |
| 2   | `VisitorFileEntryScope.php:126` | `$member = '{closure#' . ++$this->closureCounter . '}'` — не байтовое смещение, а счётчик обхода, но тоже позиционно-зависимая величина, вошедшая в `$member`, а не в отдельное поле |

Оба значения дальше участвуют в `$logicalFqn` (:133-136) и, следовательно, во
всех четырёх счётчиках §1 и во всех восьми картах §2 через `buildClassFqn`/
групповые ключи, где встречается `class`/`member`.

**Смежное, но не то же самое:** `RfcVisitor.php:370` —
`return '*@' . spl_object_id($expr);` — встраивает не позицию в файле, а
рантайм-идентификатор объекта AST-узла в строку-ключ **внутри одного вызова
визитора**, для дедупликации receiver-выражений при подсчёте RFC (не
`DeclarationPath`, не `MetricSubject`, не переживает пределы одного прохода).
Не входит в перечень: критерий — «позиция входит в логическое имя
объявления», а `spl_object_id` — не позиция и не логическое имя объявления.

**Чем получено:** `grep -rn "'{anonymous@\|{closure#\|anonymous@' \.\|'@' \. \$position\|'@' \. \$startFilePos\|'@' \. \$node->getStartFilePos" src`, затем сплошной `grep -rn "getStartFilePos" src` (23 вхождения) с ручной проверкой каждого — все прочие 21 сохраняют `startFilePos` как отдельное поле VO (`startFilePos: $node->getStartFilePos()`), а не конкатенируют его в строку имени.
**Чего не видит:** конкатенацию, построенную не через буквальный `.` в той же
строке (например, через `sprintf` с промежуточной переменной) — не встретилась
ни разу при чтении всех 23 вхождений, но грамматика поиска этого не
гарантирует.

## 4. Производители и читатели `DeclarationPath::$startFilePos`

### 4.1 Производители (конструктор `new DeclarationPath(...)` и codec)

Тот же список, что в `enumeration-identity.md` §E1 (15 мест), подтверждён
повторным чтением каждого файла в этой сессии:

`MetricSubjectCodec.php:133` (декодирование, включая путь `decodeEntry()` →
`decode()`, минующий все прочие фабрики), `VisitorCallableMetadata.php:27,36`,
`VisitorFileEntryScope.php:81,217,218` (через `encodeClass`/`encodeMethod`/
`encodeFunction`), `InheritanceDepthCollector.php:103`,
`TypeCoverageVisitor.php:129`, `LcomCollector.php:101`,
`TccLccCollector.php:137`, `UnusedPrivateCollector.php:91`,
`RfcCollector.php:111`, `LocCollector.php:137`, `MethodCountCollector.php:205`,
`DependencyVisitor.php:181` (внутри `enterNamedClassLike()` :178-186).

**Дополнительно найдено в этой сессии:** `MetricSubjectCodec::encodeClass()`
(:36-42), `encodeMethod()` (:45-51), `encodeFunction()` (:54-60) сами по себе —
три места, принимающие `int $startFilePos` как параметр и упаковывающие его в
скалярный wire-массив (`['startFilePos' => $startFilePos, ...]`), который
позже разворачивается в `new DeclarationPath` только в `decode()` (:133). Это
не отдельные производители сверх пятнадцати (кодек считается одним пунктом
№1 в E1), но они — три отдельных места записи значения в промежуточную форму,
до конструктора.

### 4.2 Читатели поля `->startFilePos`

| Место                                         | Что делает                                                                                                                                                                                                                                                                                                                                      |
| --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `DeclarationControlBindings.php:71`           | `$byStart[$callable->declarationPath->startFilePos][] = $subject` — индекс callable-субъектов по позиции                                                                                                                                                                                                                                        |
| `DeclarationControlBindings.php:73`           | то же значение кладётся в `'start' => ...` элемента `$callableStarts`                                                                                                                                                                                                                                                                           |
| `DeclarationControlBindings.php:82`           | `$byStart[$declaration->startFilePos][] = $classMetric['subject']` — **пропущено в прошлой редакции плана**, которая называла только строки 71,73,110,114; это пятое чтение, для класс-уровневых субъектов                                                                                                                                      |
| `DeclarationControlBindings.php:110`          | `'start' => $callable->declarationPath->startFilePos` во втором проходе (`assertCompatibleSourceMetadata()`)                                                                                                                                                                                                                                    |
| `DeclarationControlBindings.php:114`          | `(...)->declarationPath()->startFilePos` для класс-уровневого субъекта в том же проходе                                                                                                                                                                                                                                                         |
| `DeclarationPath.php:39-44` (`toCanonical()`) | встраивает `$this->startFilePos` в канонический ключ строки (`'declaration:%s@%s:%d'`) — это тот самый ключ, что расходится в `MetricSubject::toCanonical()`, `BaselineEntry`, occurrence-based фингерпринты и весь вывод форматтеров                                                                                                           |
| 4 файла тестов                                | `tests/Analysis/Evidence/Measurement/Unit/FileMeasurement/DerivedMetricExtractorTest.php`, `tests/Analysis/Evidence/Complexity/Unit/CyclomaticComplexityVisitorTest.php`, `tests/Analysis/Evidence/CodeSmell/Integration/LongParameterListVoPropagationTest.php`, `tests/Analysis/Run/Unit/Collection/FileProcessorTest.php` — фикстуры/ассерты |

`DeclarationPath::toCanonical()` — не «читатель» в терминах прошлой редакции
(там перечислялись только вызовы `->startFilePos` из production-кода за
пределами `Core/Symbol`), но это тот самый метод, что превращает поле в
байтовое смещение внутри строкового ключа субъекта — корневой механизм
дефекта, описанного во введении плана.

**Чем получено:** `grep -rn "startFilePos" src --include="*.php"` (полный
список, 60+ строк), отфильтрованный вручную на «объявление параметра/поля» и
«использование значения»; отдельно `grep -rln "\->startFilePos" tests --include="*.php"` для тестовых читателей.
**Чего не видит:** чтение через промежуточную переменную без `->startFilePos`
в той же строке (например, `$pos = $x->startFilePos; ...$pos...` в другой
строке) — не нашлось ни одного случая при чтении контекста вокруг каждого
совпадения, но поиск по строке этого не гарантирует.

## 5. Транспорт класс-метрик и callable-метрик от визитора до правила

### 5.1 Callable-метрики: VO переживает границу процесса как есть

`FileProcessor::extractCallableMetrics()` (:117-136) собирает
`list<CallableWithMetrics>` из всех `CallableMetricsProviderInterface`-коллекторов
и **не разворачивает** VO в массив: при коллизии двух коллекторов на одном
ключе (`$callable->declarationPath->toCanonical()`, :128) вызывает
`mergeCallableMetrics()` (:138-162), которая строит **новый**
`CallableWithMetrics`, но тот же класс. Итоговый список кладётся как есть в
`SuccessfulFileProcessing::$callableMetrics` (`SuccessfulFileProcessing.php:20,30`),
докблок которого прямо называет всю структуру «Serializable facts» (:16).
`CollectionOrchestrator::registerResult()` читает этот список без
пересборки (:173-175, :218) и передаёт его в `MetricRepositoryInterface::addCallable()`
(вызов :174) и в `DerivedMetricExtractor::extract()` (:218) как тот же объект.

### 5.2 Класс-метрики: VO выбрасывается, данные едут отдельно, VO собирается заново

`FileProcessor::extractClassMetrics()` (:167-189) итерирует
`ClassMetricsProviderInterface::getClassesWithMetrics()`, получает
`ClassWithMetrics` (конструктор `ClassWithMetrics.php:18-24`, поле
`MetricSubject $subject`, вычисляемое в конструкторе из `$declarationPath`
:23), но **не сохраняет объект**: строит плоский массив
`['subject' => $classWithMetrics->subject, 'metrics' => ..., 'line' => ...]`
(:178-183), ключуя мёрж по `$classWithMetrics->subject->toCanonical()` (:174).
Это и есть форма, в которой класс-метрики едут в
`SuccessfulFileProcessing::$classMetrics` (docblock-тип
`array<string, array{subject: MetricSubject, metrics: MetricBag, line: int}>`,
`SuccessfulFileProcessing.php:21`) — сам класс `ClassWithMetrics` границу
процесса не пересекает, пересекает только тройка `(subject, metrics, line)`.

На принимающей стороне `CollectionOrchestrator::registerResult()` (:207-217)
**реконструирует** `ClassWithMetrics` из этой тройки — новый объект, новый
`declarationPath`, взятый обратно из `$classData['subject']->declarationPath()`
(:209) — исключительно для передачи в `DerivedMetricExtractor::extract()`
(:218). Прямые класс-метрики (`$repository->addSubject(...)`, :179-184)
работают с массивом-тройкой, не с объектом.

### 5.3 IPC при `--workers > 0`

`FileProcessingTask::run()` (`FileProcessingTask.php:66-87`) возвращает
`FileProcessingResult` целиком из метода `Task::run()` (сигнатура
`@implements Task<FileProcessingResult, mixed, mixed>`, :31) — весь граф
объектов (`SuccessfulFileProcessing`, вложенные `CallableWithMetrics`,
`DeclarationPath`, `SymbolPath`, `MetricSubject`, `MetricBag`) пересекает
границу воркера через собственный IPC-механизм `amphp/parallel`
(`Amp\Parallel\Worker\Task`/`Amp\Sync\Channel`, импорты :7-9), который
сериализует возвращаемое значение целиком средствами самого `amphp/parallel`.

Собственный `Infrastructure\Serializer\{SerializerSelector,IgbinarySerializer,PhpSerializer}`
этого пути не касается: единственный потребитель этого выбора —
`Infrastructure\Cache\FileCache.php` (AST-кэш), что подтверждено поиском
(`grep -rln "igbinary\|Serializer" src` — оба класса встречаются только в
`Infrastructure/Serializer/*` и `Infrastructure/Cache/FileCache.php`). Форма
класс-метрик как плоского массива (§5.2) означает, что через IPC-границу для
класс-уровневых данных едут только скаляры `MetricSubject`/`MetricBag`, а не
`ClassWithMetrics`.

**Чем получено:** чтение исходников `FileProcessor.php`, `ClassWithMetrics.php`,
`CallableWithMetrics.php`, `SuccessfulFileProcessing.php`,
`CollectionOrchestrator.php:150-219`, `FileProcessingTask.php` целиком;
`grep -rln "igbinary\|Serializer" src --include="*.php"`.
**Чего не видит:** фактическую сериализацию `amphp/parallel` изнутри (её код —
за пределами репозитория; утверждение о «PHP-нативной сериализации всего
графа» — вывод из сигнатуры `Task::run(): FileProcessingResult` и докблока
«Serializable facts», а не из чтения кода amphp/parallel).

## 6. Достижимость «двух объявлений одного FQN в одном файле» — по фикстурам

Фикстуры созданы вне репозитория, в scratchpad, и удалены после прогона.
Команда для класса и функции одинаковая по форме
(`--only-rule=complexity.cyclomatic` с нулевым порогом, чтобы получить по
одному finding на каждое объявление и увидеть оба ключа субъекта).

### 6.1 Класс

Фикстура (`DupClass.php`, namespace `Fixture\Identity`):

```php
if (PHP_VERSION_ID >= 80000) {
    class DupClass { public function greet(): string { return 'branch-a'; } }
} else {
    class DupClass { public function greet(): string { return 'branch-b'; } }
}
```

Команда:

```
bin/qmx check DupClass.php --format=metrics --workers=0 --no-cache --fail-on=none
```

Вывод (сокращён до релевантных полей `--format=metrics`):

```
"symbols": [
  {"type": "file", ..., "metrics": {"classCount": 2, ...}},
  {"type": "project", ..., "metrics": {"ccn.count": 2, "lcom.count": 1, "rfc.count": 1, "dit.count": 1, ...}},
  {"type": "namespace", "name": "Fixture\\Identity", ...},
  {"type": "namespace", "name": "Fixture", ...},
  {"type": "class", "name": "Fixture\\Identity\\DupClass", "metrics": {
      "methodCount": 1, "methodCountTotal": 1, "lcom": 1, "rfc": 1, "dit": 0,
      "ccn.count": 2, "cognitive.count": 2, "methodStatementCount.count": 2, "mi.count": 2, "wmc": 2, ...
  }}
]
```

**Достижимо, и достигает двух разных уровней последствий за один прогон:**
`classCount: 2` на файле (LocVisitor's file-range collector видит оба узла
класса), но ровно один `type: "class"` символ на выходе — второе объявление
не породило второй класс-уровневый субъект (`methodCount: 1`, `lcom: 1`,
`rfc: 1`, `dit: 0` — метрики только одного из двух объявлений; это прямое
следствие FQN-ключевания из §2). При этом callable-уровневые метрики,
агрегированные под этим единственным class-субъектом, видят оба метода
(`ccn.count: 2`, `methodStatementCount.count: 2`).

Форсируя находки по `complexity.cyclomatic` с порогом 0
(`--rule-opt=complexity.cyclomatic:callable.warning=0 --rule-opt=complexity.cyclomatic:callable.error=0 --all`),
получены ровно 2 violations на методе `greet`, с разными ключами субъекта,
различающимися только байтовым смещением, без `#ordinal`:

```
declaration:callable:Fixture\Identity\DupClass::greet@.../DupClass.php:126
declaration:callable:Fixture\Identity\DupClass::greet@.../DupClass.php:257
```

### 6.2 Метод

Отдельной фикстуры не потребовалось: метод `greet` в фикстуре §6.1 — это и
есть метод-уровневый случай (единственный синтаксически достижимый способ
получить два объявления одного `Class::method` в одном файле — обернуть
класс-декларацию целиком, поскольку два метода с одинаковым именем в теле
одного класса — фатальная ошибка компиляции PHP, а не случай для парсера).
Результат — см. два ключа выше: метод-уровневая идентичность **достижима и
сегодня корректно различает** оба объявления, но исключительно за счёт сырого
байтового смещения в ключе (`:126` vs `:257`), без какого-либо ordinal —
ровно то, что делает ключ хрупким к правкам выше по файлу.

### 6.3 Глобальная функция

Фикстура (`DupFunction.php`, namespace `Fixture\Identity`):

```php
if (!function_exists(__NAMESPACE__ . '\\dupFunc')) {
    function dupFunc(): int { return 1; }
} else {
    function dupFunc(): int { return 2; }
}
```

Та же команда с `--only-rule=complexity.cyclomatic` и нулевым порогом даёт 2
violations:

```
declaration:func:Fixture\Identity::dupFunc@.../DupFunction.php:119
declaration:func:Fixture\Identity::dupFunc@.../DupFunction.php:186
```

**Достижимо**, тем же механизмом и с тем же следствием, что и метод: два
разных байтовых смещения, ни одного ordinal.

### 6.4 Замыкание

Фикстура (`DupClosure.php`, namespace `Fixture\Identity`):

```php
function makeClosures(): array
{
    $a = function (): int { return 1; };
    $b = function (): int { return 2; };
    return [$a, $b];
}
```

Та же команда даёт 3 violations (функция-обёртка + два замыкания):

```
declaration:func:Fixture\Identity::makeClosures@.../DupClosure.php:62
declaration:func:Fixture\Identity::{closure#1}@.../DupClosure.php:104
declaration:func:Fixture\Identity::{closure#2}@.../DupClosure.php:157
```

**Не достигает коллизии одной логической идентичности**: `$closureCounter`
(§3, п.2) присваивает каждому замыканию отдельный синтетический `member`
(`{closure#1}`, `{closure#2}`) до всякой группировки, поэтому два замыкания в
одной области видимости никогда не делят один и тот же `logicalFqn` — случай
«два объявления одного FQN» для замыканий структурно недостижим тем же путём,
что для класса/метода/функции. Байтовое смещение в ключе присутствует и
здесь, но не как средство различения дубликата, а как обычная позиционная
часть ключа единственного объявления с этим синтетическим именем.

**Чем получено:** прогон `bin/qmx check` против всех трёх фикстур с
`--format=metrics` и с `--format=json --only-rule=complexity.cyclomatic
--rule-opt=...:callable.warning=0 --rule-opt=...:callable.error=0 --all`,
`--workers=0 --no-cache --fail-on=none`; полный JSON сохранён во временных
файлах scratchpad и удалён по завершении.
**Чего не видит:** случаи, требующие more than one файл (например, два
объявления одного класса в разных файлах classmap-автолоада — это другой
механизм, не «в одном файле», и не проверялся здесь); поведение при
`--workers>0` (фикстуры прогонялись только с `--workers=0`, IPC-путь §5.3
проверен чтением кода, не прогоном).
