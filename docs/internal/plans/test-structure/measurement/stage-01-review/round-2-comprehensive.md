# Round 2 — узкое ревью раунда фиксов (`git diff 5c660f16..HEAD`)

- **reviewer**: comprehensive-round2
- **материал**: 15 файлов диффа `5c660f16..HEAD`; контекст — `.review-test-structure-01/findings/{claude,codex}.md`, раздел «What review replaced» в `docs/internal/plans/test-structure/01-suite-integrity.md`
- **состояние дерева на момент ревью**: `vendor/bin/phpunit --testsuite=Governance --no-coverage` → `OK (14 tests, 47 assertions)`, 10 s
- **PHPUnit**: 12.5.25, PHP 8.5.9

Все команды в evidence воспроизводимы дословно из корня рабочего дерева. Где нужна проба с изменённым
кодом, её тело приведено целиком и создаётся в каталоге из `mktemp -d`; рабочее дерево не менялось.

---

## Регрессии лечения

Находки, которых не было в первом раунде и которые внесены именно этим диффом.

### comprehensive-round2-01

- **reviewer**: comprehensive-round2
- **severity**: HIGH
- **kind**: point
- **domain**: tests
- **title**: G2 применяет собственную модель исполняемости ДО измерения, поэтому отказывает классу, который PHPUnit перечислил и исполняет
- **mechanism**: `unreachableIn()` проверяет `$declared['executable'] === []` раньше, чем сверяет класс со списком `$reachable`. Для класса, который PHPUnit уже перечислил, предикат не нужен — ответ получен от PHPUnit. Предикат нужен только чтобы разложить *неперечисленный* класс на «должен был бежать» и «фикстура». `TestTree::isExecutableClass()` читает только методы, объявленные в теле класса в AST, тогда как PHPUnit собирает случаи через `Reflection::publicMethodsDeclaredDirectlyInTestClass()` → `$class->getMethods(IS_PUBLIC)`, где отфильтрованы только методы, объявленные `TestCase` и `Assert`. Поэтому кейсы, унаследованные от промежуточного абстрактного базового тест-класса, и кейсы из трейта PHPUnit исполняет, а предикат их не видит.

  Два эффекта, один механизм:

  **(а) громкий ложный отказ.** Файл с классом, чьи кейсы унаследованы или пришли из трейта, получает отказ «PHPUnit would run no case in this file. Its directory is not the suspect: give the file a case, or delete it» — про класс, который в этот момент бежит. Лечение в тексте отказа («дай файлу кейс или удали файл») прямо неверно.

  **(б) тихая половина.** Если в файле есть один самостоятельно исполняемый класс A и второй класс B, чьи кейсы унаследованы/трейтовые, то `executable = [A]`, а B не судится вообще. То есть **подсадка заказчика «второй класс в живом `*Test.php`» краснит только тогда, когда второй класс объявляет кейсы сам** — ровно тот класс «проверка покрывает меньше, чем заявлено», ради которого раунд и делался. Докблок при этом утверждает «The unit judged is the class, not the file».
