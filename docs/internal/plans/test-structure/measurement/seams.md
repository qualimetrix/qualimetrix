# Швы: то, чего не видел ни один срез поодиночке

Сведение десяти отчётов волны 1. Всё ниже перепроверено в коде рабочего дерева
`<корень репозитория>`
чтением и `grep`; тесты не запускались.

---

## Правила, по которым собран `defect-ledger.tsv`

Эти решения меняют состав реестра, поэтому названы явно — орк может их отменить.

1. **Одна строка — один дефект, а не один файл.** Файл с двумя разными дефектами даёт две строки
   (`RuntimeConfiguratorTest` — `category-wrong` и `dupe`; `SymbolInfoTest` — `name-lies` и `tautology`).
2. **Внутрифайловый дубль** записан с `counterpart`, равным самому файлу. Межфайловый — строка ставится
   на **избыточной** стороне (той, что предлагается убрать/слить), `counterpart` указывает на владельца факта.
3. **Severity назначается классом, а не по обстоятельствам** (чтобы не появилось 213 частных суждений):
   `never-runs`, `name-lies` → **high**; `dupe`, `tautology`, `category-wrong` → **medium**;
   `misplaced` → **low**; `stale-doc` → low, и **medium в одном случае** — когда докблок утверждает
   ПРОТИВОПОЛОЖНОЕ телу (`AnalysisPipelineIntegrationTest`: «все тесты должны падать», а тела проверяют
   исправленное поведение); `other` → low по умолчанию, medium там, где отчёт показал, что тест
   не может поймать свой дефект (условный assert, слабый оракул, ручной реестр, утечка состояния процесса).
   Это правило и инвариант «`counterpart` непуст ⇔ класс `dupe`» проверяются `assert`-ами в генераторе
   (`build/gen.py`), а не глазами.
4. **Чистая классификация «это control/control-invariant» — НЕ строка реестра.** Ни одного такого класса
   в списке брифа нет, и у этого материала есть собственный артефакт `controls-from-category.tsv`.
   Control-тест попадает в реестр только когда у него есть ещё и дефект размещения: **его SUT лежит вне
   `src/`** (`scripts/promise-effect/`, `scripts/directive-audit*/`, `scripts/finding-gate/`,
   `scripts/benchmark-*.php`, `benchmarks/composer.lock`, `docs/adr/`, `website/docs/`), а сам тест —
   в дереве, зеркалящем `src/`. Таких строк 16, все `misplaced`/low.
5. **Гипотезы отчётов в реестр не вошли.** Отчёт 05 явно пометил как непроверенные пересечения
   `SarifRuleCollectorTest`↔`SarifRuleDescriptorCoverageTest` и `OutputFormatResolverTest`↔
   `OutputFormatRefusesUnexecutableValuesTest`; отчёт 04 — `ResultPresenterTest`↔`DrillDownBindingTest`.
   Строка реестра — это утверждение, по которому будут действовать; недоказанному там не место.
6. **Дефекты продуктового кода, найденные попутно, в реестр не вошли** — реестр про тесты.
   Единственный такой: `src/Analysis/Policy/Architecture/Layer/Expansion/LayerExpansionResult.php` объявляет
   одновременно `public readonly array $expandedLayers` и одноимённый метод `expandedLayers()`, возвращающий
   то же самое (вероятно мёртвый accessor); замечено отчётом 03 по разнобою обращений в `LayerExpansionStageTest`.
7. Номера строк — только точные. Приблизительные из отчётов («~330», «~250», «~99-115», «около 37»)
   перепроверены `grep -n`: `CheckCommandInputValidationTest` — не 330, а **458**;
   `CircularDependencyRuleTest` — не 99, а **100**; `DrillDownBindingTest` 250 и
   `LayerViolationIntegrationTest` 37 подтвердились. Где номер не проверялся — колонка пуста.
8. **Сверка с частичными кусками 01a/01b/01c/01d проведена** (бриф просил использовать их только для
   контроля). Все их находки уже присутствуют в сводном `01-finding.md` и в реестре, кроме двух,
   которые сводный отчёт потерял или сформулировал глуше, — обе добавлены с `source_report` 01b/01c:
   stale `@see` в `ChannelCoverageTest.php:88` на несуществующий FQCN
   `Qualimetrix\Tests\Integration\Infrastructure\Rule\ChannelDeclarationFixtureDriftTest`
   (`grep -rn 'Tests\Integration\Infrastructure' tests/` — ноль совпадений; реальный класс живёт в
   `Qualimetrix\Tests\Analysis\Finding\Integration`), и вводящее в заблуждение имя
   `AbstractRuleSubjectControlTest`. Ещё одно утверждение 01c — «`readExcludedFixtureKeys()` из
   `ChannelCoverageTest` дублирует хелпер `ChannelEmissionStaticGuardTest`» — **не подтвердилось**:
   метод с таким именем есть только в `ChannelEmissionStaticGuardTest` (стр. 1034), в
   `ChannelCoverageTest` его нет; в реестр не вносил.

