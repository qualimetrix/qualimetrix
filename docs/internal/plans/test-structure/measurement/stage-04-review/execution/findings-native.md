# Stage 04 — ревью исполнения (native Claude reviewer)

Материал: ветка `x30-stage-04-subject-layout`, `e15c7f42..1338d5fa` (11 коммитов).
Схема находок: `dvizh-vr-review/reference/finding-schema.md`.
Находки упорядочены по убыванию severity. После находок — coverage, затем refuted.

---

## Находки

### claude-01

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Список C принимает случай, который его собственное определение исключает — покрытие класса, которого нет в манифесте
- **mechanism**: `TestSubjectPaths::judge()` перебирает `#[CoversClass]`-заявки и пропускает (`continue`) каждую, для которой `declarationOwners[$class]` не найден. Если ВСЕ заявки файла такие, то `$coveredOwners === []`, ветка `ANOTHER_OWNER` не срабатывает (её условие требует непустого `$coveredOwners`), и управление доходит до финального `return NOT_A_PREFIX` с `detail` = `"… against (nothing the manifest declares)"`. То есть файл попадает в список C (`remainder_is_not_a_prefix`, потолок 4), хотя про его remainder никакого суждения вынесено не было. Поведение зафиксировано пробой как намеренное (`TestPathsNameTheirSubjectTest.php:249-252`, класс `Qualimetrix\Nobody\Stranger`), при этом докблок `SubjectPathExceptions` определяет список C иначе: «the path owner is **among the covered owners** and the path below the level is still not a prefix». Для этого случая владелец пути среди покрытых владельцев не значится — их вообще нет.
- **trigger**: воспроизводится на будущем штатном входе, не на рукотворном: достаточно первого теста, покрывающего класс вне `src/` — из `governance/`, `scripts/`, `tools/` или тестового TestSupport. Сегодня таких нет (измерено: все 759 `#[CoversClass]`-заявок дерева резолвятся в манифест, 0 неизвестных), поэтому дефект латентный.
- **in_scope**: да — обе строки добавлены этим этапом (P5)
- **anchor**: `governance/TestSuiteHygiene/TestSubjectPaths.php:251-293`; определение списка — `governance/TestSuiteHygiene/SubjectPathExceptions.php:25-29`; закрепляющая проба — `governance/TestSuiteHygiene/TestPathsNameTheirSubjectTest.php:249-252`
- **evidence**:
  ```php
  $coveredOwner = $declarationOwners[$class] ?? null;
  if ($coveredOwner === null) {
      continue;                      // заявка молча исчезает
  }
  ...
  return ['verdict' => self::NOT_A_PREFIX, 'detail' => \sprintf(
      '%s against %s',
      self::spell($remainder),
      $subjects === [] ? '(nothing the manifest declares)' : implode(' / ', array_unique($subjects)),
  )];
  ```
- **verification**: confirmed
- **verification_note**: ветка достижима по коду и явно закреплена пробой `itRefusesEachWayOnThePathsItIsGiven`; расхождение с докблоком списка C проверено текстуально. Последствие при срабатывании двойное: (1) съедается бюджет списка C (потолок ровно 4, все 4 строки заняты — т.е. отказ будет немедленным), (2) текст отказа велит «переименовать директорию под предмет или положить тест плоско», тогда как настоящая причина — что покрываемый класс вне манифеста, и ни одно из двух предписаний её не устраняет.
- **fix_direction**: развести две причины на уровне вердикта: «заявки есть, но ни одна не разрешается манифестом» — это отдельное состояние, а не отсутствие префикса. Либо завести ему собственный отказ без списка (как у частей 1 и 2), либо явно отнести к списку A («файл не заявляет предмет, который это правило умеет проверять») и поправить формулировку в докблоке. Заодно решить, считается ли покрытие не-`src/` класса законным вообще.

