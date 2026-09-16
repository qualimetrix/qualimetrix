# Stage 02 — findings (reviewer: claude)

Диапазон: `52eae218..HEAD`, ветка `stage-02-controls-extraction`.
Метод: статические свипы по литералам путей и FQCN, двухсвидетельское сравнение
сгенерированного листинга кейсов, измерение co-change по git-истории и **подсадки
поломок в изолированную копию дерева** (`rsync` без симлинка на `vendor`;
изоляция подтверждена тем, что `ReflectionClass::getFileName()` резолвится внутри
копии). Рабочее дерево не изменялось: `git status --porcelain` до и после прогона
совпадает.

---

### claude-01

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: `ceiling` в allow-list остался 60 при 57 строках — файл в состоянии, которого не мог записать ни один `derive`
- **mechanism**: Пакеты удалили из `namespace-path-allow-list.php` три строки (файлы уехали в `governance/`), но не перезапустили `derive-namespace-path-allow-list.php`. `NamespacePathAllowList::derive()` пишет `min($ceiling, count($violations))`, то есть после честного перезапуска потолок стал бы 57. Вместо этого список отредактирован руками — ровно то, что его собственный заголовок запрещает («Do not add a row by hand»), — и потолок ратчета перестал быть монотонным: между «сколько нарушений есть» и «сколько разрешено» появился зазор в три позиции. Единственная проверка потолка — `assertLessThanOrEqual`, поэтому 57 ≤ 60 зелено.
- **trigger**: воспроизводится в нормальной работе — дефектное состояние уже лежит в дереве; поглощение нового нарушения дополнительно требует, чтобы оно легло в уже разрешённый неймспейс с высвободившейся квотой (`untrackedIn()` считает кратность по значению)
- **in_scope**: да
- **anchor**: `governance/TestSuiteHygiene/namespace-path-allow-list.php:20`, `governance/TestSuiteHygiene/NamespacePathAllowList.php:292`, `governance/TestSuiteHygiene/TestNamespacesFollowTheirPathTest.php:149`
- **evidence**:
  ```php
  // namespace-path-allow-list.php:20
      'ceiling' => 60,
  // NamespacePathAllowList.php:292 — что записал бы derive
  if (!self::write(self::render($violations, min($ceiling, \count($violations))))) {
  ```
  Измерено read-only через публичный API самого класса:
  `measured violations = 57`, `tracked ceiling = 60`, `tracked rows = 57`,
  `derive would write ceiling = 57`, расхождений между измеренным и
  отслеживаемым множеством нет (0 в обе стороны). На `52eae218` было
  `ceiling=60 rows=60` — то есть зазор внесён этим диапазоном.
- **verification**: confirmed
- **verification_note**: числа получены исполнением `NamespacePathAllowList::measure()/ceiling()/load()` на текущем дереве; строка 292 прочитана в исходнике, а не восстановлена по докблоку
- **fix_direction**: перезапустить штатную команду derive и закоммитить полученный файл целиком (она и есть единственный разрешённый способ править этот список), вместо ручного удаления строк; если ручная правка списка при переносах неизбежна, проверку потолка стоит сделать равенством, а не неравенством, — тогда несоответствие «потолок ≠ число строк» краснеет само

---