---

## Шов 1. `tests/Analysis/Finding/Support/FindingFactory.php` живёт не в своём предмете

**Что не так.** Файл лежит в предмете Finding, но ни один тест Finding его не использует.

**Чем подтверждается.** `grep -rl FindingFactory tests/ src/ scripts/` даёт ровно девять мест, кроме самого файла:

- восемь `use Qualimetrix\Tests\Analysis\Finding\Support\FindingFactory;` — и все восемь в
  `tests/Analysis/Policy/Baseline/Unit/`: `BaselineIdentityTest`, `BaselineGeneratorTest`, `BaselineCleanerTest`,
  `BaselineUpdaterTest`, `BaselineCeilingStage{Acceptance,FailSafe,JudgeAll,Promotion}Test`;
- девятое — `scripts/generate-modular-architecture-test-inventory.php:181`, где путь
  `tests/Analysis/Finding/Support/FindingFactory.php` **захардкожен** внутри константы
  `P6_A_FINDING_TEST_PATHS` («Exact Finding test closure; future siblings require an ownership decision»).

Потребителей в `tests/Analysis/Finding/` — ноль.

**Что делать.** Перенести в `tests/Analysis/Policy/Baseline/Support/FindingFactory.php` (рядом с уже существующим
`Baseline/Fixtures/CeilingStageFixtures.php`), сменить namespace, поправить восемь `use`.
**Перенос не полон без правки `scripts/generate-modular-architecture-test-inventory.php`** — путь там записан
буквально, и манифест модульной архитектуры считает этот файл частью замыкания Finding. Порядок: перенос →
правка скрипта → `composer architecture:check` (он же проверяет свежесть сгенерированных артефактов).

Реестр: `misplaced`, low (одна строка, срез 01).

---

## Шов 2. Два параллельных дома функциональных тестов консоли — и механизм, который их держит

**Что не так.** `tests/Functional/Console/` и `tests/Infrastructure/Console/Functional/` — два каталога
одного назначения. Пересечений по содержимому нет (Hook-команды только в первом, всё остальное только во втором),
но это частный случай более широкого явления, которое не видит ни один срез: в дереве сосуществуют
**две раскладки**, старая («уровень теста в корне, предмет — подпапка»: `tests/Unit/*`, `tests/Integration/*`,
`tests/Functional/*`) и новая по ADR 0022/0016 («предмет — папка, уровень — подпапка внутри предмета»).
Старую населяют `tests/Unit/{Reporting,Infrastructure,Core,PromiseEffect,RuleVocabulary}`,
`tests/Integration/{Architecture,Infrastructure,DependencyInjection}`, `tests/Functional/{Console,Reporting}`.

**Чем подтверждается.** `phpunit.xml.dist`: сюиты `Unit` и `Integration` **перечисляют каталоги поимённо** —
33 записи `<directory>` в `Unit` и 16 в `Integration`, включая одновременно `tests/Unit` (старый корень) и
`tests/Analysis/.../Unit`, `tests/Reporting/.../Unit` (новый). Сюиты `Functional` и `Infrastructure` короче:
`tests/Functional` + `tests/Analysis/Policy/Baseline/Functional`, и `tests/Infrastructure` целиком.

**Важное отрицательное наблюдение (проверено, а не предположено).** Прямо сейчас ничего не потеряно:
скрипт по `phpunit.xml.dist` против `glob('tests/**/*Test.php')` даёт **679 тестовых файлов и 0 недостижимых**;
независимо то же говорит колонка `suites` в `tests-inventory.csv` — пустых нет ни у одного из 679.

**Риск — латентный, и он ровно того класса, который в памяти проекта называется «ownerless files break packages».**
Сторожа на это в дереве нет — проверка «каждый `*Test.php` достижим хотя бы из одной `<directory>`»
не выполняется ничем.

