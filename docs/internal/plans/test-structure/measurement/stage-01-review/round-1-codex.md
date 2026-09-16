# Находки codex (внешний CLI-ревьюер) — этап 01 test-structure

Диапазон: `9a3b2548..HEAD`. Файл `diff_files.txt` в `review_root` отсутствовал;
допущение зафиксировано ниже в разделе Coverage. Прогон `codex-eval.sh`: код
возврата `0`, ответ получен полностью, все находки ниже приведены к схеме и
перепроверены фасилитатором чтением кода / точечными read-only экспериментами
(не менявшими рабочее дерево).

### codex-01

- **reviewer**: codex
- **severity**: HIGH
- **kind**: point
- **domain**: tests
- **title**: G1 не видит непубличные методы `#[Test] itXxx`, и PHPUnit тоже их не исполняет
- **mechanism**: `TestMethodsAreReachableTest::violationsIn()` собирает только `$method->isPublic()`-методы (`$node->getMethods()` + фильтр). Обычная правка видимости метода (`public` → `protected`/`private`) оставляет имя `itXxx` и атрибут `#[Test]`, но метод исчезает и из выполнения PHPUnit (PHPUnit тоже требует public), и из корпуса самого стража — G1 о такой находке не узнаёт.
- **trigger**: воспроизводится в нормальной работе — случайная смена видимости при рефакторинге теста (например, при извлечении общего кода в базовый класс)
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestMethodsAreReachableTest.php:162-166`
- **evidence**:
  ```php
  foreach ($node->getMethods() as $method) {
      if ($method->isPublic()) {
          $this->methods[] = ['class' => $name, 'method' => $method];
      }
  }
  ```
- **verification**: confirmed
- **verification_note**: подтверждено прямым чтением исходника (строки 162-166 в файле диффа); совпадает с известным поведением PHPUnit (тест-методы обязаны быть public). Метод, ставший непубличным, не попадает ни в `$this->methods`, ни, соответственно, в диагностику стража.
- **fix_direction**: собирать все методы (включая непубличные) и отдельно отказывать, если непубличный метод назван `itXxx` или несёт `#[Test]` — оба случая сигнализируют потерянный тест.

### codex-02

- **reviewer**: codex
- **severity**: HIGH
- **kind**: point
- **domain**: tests
- **title**: G2 моделирует только `--exclude-group`, любой другой PHPUnit-селектор в `COMMON_ARGUMENTS` тихо сузит и reachable-, и executed-корпус одинаково
- **mechanism**: `aggregate()['reachableArguments']` вычисляется как `COMMON_ARGUMENTS` минус аргументы `--exclude-group=*`. Любой другой отбирающий флаг (`--filter=`, `--exclude-filter=`, `--group=`), добавленный в `COMMON_ARGUMENTS` в `scripts/phpunit-aggregate.py`, останется и в «reachable», и в «executed» листингах одновременно — разница между ними («что стало недостижимым») останется пустой, хотя фактический набор исполняемых тестов сузился.
- **trigger**: воспроизводится в нормальной работе — обычное добавление, например, `--exclude-filter=...` в общий список аргументов ради временного исключения одного метода
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:310-323`
- **evidence**:
  ```php
  'reachableArguments' => array_values(array_filter(
      $arguments,
      static fn(string $argument): bool => !str_starts_with($argument, '--exclude-group='),
  )),
  ```
- **verification**: confirmed
- **verification_note**: прочитан весь `aggregate()` и три теста `itAccountsForEveryGroupTheAggregateExcludes` / `itNamesEveryCaseTheAggregateExcludesFromCheck` / `itCarriesNoStaleSilentExclusionDeclaration` — все три оперируют исключительно множеством `--exclude-group=*`; никакого другого класса аргументов guard не распознаёт и не отказывает по нему.
- **fix_direction**: либо ограничить допустимые записи `COMMON_ARGUMENTS` заведомо неселектирующими флагами (документированным контрактом), либо явно моделировать и вычитать из reachable-корпуса каждый поддерживаемый тип PHPUnit-селектора, а не только `--exclude-group`.

### codex-03

- **reviewer**: codex
- **severity**: HIGH
- **kind**: point
- **domain**: tests
- **title**: `orphansIn()` проверяет достижимость файла, а не каждого объявленного в нём класса
- **mechanism**: `orphansIn()` считает файл неосиротевшим, если `array_intersect($declared, $reachable) !== []` — то есть достаточно, чтобы ХОТЯ БЫ ОДИН из объявленных в файле классов был достижим. Если файл объявляет два `TestCase` (например, второй добавлен по ошибке или является мёртвым остатком рефакторинга), а PHPUnit по имени файла грузит только первый — второй класс никогда не исполняется, и G2 этого не видит: пересечение с первым классом уже непустое.
- **trigger**: воспроизводится в нормальной работе — случайное или намеренное добавление второго тестового класса в один файл (нарушение, которое ничем в стиле проекта явно не запрещено этим guard'ом)
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:210-221`
- **evidence**:
  ```php
  foreach ($declarations as $path => $declared) {
      if (array_intersect($declared, $reachable) === []) {
          $orphans[] = $path . ' declares ' . (
              $declared === [] ? 'no class at all' : implode(', ', $declared)
          );
      }
  }
  ```