### claude-02

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: Опустевший, но всё ещё объявленный каталог группы бесшумно теряет кейсы: у 21 объявления нет floor'а непустоты
- **mechanism**: Этап заменил один объявленный корень на 21 объявление групп в `phpunit.xml.dist`. Единственный floor, который вообще говорит о непустоте, — `assertNotEmpty(self::listing(...))` **на сюиту целиком** плюс три глобальных порога (`>500` файлов, `>600` классов, `>5000` id). Ни один из них не может сработать на исчезновение одной группы. Это ровно та форма, которую бриф называет хазардом №2, но в варианте «каталог существует и пуст» (вариант «каталога нет» закрыт: он краснеет).
- **trigger**: только на рукотворном входе — сегодня ни одно из 21 объявления не пусто (проверено), поломку я подсадил сам; достижимость в будущем — обычный перенос файлов группы без правки `phpunit.xml.dist`
- **in_scope**: да
- **anchor**: `phpunit.xml.dist:82-104`, `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:391-407`
- **evidence**:
  ```php
  foreach ($suites as $suite) {
      self::assertNotEmpty(self::listing(self::reachableCommand($suite)), $suite);
  }
  self::assertGreaterThan(500, \count(TestTree::testFiles()));
  ```
  Подсадка в копии: `governance/Occurrence/*.php` убраны, объявление оставлено.
  `TestFilesAreExecutedTest` — `OK (9 tests, 33 assertions)`; сюита Governance —
  `Tests: 705` против базовых `709` при единственной известной средовой ошибке.
  Ни одна проверка не покраснела. Контрольная подсадка обратной формы
  (каталог `governance/ProbeGroup/` с тестом, но без объявления) краснеет
  (`Failures: 1`), и объявление несуществующего каталога краснеет дважды
  (PHPUnit отказывается собрать сюиту; `ModularArchitectureGeneratorRefusalTest`
  и `TestFilesAreExecutedTest` падают) — то есть незакрыт ровно один из трёх
  вариантов шва.
- **verification**: confirmed
- **verification_note**: результат получен отказом/неотказом на подсаженной поломке в изолированной копии, а не чтением кода; базовый прогон копии зафиксирован отдельно (709 кейсов, 1 средовая ошибка из-за отсутствия `.git`)
- **fix_direction**: сделать непустоту свойством *объявления*, а не сюиты: сопоставить каждому `<directory>` листинг и потребовать, чтобы он что-то вернул. Это добавляет стража, чего этап 02 сознательно не делает, — поэтому решение о том, закрывать ли шов здесь или отдать его этапу 04/05, стоит принять явно и записать, а не оставить как «проверено и чисто»

---

### claude-03

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: architecture
- **title**: `SolePrimitiveOwnership` и `RepositoryEntrypoints` проваливают co-change-тест ADR 0016 — это форма утверждения, а не предмет
- **mechanism**: Имя `SolePrimitiveOwnership` отвечает на вопрос «какой формы здесь утверждения» («ровно одно место в `src/` делает X»), а не «про что это». Предметы её семи файлов не пересекаются: резолв версии пакета, алфавит glob, нормализация в `NamespaceMatcher`, ключи подавления, обход AST, классификация фреймворочных неймспейсов, аргумент scope у `AnalysisContext`. Тот же дефект у `RepositoryEntrypoints`, где `MemoryCeilingManifestTest` читает baseline-фикстуру и `benchmarks/composer.lock`. Сам план называет `SolePrimitiveOwnership` непроверенной по co-change («**Two open points, stated rather than hidden**»), и проверка это подтверждает.
- **trigger**: недостижим как рантайм-дефект — это структурное нарушение принятого правила раскладки
- **in_scope**: да
- **anchor**: ADR 0016 (subject cohesion) / ADR 0022, каталоги `governance/SolePrimitiveOwnership/` (7 файлов) и `governance/RepositoryEntrypoints/` (3 файла)
- **evidence**: Co-change измерен по `git log --follow` для предшественников файлов каждой группы, по всем парам:
  - `SolePrimitiveOwnership` — 19 из 21 пары имеют общий коммит, но **все** общие коммиты это ровно два: `63224f38` (сам коммит переноса, 46 файлов) и `6a833ab8` (1196 файлов). Вне их — ноль.
  - `RepositoryEntrypoints` — 3 из 3 пар, и снова только `63224f38` и `2c83c285` (919 файлов).
  - Для сравнения `Channel` (та же методика): общие коммиты включают `e4bdeca6` (22 файла) и `40ae4019` (192 файла) — ровно те, на которые план ссылается как на доказательство.
- **verification**: confirmed
- **verification_note**: co-change измерен, а не оценён; «общий коммит» отброшен как свидетельство, когда это сам перенос или массовый рефактор — иначе предметом оказывается любая пара файлов репозитория
- **fix_direction**: разнести обе группы по предметам, которые их файлы уже называют (версия/пакет, glob, namespace-сопоставление, подавление, обход, coupling; ceiling памяти — к baseline), либо явно зафиксировать в плане, что эти две группы — признанное исключение, с причиной. Оставлять их как есть без записи опасно: `SolePrimitiveOwnership` по построению принимает любой будущий контроль формы «единственное место», то есть это не предмет, а ведро