**И риск не абстрактно-будущий: дыры зияют вокруг УЖЕ существующих capability.** Диф 53 перечисленных
записей `<directory>` против декартова произведения `{существующая capability} × {Unit, Integration, Functional}`
даёт **39 непокрытых уровневых каталогов**, и ни один из них сейчас не существует на диске — именно поэтому
679/0 сходится. Среди них: `Evidence/{CircularDependency,Cohesion,DependencyModel,Duplication,Maintainability,
Prioritization,Security,Size}/Integration`, `Reporting/{FindingProjection,Formatter,GraphProjection}/Integration`
и **`Functional/` у каждой capability, кроме `Policy/Baseline`** (включая `Finding/Functional`,
`Run/Functional`, `Configuration/Functional`, `Policy/{Architecture,Inline}/Functional`).

**Отсюда главное следствие, которого не видел ни один срез: лечение реестра само наступает на этот шов.**
Штатный ремонт строки `category-wrong` — «перенести файл в каталог своего настоящего уровня». Для части из
32 таких строк целевой каталог ровно в списке непокрытых, и перенос **создаст новый каталог, не попадающий
ни в одну сюиту, — тест молча перестанет исполняться при зелёном `composer check`**. Конкретно:
`CycleIdentityStabilityTest` → `Evidence/CircularDependency/Integration/`;
`LcomCollectorTest`/`TccLccCollectorTest` → `Evidence/Cohesion/Integration/`;
`DuplicationDetectorTest` → `Evidence/Duplication/Integration/`;
`DuplicationMemoryLimitProcessTest`, `RuleOptionKeyDoorSymmetryTest`, `TranslatedRefusalVocabularyTest`,
`ConfigurationValidatorSilencingPathsTest` → любой `*/Functional/`.
То есть сторож достижимости — **предусловие** пакета `category-wrong`, а не приятное дополнение.

**Что делать, по убыванию ценности.**
1. **Сначала** завести сторож достижимости: тест (или шаг `composer check`), сверяющий множество
   `tests/**/*Test.php` с раскрытием `<directory>` из `phpunit.xml.dist`; расхождение — красное.
   Закрывает класс, а не случай. Альтернатива дешевле и радикальнее: заменить 53 перечисления одной
   `<directory>tests</directory>` на сюиту с фильтрацией по `#[Group]`/суффиксу — но это смена
   модели сюит и отдельное решение владельца.
2. Перенести `tests/Functional/Console/*` (три Hook-теста + `LayerAssignmentCommandTest`) под
   `tests/Infrastructure/Console/Functional/` — все их SUT лежат в `src/Infrastructure/Console/Command/`.
   Это же снимает шов 3a. Целевой каталог **покрыт** (сюита `Infrastructure` берёт `tests/Infrastructure` целиком),
   так что этот перенос безопасен и без сторожа.
3. Доводить миграцию остальных старых корней отдельными пакетами; пока она не доведена, реестр несёт
   45 строк `misplaced`, значительная часть которых — именно этот хвост.

---

## Шов 3. Два дублирующихся имени файлов в дереве

Совпадающих basename во всём дереве ровно два (проверено по `tests-inventory.csv`), и **коллизий FQCN нет**:
namespace у всех четырёх файлов разные, PHPUnit грузит и исполняет все четыре. Вариант «один файл молча
не исполняется» исключён.

### 3a. `HookStatusCommandTest.php` — это **расщеплённый один предмет**

| файл                                                                  | namespace                                               | что проверяет                                                                                                            |
| --------------------------------------------------------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `tests/Functional/Console/Command/HookStatusCommandTest.php`          | `Qualimetrix\Tests\Functional\Console\Command`          | 7 тестов поведения `execute()`: hook не установлен, symlink, copy, чужой хук, неисполняемый, backup, вне git-репозитория |
| `tests/Unit/Infrastructure/Console/Command/HookStatusCommandTest.php` | `Qualimetrix\Tests\Unit\Infrastructure\Console\Command` | 3 смоук-теста `configure()`: имя и description, «нет своих опций», «нет аргументов»                                      |

Оба несут `#[CoversClass(HookStatusCommand::class)]` и оба конструируют
`new HookStatusCommand(new GitRepositoryLocator())`. Дублирования утверждений нет — это один SUT,
разрезанный по уровню и разложенный по двум конкурирующим корням (шов 2). Докблок второго честно
называет себя смоуком.

**Что делать.** Слить в один файл под `tests/Infrastructure/Console/Functional/Command/HookStatusCommandTest.php`
(или, если смоук-часть хочется сохранить отдельно, оставить два файла, но в **одном** корне и с разными
именами — `HookStatusCommandDefinitionTest` рядом с `HookStatusCommandTest`). Заодно чинится
`chdir()`-без-восстановления в функциональной половине (реестр, срез 04, `other`/medium).

### 3b. `UnmatchedExcludeIntegrationTest.php` — это **два разных предмета**