- **verification**: confirmed
- **verification_note**: логика прочитана целиком; `TestTree::declarationsIn()` действительно возвращает список ВСЕХ class-like деклараций файла (`parseDeclarations` собирает `$collector->classes` по каждому `ClassLike`-узлу), так что множественность деклараций на файл поддерживается моделью данных, но не проверяется поэлементно в `orphansIn()`.
- **fix_direction**: сравнивать с reachable-множеством каждый объявленный `TestCase`-класс отдельно, а не файл целиком; нетестовые вспомогательные декларации (трейты, интерфейсы, анонимные классы) исключать из сравнения по типу узла, а не полагаться на пересечение множеств.

### codex-04

- **reviewer**: codex
- **severity**: HIGH
- **kind**: point
- **domain**: reliability
- **title**: отсутствующий на диске PSR-4 dev-root тихо выпадает из корпуса всех трёх стражей
- **mechanism**: `TestTree::autoloadDevRoots()` включает в карту только те записи `autoload-dev.psr-4`, для которых `is_dir()` истинно (строка 93). Если новый или переименованный dev-root объявлен в `composer.json`, но каталог ещё не создан (или создан с опечаткой в пути) — root молча исчезает из `roots()`/`testFiles()`, и ни один из трёх guard'ов не видит ни одного файла из него; нижние пороги (`assertGreaterThan(500, ...)`, наличие ключей `tests`/`governance`) этого не ловят, если общий счёт остальных корней всё ещё выше порога.
- **trigger**: воспроизводится в нормальной работе — именно тот сценарий, который проделал сам этот этап (регистрация нового dev-root `governance/`): при рассинхронизации порядка «объявить в composer.json» / «создать каталог» в разных коммитах или PR guard остаётся зелёным всё это время.
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestTree.php:86-105`
- **evidence**:
  ```php
  $root = rtrim($directory, '/');
  if (is_dir(self::absolute($root))) {
      $roots[rtrim($prefix, '\\')] = $root;
  }
  ```
- **verification**: confirmed
- **verification_note**: прочитан код `autoloadDevRoots()` целиком, включая `if ($roots === []) { throw ... }` — эта проверка ловит только полное отсутствие ВСЕХ root'ов, а не одного из нескольких. Отдельно проверена и ОТКЛОНЕНА гипотеза codex про `RecursiveDirectoryIterator` и символические ссылки: эмпирическая проверка (`php -r` с `RecursiveDirectoryIterator::SKIP_DOTS` без `FOLLOW_SYMLINKS`, каталог-симлинк) на этой же машине показала, что итератор ОБХОДИТ симлинк-каталог и находит файлы внутри — `FOLLOW_SYMLINKS` в PHP документирован как относящийся только к Windows/junction-специфике, на POSIX не требуется. Эта часть исходной находки codex — refuted моей проверкой; сохраняю только подтверждённую часть про отсутствующий на диске root.
- **fix_direction**: после построения `roots()` явно провалидировать, что каждая запись `autoload-dev.psr-4` из `composer.json` либо присутствует на диске, либо намеренно отфильтрована с явной причиной (а не молча пропущена через `is_dir()`).

### codex-05

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: point
- **domain**: reliability
- **title**: нежадная регулярка `tupleIn()` тихо обрезает Python-кортеж на строке-комментарии, содержащей `)`
- **mechanism**: `tupleIn()` ищет `NAME = \((.*?)\)$` с флагами `ms` (нежадный `.*?`, `$` — конец строки при `/m`). Валидный для Python однострочный комментарий вида `    # )` внутри кортежа (например, временно закомментированная строка) даёт совпадение `)$` раньше настоящей закрывающей скобки — регулярка возвращает укороченное, но НЕПУСТОЕ содержимое кортежа. Guard не бросает `LogicException` (тело непустое), просто читает меньше элементов, чем есть на самом деле в Python.
- **trigger**: воспроизводится на валидной, но специфической правке `scripts/phpunit-aggregate.py` — построчный Python-комментарий, заканчивающийся на `)`, внутри `SUITES` или `COMMON_ARGUMENTS`
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:349-359`
- **evidence**:
  ```php
  if (preg_match('/^' . preg_quote($name, '/') . ' = \((.*?)\)$/ms', $source, $matches) !== 1) {
      throw new LogicException(...);
  }
  ```
- **verification**: confirmed
- **verification_note**: эмпирически воспроизведено (`php -r` вне рабочего дерева, только чтение/временный скрипт, ничего в проекте не менялось): подан текст
  ```
  COMMON_ARGUMENTS = (
      "--no-coverage",
      # )
      "--exclude-group=slow",
  )
  ```
  — `preg_match` вернул совпадение, а `preg_match_all` на группе извлёк только `["--no-coverage"]`, потеряв `--exclude-group=slow` без единого исключения или предупреждения.
- **fix_direction**: не извлекать структуру Python регулярным выражением; либо звать интерпретатор Python (`python3 -c 'import ast; ...'`) для честного разбора кортежа, либо перенести единый источник правды в формат, который PHP умеет разбирать нативно (например, JSON/YAML, генерируемый из Python при сборке).

### codex-06

- **reviewer**: codex
- **severity**: LOW
- **kind**: judgement
- **domain**: tests
- **title**: одиннадцатый адрес суite-карты — локальная IDE-конфигурация PHPUnit, не покрытая регистрацией нового корня
- **mechanism**: `.idea/phpunit.xml` перечисляет как единственную тестовую директорию `$PROJECT_DIR$/tests`; запуск «всех тестов» через эту локальную конфигурацию PhpStorm не заденет ни один governance-guard, поскольку каталог `governance/` в этот список не добавлен.
- **trigger**: недостижим как регрессия CI/`composer check` — файл `.idea/phpunit.xml` в `.gitignore` (не в дифф-скоупе, не воспроизводится в общей для команды конфигурации); влияет только на локальный ручной запуск конкретного разработчика через IDE
- **in_scope**: нет (файл `.idea/phpunit.xml` git-ignored и не входит в диапазон `9a3b2548..HEAD`)
- **anchor**: свободная форма — «одиннадцатый адрес» из вопроса №6 задания
- **evidence**: `.idea/phpunit.xml:3-8` перечисляет только `$PROJECT_DIR$/tests`; строка 5 `.gitignore` подтверждает, что `.idea/` не версионируется
- **verification**: unverifiable
- **verification_note**: факт содержимого файла подтверждён чтением, но это личная/невоспроизводимая конфигурация конкретной машины — не общекомандный артефакт и не часть диффа, поэтому нельзя классифицировать как `confirmed` дефект процесса; ставлю `unverifiable` по правилу 2 схемы, а не `refuted`, так как сам факт (локальный запуск не покроет Governance) верен.
- **fix_direction**: не относится к продуктовому или CI коду; максимум — заметка в README/AGENTS.md, что канонический запуск теста — через `composer check`/`scripts/phpunit-aggregate.py`, а не через произвольную IDE-конфигурацию.

### codex-07

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: reliability
- **title**: `NamespacePathAllowList::derive()` пишет tracked-файл напрямую, в обход правила «Atomic Cache Writes» (AGENTS.md, Critical Rule №5)
- **mechanism**: `derive()` вызывает `file_put_contents(TestTree::absolute(self::PATH), self::render($violations))` напрямую в отслеживаемый файл и проверяет только строгое `=== false`. `file_put_contents` может завершиться частичной записью (переполнен диск, прерывание процесса) — это не `false`, а положительное число меньше длины буфера — и код в этом случае трактует запись как успех (`echo` + `return self::WROTE`), хотя allow-list на диске уже повреждён/обрублен.
- **trigger**: воспроизводится только на нештатном I/O-сбое (диск переполнен, `kill -9` посреди записи) — не в обычной работе
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/NamespacePathAllowList.php:199-208`
- **evidence**:
  ```php
  if (file_put_contents(TestTree::absolute(self::PATH), self::render($violations)) === false) {
      fwrite(\STDERR, 'Cannot write ' . self::PATH . "\n");
      return self::MEASUREMENT_FAILED;
  }
  ```