---

### claude-08

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: judgement
- **domain**: tests
- **title**: Ссылка на путь или класс внутри комментария — адрес, который не проверяет ничто; это и есть шов, не покрытый ни одним пакетом
- **mechanism**: Ни один пакет не мог быть покрашен адресом из claude-04, потому что в репозитории нет контроля, резолвящего путь или FQCN, названный в комментарии. Ближайший существующий механизм, `assertPathLiteralsResolve()`, покрывает только литералы внутри самого `generate-modular-architecture-test-inventory.php`. Шесть исполнителей видели каждый свой набор файлов, и вопрос «а куда ещё это имя записано прозой» не мог задать никто из них: он не локален ни для одного пакета и не краснеет нигде. Этап 02 — крупнейшее переименование путей в истории репозитория, и именно на нём пробел стал измеримым: 7 внесённых протухших путей плюс 8 предсуществующих `{@see}`-целей.
- **trigger**: недостижим как рантайм-дефект — это отсутствующий контроль, а не поведение
- **in_scope**: да — пробел проявился на этом диапазоне и объясняет форму находки claude-04
- **anchor**: отсутствующий контроль «путь и FQCN, названные в трекаемом файле, разрешаются»
- **evidence**: измеренный остаток — 7 ссылок в claude-04 (внесены диапазоном) и 8 висячих `{@see}`-целей, из которых бриф сам называет шесть имён предсуществующими:
  ```
  governance/Channel/ChannelEmissionStaticGuardTest.php:48 -> Qualimetrix\Tests\Integration\Infrastructure\Rule\ChannelDeclarationFixtureDriftTest
  tests/Analysis/Finding/Unit/ChannelDeclarationTest.php:72 -> Qualimetrix\Tests\Infrastructure\Unit\ChannelDeclarationCompilerPassTest
  ```
- **verification**: unverifiable
- **verification_note**: отсутствие контроля — факт (проверено: никакой другой резолвер прозаических ссылок в дереве не найден). Неверифицируемо решение: нужен ли такой контроль и какой ценой — это выбор владельца, а не свойство кода
- **fix_direction**: решить явно, заводить ли контроль над всем деревом по образцу `assertPathLiteralsResolve()` — и если не заводить, записать это решение там же, где записаны прочие «известные и сознательные» пункты этапа. Без записи следующий перенос повторит класс ровно так же, и заметит его снова только ревью

---

### claude-04

- **reviewer**: claude
- **severity**: LOW
- **kind**: pattern
- **domain**: tests
- **title**: Семь ссылок на перенесённые пути остались в прозе, три из них — в докблоках `src/`
- **mechanism**: Все *исполняемые* ссылки на перенесённые пути обновлены и это подтверждается измерением (см. coverage). Не обновлены ссылки, живущие в комментариях: докблоки `src/`, шапка самой фикстуры, README гейта, пояснительный блок генератора перечисления. Три из них — в продакшен-докблоках, и все три инструктируют читателя, куда класть объявление канала: разработчик, добавляющий канал по этой инструкции, идёт в несуществующий каталог.
- **trigger**: воспроизводится в нормальной работе — но цена промаха это потерянный цикл разработчика, а не поломка поведения
- **in_scope**: да
- **anchor**: `src/Analysis/Finding/Contract/ChannelDeclarationRegistryInterface.php:62`, `src/Analysis/Evidence/CodeSmell/AbstractCodeSmellRule.php:101`, `src/Infrastructure/DependencyInjection/Configurator/DesignConfigurator.php:22` (+ 4 ниже)
- **evidence**: Свип по всем трекаемым файлам, отфильтрованный до ссылок, **которые разрешались на `52eae218` и не разрешаются сейчас**:
  ```
  src/Analysis/Finding/Contract/ChannelDeclarationRegistryInterface.php:62 -> tests/Analysis/Finding/Fixtures/Channels
  src/Analysis/Evidence/CodeSmell/AbstractCodeSmellRule.php:101          -> tests/Analysis/Finding/Fixtures/Channels/declared.txt
  src/Infrastructure/DependencyInjection/Configurator/DesignConfigurator.php:22 -> tests/Analysis/Finding/Fixtures/Channels/order.txt
  governance/Channel/Fixtures/declared.txt:51 -> tests/Analysis/Finding/Integration/ChannelDeclarationFixtureDriftTest.php
  scripts/generate-rename-enumeration.php:1305, :1353 -> tests/Analysis/Finding/Integration/Occurrence{Kind,Leaf}FreezeGuardTest.php
  finding-gate/README.md:912 -> tests/Analysis/Finding/Integration/ChannelLevelDeclarationDriftTest.php
  ```
  Отдельный свип по `{@see}` даёт 8 висячих целей; все они входят в шесть имён, которые бриф называет предсуществующими, — новых не появилось.