| файл                                                                                 | SUT                                                | канал                                                |
| ------------------------------------------------------------------------------------ | -------------------------------------------------- | ---------------------------------------------------- |
| `tests/Analysis/Policy/Architecture/Integration/UnmatchedExcludeIntegrationTest.php` | `LayerViolationRule` + `LayerDeclarationValidator` | `architecture.unmatched-exclude` (docblock, стр. 17) |
| `tests/Analysis/Run/Integration/ExcludeBinding/UnmatchedExcludeIntegrationTest.php`  | `UnmatchedExcludeRule` + `UnmatchedExcludeAudit`   | `discovery.unmatched-exclude` (docblock, стр. 17)    |

Разные `#[CoversClass]`, разные каналы, разные утверждения. Не дубль. Но структура текста у них
зеркальная (`itStaysSilentOnARunNarrowedBelowTheAutoloadRoots`, `itLeavesTheRunGreenUnderFailOnNone`,
`itFailsTheRunUnderFailOnWarning` — одинаковые имена в обоих файлах), и совпадающий basename делает любую
ссылку «в UnmatchedExcludeIntegrationTest сказано…» неоднозначной.

**Что делать.** Переименовать по каналу, который каждый охраняет:
`ArchitectureUnmatchedExcludeIntegrationTest` и `DiscoveryUnmatchedExcludeIntegrationTest`.
Реестр: `other`, low.

---

## Шов 4. Дубли, чьи двойники попали в РАЗНЫЕ срезы

Внутрисрезовые дубли агенты видели; межсрезовых не видел никто — граница среза проходила между файлами.

**Как перечислено, а не найдено на глаз.** Скрипт прошёл все 679 `*Test.php`, отнёс каждый к одному из
десяти срезов по префиксу пути (нераспределённых — 0), вынул каждый `#[CoversClass(X::class)]` и сгруппировал
по `X`. Классов, покрываемых из **двух и более** срезов, — 38. Из них 20 — артефакт одного файла
`Policy/Inline/Unit/ThresholdOverrideIntegrationTest.php`, который рефлексией обходит 17 `*Options`-классов
чужих capability: это его предмет, а не дубль. Остальные прочитаны попарно. Оракул неполный: файлы
без `#[CoversClass]` (например `CoversNothing`-тесты) он не видит — поэтому докс-семейство ниже найдено
отдельным `grep -rl "website/docs" tests/`.

### 4a. `RuleOptionsCompilerPass` — срезы 04 и 10 (подтверждённый дубль)

`tests/Infrastructure/Integration/SharedRuleOptionsContainerTest.php` (единственный тест, стр. 55) и
`tests/Infrastructure/Unit/RuleOptionsCompilerPassTest.php::itKeepsTheOptionsServiceIdentitySeparateForEveryProducer`
(стр. 144). Оба несут `#[CoversClass(RuleOptionsCompilerPass::class)]`, оба утверждают «у каждого
producer'а, делящего Options-класс, свой отдельный options-сервис», и **список из десяти producer-правил
(7 × `CodeSmellOptions` + 3 × `SecurityPatternOptions`) скопирован в оба файла дословно**, включая порядок.
Разница — уровень: один читает граф definition'ов после `process()`, другой берёт `RuleOptionsRegistry`
из собранного контейнера. Законное расслоение, но ни один файл не ссылается на другой, а список
producer'ов придётся править в двух местах. Реестр: `dupe`/medium.

### 4b. `RulesCommand` — срезы 04 и 10 (подтверждённый дубль)

`tests/Infrastructure/Integration/RulesCommandWiringTest.php` (3 теста, реальный контейнер) против
`tests/Infrastructure/Unit/RulesCommandTest.php` (12 тестов, `CommandTester`):
`itRefusesAGroupNoProducerHas` (стр. 212) ↔ `itFailsOnAGroupNoProducerHas` (стр. 83);
`itFiltersByGroupAgainstTheRealRuleSet` (120) ↔ `itFiltersRulesByGroup` (140);
`itListsEveryRegisteredRule` (35) ↔ `itListsRulesUnderGroupHeaders` (120).
Все три теста «wiring»-файла имеют двойника в «unit»-файле, отличающийся только источником набора правил.
Реестр: `dupe`/medium. Эти же два файла несут ещё и `misplaced` (оба лежат не в
`tests/Infrastructure/Console/`, хотя SUT — Console-домен).

### 4c. «У каждого канала есть страница доки» — срезы 01 и 05 (подтверждённый дубль)