- **verification**: confirmed
- **verification_note**: код прочитан целиком; сравнён напрямую с образцом «Correct: atomic rename» из AGENTS.md, раздел «Critical Rules», пункт 5 — здесь нет ни временного файла, ни `rename()`. Расхождение с задокументированным в проекте архитектурным правилом подтверждено буквальным чтением обоих текстов.
- **fix_direction**: писать во временный файл рядом с целевым и атомарно переименовывать поверх него — той же схемой, что уже принята в проекте для кэша (см. правило 5 AGENTS.md), с проверкой полной длины записанных байт перед `rename()`.
- **severity_note**: по правилу 1 схемы триггер «недостижим в нормальной работе» ограничивает потолок; понижено с предложенного codex до фактически заявленного MEDIUM (contract-нарушение без штатного триггера).

### codex-08

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: пустой `<testsuite>` в `phpunit.xml.dist` невидим ни для одного из пяти представлений suite-карты
- **mechanism**: `assertSuiteClassifierAgreesWithPhpunit()` в генераторе инвентаря итерируется только по `<directory>`-элементам внутри `<testsuite>`; suite без единой директории не порождает ни одной проверяемой записи ни в прямом, ни в обратном сравнении. Python-агрегатор (`scripts/phpunit-aggregate.py::assert_partition`) сверяет множества ID тестов, а не имена suite из XML — пустой suite не добавляет ID и не портит партицию. G2 (`TestFilesAreExecutedTest::aggregate()`) вообще не читает `phpunit.xml.dist`, только `scripts/phpunit-aggregate.py`. В результате новый пустой `<testsuite name="Reserved"/>`, объявленный в XML, но не добавленный в `SUITES` (Python) и `testSuitePrefixTable()` (генератор), не даёт красным ни один из механизмов.
- **trigger**: воспроизводится на валидной, но малораспространённой правке — предварительное объявление suite до создания первой директории в нём (например, задел на будущий этап плана)
- **in_scope**: да
- **anchor**: `scripts/generate-modular-architecture-test-inventory.php:1113-1126`, `scripts/phpunit-aggregate.py:203-209`, `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:310-317`
- **evidence**:
  ```php
  foreach ($document->testsuites->testsuite as $suite) {
      $name = (string) $suite['name'];
      foreach ($suite->directory as $directory) {
          // пустой $suite->directory просто не даёт итераций
  ```