### claude-02

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Ратчет списка B снимается добавлением одной аннотации, а не переносом файла; потолок при этом опускается навсегда
- **mechanism**: часть 3 правила выполняется, если **хотя бы одна** `#[CoversClass]`-заявка называет владельца пути и её remainder начинается с remainder'а пути. Остальные заявки на вердикт не влияют (`judge()`: `if ($coveredOwner !== $owner) { continue; }`), и это закреплено пробой «One claim naming the path's own owner is enough». Отдельно: для файла, лежащего плоско в `{owner}/{level}/`, `$remainder === []`, а пустой массив — префикс любого remainder'а, поэтому часть 3 для него выполняется автоматически при любой заявке своего владельца. Следствие: файл из списка B (`covers_another_owner`, потолок 19) покидает список, если к нему дописать одну `#[CoversClass]` любого класса своего владельца пути — переносить файл не нужно. `SubjectPathExceptions::derive()` тогда запишет `'ceiling' => min($ceiling, count($rows))`, то есть потолок опустится необратимо (поднять его можно только ручной правкой), и сигнал, ради которого список заведён («adapter-exclusion principle showing through», передаётся этапу 05), исчезнет как «сделанная работа».
- **trigger**: воспроизводится в нормальной работе — это ровно тот путь наименьшего сопротивления, которым разработчик будет закрывать красный контроль: 9 из 19 строк списка B — это `tests/Analysis/Policy/Baseline/Functional/Baseline*CommandTest.php`, покрывающие `Infrastructure\Console`; дописать им `#[CoversClass]` на baseline-класс дешевле, чем перенести.
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/TestSubjectPaths.php:251-273`; потолок-ратчет — `governance/TestSuiteHygiene/SubjectPathExceptions.php:241`; закрепляющая проба — `governance/TestSuiteHygiene/TestPathsNameTheirSubjectTest.php:242-247`
- **evidence**:
  ```php
  $coveredOwners[$coveredOwner] = true;
  if ($coveredOwner !== $owner) {
      continue;
  }
  ...
  $prefix = $prefix || $remainder === \array_slice($subject, 0, \count($remainder));
  ```
  ```php
  $lists[$name] = ['ceiling' => min($ceiling, \count($rows)), 'rows' => $rows];
  ```
- **verification**: confirmed
- **verification_note**: докблок `SubjectPathExceptions` называет дыру «substitution» (retire одного и появление другого в том же списке) и закрывает её тем, что оба отказа сработают до re-derive. Описанный здесь сценарий — другой: он не substitution, а законный с точки зрения контроля выход из списка, после которого re-derive обязан пройти и обязан опустить потолок. Ни один из семи кейсов `TestPathsNameTheirSubjectTest` его не ловит, и поймать по конструкции не может — вердикт файла действительно становится `prefix`.
- **fix_direction**: разделить «часть 3 выполнена» и «файл тестирует своего владельца». Кандидаты: требовать, чтобы владелец пути был у *большинства* (или у всех) заявок, а не у одной; либо вести список B не по вердикту, а по отношению «сколько заявок вне владельца», чтобы добавление одной своей заявки не обнуляло измерение. Минимально — зафиксировать в докблоке, что выход из списка B аннотацией допустим, и что этап 05 адъюдицирует список по своей мерке, а не по остатку.

### claude-03

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: architecture
- **title**: После короткого замыкания по тест-классам в `targetPath()` две префиксные ветви стали недостижимыми, и их недостижимость по построению молчит
- **mechanism**: P0 вставил в `targetPath()` ветку `if (isTestClassPath($path)) { return $path; }` (строка 1277), которая отвечает за все `tests/**/*Test.php` раньше следующего условия. В следующем условии остались два дизъюнкта, чья популяция состояла исключительно из тест-классов: регулярка по `tests/Analysis/Evidence/(CodeSmell|Cohesion|Complexity|Coupling|Design|Maintainability|Security|Size)/` (строка 1280) и `str_starts_with($path, 'tests/Infrastructure/Logging/Unit/')` (строка 1282). Под этими префиксами в дереве нет ни одного файла, который не заканчивается на `Test.php`, поэтому оба дизъюнкта теперь не отвечают ни на что. Докблок `assertPathLiteralsResolve()` прямо выводит литералы `targetPath()` из-под проверки («claims about inputs rather than about the tree»), так что мёртвая ветка здесь не краснеет — это ровно тот механизм, который `04-subject-layout.md` называет причиной переписать `classifyOwner()`: «the ladder is the one place that is not guarded by `assertPathLiteralsResolve()`, so a dead prefix there is silent».
- **trigger**: недостижим сам по себе (мёртвый код не меняет вывод). Достижимое последствие — при следующем переносе: ветка выглядит как действующее решение об удержании на месте и будет прочитана как таковое, хотя удержание на самом деле обеспечивает строка 1277.
- **in_scope**: да — строка 1277, из-за которой они умерли, добавлена в этом диффе (P0), и в этом же диффе соседняя `preg_match('#^tests/Infrastructure/(Unit|Integration)/#')` из того же условия была удалена как мёртвая, а эти две — нет
- **anchor**: `scripts/generate-modular-architecture-test-inventory.php:1277-1287`
- **evidence**:
  ```php
  if (isTestClassPath($path)) {
      return $path;
  }
  if (preg_match('#^tests/Analysis/Evidence/(CodeSmell|Cohesion|Complexity|Coupling|Design|Maintainability|Security|Size)/#', $path) === 1
      || in_array($path, P7_MEASUREMENT_PATHS, true)
      || str_starts_with($path, 'tests/Infrastructure/Logging/Unit/')
  ```
- **verification**: confirmed
- **verification_note**: измерено над живой популяцией генератора (926 строк `test-ownership.tsv`), с исключением tooling-корней и тест-классов, которые отвечают раньше: регулярка по Evidence — 0 достижимых строк, `tests/Infrastructure/Logging/Unit/` — 0 достижимых строк. Для сравнения, оставшиеся дизъюнкты того же условия живы: `P7_MEASUREMENT_PATHS` (фикстуры Measurement), `tests/Analysis/Evidence/ComputedMetrics/` (1 строка — `Health/Unit/MetricRepositoryTestHelper.php`), `P6_D_PRIORITIZATION_TEST_PATHS` (1 support-класс). `git ls-files` под обоими мёртвыми префиксами не даёт ни одного не-`Test.php` файла.
- **fix_direction**: убрать оба дизъюнкта вместе с остальной вычищенной лестницей — их содержание уже обеспечено строкой 1277. Если решено оставить, то не в виде префикса: превратить их в перечисление конкретных путей и завести под `assertPathLiteralsResolve()`, чтобы «ветка перестала что-то значить» перестало быть бесшумным состоянием.

### claude-04

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: architecture
- **title**: Инвентарь по-прежнему предписывает перенос фикстуры в корень `tests/Reporting/Sarif/`, который не является владельцем манифеста
- **mechanism**: `classifyOwner()` отдаёт для `tests/Fixtures/Schema/` владельца `Reporting/Sarif` (строка 907-909), а `targetPath()` для `kind === 'fixture'` собирает цель как `'tests/' . $owner . '/Fixtures/' . fixtureTail($path)`. В сгенерированном `test-ownership.tsv` это даёт строку `tests/Fixtures/Schema/sarif-2.1.0.schema.json → tests/Reporting/Sarif/Fixtures/Schema/sarif-2.1.0.schema.json`, `subject_owner = Reporting/Sarif`. `Reporting.Sarif` в манифесте нет: `Reporting` — один владелец, и это прямо оговорено в `04-subject-layout.md` («`Reporting` is a single manifest owner»). Это ровно та словарная ошибка, которой этот же документ обосновывает недоверие к прежней карте: «the `target_path` column of the generated `test-ownership.tsv`, whose owner vocabulary includes `Infrastructure`, `Reporting`, `Core/Neutral` and `Infrastructure/GitHook` — none of which is a manifest owner». Для тест-классов она вылечена разбором пути; для фикстур осталась. Дополнительно этап удалил сам корень `tests/Reporting/Formatter/Sarif/` и перенёс единственного потребителя фикстуры в `tests/Reporting/Unit/Formatter/Sarif/SarifSchemaValidationTest.php`, так что предписание теперь указывает в корень, которого этап только что лишился.
- **trigger**: недостижим в текущем прогоне (строка — предписание, никто его не исполняет). Достижимо при исполнении: следующий этап, читающий колонку `target_path`, создаст `tests/Reporting/Sarif/`.
- **in_scope**: нет — сама строка 907-909 в дифф не попала; противоречие создано соседними правками этого диффа (удалением `tests/Reporting/Formatter/Sarif/` и переписыванием владельческого словаря для тест-классов)
- **anchor**: `scripts/generate-modular-architecture-test-inventory.php:907-909`; следствие — строка `tests/Fixtures/Schema/sarif-2.1.0.schema.json` в `docs/internal/generated/modular-architecture/test-ownership.tsv`
- **evidence**:
  ```php
  if (str_starts_with($path, 'tests/Fixtures/Schema/')) {
      return ['Reporting/Sarif', 'permanent'];
  }
  ```
  Потребитель: `tests/Reporting/Unit/Formatter/Sarif/SarifSchemaValidationTest.php:34` — `__DIR__ . '/../../../../Fixtures/Schema/sarif-2.1.0.schema.json'`.
- **verification**: confirmed
- **verification_note**: сверено со списком 37 владельцев в `docs/internal/modular-architecture-manifest.json` — `Reporting.Sarif` отсутствует. Полный список не-манифестных значений `subject_owner` в артефакте: `Architecture.Governance` (132, намеренно, `TOOLING_TEST_ROOT_OWNERS`), `Tooling/*` (намеренно), `TestSupport/Logging` (1), `Reporting/HtmlTemplate` (10, JS-тесты в `src/`) и `Reporting/Sarif` (1). Первые две группы — осознанный словарь tooling-корней; последние три — остатки старой лестницы, и `Reporting/Sarif` из них единственный, чей корень этап только что удалил.
- **fix_direction**: привести владельца фикстуры к манифестному (`Reporting`), чтобы цель стала `tests/Reporting/Fixtures/Schema/...`, либо признать фикстуры отдельной популяцией с собственным, явно названным словарём — но тогда сказать это в докблоке `classifyOwner()`, а не оставлять читателю вывод «владельцы у фикстур и у тест-классов из разных множеств». Заодно перепроверить `TestSupport/Logging` и `Reporting/HtmlTemplate` тем же вопросом.

### claude-05

- **reviewer**: claude
- **severity**: LOW
- **kind**: contract
- **domain**: tests
- **title**: Граница популяции инвариантного контроля объявлена одним механизмом, а реализована другим, и нигде не утверждается
- **mechanism**: докблок `TestSubjectPaths` и `04-subject-layout.md` утверждают: «Population is `tests/**/*Test.php` … Support and fixture files **have no level segment** and are excluded deliberately». Фактический механизм исключения — не отсутствие level-сегмента, а предикат имени файла: `population()` → `TestTree::testFilesIn('tests')` → `filesIn(…, fn($name) => str_ends_with($name, 'Test.php'))`, обход `RecursiveDirectoryIterator` по диску. Два следствия расходятся с объявленным механизмом: (1) support-файл, лежащий под level-директорией и названный `*Test.php`, **судится** — в дереве таких четыре (`tests/Reporting/Unit/Formatter/Support/*Test.php`), и они проходят только потому, что на самом деле являются тест-классами, а `Support` здесь — сегмент предмета, а не бакет; (2) настоящий тест-класс, чей файл назван не `*Test.php`, не попадает в популяцию вообще, и это не утверждается ничем. Само утверждение границы в контроле отсутствует: `assertGreaterThan(500, count($population))` при 616 файлах оставляет запас в 116, а `assertSame($population, array_keys($judged))` тавтологично — `$judged` строится из `population()`.
- **trigger**: воспроизводится в нормальной работе только как ослабление контроля, не как ложный отказ. Отдельный достижимый эффект: популяция читает диск, а генератор — индекс git (`git ls-files --cached --others --exclude-standard`), поэтому неотслеживаемый черновой `*Test.php` под `tests/` краснит governance-контроль и не попадает в инвентарь.
- **in_scope**: да — обе цитаты и обе ассерции добавлены P5
- **anchor**: `governance/TestSuiteHygiene/TestSubjectPaths.php:40-43,147-150`; ассерции — `governance/TestSuiteHygiene/TestPathsNameTheirSubjectTest.php:294-297`; предикат — `governance/TestSuiteHygiene/TestTree.php:194-198`
- **evidence**:
  ```php
  public static function population(): array
  {
      return TestTree::testFilesIn(self::ROOT);
  }
  ```
  ```php
  self::assertGreaterThan(500, \count($population));
  self::assertSame($population, array_keys($judged));
  ```
- **verification**: confirmed
- **verification_note**: измерено: `find tests -name '*Test.php' | wc -l` = 616, ровно столько судит `measure()`; из них 4 лежат под сегментом `Support/` и все четыре — настоящие тест-классы (`classifyKind()` подтверждает: `phpunit-test-class`). Компенсация есть, но в другом месте и по другой популяции: `validateInventory()` в генераторе падает на `*Test.php`, который PHPUnit не обнаружил, и на тест-классе с `current_suite = none`; `TestFilesAreExecutedTest` ловит класс, который не гоняет ни один suite. То есть «популяция = то, что PHPUnit действительно исполняет» утверждается, но не этим контролем.
- **fix_direction**: либо переписать докблок под фактический механизм (предикат имени файла по диску), либо добавить в контроль утверждение границы: сверку популяции с множеством файлов, которые PHPUnit обнаруживает, или хотя бы отказ на `tests/**/*Test.php`, который не является тест-классом. Пол в 500 при 616 стоит либо заменить на сверку с независимым источником, либо поднять до величины, при которой молчаливая потеря файлов невозможна.

### claude-06

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: Обратная половина `assertSuiteClassifierAgreesWithPhpunit()` обходит только табличные литералы, а две регулярки `currentSuite()` остаются без проверки в эту сторону
- **mechanism**: функция объявляет симметрию: «a phpunit.xml.dist `<directory>` currentSuite() cannot place under the same name (forward) … and a `testSuitePrefixTable()` literal with no matching `<directory>` (backward)». Обратный обход идёт по `testSuitePrefixTable()`, а `currentSuite()` отвечает не только по этой таблице: над ней стоят два `preg_match` (строки 1101 и 1104), покрывающие `tests/Analysis/Evidence/(CodeSmell|Cohesion|Complexity|Coupling|Design|Maintainability|Security|Size)/Unit/` и `…/(CodeSmell|Complexity|Coupling)/Integration/`. Эти префиксы в таблице отсутствуют, поэтому одиннадцать `<directory>`-записей `phpunit.xml.dist` проверяются только в прямую сторону: удаление такой записи из конфига генератор не заметит — классификатор по-прежнему вернёт `Unit`/`Integration`, проверка `current_suite === 'none'` не сработает, и тесты тихо перестанут исполняться.
- **trigger**: воспроизводится на штатной правке `phpunit.xml.dist` (удаление или переименование одной из одиннадцати директорий), не требует рукотворного входа
- **in_scope**: нет — обе регулярки и обе половины проверки существовали до этапа; этап правил обе половины (таблицу и конфиг) и опирался на заявленную симметрию
- **anchor**: `scripts/generate-modular-architecture-test-inventory.php:1151-1162` (обратный обход) против `scripts/generate-modular-architecture-test-inventory.php:1101-1106` (регулярки)
- **evidence**:
  ```php
  foreach (testSuitePrefixTable() as $entry) {
      $literal = rtrim($entry['prefix'], '/');
      if (!in_array($literal, $declaredDirectories[$entry['suite']] ?? [], true)) {
  ```
- **verification**: confirmed
- **verification_note**: последствие компенсировано другим контролем — `TestFilesAreExecutedTest::itExecutesEveryTestClassTheTreeDeclares` отказывает на классе, который не гоняет ни один объявленный suite, и он входит в `composer check`. Поэтому severity низкая: молчит генератор, а не весь репозиторий. Сегодня рассинхрона нет: собственная сверка (все директории `tests/`, содержащие `*Test.php`, против `<directory>`-записей) даёт 0 непокрытых директорий и 0 объявленных директорий без тестов.
- **fix_direction**: либо перенести две регулярки в `testSuitePrefixTable()` как явные префиксы (их восемь и три соответственно — перечислимо), либо расширить обратный обход так, чтобы он шёл от объявленных директорий к тому, чем именно `currentSuite()` их классифицировал, и падал на директории, которую ни один *табличный* литерал не покрывает. Формулировку докблока про симметрию стоит сузить до того, что реально проверяется.

### claude-07

- **reviewer**: claude
- **severity**: LOW
- **kind**: contract
- **domain**: tests
- **title**: Два канала свипа не выполняются ни одним отслеживаемым инструментом, а девять известных «висячих» имён нигде не закреплены
- **mechanism**: набор инструментов этапа покрывает: (а) объявленное полное имя перемещённого класса в двух написаниях, один и два обратных слэша — `move-oracle.py` арм 6; (б) любое имя вида `Qualimetrix\Tests\…`, не объявленное ни одним файлом и не имеющее объявлений под собой — `dangling-test-names.py`; (в) существование каждой `<directory>` в индексе git — `move-oracle.py` арм 7; (г) существование путей в замыканиях генератора — `assertPathLiteralsResolve()`. Вне покрытия остаются два канала: **короткое имя без FQN** (например `{@see UnmatchedExcludeIntegrationTest}` или упоминание в прозе — префикс `Qualimetrix\Tests\` там не пишется, а объявленного FQN в строке нет) и **путевой литерал** (`tests/Unit/…` в workflow, `.gitattributes`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `.githooks/pre-commit`, `.dockerignore`) — DoD этапа сознательно отказался от репозиторного grep'а по путям, и ничем его не заменил. Отдельно: `dangling-test-names.py` объявлен измерением, а не контролем, его девять известных имён не зафиксированы ни в каком отслеживаемом ожидаемом множестве, поэтому десятое имя, добавленное этапом 05, от девяти неотличимо без ручного сравнения.
- **trigger**: воспроизводится на штатном входе следующего этапа — этап 05 переименовывает `Analysis\Evidence\…`, то есть работает ровно в том канале, где короткие имена в докблоках распространены
- **in_scope**: да — все четыре инструмента добавлены/правлены этим этапом
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-04/dangling-test-names.py:28-33` (собственная оговорка «It is a measurement, not a control»); `docs/internal/plans/test-structure/measurement/stage-04/move-oracle.py:208-228` (арм 6, два написания FQN); `docs/internal/plans/test-structure/04-subject-layout.md` — раздел DoD, «No repository-wide grep, for namespaces or for paths»
- **evidence**:
  ```python
  for spelling in (old, old.replace("\\", "\\\\")):
  ```
  ```python
  NAME = re.compile(r"Qualimetrix(?:\\\\|\\)Tests(?:(?:\\\\|\\)\w+)+")
  ```
- **verification**: confirmed
- **verification_note**: оба непокрытых канала измерены мной вручную и сегодня чисты — это ответ на вопрос «что осталось непокрытым», а не находка о грязном дереве. (1) Короткие имена двух переименований P6: `git grep -n 'UnmatchedExcludeIntegrationTest'` вне `docs/internal/plans` и `docs/adr` — 0 попаданий. (2) Путевые литералы: все 117 старых путей из `git diff --name-status -M e15c7f42..HEAD` просвечены по всем отслеживаемым файлам, кроме planов/ADR/generated — 0 попаданий; отдельно `tests/(Unit|Integration|Functional)` даёт 4 попадания, все намеренные (`RETIRED_PATH_ASSERTIONS`, докблок `assertPathLiteralsResolve`, две строки фикстуры в `LocationTest`). (3) `dangling-test-names.py` даёт 9 имён — совпадает с числом, зафиксированным в `p6-report.md`; из них 3 — ключи `P6_RENAMED_TEST_IDS` (история по построению), 1 — неймспейс, который контроль отказа сажает нарочно, и 5 — реальные устаревшие ссылки прошлых эпох в живых файлах. Здесь же расхождение документа с собственным инструментом: `04-packages.md` пишет «Two members are known» про канал ссылок прошлой эпохи, а инструмент печатает пять.
- **fix_direction**: превратить `dangling-test-names.py` из измерения в контроль с отслеживаемым множеством из девяти строк и потолком — по тем же правилам, по которым сделаны `subject-path-exceptions.php` и `namespace-path-allow-list.php`; тогда десятое имя краснеет само. Для короткоимённого канала — добавить в судью перемещающего пакета свип по базовому имени класса (basename FQN) с отдельным, тоже потолочным, списком законных совпадений. Для путевого канала — либо признать, что он не проверяется, и написать это в DoD, либо завести узкий свип по конкретным адресам из таблицы в `CLAUDE.md`, а не репозиторный grep.

### claude-08

- **reviewer**: claude
- **severity**: LOW
- **kind**: pattern
- **domain**: tests
- **title**: Одно правило записано в четырёх местах с двумя разными семантиками level-сегмента, и генератор принимает путь, который контроль отвергает
- **mechanism**: разбор владельца из пути существует в четырёх копиях: `TestSubjectPaths::judge()` (контроль), `parseOwnerFromTestPath()` (генератор), `parse_owner()` в `move-oracle.py` и `parse_owner()` в `p0-oracle.py`. Три последние берут **первый** встреченный level-сегмент. Контроль требует, чтобы level-сегмент был **ровно один**, и на двух сегментах возвращает вердикт `LEVEL` без всякого списка исключений. На пути с двумя уровнями три копии отвечают «владелец найден, всё в порядке», четвёртая отказывает. Это ровно тот класс дефекта, который докблок `TestTree` называет причиной существования всей группы: «a guard that answers them for itself is a second copy of a map that can drift from the first».
- **trigger**: только на рукотворном входе — ни один из 616 файлов дерева не несёт двух уровней, и появление такого файла краснит `itFindsNoTestFileThatNamesNoSingleAnalysisLevel`, то есть расхождение громкое, а не тихое
- **in_scope**: да — `parseOwnerFromTestPath()` написан P0, `judge()` написан P5, оба судьи написаны этим этапом
- **anchor**: `governance/TestSuiteHygiene/TestSubjectPaths.php:218-229`; `scripts/generate-modular-architecture-test-inventory.php:721-731`; `docs/internal/plans/test-structure/measurement/stage-04/move-oracle.py:77-80`; `docs/internal/plans/test-structure/measurement/stage-04/p0-oracle.py:66-72`
- **evidence**:
  ```php
  if (\count($levels) !== 1) {            // контроль: ровно один
      return ['verdict' => self::LEVEL, ...
  ```
  ```php
  foreach ($segments as $index => $segment) {
      if (in_array($segment, TEST_LEVELS, true)) {
          return implode('/', array_slice($segments, 0, $index));   // генератор: первый
  ```
- **verification**: confirmed
- **verification_note**: измерено обеими реализациями на одном входе `tests/Analysis/Run/Unit/Integration/FooTest.php`. Генератор через `--classification-probe=` печатает `Analysis/Run  permanent  Unit  tests/Analysis/Run/Unit/Integration/FooTest.php` и выходит с 0, то есть опубликовал бы строку как конформную и удерживаемую на месте; `validateInventory()` на ней не спотыкается, потому что `current_suite` не `none`. `TestSubjectPaths::judge()` на том же входе возвращает `['verdict' => 'level', 'detail' => 'names 2 of Unit, Integration, Functional, and a test file names exactly one']`.
- **fix_direction**: свести четыре копии к одной семантике и по возможности к одному источнику. Минимально — привести генератор и обоих судей к правилу «ровно один level-сегмент», чтобы отказ приходил из того места, которое инвентарь публикует, а не только из контроля; лучше — дать генератору отказ на второй level-сегмент по имени, как он уже делает на неизвестного владельца через `failUnownedTestClass()`.

---

## Coverage — что проверено и признано чистым

**Прогнано машинно (все зелёные):**

- `composer architecture:check` — exit 0; «955 declarations, 37 semantic-owner layers, 0 seams, 73 exact internal grants -> 13 coarse edges» и «926 artifacts, 120 fixture directories, 726 PHPUnit classes, 9207 expanded cases». Это подтверждает свежесть всех сгенерированных артефактов относительно дерева и прохождение точной манифестной политики.
- `vendor/bin/phpunit --testsuite=Governance --filter TestSuiteHygiene` — 31 тест, 1128 ассерций, OK. Включая все девять кейсов нового `TestPathsNameTheirSubjectTest` и `TestNamespacesFollowTheirPathTest` с его allow-list (55, файл не менялся этим этапом — ни один из 114 перенесённых файлов в нём не оказался и ни один не породил нового нарушения).
- `python3 scripts/phpunit-aggregate.py` — exit 0, прогнан дважды. Плюс отдельный прогон Infrastructure. Все шесть посуитных чисел DoD воспроизведены независимо от отчётов пакетов: **Unit 6705, Integration 383, Functional 152, Infrastructure 1029, Tooling 179, Governance 757**. Совпадает построчно с таблицей `p6-report.md`.
- `python3 docs/internal/plans/test-structure/measurement/stage-04/dangling-test-names.py` — 9 имён / 9 носителей, член в член совпадает с зафиксированным в `p6-report.md`.

**Проверено собственными измерениями (чисто):**

- **Покрытие suite'ами.** Все директории под `tests/`, содержащие `*Test.php`, против всех `<directory>` `phpunit.xml.dist`: 0 непокрытых директорий, 0 объявленных директорий, под которыми нет тестов, 0 объявленных несуществующих путей. Семь удалённых и три добавленных записи конфигурации согласованы с деревом.
- **Распределение вердиктов инвариантного правила.** Воспроизведено независимым прогоном `TestSubjectPaths::measure()`: exact 377, prefix 132, no-coverage 84, another-owner 19, not-a-prefix 4, итого **616** — в точности таблица из `04-subject-layout.md`, и суммы сходятся с популяцией. Все три списка исключений заполнены ровно под потолок (84/84, 19/19, 4/4), то есть любое *прибавление* исключения краснеет немедленно.
- **Инвариант владельца в манифесте.** 955 деклараций: 0 классов, чей путь владельца не является сегментным префиксом имени — то есть `subjectRemainder()` не может тихо разрезать имя; 0 продуктовых классов, у которых сегмент неймспейса совпадает с именем уровня (`Unit`/`Integration`/`Functional`) — то есть часть 2 правила не может ложно отказать законному предмету.
- **Формы coverage-атрибутов.** В `tests/` только `#[CoversClass(X::class)]` (759) и `#[CoversNothing]` (5); ноль строковых аргументов, ноль `CoversTrait`/`CoversMethod`/`CoversFunction`, ноль докблочных `@covers`. То есть ограничение `TestTree::parseDeclarations()` на форму `Name::class` сегодня верно — но это свойство дерева, не проверяемое ничем: первый `#[CoversTrait]` молча станет «declares no coverage attribute» и упрётся в потолок списка A с неверным объяснением. Записываю как coverage-замечание, не как находку.
- **Заявки покрытия вне манифеста.** 0 из 759 `#[CoversClass]`-целей не разрешаются в манифест — почему claude-01 сегодня латентен.
- **Относительные пути в перенесённых файлах.** Все 117 переименований просвечены на `__DIR__ . '…'` и `dirname(__DIR__, N)`: 0 выражений, у которых изменилась глубина или которые не резолвятся. 34 переименования меняют глубину пути, и ни одно из них не содержит относительного путевого идиома. Фикстура SARIF (`__DIR__ . '/../../../../Fixtures/Schema/…'`) сохранила глубину 4 и резолвится.
- **Стыки, названные в задании.** `TestTree::declarationsIn()` — новый ключ `covers` аддитивен; все четыре существующих потребителя читают `['namespaces']` или `['classes']` и остаются корректными (`NamespacePathAllowList:116`, `TestFilesAreExecutedTest:153,421`, `TestMethodsAreReachableTest:147`). `LEGACY_UNMOVED` и его страж удалены полностью — 0 упоминаний в дереве. `validateP4Topology()` удалён, его живое содержание действительно покрыто разбором пути и новым контролем. `P6_D_GIT_TEST_PATHS` и `P6_D_REPORTING_TEST_PATHS` сняты вместе со своими ссылками в `assertPathLiteralsResolve()`. Дайджест `P6_C_BASELINE_PATHS_SHA256` обновлён ровно один раз и согласован с деревом (проверяется на каждом прогоне генератора). Копия изолированного проекта в `ModularArchitectureGeneratorRefusalTest` дополнена манифестом, а адрес зонда перенесён в `tests/Reporting/GraphProjection/Functional` — корень, который является владельцем манифеста, несёт level и не объявлен ни одним `<testsuite>`; проверено, что это так.

**Ответы на четыре заданных вопроса:**

1. **Какая форма входа не покрыта ни одним свипом** — две: короткое имя класса без FQN и путевой литерал. Подробно в claude-07, включая измерение, что сегодня обе чисты. Третья, более тонкая: девять известных висячих имён не закреплены ни в каком отслеживаемом множестве, поэтому десятое неотличимо.
2. **Является ли популяция контроля той, что заявлена** — заявленный механизм («support и fixture не имеют level-сегмента») не является фактическим (предикат имени файла по диску), и граница не утверждается ничем внутри контроля. Подробно в claude-05. Утверждается она в другом месте и по другой популяции — `validateInventory()` и `TestFilesAreExecutedTest`.
3. **Ограничивают ли три списка то, что обещают** — рост ограничен жёстко (все три ровно под потолком). Substitution назван в докблоке и ловится двумя отказами до re-derive. Не ограничено: (а) выход из списка B добавлением одной аннотации вместо переноса, с необратимым опусканием потолка — claude-02; (б) свободный переход exact↔prefix (по замыслу, но это значит, что для плоско лежащего файла часть 3 не накладывает ничего); (в) попадание в список C по причине, которой его определение не описывает — claude-01.
4. **`closure_package`** — рассуждение в `04-packages.md` состоятельно. Измерение, опровергшее посылку (272 против 67), воспроизведено: перекрёстная таблица `closure_package × disposition` по 926 строкам даёт 277 строк «Retain» рядом с пакетной меткой (P3 4, P4 57, P5 1, P6-C 25, P6-D 1, P7 10, P8 179) и 616 `permanent`/Retain. Число 272 в документе измерено до того, как P4 стал выводить `disposition` из `target`, поэтому 277 и 272 — измерения разных состояний артефакта, а не расхождение; порядок величины, на котором держится вывод («на порядок больше, чем 67»), подтверждается. Одно утверждение в тексте стоит поправить: «The column is read by nothing but the artifact» неточно — колонку читают `move-oracle.py` (арм 3 требует `permanent` у тест-классов), `p0-oracle.py` (та же ожидаемая величина) и `inventorySummary()` в `closure_package_counts`. Все три читают её по второму прочтению («какой пакет вопрос закрыл»), ни один — по первому, поэтому выбор прочтения ничего вниз по течению не ломает. Отдельной находки не завожу.

**Замечание, не оформленное как находка (предсуществующее):** четыре константы замыканий — `P3_TEST_PATHS` (70 путей), `P6_A_FINDING_TEST_PATHS` (34), `P6_B_FINDING_TEST_PATHS` (2), `P6_B_INLINE_TEST_PATHS` (19) — не читаются нигде, кроме собственного объявления и `assertPathLiteralsResolve()`, то есть их единственная функция — утверждать собственное существование. Проверено, что так было и на базовом коммите `e15c7f42` (то же единственное упоминание в строках 1829-1832 исходного файла), поэтому этап это состояние не создал. Но это та же форма, которую P4 выносит владельцу про `P6_LIVE_ADDED_TEST_IDS` и `P6_RENAMED_TEST_IDS` («declarations with no consumer»), и 125 путей — величина, которую стоит приложить к тому же решению.

**Что НЕ проверено (честно):**

- `composer check` целиком я не прогонял: прогнаны `architecture:check` и полный PHPUnit-агрегат (это две из четырёх групп плюс ведущая проверка артефактов); `check:docs` (строгая сборка mkdocs), `check:self` (gate self-test, qmx-ратчет, directive audit), cs-fixer, PHPStan и JS-тесты не запускались.
- Отказы контроля под подсаженными поломками (DoD P5 «has been observed to refuse») я не воспроизводил — ни одна поломка не подсаживалась, потому что дерево read-only, а копия проекта под это не разворачивалась. Считаю заявление пакета непроверенным мной, а не подтверждённым.
- `move-oracle.py` и `p0-oracle.py` я читал, но не исполнял: оба требуют базового коммита конкретного пакета и промежуточного состояния дерева, которого в `HEAD` уже нет.
- Отчёты `p1-report.md` … `p5-report.md` прочитаны выборочно (по разделам DoD и «что оставлено»), не целиком; `p0-report.md`, `p1-fileset.md`, `addresses-witness-{a,b}.md`, `invariant-shape.md`, `prediction.md` — не читались построчно.
- Содержание 114 перенесённых тестов (что именно они проверяют) не ревьюировалось — это предмет этапа 05.
- `governance/SolePrimitiveOwnership` и вопрос стадии 06 не рассматривались: этап явно вынес их за границу.

---

## Отклонённые находки (refuted)

| id  | title                                                                                                                                                                          | причина                                                                                                                                                                                                                                                                                                                                                                             |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| —   | Устаревшая ссылка `Qualimetrix\Tests\Infrastructure\Unit\ChannelDeclarationCompilerPassTest` в `tests/Analysis/Finding/Unit/ChannelDeclarationTest.php:72` внесена этим этапом | Опровергнуто по дереву базового коммита: `git ls-tree -r e15c7f42` показывает, что `ChannelDeclarationCompilerPassTest.php` уже лежал в `tests/Analysis/Finding/Unit/` до этапа. Ссылка — остаток этапа 02/03, канал «ссылка прошлой эпохи», и она входит в девятку, которую этап зафиксировал. Учтена в claude-07 как член неограниченного множества, но не как дефект исполнения. |
| —   | Смешение словарей `subject_owner` (`Architecture.Governance` через точку против `Analysis/Evidence/Duplication` через слэш) — дефект переписанного вывода владельца            | Опровергнуто: точечные имена приходят из `TOOLING_TEST_ROOT_OWNERS`, существовавшей до этапа, и оба судьи (`move-oracle.py`, `p0-oracle.py`) приводят манифестные имена к слэшам через одну и ту же `owner_path()`, то есть сверка не ломается. Косметика предсуществующего словаря, не дефект этапа.                                                                               |
| —   | `governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php` не правлен, хотя план отдал его P4                                                              | Опровергнуто: жёстко зашитые счётчики в нём (`assertCount(28, test-orphan-dispositions.tsv)`, `assertCount(1, test-system-support-owners.tsv)`) относятся к артефактам, которые этап не менял; правка не требовалась. Файл прогнан в составе Governance-агрегата — зелёный.                                                                                                         |
| —   | `TestTree::parseDeclarations()` читает только форму `#[CoversClass(Name::class)]` — дыра в чтении покрытия                                                                     | Не находка, а измеренное свойство дерева: строковых аргументов и прочих `Covers*`-атрибутов в `tests/` ноль, а появление первого упирается в потолок списка A и краснеет (хотя и с вводящим в заблуждение текстом отказа). Оставлено в coverage как замечание.                                                                                                                      |