`tests/Analysis/Finding/Integration/ChannelPresentationCoverageTest.php` и
`tests/Reporting/Formatter/Sarif/Integration/SarifRuleDescriptorCoverageTest.php` устроены одинаково:
оба разбиты на те же два метода (весь статический универсум каналов + configured computed-metric каналы),
оба резолвят `website/docs` (`dirname(__DIR__, 4)` и `dirname(__DIR__, 5)` соответственно) и оба падают,
если у канала нет страницы. Докблок второго прямо признаёт родство («Matches ChannelPresentationCoverageTest»).
Первый проверяет это через `ChannelPresentationInterface::presentationFor()`, второй — через `helpUri`
SARIF-дескриптора. Факт про существование страницы утверждается дважды; уникален у второго только
формат helpUri. Реестр: `dupe`/medium на Sarif-стороне.

Рядом стоят ещё три докс-сверщика в трёх других срезах — `RuleDocsPageCoverageTest` и
`RuleRemediationMinutesCoverageTest` (оба 01, между собой уже отмечены как дубль хелперов),
`DebugCodeDocumentationConsistencyTest` (06), `ChannelPublicationConsistencyTest` и
`DocumentationConsistencyTest` (03). Разные факты, но одна и та же механика «пройтись по реестру и
`is_file`/`preg_match` по `website/docs`», реализованная шесть раз шестью приватными хелперами.
Это кандидат на один общий docs-guard, но утверждать «дубль факта» сверх пары 4c я не могу без
построчного чтения всех шести — в реестр не вносил.

### 4d. Проверено и дублем НЕ оказалось (отрицательный результат, чтобы не перепроверяли)

- **`Application`, срезы 04 и 10.** `Unit/Infrastructure/Console/ApplicationTest` (14 тестов, `CommandTester`)
  и `Infrastructure/Console/Functional/ApplicationRefusalTest` (9 тестов, реальный подпроцесс) имеют три
  тематически парных теста (неизвестная команда; working-dir — файл; нечитаемый working-dir). Функциональная
  половина проверяет то, что юнит физически не может: отсутствие PHP-warning на stderr, отмену кода 255,
  трассу под `-v`. Расслоение законное, строк реестра не ставил.
- **`FileProcessingResult`, срезы 09 и 10.** `Run/Unit/Collection/FileProcessingResultTest` (контракт VO:
  success/failure/частичные состояния) против `Unit/Infrastructure/Parallel/FileProcessingResultWireFormatTest`
  (round-trip через `serialize` и igbinary). Пересечения утверждений нет.
- **`VisitorMethodContext`, срезы 06 и 07.** `Complexity/Unit/CyclomaticComplexityVisitorTest` (3 теста про
  скоупинг визитора) против `Measurement/Unit/VisitorMethodContextTest` (7 тестов про сам контракт контекста).
  Соприкасаются на «состояние сбрасывается между файлами», но с разных сторон; строгим дублем не назову.
- **`RuleCompilerPass`, `FormatterContextFactory`, `BaselineGenerateCommand`, `ChannelDeclaration`,
  `ConfigurationPipeline`, `RuleOptionsFactory`, `RuleExecution`, `CompositeCollector`** — покрываются из
  двух срезов, но каждым файлом под своим углом; общих утверждений при чтении имён методов не видно.

---

## Число строк реестра

`defect-ledger.tsv` — **213 строк** (плюс строка заголовка).

Разбивка по классам: `other` 60, `dupe` 47, `misplaced` 45, `category-wrong` 32, `tautology` 13,
`name-lies` 8, `stale-doc` 7, `never-runs` 1.
По severity: high 9, medium 106, low 98.

Все 213 значений колонки `file` и все непустые значения колонки `counterpart` проверены на существование
циклом `while read -r f; do [ -f "$f" ] || echo MISSING "$f"; done` — `MISSING` нет ни одного.
Каждая строка ровно из 7 полей; класс, severity и связка `counterpart`⇔`dupe` держатся `assert`-ами
в генераторе.

**Самая дорогая одиночная находка реестра** — единственная строка класса `never-runs`:
`tests/Analysis/Evidence/Design/Unit/TypeCoverage/TypeCoverageRuleTest.php:143`,
метод `itAliasesItsOwnTwoBoundariesOnly` объявлен без `#[Test]` и `#[DataProvider]` при том, что все
одиннадцать соседних методов файла их имеют. PHPUnit его не вызывает; CLI-alias контракт трёх
type-coverage правил сейчас не проверяется ничем. Перепроверено мной лично полным чтением файла
(`grep` по сигнатурам этого не видит — атрибуты стоят на отдельных строках).