- **verification**: confirmed
- **verification_note**: каждая ссылка разрешена дважды — против `git ls-tree -r 52eae218` и против текущего диска; в выборку попали только те, что разрешались тогда и не разрешаются теперь, поэтому предсуществующая гниль сюда не подмешана
- **fix_direction**: починить семь ссылок; три в `src/` важнее остальных, потому что адресуют ровно тот предмет, который переезжал. Системную половину см. claude-08

---

### claude-05

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: Мёртвая ветка классификации владельца `System/DocumentationConsistency` пережила зачистку своих сиблингов
- **mechanism**: Этап удалил из `classifyOwner()` две ветки, возвращавшие упразднённых владельцев (`tests/System/DocumentationConsistency/`, `tests/System/ScratchPathIsolation/`), но оставил третью, возвращающую того же упразднённого владельца `System/DocumentationConsistency` по префиксу `tests/Integration/Documentation/`. Такого каталога нет ни сейчас, ни на `52eae218`, а владелец после этого этапа не имеет ни одного файла (`test-system-support-owners.tsv` ужался с 6 строк до 3, пин `assertCount` — с 6 до 3). Ветка не может вернуть ничего и описывает несуществующее.
- **trigger**: недостижим — ветка не исполняется
- **in_scope**: да — владельца, которого она называет, упразднил именно этот диапазон
- **anchor**: `scripts/generate-modular-architecture-test-inventory.php:952-954`
- **evidence**:
  ```php
  if (str_starts_with($path, 'tests/Integration/Documentation/')) {
      return ['System/DocumentationConsistency', 'P8'];
  }
  ```
  `test -d tests/Integration/Documentation` → нет; `git ls-tree -d 52eae218 tests/Integration/Documentation` → пусто. При этом соседние ветки того же владельца в диапазоне удалены (диапазонный дифф `:658-666`).
- **verification**: confirmed
- **verification_note**: отсутствие каталога проверено и на HEAD, и на базовом коммите; принадлежность владельца к упразднённым — по диффу сгенерированного `test-system-support-owners.tsv`
- **fix_direction**: удалить ветку вместе с сиблингами в том же коммите; отдельно стоит решить, должен ли генератор вообще отказывать на ветку классификации, которая ни разу не сработала за прогон, — сейчас мёртвая ветка неотличима от живой

---

### claude-06

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: `composer directives:controls:coverage` красный на ветке (два кейса не покрыты ни одним probe), и это вне `composer check`
- **mechanism**: Красный **до** диапазона, не внесён им (вывод по диффу: `Probes.php` содержит только переименования идентификаторов, `Suite.php` — только добавления, так что ни один кейс покрытие не терял). Контрольный стенд аудита директив сообщает «120 probes, 0 not as declared, 2 cases guarded by nothing» и возвращает код 1. Ноль «not as declared» — это, наоборот, хорошая новость: все переименования идентификаторов в `Probes.php` точны. Но сам стенд красный, и он не входит ни в `composer check`, ни в один из его подгрупп, поэтому зелёный агрегат об этом ничего не говорит.
- **trigger**: воспроизводится при запуске команды; в штатном `composer check` не достигается
- **in_scope**: нет — оба непокрытых кейса живут в `tests/Infrastructure/Console/Functional/DirectivesCommandTest.php`, который диапазон не трогает, и ни один probe их не терял
- **anchor**: `scripts/directive-audit-coverage-control.php` (вывод команды), кейсы `Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itAnswersExitOneForAnUnrecognisedExceptionFromAnUnreadablePath` и `::itRefusesANonExistentPath`
- **evidence**:
  ```
  120 probes, 0 not as declared, 2 cases guarded by nothing.
    guarded by nothing: ...DirectivesCommandTest::itAnswersExitOneForAnUnrecognisedExceptionFromAnUnreadablePath
    guarded by nothing: ...DirectivesCommandTest::itRefusesANonExistentPath
  Script php scripts/directive-audit-coverage-control.php ... returned with error code 1
  ```