- **verification**: confirmed
- **verification_note**: прочитан код всех трёх упомянутых мест; подтверждено, что цикл `foreach ($suite->directory as $directory)` для suite без дочерних `<directory>` не выполняется ни разу, и что PHPUnit XSD допускает `testSuiteType` без дочерних элементов (`choice minOccurs="0"`), т.е. XML с пустым `<testsuite>` синтаксически валиден.
- **fix_direction**: добавить отдельную проверку точного равенства множества имён `<testsuite>` в XML и кортежа `SUITES`, независимо от наличия директорий внутри каждого suite.

### codex-09

- **reviewer**: codex
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: `idsAcrossSuites()` обрезает суффикс числового датасета (`#0`), но не именованного (`"name"`)
- **mechanism**: PHPUnit в текстовом листинге `--list-tests` печатает числовой data-set как `Method#0`, а именованный — как `Method"название"` (без `#`). Код `idsAcrossSuites()` ищет только `#` (`strpos($identifier, '#')`) и обрезает по нему; для именованного датасета `#` не встречается, и полный `Method"название"` остаётся отдельным «идентификатором метода» в сравнениях `reachableIds()`/`excludedIds()`, где единицей объявлен метод, а не конкретный кейс.
- **trigger**: воспроизводится в нормальной работе — присвоение `#[Group('live-freshness')]`/аналогичной группы методу, чей `#[DataProvider]` использует именованные ключи массива
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:270-273`
- **evidence**:
  ```php
  $position = strpos($identifier, '#');
  $ids[$position === false ? $identifier : substr($identifier, 0, $position)] = true;
  ```
- **verification**: confirmed
- **verification_note**: подтверждено на реальных сгенерированных данных этого же диффа — `docs/internal/generated/modular-architecture/test-phpunit-discovery.txt:24-43` содержит записи вида `...YamlNormalizationCharacterizationTest::itNormalizesEachRootKeyExactlyAsTheCurrentSnapshot"architecture.allow subtree preserves snake_case verbatim"` — именованный датасет без `#`, что подтверждает описанный формат листинга и, соответственно, механизм находки.
- **fix_direction**: нормализовать оба задокументированных PHPUnit-формата датасета (числовой `#N` и именованный `"name"`) к общему `Class::method` перед сравнением множеств, а не только один из них.