- **trigger**: воспроизводится в нормальной работе — первый же абстрактный базовый тест-класс или трейт с кейсами. Живого инстанса в дереве сейчас нет (поэтому `composer check` зелёный), обе идиомы штатные для PHPUnit
- **in_scope**: да — `executableClasses`, `isExecutableClass()`, `isExecutableMethod()` и ветка `executable === []` целиком внесены этим диффом
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:362-378`, `governance/TestSuiteHygiene/TestTree.php:287-300`
- **evidence**:
  ```php
  // TestFilesAreExecutedTest.php:369-378 — модель раньше измерения
  if ($declared['executable'] === []) {
      $unreachable[] = \sprintf(
          '%s declares %s, and PHPUnit would run no case in this file.'
          . ' Its directory is not the suspect: give the file a case, or delete it',
  ```
  Пробы (создаются вне дерева; запускать из корня репозитория). Блок отступлён на два пробела ради markdown — при копировании отступ снять, иначе файлы начнутся с пробелов перед `<?php`:
  ```bash
  D=$(mktemp -d)
  cat > "$D/InheritedCaseTest.php" <<'EOF'
  <?php
  declare(strict_types=1);
  namespace Probe;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;
  abstract class AbstractProbeTestCase extends TestCase
  {
      #[Test]
      public function itRunsFromTheBase(): void { self::assertTrue(true); }
  }
  final class InheritedCaseTest extends AbstractProbeTestCase
  {
  }
  EOF
  cat > "$D/TraitCaseTest.php" <<'EOF'
  <?php
  declare(strict_types=1);
  namespace Probe;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;
  trait ProbeCases
  {
      #[Test]
      public function itRunsFromTheTrait(): void { self::assertTrue(true); }
  }
  final class TraitCaseTest extends TestCase
  {
      use ProbeCases;
  }
  EOF
  vendor/bin/phpunit --no-configuration --do-not-cache-result --list-tests "$D/InheritedCaseTest.php"
  #  - Probe\InheritedCaseTest::itRunsFromTheBase
  vendor/bin/phpunit --no-configuration --do-not-cache-result --list-tests "$D/TraitCaseTest.php"
  #  - Probe\TraitCaseTest::itRunsFromTheTrait

  D="$D" php -r 'require "vendor/autoload.php";
    foreach (["InheritedCaseTest.php", "TraitCaseTest.php"] as $f) {
      $d = Qualimetrix\Governance\TestSuiteHygiene\TestTree::parseDeclarations($f, file_get_contents(getenv("D") . "/" . $f));
      echo $f, " classes=", json_encode($d["classes"]), " executable=", json_encode($d["executableClasses"]), PHP_EOL;
    }'
  # InheritedCaseTest.php classes=["Probe\\AbstractProbeTestCase","Probe\\InheritedCaseTest"] executable=[]
  # TraitCaseTest.php     classes=["Probe\\ProbeCases","Probe\\TraitCaseTest"]                executable=[]
  ```
  Сквозной отказ через саму `unreachableIn()` на классе, который PHPUnit перечисляет:
  ```
  php -d error_reporting=0 -r 'require "vendor/autoload.php";
    $m = new ReflectionMethod(Qualimetrix\Governance\TestSuiteHygiene\TestFilesAreExecutedTest::class, "unreachableIn");
    var_export($m->invoke(null,
      ["tests/Probe/InheritedCaseTest.php" => ["classes"=>["Probe\\AbstractProbeTestCase","Probe\\InheritedCaseTest"],"executable"=>[]]],
      ["Probe\\InheritedCaseTest"]));'
  # => "tests/Probe/InheritedCaseTest.php declares Probe\AbstractProbeTestCase, Probe\InheritedCaseTest,
  #     and PHPUnit would run no case in this file. Its directory is not the suspect:
  #     give the file a case, or delete it"
  ```
  Источник правила PHPUnit: `vendor/phpunit/phpunit/src/Util/Reflection.php:61-110` (`filterAndSortMethods` пропускает только `TestCase::class` и `Assert::class`), `vendor/phpunit/phpunit/src/Framework/TestSuite.php:104`.
- **verification**: confirmed
- **verification_note**: обе половины измерены, не выведены. Громкая половина fail-loud — дефект дерева не проскочит; проскочит разработчик, которому отказ предписал неверное лечение. Тихая половина — настоящее ложно-зелёное и она сужает смысл подсадки, на которой раунд фиксов заявлен доказанным
- **fix_direction**: **эффект (а)** закрывается переворотом порядка — сначала достижимость (`in_array($class, $reachable)`), и только для неперечисленного класса привлекать модель исполняемости, чтобы отличить «должен был бежать» от фикстуры; перечисленный класс отвечает за себя сам, иерархию моделировать не нужно. Отдельно сохранить файловый отказ «ни один класс файла не перечислен И ни один не исполняем» — иначе теряется кейс `EmptyTest.php`, который контроль уже проверяет. **Эффект (б) переворотом НЕ закрывается**: неперечисленный класс с унаследованными/трейтовыми кейсами и после него попадёт в «не исполняем → фикстура» и промолчит, а отличить его от настоящей фикстуры (`AlphaParticipant` и соседи в `FileSetInspectionParticipantCompilerPassTest.php`) без резолва родителя нельзя — резолв же невозможен ровно по причине из докблока: такой класс не автолоадится. Это остаточная дыра, и решение по ней нужно принять явно: либо резолвить наследование/трейты только для *неперечисленных* классов (узкое множество, автолоадер не нужен — достаточно разобрать `extends`/`use` по тому же корпусу), либо сузить обещание — в докблоке («The unit judged is the class», «which of those PHPUnit would run») и в описании подсадки в плане, где сейчас сказано «второй тест-класс в живом `*Test.php` краснит», без оговорки «если объявляет кейсы сам»

### comprehensive-round2-02

- **reviewer**: comprehensive-round2
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Контроль G1 потерял кейс «приватный хелпер молчит» переименованием фикстуры, а новое правило этот кейс отказывает с мисбранчем
- **mechanism**: до диффа `violationsIn()` отбрасывала непубличные методы, и фикстура несла `private function itIsAPrivateHelper(): void {}` как доказательство молчания. Дифф снял фильтр по видимости и **одновременно переименовал эту строку фикстуры** в `private function collectFixtures(): void {}`. В результате: (1) контроль больше не проверяет ни одного непубличного метода, названного `itXxx`, — потеря кейса невидима, ожидаемый массив просто не содержит про него строки; (2) новое правило такой метод отказывает, потому что ветка `named && !attributed` стоит первой и срабатывает независимо от видимости; (3) текст отказа предписывает добавить `#[Test]`, после чего метод упрётся во вторую ветку «reads as a case but is not public» — двухшаговая диагностика на одном и том же методе; (4) докблок утверждает «A method that is neither named nor attributed as a test is a helper, and helpers are not this guard's business at any visibility», но приватный хелпер, *названный* `itXxx`, под эту фразу не подпадает и теперь запрещён — решение принято молча, переименованием фикстуры.
- **trigger**: воспроизводится в нормальной работе — любой приватный/protected хелпер с именем `itXxx` в тестовом файле
- **in_scope**: да — снятие фильтра видимости, `complaintAbout()` и правка фикстуры внесены этим диффом
- **anchor**: `governance/TestSuiteHygiene/TestMethodsAreReachableTest.php:107-109`, `governance/TestSuiteHygiene/TestMethodsAreReachableTest.php:218-241`
- **evidence**:
  ```
  git diff 5c660f16..HEAD -- governance/TestSuiteHygiene/TestMethodsAreReachableTest.php
  # -                private function itIsAPrivateHelper(): void
  # +                private function collectFixtures(): void
  ```
  Поведение нового правила на том, что фикстура перестала проверять:
  ```
  php -r 'require "vendor/autoload.php";
    $m = new ReflectionMethod(Qualimetrix\Governance\TestSuiteHygiene\TestMethodsAreReachableTest::class, "violationsIn");
    var_export($m->invoke(null, "probe/ProbeTest.php", "<?php namespace Acme; final class ProbeTest {
      private function itIsAPrivateHelper(): void {} protected function itAlsoHelps(): void {} }"));'
  # probe/ProbeTest.php:2 Acme\ProbeTest::itIsAPrivateHelper() is named itXxx but carries no #[Test] attribute, so PHPUnit never calls it
  # probe/ProbeTest.php:2 Acme\ProbeTest::itAlsoHelps()        is named itXxx but carries no #[Test] attribute, so PHPUnit never calls it
  ```
- **verification**: confirmed
- **verification_note**: измерено вызовом самой `violationsIn()`; потеря кейса читается прямо из диффа фикстуры
- **fix_direction**: решить явно, запрещён ли непубличный `itXxx`-хелпер, и записать решение в докблок, а не в фикстуру. Если запрещён — вернуть его в фикстуру как *ожидаемое нарушение* со своей формулировкой про видимость (ветка видимости должна стоять раньше ветки атрибута, иначе диагноз ведёт по кругу). Если разрешён — вернуть его в фикстуру как доказательство молчания, которое там и было

### comprehensive-round2-03

- **reviewer**: comprehensive-round2
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Новая сверка сюит читает `phpunit.xml.dist` по константе, а все листинги идут по конфигу, который PHPUnit выбирает сам — и `phpunit.xml` его перебивает
- **mechanism**: `declaredSuites()` парсит файл из константы `CONFIGURATION = 'phpunit.xml.dist'`. При этом ни `reachableCommand()`, ни печатаемый `shard_command()` не передают `--configuration`, поэтому PHPUnit резолвит конфиг сам: `phpunit.xml` → `phpunit.dist.xml` → `phpunit.xml.dist`. Файл `phpunit.xml` числится в `.gitignore` этого репозитория, то есть это ожидаемый локальный артефакт (его пишет, в частности, IDE). При его наличии `itShardsEverySuiteTheConfigurationDeclares` сравнивает имена сюит из одного файла с измерением по другому, а остальные четыре отказа G2 целиком описывают дерево, которое `composer check` в CI не запускает. Дрейф односторонне невидим: в CI `phpunit.xml` нет, значит зелёный CI ничего не говорит о локальном прогоне, и наоборот.
- **trigger**: воспроизводится в нормальной работе — достаточно локального `phpunit.xml`, который репозиторий сам предусмотрел в `.gitignore`
- **in_scope**: да — `CONFIGURATION`, `declaredSuites()` и `itShardsEverySuiteTheConfigurationDeclares()` внесены этим диффом
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:89`, `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:527-555`, `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:562-565`
- **evidence**:
  ```
  sed -n '57,72p' vendor/phpunit/phpunit/src/TextUI/Configuration/Cli/XmlConfigurationFileFinder.php
  #   private function configurationFileInDirectory(string $directory): false|string
  #   {
  #       $candidates = [
  #           $directory . '/phpunit.xml',
  #           $directory . '/phpunit.dist.xml',
  #           $directory . '/phpunit.xml.dist',
  #       ];
  grep -n phpunit .gitignore
  #   16:phpunit.xml
  ```
  Ни в `list_command()`, ни в `shard_command()` нет `--configuration` — прогнано, вывод пуст, exit 1:
  ```
  grep -n -- '--configuration' scripts/phpunit-aggregate.py ; echo "exit=$?"
  # exit=1
  ```
- **verification**: confirmed
- **verification_note**: проверено по исходнику PHPUnit и по `.gitignore`. `phpunit.xml` в проекте намеренно не создавался — ревью не меняет дерево
- **fix_direction**: сделать конфиг частью того же единственного источника, что и остальной argv: раннер передаёт `--configuration` явно, страж берёт путь из напечатанного argv и читает имена сюит из него же. Альтернатива слабее, но честнее текущего: страж отказывает, когда в корне лежит файл, перебивающий `phpunit.xml.dist`

---

## Прочие находки

### comprehensive-round2-04

- **reviewer**: comprehensive-round2
- **severity**: MEDIUM
- **kind**: point
- **domain**: reliability
- **title**: Потолок allow-list считает строки, а не множество нарушений, поэтому обмен «одно починили, одно завели» поглощается повторным derive
- **mechanism**: `derive()` отказывается писать только при `count($violations) > $ceiling` и после успешной записи выставляет `min($ceiling, count($violations))`, то есть ровно текущее число строк. Если в одном изменении одно нарушение исправлено и одно заведено, число строк не растёт: derive пишет новый набор строк с тем же потолком, обе проверки `TestNamespacesFollowTheirPathTest` (пропущенное нарушение / устаревшая строка) сходятся, `count($allowed) <= ceiling()` держится. Дерево получило новое нарушение запуском команды — ровно то, что докблок обещает предотвратить: «a fresh violation cannot be absorbed by re-running the command». Это не регрессия (механизм строго лучше литерала `60` из `claude-06`: чистый рост теперь отказывает), а недовыполненное обещание.
- **trigger**: воспроизводится в нормальной работе — стадии 02–04 плана одновременно переносят пути (легитимная смена ключа строки) и правят неймспейсы, то есть обмен там ожидаем, а не экзотичен
- **in_scope**: да — потолок, `ABOVE_CEILING` и вся ветка внесены этим диффом
- **anchor**: `governance/TestSuiteHygiene/NamespacePathAllowList.php:219-249`, докблок `governance/TestSuiteHygiene/NamespacePathAllowList.php:25-33`
- **evidence**:
  ```php
  // NamespacePathAllowList.php:231-244
  if (\count($violations) > $ceiling) { ... return self::ABOVE_CEILING; }
  if (!self::write(self::render($violations, min($ceiling, \count($violations))))) {
  ```
  Потолок — единственное, что сравнивается, и он скаляр:
  ```
  php -r '$a=require "governance/TestSuiteHygiene/namespace-path-allow-list.php";
          echo "ceiling=",$a["ceiling"]," rows=",count($a["rows"]),"\n";'
  # ceiling=60 rows=60
  ```
  `derive-namespace-path-allow-list.php` намеренно НЕ запускался: он пишет tracked-файл.
- **verification**: confirmed
- **verification_note**: вывод из кода, не из прогона. Запуск derive исключён границами ревью; арифметика `min()` и единственное сравнение с `$ceiling` читаются прямо
- **fix_direction**: ратчет должен считаться по множеству, а не по числу строк: разрешать только те строки, что уже были в tracked-файле, с учётом того, что перенос файла меняет ключ, но сохраняет *значение* объявленного неймспейса, а новое нарушение приносит новое значение. Этот дискриминатор и отличает легитимное переписывание ключа от поглощения нового нарушения

### comprehensive-round2-05

- **reviewer**: comprehensive-round2
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Третья ветка сообщения G2 различает «каталог не зарегистрирован» по соседнему файлу, а не по регистрации, и на одиночном файле предписывает зарегистрировать уже зарегистрированный каталог
- **mechanism**: дискриминатор веток — `$reachedDirectories[\dirname($path)]`, то есть «перечислен ли хоть один класс из *другого файла ровно этого* каталога». Это не то же самое, что «каталог достижим»: `<directory>` в `phpunit.xml.dist` рекурсивен, а каталог может содержать один файл. Отсюда два реальных входа, попадающих в ветку C с неверным лечением «Register the directory in phpunit.xml.dist»: (1) зарегистрированный каталог с единственным файлом, классы которого PHPUnit не берёт (измеренная планом форма «AlphaTest+BetaTest в `ProbeTest.php` → не бежит ничего»); (2) новый подкаталог под уже зарегистрированным родителем — он достижим рекурсивно, регистрировать нечего. Отдельно проверено, что до сообщения дело вообще доходит: такой файл не роняет листинг.
- **trigger**: воспроизводится в нормальной работе — оба входа возникают при создании каталогов, а стадия 04 плана создаёт 61 каталог
- **in_scope**: да — три ветки и `reachedDirectories` внесены этим диффом
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:354-359`, `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:400-417`
- **evidence**:
  ```php
  // TestFilesAreExecutedTest.php:354-359
  foreach ($declarations as $path => $declared) {
      if (array_intersect($declared['classes'], $reachable) !== []) {
          $reachedDirectories[\dirname($path)] = true;
  ```
  Листинг переживает файл с «чужими» классами — exit 0, пустой stderr, классы просто отсутствуют, то есть `runCommand()` не бросает и ветвление действительно выполняется. Блок отступлён на два пробела ради markdown — при копировании отступ снять:
  ```bash
  D=$(mktemp -d)
  cat > "$D/GoodTest.php" <<'EOF'
  <?php
  declare(strict_types=1);
  namespace DirProbe;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;
  final class GoodTest extends TestCase { #[Test] public function itRuns(): void { self::assertTrue(true); } }
  EOF
  cat > "$D/MisnamedTest.php" <<'EOF'
  <?php
  declare(strict_types=1);
  namespace DirProbe;
  use PHPUnit\Framework\Attributes\Test;
  use PHPUnit\Framework\TestCase;
  final class AlphaTest extends TestCase { #[Test] public function itAlpha(): void { self::assertTrue(true); } }
  final class BetaTest extends TestCase { #[Test] public function itBeta(): void { self::assertTrue(true); } }
  EOF
  vendor/bin/phpunit --no-configuration --do-not-cache-result --list-tests "$D" ; echo "exit=$?"
  # Available test:
  #  - DirProbe\GoodTest::itRuns
  # exit=0, stderr пуст; AlphaTest и BetaTest отсутствуют в листинге и нигде не упомянуты
  ```
- **verification**: confirmed
- **verification_note**: отдельно проверено, не падает ли весь G2 одной строкой вместо диагноза (план сообщает про форму «AlphaTest+BetaTest» ошибку «Class ProbeTest cannot be found»): нет — exit 0 и пустой stderr, `runCommand()` такой листинг пропускает. Значит дефект именно в выборе ветки, а не в отсутствии диагноза
- **fix_direction**: дискриминатор «зарегистрирован ли каталог» брать из достижимости самого каталога, а не из соседнего файла: достижимость каталога измерима теми же двумя листингами по любому файлу под ним, включая предков; либо перестать называть регистрацию лечением в этой ветке и ограничиться фактом «ни один класс этого файла не перечислен»

### comprehensive-round2-06

- **reviewer**: comprehensive-round2
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: Разность двух листингов не утверждает, что исполняемое — подмножество достижимого, и аргумент, расширяющий исполняемое, делает оба отказа про группы пустыми
- **mechanism**: `excludedIds()` = `array_diff(reachable, executed)`. Направление не проверяется. `reachableCommand()` — это `[phpunit, --list-tests, --testsuite=X]` без `COMMON_ARGUMENTS`, `executedCommand()` — напечатанный argv плюс `--list-tests`. Сегодня второе сужает первое, и разность осмысленна. Аргумент, который расширит исполняемый набор (например явный `--configuration` на другой файл, добавленный в `COMMON_ARGUMENTS`), даст `executed ⊄ reachable`: лишние идентификаторы просто выпадут из `array_diff`, `itNamesEveryCaseTheRunnerExcludesFromCheck` останется зелёным, а вся разница уйдёт в тишину. Одной проверки включения нет.
- **trigger**: только на рукотворном входе — сегодняшний `COMMON_ARGUMENTS` состоит из трёх сужающих флагов; расширяющий аргумент надо внести руками
- **in_scope**: да — `excludedIds()`/`reachableCommand()`/`executedCommand()` переписаны этим диффом
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:452-456`, `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:562-580`
- **evidence**:
  ```php
  // TestFilesAreExecutedTest.php:453-456
  private static function excludedIds(): array
  {
      return array_values(array_diff(self::reachableIds(), self::idsAcrossSuites(self::executedCommand(...))));
  }
  ```
- **verification**: confirmed
- **verification_note**: дефект самой арифметики подтверждён чтением; достижимость триггера — гипотеза, конкретного расширяющего аргумента в `COMMON_ARGUMENTS` сегодня нет
- **fix_direction**: утверждать включение явно — исполняемый набор не может содержать идентификатор, которого нет в достижимом; несимметричная разность без этого утверждения не измеряет то, что заявлено

### comprehensive-round2-07

- **reviewer**: comprehensive-round2
- **severity**: LOW
- **kind**: point
- **domain**: reliability
- **title**: `derive()` читает потолок внутри `try`, поэтому битый tracked-файл отчитывается как «замер не удался»
- **mechanism**: `self::ceiling()` вызван первой строкой внутри `try`, и `catch (Throwable)` печатает «The scan this list would be measured from failed». `ceiling()` бросает `LogicException` при tracked-файле неверной формы — то есть единственная ошибка, которую пользователь может починить одним движением (форма файла), рапортуется под кодом и текстом другой ошибки (`MEASUREMENT_FAILED`, замер дерева).
- **trigger**: воспроизводится в нормальной работе — ручная правка `namespace-path-allow-list.php` (например, поднятие потолка, которое дизайн прямо предусматривает) с опечаткой в форме
- **in_scope**: да — строка внесена этим диффом
- **anchor**: `governance/TestSuiteHygiene/NamespacePathAllowList.php:219-229`
- **evidence**:
  ```php
  try {
      $ceiling = self::ceiling();
      $violations = self::measure();
  } catch (Throwable $error) {
      fwrite(\STDERR, 'The scan this list would be measured from failed, so nothing was written: '
  ```
- **verification**: confirmed
- **verification_note**: чтение кода; derive не запускался (пишет tracked-файл)
- **fix_direction**: читать потолок до `try` либо отказывать на форме tracked-файла отдельным кодом и текстом — две разные причины не должны делить один exit-код

### comprehensive-round2-08

- **reviewer**: comprehensive-round2
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: Ветка `resolvedName` в `carriesTestAttribute()` мертва, и фикстура с алиасом доказывает не её, а fallback
- **mechanism**: `NodeTraverser` получает `new NameResolver()` с настройками по умолчанию, то есть `replaceNodes: true`: резолвер **заменяет** узел имени на `FullyQualified` и атрибут `resolvedName` не выставляет. Поэтому `$attribute->name->getAttribute('resolvedName')` всегда `null`, и совпадение всегда происходит по fallback-ветке `$attribute->name->toString()`. Фикстура G1 с `use ... Test as RenamedAttribute` зелёная именно благодаря fallback. Сама по себе проблема безобидна, но ветка читается как страховка, которой нет, а при переводе резолвера в `replaceNodes: false` зелёной останется ровно она — а сломается всё остальное сравнение имён в группе.
- **trigger**: недостижим сегодня — обе ветки дают один и тот же ответ
- **in_scope**: да — метод перенесён в `TestTree` этим диффом
- **anchor**: `governance/TestSuiteHygiene/TestTree.php:322-335`
- **evidence**:
  ```
  php -r 'require "vendor/autoload.php"; /* NameResolver по умолчанию, атрибут под алиасом */'
  # class-of-name=PhpParser\Node\Name\FullyQualified toString=PHPUnit\Framework\Attributes\Test resolvedName=NULL
  ```
- **verification**: confirmed
- **verification_note**: измерено на том же коде, что в фикстуре G1
- **fix_direction**: оставить один путь — либо явно включить сохранение исходных имён у резолвера и читать `resolvedName`, либо убрать мёртвую ветку и прямо сказать в докблоке, что имя уже разрешено резолвером

### comprehensive-round2-09

- **reviewer**: comprehensive-round2
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: Новая ветка legacy-префикса применяет правило `TestCase` ко всем class-like файла, включая фикстуры и трейты, и ловит метод, чьё имя просто начинается на `test`
- **mechanism**: `carriesLegacyTestPrefix()` — это `str_starts_with($name, 'test')`, то же правило, что у PHPUnit. Но PHPUnit применяет его только к классу, который он грузит из файла и который наследует `TestCase`, а G1 применяет ко всем классам и трейтам в любом `*Test.php`. В дереве такие файлы есть: `tests/Infrastructure/Unit/FileSetInspectionParticipantCompilerPassTest.php` несёт восемь вспомогательных классов помимо тест-класса, несколько `*Test.php` объявляют трейты. Метод-хелпер в такой фикстуре с именем вида `testable()` получит отказ «PHPUnit runs it under the legacy prefix», хотя PHPUnit этот класс не исполняет вовсе.
- **trigger**: только на рукотворном входе — сегодня таких имён в дереве нет (`composer check` зелёный)
- **in_scope**: да — ветка legacy-префикса внесена этим диффом
- **anchor**: `governance/TestSuiteHygiene/TestMethodsAreReachableTest.php:231-234`, `governance/TestSuiteHygiene/TestTree.php:317-320`
- **evidence**:
  ```
  php -r '... violationsIn("probe/ProbeTest.php", "<?php namespace Acme; final class ProbeTest {
      public function testable(): void {} }")'
  # probe/ProbeTest.php:2 Acme\ProbeTest::testable() is public and named test..., so PHPUnit runs it
  #   under the legacy prefix instead of the itXxx convention
  ```
- **verification**: confirmed
- **verification_note**: измерено вызовом `violationsIn()`; ложное срабатывание громкое, тихого режима отказа здесь нет
- **fix_direction**: сузить область правила до классов, которые PHPUnit действительно исполняет, — тот же признак, который решает `comprehensive-round2-01`, годится и здесь; либо явно записать в докблоке, что правило применяется ко всем class-like файла осознанно, и что цена — ложное срабатывание на фикстуре

---

## Coverage — проверено и признано чистым

Проверено, находок нет:

- **`--print-commands`, все ветки отказа.** Ненулевой код, непустой stderr, не-JSON, `{}`, `{"commands":{}}`, не-строковый аргумент, отсутствующий ключ `phpunit` — каждый случай даёт `LogicException` в `aggregate()` (`TestFilesAreExecutedTest.php:593-631`) или в `runCommand()` (`:705-710`). «Исключений нет» из сбоя вывести нельзя. Отказ `--print-commands` без `--cache-root` — exit 2, покрыт python-тестом.
- **Печать против запуска.** Обе стороны идут через `shard_command()`, python-тест шпионит за функцией и дополнительно сверяет напечатанный argv с тем, что получил `Popen` (`tests/System/TestRunnerConfiguration/Tests/test_phpunit_aggregate.py`, `test_printed_commands_are_built_by_the_function_that_starts_a_shard`). Расхождения не нашёл. Отдельно отмечаю без статуса находки: в скрипте остались **два** построителя команд — `list_command()` и `shard_command()`; страж измеряет второй, партиционное доказательство раннера — первый.
- **Пустой листинг и форма заголовка.** `parseListing()` требует ровно один заголовок, принимает и `Available tests:`, и единственное число `Available test:` (обе формы наблюдал в пробах), и бросает на нулевом числе идентификаторов. Предупреждение PHPUnit «No tests found in class» под `--list-tests` не печатается вовсе — проверено пробой: exit 0, чистый stdout, пустой stderr.
- **Оба написания датасета.** В живом листинге `--testsuite=Unit` присутствуют обе формы вплотную к имени метода: `...::itRejectsARemovedPerDiagnosticSeverityKey#0` (194 строки) и `...::itPicksTheBoundary...WidenTowards"higher: equal"` (1771 строка). `withoutDataSet()` режет по первому из маркеров и обе формы обрабатывает верно — `codex-09` вылечен, и фикстура проверяет форму, которая действительно существует.
- **`--cache-root` как новая поверхность.** Страж создаёт scratch-корень сам (`sys_get_temp_dir()` + 6 случайных байт); при неудаче `mkdir` — `LogicException` (`TestFilesAreExecutedTest.php:637-649`). Подкаталог сюиты под ним создаёт уже PHPUnit по `--cache-directory`; если он не создастся или окажется недоступен на запись, PHPUnit вернёт ненулевой код и `runCommand()` откажет. Корень снимается в `tearDownAfterClass()`. Прочитано как «исключений нет» это не будет ни в одной ветке. Отдельно: `printable_commands()` существование `--cache-root` не проверяет — печать это просто строки, и для стража это безвредно, потому что каталог он создаёт сам.
- **Может ли потолок allow-list вырасти незаметно.** Нет: `derive()` пишет `min($ceiling, count($violations))`, то есть после успешного запуска потолок всегда равен текущему числу строк и только убывает. Вырасти он может единственным способом — рукописной правкой одного числа в tracked-файле, и она видна в диффе. Рукописно добавленная строка не проходит: неверная строка отказывается как устаревшая. Обещание «рост только руками» выполняется; невыполненным осталось другое обещание — см. `comprehensive-round2-04`.
- **Атомарная запись derive (`codex-07`).** Временный файл в том же каталоге, что цель (значит `rename` атомарен на одной ФС), сверка возврата `file_put_contents` с длиной, `@unlink` на обоих путях сбоя, `rename` как публикация. Вылечено. Остаточный `*.tmp.PID` при убийстве процесса между записью и переименованием не попадает ни в один корпус (не `*Test.php`).
- **Предикат против настоящих правил PHPUnit 12.5.25.** Аннотация `@test` не читается: `Registry::build()` собирает `new CachingParser(new AttributeParser)` — парсера докблоков нет, так что предикат прав, отказавшись её знать. `#[Test]` на абстрактном методе: обе стороны не исполняют. Статический метод: `Util\Test::isTestMethod()` статику не отбрасывает, и предикат тоже — стороны согласны. `#[DataProvider]` без `#[Test]`: хелпер с обеих сторон. Атрибут под алиасом импорта: резолвится (см. `comprehensive-round2-08` про то, какой именно веткой).
- **«PHPUnit исполняет не каждый класс файла».** Посылка, на которой стоит весь переход к суждению по классу, перепроверена: файл `TwoClassTest.php` с двумя `TestCase`-классами даёт в листинге только `Probe\TwoClassTest::itRunsFirst`. Посылка держится; правило выбора класса в диффе нигде не утверждается — это верно.
- **Дедупликация вложенных PSR-4 корней** (`claude-08`): `testFiles()` кладёт пути в ключи массива. Вылечено. Третий корень `tools/phpstan/` реально сканируется и `*Test.php` в нём сейчас нет, то есть он не подпирает и не сдвигает полы.
- **Отказ на объявленном, но отсутствующем корне** (`codex-04`): `autoloadDevRoots()` бросает с диагнозом вместо пропуска. Вылечено.
- **Полы G2/G1** (`>500` файлов, `>600` классов, `>5000` идентификаторов, непустой листинг на каждую сюиту, оба именованных корня): на месте, вакуумно-зелёным прогон не станет.
- **Парсинг `phpunit.xml.dist` в `declaredSuites()`**: отказ на нечитаемом XML, на сюите без имени, на нуле сюит. Чисто — с оговоркой `comprehensive-round2-03` про то, какой файл читается.
- **Проза диффа.** Утверждения о том, как PHPUnit выбирает класс из файла, из докблоков действительно убраны; `AGENTS.md` получил таблицу адресов регистрации корня, на которую план ссылается вместо своего списка, и таблица помечена как подлежащая переизмерению. Числа `60/146/147/149` из докблоков убраны. Остаточные обещания, которые код не выполняет, вынесены в находки 01 («which of those PHPUnit would run», «The unit judged is the class») и 04 («a fresh violation cannot be absorbed by re-running the command»).
- **Сгенерированные артефакты** диффа (`test-phpunit-discovery.txt`, `test-phpunit-suites.txt`, `test-topology.tsv`, `test-ownership.tsv`) согласованы между собой: 6→7 кейсов у `TestFilesAreExecutedTest`, Governance 13→14, `phpunit_ids` 9195→9196. Чисто.

## Чего я не покрыл

- Полный `composer check` не запускал — измерял только шард `Governance` (10 s, зелёный) и точечные листинги. Утверждения о прочих группах проверки не делаю.
- `python3 -m unittest` по `tests/System/TestRunnerConfiguration/Tests/` не запускал; python-тесты читал, но их зелёность не измерял.
- Подсадки заказчика (второй класс в живом `*Test.php`, метод переименован в `testXxx`) не воспроизводил — это правка дерева. Вывод о том, что первая из них краснит уже, а её унаследованный вариант — нет (`comprehensive-round2-01`, эффект «б»), получен из кода и из пробы `unreachableIn()`, не из подсадки.
- `derive-namespace-path-allow-list.php` не запускал ни в каком виде — команда пишет tracked-файл. Находка 04 выведена из кода.
- Локальный `phpunit.xml` не создавал — вывод в находке 03 сделан по исходнику PHPUnit и `.gitignore`.
- Таблицу адресов регистрации корня в `AGENTS.md` прочитал, но не переизмерял против дерева — сама таблица предписывает это делать; строки «loudly/silently» я не проверял.
- Производительность стражей (G2 запускает 10 листингов PHPUnit плюс печать команд) не измерял отдельно от общих 10 s шарда.
- Мёртвая регулярка `classifyOwner()`, `scripts/` мимо pre-commit-хука, неопустошённый allow-list G3, `.idea/phpunit.xml`, потеря видимости инертной новой `--exclude-group`, число `110` в прозе — исключены заданием, не рассматривал.

## Отклонённые находки (`refuted`)

Нет.