- **verification**: confirmed
- **verification_note**: краснота — измерена (прогон в копии). Предсуществование — вывод, а не измерение: базовую ревизию без checkout прогнать нельзя, но диапазонный дифф `Probes.php` содержит только переименования, а `Suite.php` только добавления, так что ни один кейс покрытие не терял
- **fix_direction**: развести два вопроса — покрыть два кейса probe'ами (или объявить их сознательно непокрытыми) и отдельно решить, должен ли этот стенд входить в какую-то из групп `composer check`; сейчас его краснота видна только тому, кто вспомнит запустить команду

---

### claude-07

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: style
- **title**: `BenchmarkConsumersCoverageTest` сохранил имя уехавшего контроля
- **mechanism**: Из файла уехал единственный метод, оправдывавший имя, — `itKeepsTrackedBenchmarkConsumersOnTheCheckCommand` (теперь `governance/RepositoryEntrypoints/BenchmarkScriptEntrypointCoverageTest`). Оставшиеся три кейса ничего не «покрывают»: они гоняют `scripts/benchmark-regression.php` и `collect-benchmark-data.php` над рукотворной фикстурой и проверяют, что скрипт отказывается писать при неполном покрытии. Имя класса теперь описывает половину, которой в нём нет.
- **trigger**: недостижим — вопрос читаемости
- **in_scope**: да
- **anchor**: `tests/Analysis/Evidence/ComputedMetrics/Integration/BenchmarkConsumersCoverageTest.php:14`
- **evidence**:
  ```php
  final class BenchmarkConsumersCoverageTest extends TestCase
  ```
  Три оставшихся кейса: `itDoesNotUpdateBaselinesFromANonAuthoritativeArtifact`,
  `itDoesNotWriteCollectedDataWhenAnyProjectIsIncomplete`,
  `itDoesNotPartiallyRatchetWhenAConfiguredProjectIsSkipped` — все про отказ
  скрипта, ни один про «покрытие потребителей».
- **verification**: confirmed
- **verification_note**: обе половины прочитаны; разделение по классу верно (оставшаяся половина — tooling-test с рукотворным входом, ей место в `tests/`), вопрос только в имени
- **fix_direction**: переименовать оставшуюся половину по тому, что она теперь утверждает (отказ benchmark-скриптов писать при неполном измерении); соседние сплиты этого этапа переименованы именно так, так что здесь просто пропущен один шаг

---

## Coverage — что проверено и признано чистым