## Coverage

Проверено чтением кода и (где отмечено) точечными read-only экспериментами вне
рабочего дерева проекта:
- Вопрос 1 (полностью пустой прогон стражей) — не находка: у всех трёх guard'ов есть нижние пороги (`assertGreaterThan`) и самопроверки на синтетических данных (`itRefusesEach...OnTheSetsItIsGiven` / `...OnSourceItIsGiven` / `...OnTheDeclarationsItIsGiven`), которые не позволяют guard'у остаться зелёным при полностью пустом или нечитаемом входе — эти механизмы прочитаны и признаны рабочими.
- Вопрос 2 (тихое сужение `TestTree`) — закрыто находкой codex-04 (отсутствующий root); гипотеза про символические ссылки на директории проверена эмпирически и ОТКЛОНЕНА (см. verification_note codex-04) — `RecursiveDirectoryIterator` без `FOLLOW_SYMLINKS` на POSIX всё равно обходит симлинк-каталог.
- Вопрос 3 (регулярка `tupleIn()`) — закрыто находкой codex-05, воспроизведено эмпирически.
- Вопрос 4 (семантика листингов) — три случая разобраны: exclude-group-исключённый класс остаётся в reachable намеренно (это НЕ дефект, подтверждено кодом трёх тестов `itAccountsForEveryGroupTheAggregateExcludes` и т.д.); числовой `#dataset` обрезается верно; именованный dataset — не обрезается верно (codex-09); несколько классов в одном файле — не проверяется поэлементно (codex-03).
- Вопрос 5 (G3/allow-list устаревание) — переименование/удаление/смена namespace на другой неверный: во всех трёх случаях `array_diff_assoc()` в обе стороны (`itFindsNoTestFileWhoseNamespaceDisagreesWithItsPath` + `itCarriesNoStaleAllowListEntry`) ловит расхождение — прочитано и признано рабочим, находки не заведено (см. секцию «Отклонённые» — codex-R04). `derive()` — не возвращает 0 никогда (подтверждено чтением обеих ветвей), но не атомарен (codex-07).
- Вопрос 6 (одиннадцатый адрес) — закрыто находкой codex-06 (низкая значимость, вне диффа, вне CI).
- Вопрос 7 (непокрытые формы входа) — синтаксически некорректный `*Test.php` не проходит молча (парсер `nikic/php-parser` бросает исключение при отсутствии statements, что превращается в `LogicException`); файл без класса с суффиксом `Test` внутри `*Test.php` — попадает в orphan-проверку и был бы пойман (кроме случая двух классов в одном файле — см. codex-03); файл-фикстура внутри dev-root, соответствующий маске `*Test.php`, — тоже войдёт в корпус и либо исполнится, либо станет orphan — не «тихо» исчезает.
- Вопрос 8 (удаление `EXPLICIT_PATH_DISPOSITIONS`) — проверено чтением диффа генератора: удалённая ветка допускала коллизию только для двух конкретных retired-путей; без неё любая коллизия безусловно проваливает `validateInventory()` — поведение строго усилено, не ослаблено. Находки не заведено (codex-R06 в «Отклонённые»).
- Вопрос 9 (пять представлений suite-карты) — для непустых, уже существующих suite все пять представлений согласованы; несогласованность найдена только для гипотетического ПУСТОГО `<testsuite>` (codex-08). Отдельно отмечено: G2 не является независимой пятой копией карты — он читает тот же файл `scripts/phpunit-aggregate.py`, что и агрегатор, поэтому не может обнаружить ошибку, одинаково искажающую оба потребителя одного файла (это ограничение зафиксировано как контекст к codex-08, отдельной находкой не выделено, так как это архитектурное свойство выбранного источника истины, а не баг).
- `composer check` / реальный прогон PHPUnit-suite'ов НЕ запускались фасилитатором и codex — по read-only ограничению задания (создание кэш/temp-артефактов запрещено). Заявленные в плане цифры (Unit 7578 и т.д.) приняты на веру, не перепроверены повторным запуском.

