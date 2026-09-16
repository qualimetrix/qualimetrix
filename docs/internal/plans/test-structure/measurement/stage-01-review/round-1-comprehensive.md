# Findings — test-structure-01 (`9a3b2548..HEAD`)

Reviewer instance: comprehensive (Claude). Ветка `test-structure-01`, дерево не изменялось.
Все пробы с изменённым кодом выполнялись в `mktemp -d`, вне рабочего дерева.

---

### claude-01

- **reviewer**: comprehensive
- **severity**: HIGH
- **kind**: point
- **domain**: tests
- **title**: G2 считает файл исполняемым, если исполняется хотя бы один из объявленных в нём классов, — а PHPUnit запускает только класс, совпадающий с именем файла
- **mechanism**: `orphansIn()` объявляет файл сиротой только когда пересечение его объявленных class-like с листингом пусто (`array_intersect($declared, $reachable) === []`). PHPUnit же грузит из `Foo Test.php` исключительно класс, чьё имя совпадает с базовым именем файла: второй тест-класс в том же файле не попадает ни в листинг, ни в прогон, и не даёт ни warning, ни ненулевого кода возврата. Поэтому файл, где рядом с исполняемым `FooTest` объявлен второй `BarTest` с корректными `#[Test]`/`itXxx`/namespace, для G2 выглядит исполняемым, а его кейсы не выполняются. G1 и G3 такой класс тоже пропускают: у него и атрибуты, и namespace в порядке. Это ровно тот класс дефекта, ради которого G2 написан («110 tests sat unexecuted for three runs under a green `composer check`»).
- **trigger**: воспроизводится в нормальной работе — достаточно дописать второй тест-класс в существующий `*Test.php`. Паттерн «несколько class-like в одном тестовом файле» в этом дереве штатный: 21 файл уже так устроен (хелперы, фикстурные правила, шпионы), поэтому добавление второго класса не выглядит для автора чем-то особенным.
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:210-222` (решающая строка 214)
- **evidence**:
  ```php
  foreach ($declarations as $path => $declared) {
      if (array_intersect($declared, $reachable) === []) {
  ```
  Проба (вне дерева, `mktemp -d`; `$R` — корень репозитория):
  ```
  # t/ProbeTest.php объявляет FirstDeclaredTest (первым) и ProbeTest (вторым),
  # оба extends TestCase, оба с #[Test]
  php $R/vendor/bin/phpunit -c phpunit.xml --list-tests --no-coverage
  # Available test:
  #  - Probe\ProbeTest::itMatchesTheFileName        <- только класс по имени файла
  ```
  Прогон того же файла со строгой конфигурацией (`failOnWarning`, `failOnRisky`, `failOnNotice`, `failOnDeprecation` — как в `phpunit.xml.dist`) в варианте `ProbeTest` + `SecondProbeTest`: `OK (2 tests, 2 assertions)`, exit 0, ни одного предупреждения о проигнорированном классе.
  Проверка, что дерево сегодня чистое (ни один «лишний» класс в 21 многоклассовом файле не оканчивается на `Test`):
  ```
  cd <repo> && php -r 'require "vendor/autoload.php"; /* AST-обход tests/, печать файлов с >1 ClassLike */'
  # TestDependencyTraversalParticipant, InMemoryLogger, FixtureRule*, SpyLogger, ...
  ```
- **verification**: confirmed
- **verification_note**: подтверждены обе половины — (а) выбор класса PHPUnit'ом по имени файла, а не по порядку объявления (первый объявленный класс отброшен); (б) логика `orphansIn` пропускает такой файл по построению. Сегодня дефект латентный: живого второго тест-класса в дереве нет.
- **fix_direction**: зеркалить правило самого PHPUnit, а не наследование от `TestCase` (у стража только AST): в `*Test.php` каждый дополнительный class-like, который выглядит тест-классом (имя оканчивается на `Test` и/или в нём есть метод с `#[Test]`), обязан присутствовать в листинге; файл с одним исполняемым и одним неисполняемым тест-классом должен отказывать отдельным сообщением от «директория не зарегистрирована».

---

### claude-02

- **reviewer**: comprehensive
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: G2 читает аргументы аггрегата лексером питоновских литералов, поэтому аргумент, добавленный не в первое литеральное присваивание, невидим — и сужает `composer check` при всех четырёх зелёных рефьюзалах
- **mechanism**: `aggregate()` берёт `SUITES` и `COMMON_ARGUMENTS` регуляркой `/^NAME = \((.*?)\)$/ms` по исходнику и вытаскивает двойные кавычки `preg_match_all('/"([^"]*)"/')`. Это модель одной синтаксической формы, а не семантики питона. Любой валидный способ дополнить аргументы вне первого литерального кортежа — `COMMON_ARGUMENTS += ("--exclude-group=slow",)` под условием, второе присваивание (`preg_match` берёт первое совпадение), сборка из другой переменной или из окружения — для стража не существует: он читает сегодняшние три аргумента, оба его листинга (`reachableArguments`/`executedArguments`) остаются сегодняшними, `excludedIds()` возвращает ровно два известных кейса, и все четыре рефьюзала зелены, пока `composer check` уже реально прогоняемого набора. Класс дефекта — тот самый, от которого класс защищается в собственном докблоке («a guard that re-derives … is a second copy»): для `--list-tests` авторы взяли измерение, для аргументов — реимплементацию. Второй, более узкий случай: `excludedGroupsIn()` распознаёт только префикс `--exclude-group=`, тогда как PHPUnit принимает и раздельную форму `--exclude-group live-freshness` (проверено: Integration 714 → 712).
- **trigger**: только на рукотворном входе — требует конкретной будущей правки `scripts/phpunit-aggregate.py`, которая сама по себе валидна для питона и проходит ревью как безобидная
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:310-339` и `349-360`
- **evidence**:
  ```php
  if (preg_match('/^' . preg_quote($name, '/') . ' = \((.*?)\)$/ms', $source, $matches) !== 1) {
      throw new LogicException(self::AGGREGATE . ' declares no readable ' . $name . ' tuple');
  }
  ```
  `preg_match` берёт первое присваивание и молча игнорирует второе:
  ```
  printf 'SUITES = ("A",)\nSUITES = ("A", "B")\n' | php -r 'preg_match("/^SUITES = \((.*?)\)$/ms", stream_get_contents(STDIN), $m); echo $m[1];'
  # "A",
  ```
  Раздельная форма действительно работает у PHPUnit:
  ```
  cd <repo>
  php vendor/bin/phpunit --list-tests --no-coverage --testsuite=Integration | grep -c '^ - '                       # 714
  php vendor/bin/phpunit --list-tests --no-coverage --exclude-group live-freshness --testsuite=Integration | grep -c '^ - '  # 712
  ```
- **verification**: confirmed
- **verification_note**: подтверждено по коду (регулярка видит ровно одно литеральное присваивание, `preg_match` — первое совпадение) и измерением для раздельной формы. Для раздельной формы у *существующей* группы тихого сужения не будет — см. секцию refuted; тихим остаётся именно нелитеральный аргумент.
- **fix_direction**: спрашивать аргументы у самого раннера, а не лексить его исходник (раннер уже импортируем как модуль и имеет `list_command()`/`COMMON_ARGUMENTS` — достаточно режима печати эффективных аргументов, вызываемого подпроцессом), ровно как страж уже поступает с `--list-tests`; распознавание исключений строить на разобранных аргументах, а не на префиксе `--exclude-group=`.

---

### claude-03

- **reviewer**: comprehensive
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: G1 моделирует только один из двух механизмов обнаружения PHPUnit: метод `testXxx` без `#[Test]` исполняется и не отказывается
- **mechanism**: `violationsIn()` сравнивает два предиката — имя по `/^it[A-Z]/` и наличие `#[Test]` — и пропускает метод, когда они совпадают. Для `public function testFoo()` без атрибута оба предиката false, метод молча пропущен, — а PHPUnit его исполняет: префикс `test` остаётся вторым механизмом обнаружения в PHPUnit 12. Докблок класса утверждает больше, чем делает код: «A test method is reachable only when its name and its attribute agree» и «Both halves are refused here». Половина «carries #[Test] but is not named itXxx» на самом деле покрывает только атрибутный путь; `testXxx`-метод одновременно (а) исполняется, (б) нарушает правило 9 CLAUDE.md («The legacy `testXxx` prefix is not used») и (в) для G1 невидим.
- **trigger**: только на рукотворном входе — сегодня в дереве 0 таких методов, требуется новая правка (в т.ч. привычная по другим проектам)
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestMethodsAreReachableTest.php:178-196` (предикат — строки 180-182), докблок `:18-32`
- **evidence**:
  ```php
  $named = preg_match('/^it[A-Z]/', $name) === 1;
  $attributed = self::carriesTestAttribute($method);
  if ($named === $attributed) {
      continue;
  }
  ```
  Проба вне дерева: класс с `public function testLegacyNameNoAttribute()` (без атрибута) — PHPUnit его перечисляет и исполняет:
  ```
  php $R/vendor/bin/phpunit -c phpunit.xml --list-tests --no-coverage
  #  - Probe\ProbeTest::testLegacyNameNoAttribute
  #  - Probe\ProbeTest::itIsAttributed
  ```
  Сегодняшнее состояние дерева:
  ```
  cd <repo> && grep -rn --include='*Test.php' -E 'public function test[A-Z]' tests governance | wc -l   # 0
  ```
- **verification**: confirmed
- **verification_note**: исполнение `testXxx` без атрибута подтверждено прогоном PHPUnit 12.5.25; отсутствие таких методов в дереве измерено.
- **fix_direction**: сделать предикат трёхзначным — «PHPUnit это исполнит» (атрибут ИЛИ префикс `test`) против «конвенция проекта это разрешает» (`itXxx` + атрибут), и отказывать на любом расхождении; заодно привести докблок в соответствие с тем, что код действительно проверяет.

---

### claude-04

- **reviewer**: comprehensive
- **severity**: MEDIUM
- **kind**: contract
- **domain**: architecture
- **title**: Канонический список «десяти адресов» регистрации корня в AGENTS.md неполон и расходится с таким же «десятью» в Execution record
- **mechanism**: AGENTS.md — документ, который прочитает следующий агент при заведении корня, — перечисляет десять адресов: `autoload-dev`, `phpstan.neon`, cs-fixer finder, pre-commit, суитовый кортеж аггрегата, scan scope генератора инвентаря, `tests`-surface rename-перечисления, `.gitattributes`, `.dockerignore`, environment bootstrap. Execution record плана перечисляет свои «десять», и это ДРУГИЕ десять: у него есть `phpunit.xml.dist`, `tests/System/TestRunnerConfiguration/Tests/test_phpunit_aggregate.py`, `ScratchPathsCarryRealEntropyTest::ROOTS` и `generate-modular-architecture-production-inventory.php`, но нет `.dockerignore` и `scripts/init-environment.sh`. Объединение — не менее тринадцати, и ни один из двух списков не называет одиннадцатый адрес, который эта же правка была вынуждена тронуть: `ModularArchitectureGovernanceIntegrationTest::createIsolatedProject()` копирует корни в изолированный проект и передаёт их `git ls-files`. Хуже всего то, что AGENTS.md пропускает именно те адреса, которые падают МОЛЧА и названы таковыми в самом Execution record: `ScratchPathsCarryRealEntropyTest::ROOTS` (энтропийный скан просто не покрывает новый корень) и запрет импорта dev-неймспейса в production-инвентаре. Это в точности повторяющийся урок проекта «перечислять по путям, а не по местам конструирования».
- **trigger**: воспроизводится в нормальной работе — следующее заведение корня (план прямо обещает новые директории на дальнейших этапах) будет сверяться с AGENTS.md
- **in_scope**: да
- **anchor**: `AGENTS.md:107-118`; сверяемый список — `docs/internal/plans/test-structure/01-suite-integrity.md`, раздел «Registering a root costs ten addresses, not four»
- **evidence**:
  ```
  cd <repo>
  sed -n '115,118p' AGENTS.md          # десять адресов AGENTS.md
  awk '/Registering a root costs ten/,/surfaces\(\) keeps one surface/' docs/internal/plans/test-structure/01-suite-integrity.md
  git diff 9a3b2548..HEAD --name-only # 33 файла; среди них тронуты адреса, которых нет ни в одном из двух списков
  ```
  Тронуто и не названо в AGENTS.md: `phpunit.xml.dist`, `tests/System/TestRunnerConfiguration/Tests/test_phpunit_aggregate.py`, `tests/System/ScratchPathIsolation/Unit/ScratchPathsCarryRealEntropyTest.php`, `scripts/generate-modular-architecture-production-inventory.php`, `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGovernanceIntegrationTest.php`.
- **verification**: confirmed
- **verification_note**: оба списка прочитаны целиком, расхождение и пропуски сверены с `git diff --name-only` по диапазону.
- **fix_direction**: держать перечисление адресов в одном месте, на которое ссылаются и AGENTS.md, и план, и помечать в нём для каждого адреса, падает он громко или молча; молчаливые адреса имеет смысл закрывать не списком, а производным от `autoload-dev` (как это уже сделано в `TestTree`), чтобы новый корень подхватывался сам.

---

### claude-05

- **reviewer**: comprehensive
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: Корпус трёх стражей определён двумя литералами, которые нигде не сверяются с областью `phpunit.xml.dist`
- **mechanism**: `TestTree` определяет корпус как «файлы, чьё имя оканчивается на `Test.php`, под PSR-4 dev-корнями из `composer.json`». PHPUnit определяет свой корпус как «`<directory>`-записи `phpunit.xml.dist` с суффиксом по умолчанию `Test.php`». Эти два определения нигде не сводятся друг с другом, а докблоки стражей формулируют обещание по первому определению так, будто оно покрывает второе («Every test file the tree carries is one the suite actually runs», «a root registered for autoloading is judged from the moment it is registered, so a new one cannot be added without also being covered»). Две формы входа выпадают целиком и молча: (а) `<directory suffix="…">` или `<file>` с другим суффиксом — PHPUnit такие файлы исполняет, `TestTree` их не видит, значит G1 не проверит достижимость их методов и G3 — их namespace; (б) тестовый корень, добавленный в `phpunit.xml.dist`, но не в `autoload-dev` — не увидит ни один из трёх стражей. Латентно сюда же попадает абстрактный базовый тест-класс в файле, не оканчивающемся на `Test.php`: его унаследованные `itXxx` без `#[Test]` не исполняются и для G1 невидимы (сегодня таких файлов 0).
- **trigger**: только на рукотворном входе — требует правки `phpunit.xml.dist` или заведения корня мимо `autoload-dev`
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestTree.php:29-40` и `:117-136`; обещания — `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:12`, `TestMethodsAreReachableTest.php:34-38`
- **evidence**:
  ```php
  return self::filesIn($relativeDirectory, static fn(string $name): bool => str_ends_with($name, 'Test.php'));
  ```
  Корни берутся исключительно из `composer.json`:
  ```php
  foreach ($autoloadDev['psr-4'] as $prefix => $directory) { ... }
  ```
  Проверка сегодняшнего состояния:
  ```
  cd <repo>
  grep -n 'suffix' phpunit.xml.dist                                             # suffix только в <source>, у testsuites его нет
  grep -rln --include='*.php' -E 'public function it[A-Z]' tests governance | grep -v 'Test\.php$' | wc -l   # 0
  ```
- **verification**: confirmed
- **verification_note**: дыра подтверждена по коду; живых экземпляров сегодня нет (измерено: 0 файлов с `itXxx` вне `*Test.php`, в `phpunit.xml.dist` нет `suffix` у `<directory>`).
- **fix_direction**: добавить рефьюзал, сводящий два определения корпуса: множество файлов, которые PHPUnit реально грузит по своей конфигурации, должно совпадать с множеством, которое перечисляет `TestTree` (или расхождение должно быть названо поимённо, как это уже сделано для `SILENTLY_EXCLUDED`).

---

### claude-06

- **reviewer**: comprehensive
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Потолок allow-list'а G3 — литерал `60`, поэтому «лечение через derive» после сокращения списка не отказывается
- **mechanism**: `itJudgesEveryTestFileInTheTree()` держит единственную машинную защиту от того, чтобы новые нарушения «лечились» перезапуском derive: `assertLessThanOrEqual(60, count($allowed))`. Число — литерал, а не производная от текущего состояния. Сценарий: список сокращён до 50 (следующий этап плана это и обещает) → кто-то добавляет 5 файлов с неверным namespace → рефьюзал 1 краснеет → правку «чинят» запуском `derive-namespace-path-allow-list.php` → список 55 → рефьюзал 1 зелёный, рефьюзал «нет протухших строк» зелёный, потолок 60 пропускает. Докблок метода обещает строго убывающую величину («emptying it may only ever take that number down»), код допускает рост до 60.
- **trigger**: воспроизводится в нормальной работе, как только список опустится ниже 60 — что и является заявленной задачей следующего этапа
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestNamespacesFollowTheirPathTest.php:122-131` (строка 129), докблок `:115-121`
- **evidence**:
  ```php
  self::assertGreaterThan(500, \count($judged));
  self::assertLessThanOrEqual(60, \count($allowed));
  ```
- **verification**: confirmed
- **verification_note**: подтверждено по коду; сегодня `count($allowed) === 60`, то есть потолок совпадает с фактом и ещё не протух — измерено: `measured rows: 60 loaded rows: 60`.
- **fix_direction**: сделать потолок производным и самозатягивающимся — хранить его рядом со списком как измеренное число, которое derive записывает и которое разрешено только уменьшать, либо вовсе снять отдельный потолок, потребовав, чтобы длина списка была его собственным трекнутым фактом (тогда любой рост виден в диффе как изменение числа, а не как молчаливый прирост строк).

---

### claude-07

- **reviewer**: comprehensive
- **severity**: MEDIUM
- **kind**: pattern
- **domain**: tests
- **title**: Докблоки стражей несут измеренные числа (60 / 146 / 147 / 149 / 110), которые не проверяет ничто
- **mechanism**: В репозитории, чья дисциплина сформулирована в этом же изменении как «a row nobody measured is a claim about the tree that nothing checks», сами стражи носят в прозе набор чисел, за которыми нет ни одного рефьюзала: `TestTree` — «60 test files … part of the 146 files, declaring 147 classes, that `composer dump-autoload -o` skips»; `TestFilesAreExecutedTest` — те же 146/147 плюс «110 tests sat unexecuted for three runs»; `TestNamespacesFollowTheirPathTest` — «149 files … all 89 non-test ones sit under a `Fixtures/` directory and none of the 60 test ones do» и «147 classes». Проверяется из них ровно одно — 60, и то как потолок (см. claude-06). Остальные протухнут молча: первый же файл, перенесённый следующим этапом, сдвинет 146/147/149/89, и докблок начнёт утверждать неправду в самом месте, куда читатель придёт разбираться, почему страж красный.
- **trigger**: воспроизводится в нормальной работе — следующий этап плана двигает ровно те файлы, по которым эти числа измерены
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestTree.php:34-40`; `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:26-33` и `:44-55`; `governance/TestSuiteHygiene/TestNamespacesFollowTheirPathTest.php:22-38`
- **evidence**:
  ```
  cd <repo>
  grep -n '146\|147\|149\|110 tests\| 60 ' governance/TestSuiteHygiene/*.php
  ```
  Проверяемым является только 60 — `TestNamespacesFollowTheirPathTest.php:129`; для 146/147/149/89/110 в `governance/` нет ни одного утверждения.
- **verification**: confirmed
- **verification_note**: сверено грепом по `governance/`; число 60 подтверждено измерением (`count(load()) === count(measure()) === 60`), остальные ни на что не опираются в коде.
- **fix_direction**: оставить в прозе только то, что проверяется рефьюзалом, а остальные измерения либо убрать, либо перевести в утверждение (так уже сделано с `--exclude-group=benchmark`: докблок прямо отмечает, что «removes nothing» стало фактом, который страж отказывается принимать на слово, — этот же приём применим и к остальным числам).

---

### claude-08

- **reviewer**: comprehensive
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: `TestTree::testFiles()` не дедуплицирует файлы при вложенных PSR-4 корнях, хотя вложенный корень явно предусмотрен соседним кодом
- **mechanism**: `roots()` дедуплицирует только строки-директории, поэтому вложенная пара (`tests` и, скажем, `tests/Unit`) даёт два корня, и `testFiles()` возвращает файлы пересечения дважды — `array_unique` там нет. Функциональные рефьюзалы это переживают (они кладут файлы в ключи массива), а вот полы — нет: `assertGreaterThan(500, count(TestTree::testFiles()))` в G2 и G3 и `assertGreaterThan(500, $perRoot['tests'])` в G1 начинают считать дубликаты. Полы — единственное устройство против вакуумной зелени, поэтому вход, который их надувает, ослабляет именно ту страховку, ради которой они написаны. Отдельно отмечу внутреннее противоречие: вложенный корень соседний код предусматривает явно — `NamespacePathAllowList::expectedNamespace()` разрешает конфликт «longest root wins, the way the autoloader resolves a nested one».
- **trigger**: только на рукотворном входе — нужна запись вложенного корня в `autoload-dev`
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestTree.php:108-130`
- **evidence**:
  ```php
  foreach (self::roots() as $root) {
      foreach (self::testFilesIn($root) as $file) {
          $files[] = $file;
      }
  }
  sort($files);
  ```
  Противоположная трактовка в `governance/TestSuiteHygiene/NamespacePathAllowList.php:111-123` (`\strlen($root) > \strlen($bestRoot)` — выбор самого длинного из вложенных корней).
- **verification**: confirmed
- **verification_note**: подтверждено чтением кода; сегодня вложенных dev-корней нет (`tests/`, `governance/`, `tools/phpstan/` не пересекаются), поэтому дубликатов в дереве не возникает.
- **fix_direction**: дедуплицировать результат `testFiles()` так же, как уже дедуплицируются корни, и держать это свойство утверждением — оно напрямую подпирает полы.

---

### claude-09

- **reviewer**: comprehensive
- **severity**: LOW
- **kind**: contract
- **domain**: style
- **title**: Докблоки стражей ссылаются на план — прямой запрет CLAUDE.md
- **mechanism**: CLAUDE.md требует: «Комментарий должен быть самостоятельным: он не ссылается на сторонние документы, включая планы». Докблоки нового кода ссылаются: `TestFilesAreExecutedTest` — «Refusals 2–4 are the plan's fourth axis» и «Every later stage of the test-structure plan creates directories»; `TestNamespacesFollowTheirPathTest` — «60 is the plan's own measurement of this tree» и «not this stage's»; `NamespacePathAllowList` — «the files any later stage relocates». Для читателя, у которого плана нет (а он удаляется/архивируется по правилам репозитория), такая ссылка не несёт ничего, кроме отсылки к несуществующему документу.
- **trigger**: воспроизводится в нормальной работе — читатель кода не обязан иметь под рукой документ плана, а сама ссылка не несёт проверяемого утверждения
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:19,49`; `governance/TestSuiteHygiene/TestNamespacesFollowTheirPathTest.php:36,119-120`; `governance/TestSuiteHygiene/NamespacePathAllowList.php:13-15`
- **evidence**:
  ```
  cd <repo> && grep -n "the plan's\|later stage\|this stage" governance/TestSuiteHygiene/*.php
  ```
- **verification**: confirmed
- **verification_note**: правило процитировано из CLAUDE.md, места найдены грепом.
- **fix_direction**: переформулировать так, чтобы утверждение стояло само по себе («этот список пуст не будет, пока переименования не закончены» вместо «это не задача этого этапа»), без апелляции к внешнему документу.

---

### claude-10

- **reviewer**: comprehensive
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: Сообщение рефьюзала G2 предписывает неверное лечение для класса без исполняемых методов
- **mechanism**: `orphansIn()` объявляет сиротой любой файл, ни один класс которого не попал в листинг, а сообщение однозначно диагностирует причину: «Register the directory in phpunit.xml.dist and in the matching branch of currentSuite()». Но у сироты есть вторая причина — класс лежит в зарегистрированной директории и у него просто нет ни одного исполняемого метода (все потеряли `#[Test]`, либо файл содержит только абстрактный базовый класс с именем `*Test.php`). В этом случае читатель отправляется править конфигурацию, где всё в порядке.
- **trigger**: только на рукотворном входе — сегодня таких файлов в дереве нет
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:100-106`
- **evidence**:
  ```php
  "%d test file(s) exist but no configured suite runs them.\n"
  . "Register the directory in phpunit.xml.dist and in the matching branch of currentSuite()\n"
  ```
- **verification**: confirmed
- **verification_note**: подтверждено чтением; вторая причина реальна — PHPUnit не перечисляет класс без исполняемых методов, что напрямую видно из пробы к claude-01.
- **fix_direction**: различать два случая по тому, известна ли директория конфигурации, и давать разное направление лечения.

---

## Coverage — проверено и признано чистым

- **G3, режим `derive`**: обе ветки прочитаны по коду — `WROTE = 4` при успешной записи, `MEASUREMENT_FAILED = 5` и при провале `measure()` (ловится `Throwable`), и при провале `file_put_contents`; 0 не возвращается ни на одном пути (`NamespacePathAllowList.php:188-209`). Скрипт не запускался — это запись в трекнутый файл.
- **Список G3 действительно измерен, а не набран руками**: `render(measure())` побайтно равен трекнутому `namespace-path-allow-list.php` (проверено read-only PHP-снипетом: `IDENTICAL`, `measured rows: 60 loaded rows: 60`).
- **G3, сценарии жизненного цикла строки**: переименование файла (старая строка протухает + новое нарушение не покрыто → красное в обе стороны), удаление файла (протухшая строка), смена namespace на другой неверный (значение строки расходится) — все три красят по построению `array_diff_assoc` в обе стороны (`TestNamespacesFollowTheirPathTest.php:48,71`).
- **G2, `#dataset`**: суффикс среза по первому `#` подтверждён живым листингом — 194 строки с `#` в Unit (`LayerViolationOptionsTest::itRejectsARemovedPerDiagnosticSeverityKey#0..#4`), группы объявляются на методе, поэтому срез корректен.
- **G2, класс, у которого все кейсы исключены группой**: не сирота по построению — рефьюзал 1 судит листинг БЕЗ исключений (`reachableArguments`), что подтверждается живым `SuppressionSnapshotFreshnessTest`.
- **Партиция суитов**: у аггрегата есть собственный отказ (`assert_partition`, `scripts/phpunit-aggregate.py`), сводящий листинг всей конфигурации с суммой суитов; суит, объявленный в `phpunit.xml.dist` и отсутствующий в `SUITES`, даёт `missing` → refusal. Докстринг соответствует коду.
- **Незарегистрированная governance-группа краснит `architecture:check` по имени** — утверждение AGENTS.md проверено: классификатор отдаёт `none` (`php scripts/generate-modular-architecture-test-inventory.php --classification-probe=governance/Other/ProbeTest.php` → `Architecture.Governance P8 none`), а `validateInventory()` безусловно отказывает на `kind === 'phpunit-test-class' && current_suite === 'none'` (строка 1299), причём `kind` для governance-файлов действительно `phpunit-test-class` (`test-ownership.tsv`).
- **Удаление `EXPLICIT_PATH_DISPOSITIONS`**: поведение не ослаблено — см. секцию refuted.
- **Полы (`>500` файлов, `>600` классов, `>5000` кейсов, `>=5` суитов)** при фактических 691 файле `*Test.php` (измерено), 9058 различных методах в объединённом листинге (измерено) и 5 суитах; число классов в листинге сам не считал — 691 взято из сводки оркестратора — запас большой, но полы подперты рефьюзалом 1: сужение множества суитов делает файлы потерянного суита сиротами и краснит. Отдельной находкой не считаю.
- **`runListing`/`parseListing`**: отказ на ненулевом коде возврата, на непустом stderr, на отсутствии/дублировании заголовка `Available tests:`, на неожиданной строке и на пустом списке — ни один из этих путей не читается как «нарушений нет» (`TestFilesAreExecutedTest.php:381-435`).
- **Статический кеш листингов** переживает методы одного класса, дублирующих подпроцессов нет; набор `Governance` зелёный за 9.3 s (13 тестов, 41 утверждение) при включённом `failOnRisky`.
- **`--list-tests` учитывает `--exclude-group`** — измерено (Integration 714 → 712 при `--exclude-group=live-freshness`), значит `excludedIds()` не вырождается в пустое множество.
- **Дерево сегодня чистое по обоим латентным дырам**: 0 методов `public function test[A-Z]` в `tests/`+`governance/`; 0 файлов с `public function it[A-Z]` вне `*Test.php`; 0 `*Test.php` под `Fixtures/`; 0 `*Test.php` под корнем `tools/phpstan/`.
- **Адреса регистрации, которые я искал своим способом**: CI-workflow'ы (`.github/workflows/*.yml`) корней не перечисляют; `.gitignore`, `qmx.yaml`, `scripts/check-private-leaks.sh`, `scripts/check-docs.sh` — тоже; из composer-скриптов `composer check` через `tests`-корень ходят только `architecture:check` и `enumeration:renames|runtime-channels:check`, и оба обновлены. Хук `.githooks/pre-commit` обновлён (`^(src|tests|governance)/`).
- **Перенос `tests/Infrastructure/Logging/*` в `Logging/Unit/`**: namespace во всех четырёх файлах приведён к пути, `dispositionFor()`/`targetPath()` получили точную ветку именно для `tests/Infrastructure/Logging/Unit/` (не для всего `Infrastructure/{Subject}/Unit`), что согласуется с зафиксированным мёртвым `classifyOwner()`-регекспом.
- **`TypeCoverageRuleTest::itAliasesItsOwnTwoBoundariesOnly`**: добавлены оба атрибута (`#[Test]` + `#[DataProvider('dimensions')]`), метод принимает `array $dimension` — сигнатура и провайдер согласованы.

## Отклонённые (refuted)

- `--list-tests` игнорирует `--exclude-group`, поэтому `excludedIds()` всегда пуст и рефьюзалы 3–4 вакуумны | опровергнуто измерением: Integration 714 без исключений и 712 с `--exclude-group=live-freshness`
- 21 многоклассовый `*Test.php` в дереве должен уже сейчас ломать G2 | опровергнуто: `orphansIn` использует пересечение, а все «лишние» классы — хелперы/фикстуры, не оканчивающиеся на `Test`; дефект claude-01 остаётся латентным
- Раздельная форма `--exclude-group live-freshness` тихо сужает `composer check` | опровергнуто: для существующей группы она попадает в `reachableArguments` двумя токенами, усекает уже «reachable»-листинг, `excludedIds()` становится пустым и рефьюзал 4 краснеет двумя протухшими строками; тихой остаётся только форма аргумента, которой нет в литеральном кортеже (claude-02)
- Удаление `EXPLICIT_PATH_DISPOSITIONS` ослабило `validateInventory()` | опровергнуто: старый код отказывал, если хоть один путь коллизии НЕ имел explicit-диспозиции, новый отказывает безусловно — строго строже; обе ключевые записи указывали на уже удалённые файлы
- `derive-namespace-path-allow-list.php` может вернуть 0 | опровергнуто чтением обеих ветвей: 4 при записи, 5 при провале измерения и при провале записи
- Утверждение AGENTS.md «an unregistered group reddens `composer architecture:check` by name» ложно | опровергнуто пробой классификатора и безусловным отказом `validateInventory()` на `phpunit-test-class` + `current_suite: none`

## Чего я не покрыл

- **`derive-namespace-path-allow-list.php` не запускался.** Это запись в трекнутый файл, а ревью read-only. Обе ветви разобраны по коду, и косвенно подтверждены тем, что `render(measure())` побайтно равен трекнутому файлу; но сам факт «exit 4 / exit 5» измерением не подкреплён.
- **Содержание `test-ownership.tsv` и `test-topology.tsv` глубже диффа.** Я сверил governance-строки и вид `kind`/`current_suite`, но не перепроверял 691-строчный инвентарь целиком и не воспроизводил `composer architecture:check`.
- **Эффект `.gitattributes` и `.dockerignore` эмпирически не проверял** — `git archive` и сборку образа не запускал; обе записи приняты по чтению.
- **`composer check` целиком не прогонял.** Взял заявленную зелень как данность; отдельно измерил только набор `Governance` (9.3 s, 13/13) и листинги `--list-tests` по суитам.
- **Полноту `SILENTLY_EXCLUDED` не перепроверял независимо** — не считал `excludedIds()` для всех пяти суитов своими руками; проверил только Integration (714→712, два кейса) и опирался на зелёный рефьюзал 3/4.
- **Плантации из «The guards bite» не воспроизводил.** Таблица из восьми плантов в Execution record принята как заявление автора: каждая требует правки рабочего дерева; выборочно я воспроизвёл только те механизмы, которые проверяются на копии вне дерева.
- **Website-документацию и `docs/ARCHITECTURE.md` не смотрел** — в диффе их нет, но появление нового корня верхнего уровня потенциально требует правки и там; вопрос не проверял.
- **Многоклассовая дыра claude-01 проверена на PHPUnit 12.5.25 и PHP 8.5.9** локально; не проверял, ведёт ли себя так же версия PHPUnit, закреплённая в CI-образе (в `composer.json` диапазон `^12.0`).
- **Производительность G2 под параллельным шардированием** (пять суитов одновременно, каждый из которых у Governance порождает десять `--list-tests`-подпроцессов) не измерял — мерил только одиночный прогон суита.