**Форма 1 — вакуумно-зелёные контроли.** Сегодня вакуумных нет; механизм,
который их породил бы, жив и продемонстрирован подсадкой.
`grep -rnoE 'dirname\(__DIR__, *[0-9]+\)' governance/ | grep -v ', *2)'` не печатает
ничего. Прочие идиомы достижения корня (`realpath(__DIR__.'/../..')`,
`__DIR__.'/../../'`, `getcwd()`) перечислены и все дают корень репозитория с
правильной глубиной. Дополнительно резолвлены **все** относительные литералы путей
внутри `governance/` (`/src`, `/docs`, `/website`, `/scripts`, `/bin`,
`/finding-gate`, `/benchmarks`, включая glob'ы): 9 непопаданий, и все 9 —
синтетические пути внутри temp-проектов, которые тест сам и создаёт. Все 15
walker'ов без собственного floor'а ходят по `$root . '/src'`, а
`RecursiveDirectoryIterator` по несуществующему каталогу бросает — то есть
вариант «каталога нет» громкий.
**Вариант «каталог есть, файлов нет» проверен подсадкой на представителе:** в
копии из `src/Analysis/Policy/Architecture` удалены все `*.php` (каталоги
оставлены), и `ArchitectureInternalTopologyTest::itLeavesNoValidationNamespaceOrDirectoryInTheArchitectureLeaf`
— walker из списка NO-FLOOR — **прошёл зелёным над пустым множеством**
(покраснел только соседний кейс, и по другой причине: он сверяется с манифестом
через `assertFileExists`). Это не дефект сегодняшнего дерева — глубина корня
верна и популяция непуста (71 файл), — но форма подтверждена измерением, а не
рассуждением, и она та же, что в claude-02. `move-hazards.md` §1 этот выбор
называет сознательным («a new assertion is a new guard, and this stage adds
none»), поэтому находкой я его не оформляю.

**Форма 2 — объявленный каталог, которого нет.** Проверено подсадкой, закрыто
(см. claude-02: этот вариант краснеет; незакрыт только вариант «каталог есть и пуст»).
Все объявления `phpunit.xml.dist` проверены на существование и непустоту:
единственное «пустое» — `src/Infrastructure/Console` в секции `<exclude>`, что
нормально. Все 21 каталог группы объявлены, лишних объявлений нет.

**Форма 3 — пины и переписи.** Проверены поимённо и чисто (кроме claude-01):
`SILENTLY_EXCLUDED` (оба FQCN переписаны в том же коммите),
`P6_C_BASELINE_PATHS_SHA256` (пересчитан; `composer architecture:check` зелёный),
`systemSupportContents()` и связанный `assertCount(6 → 3)` — согласованы с
сгенерированным `test-system-support-owners.tsv`, `Probes.php` (идентификаторы в
точечной JUnit-форме — свип показал 0 висячих; сам стенд говорит «0 not as
declared»), `Suite.php::FILES` (обе половины каждого сплита перечислены),
`Controls.php` и `ChannelWitness.php` (все пути фикстуры каналов переписаны),
`x8-overlap-sites.php::GUARD_CLASS`, `namespace-path-allow-list.php` (строки —
точное множество измеренных нарушений, расхождение 0 в обе стороны).
`finding-gate/enumeration-renames.tsv` не изменился, как и требовалось.

**Форма 4 — сплиты.** Чисто.
Двухсвидетельское сравнение `test-phpunit-discovery.txt` на `52eae218` и на HEAD:
9198 кейсов с обеих сторон, по имени метода расхождение **пусто в обе стороны**
(49 кейсов сменили класс, каждый ровно на один новый; ни один метод не исчез и не
появился). Тела `it*`-методов сверены эвристическим сплиттером против базовой
ревизии по совпадению имени: **57 тел отличаются, и все 57 прочитаны глазами** —
в каждом изменение это либо глубина `dirname`, либо пин `assertCount(6→3)`, либо
перепривязка докблока; ни одно не меняет популяцию. В остальных ~9141 сплиттер не
нашёл различий в нормализованном тексте. Оговорка честности: сплиттер режет файл
регуляркой по сигнатуре метода и сопоставляет со *старым методом того же имени*,
а не с партнёром по сплиту, — поэтому это сильное свидетельство, но не
доказательство. Дублированные при сплите приватные хелперы (`readFile`,
`setUpBeforeClass`, `finding`, `root`) сверены попарно — расхождений, кроме
обязательной разницы в глубине корня, нет. Провайдеры данных: по всем файлам
диапазона нет ни осиротевшего, ни отсутствующего провайдера.

**Регистрационные адреса корня** (таблица AGENTS.md). Проверены все:
`composer.json` autoload-dev, `phpstan.neon` paths (+ перенос `excludePaths` с
`tests/System/DocumentationConsistency/Fixtures` на
`governance/FormatOptionKeys/Fixtures`), `.php-cs-fixer.dist.php` finder
(`notPath('Fixtures/OutputFormats/broken/src/Unparsable.php')` — подстрочное
совпадение, продолжает работать из нового корня), `phpunit.xml.dist`,
`.gitattributes` export-ignore, `.dockerignore`, `.githooks/pre-commit`,
`scripts/init-environment.sh`, `generate-rename-enumeration.php::surfaces()`
(`tests` и `governance` — одна поверхность, поэтому `enumeration-renames.tsv` не
двигается), `generate-modular-architecture-production-inventory.php` (запрет
импорта dev-неймспейсов содержит `Qualimetrix\Governance\`),
`scripts/check-private-leaks.sh` (читает `git ls-files`, то есть покрывает новый
корень по построению). Сюда же — списки корней внутри самих контролей:
`ScratchPathsCarryRealEntropyTest::ROOTS` и
`PlanningRecordIsolationTest::itKeepsExecutableSourcesIndependentFromPlanningRecords`
оба расширены на `governance`, и обе расширенные области **проверены подсадкой**
(`uniqid()` и ссылка на план, подсаженные в `governance/`, называются поимённо).

**Форма 6 — вердикты.** Выборочно, не исчерпывающе. Прочитаны кандидаты, у
которых вход рукотворный: `ScopeConditionedChannelGuardTest` (популяция
выводится из реестра продукта — контроль правомерно),
`YamlKeyReachabilityTest`/`YamlNormalizationCharacterizationTest`,
`ChannelRenameMapTest` (читает трекаемый `finding-gate/maps/channels.tsv`).
В обратную сторону: все файлы, оставшиеся в `tests/` и читающие репозиторий через
`dirname(__DIR__, N) . '/{src,docs,website,scripts,bin,composer.json}'`,
перечислены — 19 штук. Из них 18 читают репозиторий только для того, чтобы
запустить его собственный инструмент над рукотворным входом (`scripts/…`,
`bin/qmx`), то есть это `tooling-test` и функциональные тесты CLI, которым по
объявленному критерию место в `tests/`; 19-й — claude-07. Это проверка по форме
доступа, не построчное перечитывание каждого из 19.

**Прогоны.** Сюита Governance в изолированной копии: 709 кейсов, 1 ошибка, и она
средовая (в копии нет `.git`, `BenchmarkScriptEntrypointCoverageTest` вызывает
`git grep`). `composer architecture:check` в рабочем дереве: зелёный, «913
artifacts, 120 fixture directories, 717 PHPUnit classes, 9198 expanded cases» —
число 9198 подтверждено независимо от брифа. `composer directives:controls:coverage`
в копии: 120 probes, 0 not as declared (см. claude-06).

## До чего не дошёл

- **`composer check` целиком не запускался.** Тесты в нём занимают ~150 s, весь
  агрегат заметно больше, и часть его требует `.git` и `website/.venv`. Проверены
  только Governance-сюита, `architecture:check` и контрольный стенд директив.
  Утверждение брифа «`composer check` зелёный, exit 0» мною не перепроверялось.
- **Сюиты Unit/Integration/Functional/Infrastructure не исполнялись** — они
  сравнивались только по сгенерированному листингу кейсов. Метод, который
  перечислен и при этом стал утверждать меньше внутри неизменившегося тела, этой
  проверкой не ловится; мера против этого здесь — побайтовое сравнение тел.
- **Подсадки сделаны в 6 точках, не во всех 21 группе.** Выбраны швы, которые
  двигал именно перенос (списки корней, регистрация группы, объявление
  каталога). Остальные группы проверены чтением и резолвом путей, а не отказом.
- **507 файлов ниже порога инструмента триажа** (бриф, п. 6) я тоже не читал.
  Мой заменитель — механический свип «кто ещё читает репозиторий из `tests/`»,
  он ловит контроли по форме доступа, но не поймает контроль, читающий
  репозиторий через контейнер или рефлексию без литерала пути.
- **`composer gate` / `gate:controls` не запускались** (слишком долго). Проверено
  только то, что `finding-gate/enumeration-renames.tsv` не изменился, — как и
  предписывает `move-hazards.md` §6.
- **Cohesion остальных 19 групп** оценена чтением списка файлов, co-change измерен
  только для трёх (`SolePrimitiveOwnership`, `RepositoryEntrypoints`, `Channel` как
  эталон). Подозрение без измерения: `DocumentationCensus` и
  `GeneratedArtifactFreshness` названы носителем артефакта, а не предметом, — то
  есть той осью, которую план явно отверг; в каждой по одному файлу, поэтому
  co-change там не измерим в принципе и находки я не выставляю.

## Отклонённые (refuted)

Нет.