## Отклонённые находки

- `codex-R01` | Стражи проходят на полностью пустом скане | Отклонено: количественные пороги (`assertGreaterThan`) и синтетические самопроверки делают нулевой/almost-нулевой корпус красным — подтверждено чтением кода.
- `codex-R02` | Ошибка подпроцесса PHPUnit читается как пустой чистый результат | Отклонено: `runListing()`/`parseListing()` бросают `LogicException` на ненулевом exit-коде, непустом stderr, отсутствии/дублировании заголовка `Available tests:` и на неожиданной строке — подтверждено чтением `TestFilesAreExecutedTest.php:381-436`.
- `codex-R03` | Класс, полностью исключённый группой, ошибочно считается orphan | Отклонено: `reachableArguments` намеренно строится БЕЗ `--exclude-group`, то есть такой класс остаётся «достижимым» для цели orphan-проверки; исключение проверяется отдельным, симметричным механизмом (`excludedIds`/`SILENTLY_EXCLUDED`) — это соответствует явно задокументированному намерению кода (docblock строки 57-61) и признано корректным дизайном, а не дефектом.
- `codex-R04` | G3 не замечает stale allow-list после переименования/удаления файла/смены namespace на другой неверный | Отклонено: направленные `array_diff_assoc()` в обе стороны (measure↔load) ловят все три сценария — подтверждено построчным разбором логики.
- `codex-R05` | `NamespacePathAllowList::derive()` может вернуть `0` | Отклонено: обе достижимые ветви кода возвращают строго `self::WROTE` (4) или `self::MEASUREMENT_FAILED` (5) — подтверждено чтением всех return-путей функции.
- `codex-R06` | Удаление `EXPLICIT_PATH_DISPOSITIONS` ослабило проверку коллизий в генераторе | Отклонено: удалённая ветка была исключением ИЗ проверки для двух retired-путей; без неё проверка строже, а не слабее — подтверждено чтением диффа.
