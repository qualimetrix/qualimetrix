# Этап 01, часть 3. Пакеты работ, порядок и ничьи файлы

Продолжение `01-refusal-envelope.md`, `01-refusal-exit-ladder.md` и
`01-refusal-verdicts.md`; решения и вердикты приняты там и здесь не повторяются.
Нумерация разделов сквозная. Порядок пакетов (§8) и ответ «каких файлов не называет ни
один пакет» (§9) — в `01-refusal-order.md`: файл разделён по порогу 400 строк.

---

## 7. Пакеты работ

Восемь. Наборы файлов пересекаются только там, где это названо; P01-3, P01-4 и P01-5
взаимно параллельны, P01-2 предшествует всем трём, P01-7 следует за всеми тремя.
P01-6 идёт после P01-5: сквозной исход `rules --group` требует броска из одного пакета и
клаузы из другого (довод — в P01-6).

### P01-1 — предъявитель (только новые файлы)

Файлы: `src/Infrastructure/Console/Refusal/{ConsoleExitCode,RefusalPresenter,MachineReadableFormats}.php`,
`tests/Infrastructure/Console/Unit/Refusal/**` (новый каталог **внутри** `tests/Infrastructure`,
который сьют берёт целиком — `phpunit.xml.dist:83`),
`docs/internal/modular-architecture-manifest.json`,
`docs/internal/generated/modular-architecture/*`, `qmx.yaml`.
Зависимости: 03/P2 (типы носителя) — жёсткая, потому что сигнатуры предъявителя
именуют носителя (`refusal(OutputInterface, ?string, ConfigurationRefusal)`, §3).
Параллельно: с 03/P1 и со всем этапом 02.
Аддитивный, никем не вызывается. Дерево зелёное.
**DoD:** `vendor/bin/phpunit --list-tests | wc -l` вырос ровно на число новых тестов;
`composer check:code` зелёный; `composer architecture:check` зелёный после внесения трёх
деклараций в манифест и регенерации.

### P01-2 — предъявитель проведён, носитель пойман (РАЗБЛОКИРОВЩИК)

Файлы: `src/Infrastructure/Console/Command/{CheckCommand,BaselineCommand,DirectivesCommand,Debug/LayerAssignmentCommand}.php`,
**`src/Infrastructure/DependencyInjection/Configurator/OutputConfigurator.php`**,
**`src/Infrastructure/DependencyInjection/Configurator/ArchitectureConfigurator.php`**,
`tests/Infrastructure/Console/Integration/ConfigurationRefusalRoutingTest.php` (новый).
Зависимости: **03/P2 и P01-1.**
**Обязан выйти до 03/P3–P5, до 02/P2 и до P01-3…P01-6** (порядок обзора, шаг 2): иначе
носитель падает в `catch (Throwable)` `CheckCommand.php:201` и в
`catch (InvalidArgumentException|RuntimeException)` `BaselineCommand.php:54` и отдаёт 1
вместо 3.

Содержимое: `RefusalPresenter` регистрируется в контейнере и внедряется в четыре командных
класса (`OutputConfigurator` — `check`, `baseline:*`, `directives`; `ArchitectureConfigurator:77`
— `debug:layer-assignment`); `catch (ConfigurationRefusal)` встаёт **первой клаузой** в
каждой из четырёх лестниц — до `RuntimeException` в `BaselineCommand`, до `Exception` в
`DirectivesCommand` и `LayerAssignmentCommand`, до `Throwable` в `CheckCommand` (порядок
обязателен: носитель — `RuntimeException`); возврат `ConsoleExitCode::Refusal`.
**Предъявитель вызывается с `format: null`** — то есть одно предложение на stderr, без
конверта: протягивание формата в предъявитель принадлежит P01-3/P01-4/P01-5, каждому в
своей команде. Отсюда и промежуточное состояние, названное в §8: код уже 3 везде, конверта
и доставки под `-q` ещё нет.
Ничего не удаляет: старые `catch` остаются рабочими, потому что старые классы ещё
бросаются.

**Развилка, решённая здесь: проводка предъявителя принадлежит разблокировщику.**
Console-сервисы регистрируются явно, автовайринга нет, поэтому DI-конфигуратор обязан
попасть в чей-то набор. Отвергнуто: **каждый из P01-3…P01-6 правит `OutputConfigurator`
сам** — четыре взаимно параллельных пакета, пишущих в один файл, это прямое пересечение
наборов, ровно то, ради предотвращения чего наборы и разделены. Отвергнуто: **отдельный
маленький пакет «проводка» между P01-2 и P01-3** — он короче критического пути на один
шаг, но оставляет P01-2 печатать носителя «сегодняшними средствами каждой команды», то
есть четырьмя разными способами; тогда единообразие появляется не в разблокировщике, а
позже, и промежуточное состояние снова различает команды. Цена принятого выбора названа:
P01-2 приобретает зависимость от P01-1, и критический путь до 03/P3–P5 длиннее на один
аддитивный пакет.

**DoD.** Перечень команд задан **предикатом «команда читает `qmx.yaml`»** и его
сегодняшним значением, а не списком имён: измерение
(`measurement/verdicts-119-133-and-m6-routes-by-measurement.md` §3, двусторонний
дискриминатор) даёт **восемь** таких команд — `check`, `baseline:generate`,
`baseline:update`, `baseline:cleanup`, `baseline:rename-channels`, `baseline:explain`,
`directives`, `debug:layer-assignment`; `rules`, `graph:export` и `hook:*` конфигурацию
не читают. Пять `baseline:*` — пять отдельных регистраций в `OutputConfigurator:492-528`,
и предъявитель обязан попасть в каждую: пропущенная регистрация проявится не сборкой
контейнера, а отсутствием аргумента у одной команды.

Носитель, брошенный подставленным резолвером конфигурации, даёт **exit 3** во всех восьми, `BaselineConflictException`
по-прежнему даёт 1, `IncompleteAnalysisException` по-прежнему 4, контейнер собирается
(`composer test` на `tests/Integration/DependencyInjection/ContainerFactoryTest.php`
зелёный), `--list-tests` вырос на число новых кейсов, `composer check:code` зелёный.
Дерево зелёное: старые маршруты не тронуты.

### P01-3 — `check`, его валидаторы ввода и `--output`

Файлы: `Command/CheckCommand.php`, `ResultPresenter.php`, `ExitPolicy.php`,
`FormatterContextFactory.php`, `RuleInputValidator.php`, `ChannelExclusionKeyValidator.php`,
`RuntimeLimits.php`, `RuntimeLimitsController.php`, `CheckScopeResolver.php`,
**`ScopeWarningChecker.php`**;
`tests/Infrastructure/Console/Unit/{RuleOptionKeyDoorSymmetryTest,ExitPolicyTest,RuleInputValidatorTest,ChannelExclusionKeyValidatorTest,ResultPresenterTest}.php`,
`tests/Infrastructure/Console/Functional/Command/{CheckCommandConfigErrorExitCodeTest,CheckCommandInputValidationTest,CheckCommandPathInputTest,CheckCommandConfigurationErrorGateTest,ChannelExclusionKeySpellingTest}.php`,
`tests/Infrastructure/Console/Integration/RuleExclusionStatsWiringTest.php`,
`tests/Unit/Infrastructure/Console/FormatterContextFactoryTest.php`.
Зависимости: P01-2 **и 02/P2**. Параллельно: с P01-4, P01-5, P01-6, а также с 03/P3–P5.

**Ребро к 02/P2 — не формальность.** 02/P2 меняет тексты отказов `computed_metrics`
(«Invalid formula syntax», «references unknown metric»), которые закрепляет
`CheckCommandConfigErrorExitCodeTest`, и подаёт секцию в фикстурах ещё трёх файлов этого
набора. **Синхронную правку этих четырёх файлов делает 02/P2 в своём же изменении**
(обоснование — `02-computed-metric-packages.md`, P2), поэтому красного окна между пакетами
нет: аггрегат зелёный и после 02/P2, и после меня. Я те же четыре файла правлю **позже**,
уже под конверт и `-q`; пересечение наборов здесь законно, потому что ребро жёсткое и
названо в обоих планах.

Содержимое: 19 мест `InvalidArgumentException` (`01-refusal-verdicts.md` §6.1) → носитель;
конверт и `-q` через предъявителя; `--format` проверяется до анализа через
`FormatterRegistry::has()`; `check --output` — предпроверка до анализа и бросок в
`writeOutput()` (`01-refusal-verdicts.md` §5.4), с проверкой, что `presentResults()` лежит
внутри того же `try`; три глотающих `catch (RuntimeException)` в `ScopeWarningChecker`
сужаются (`01-refusal-verdicts.md` §6.2); два теста X13
(`itStillRefusesUnderQuiet`, `itLeavesStdoutEmptyUnderJsonFormat`) переписываются под
новое поведение.

**DoD:**
* `grep -rn 'throw new InvalidArgumentException' <файлы пакета>` пуст;
* `check --format=json` на битом ключе даёт `ec=3` и **разбираемый** stdout;
* `check … -q` на том же входе даёт `ec=3` и непустой stderr;
* `check --format=checkstyle` на нём же — `ec=3`, stdout 0 байт, непустой stderr;
* `check --output=<каталог без права записи>` даёт `ec=3` **до** анализа (счётчик вызовов
  коллектора на подставленном пайплайне — ноль), а тот же путь, ставший незаписываемым
  после предпроверки, даёт `ec=3` из `writeOutput()`;
* `check --output=…` на входе с находками даёт **3**, а не 2 — отказ побеждает исход;
* `--list-tests` вырос; `composer check:code` зелёный **на выходе пакета**.

### P01-4 — `baseline:*`

Файлы: `Command/{BaselineCommand,BaselineExplainCommand,BaselineGenerateCommand,BaselineRun}.php`,
**`Command/{BaselineUpdateCommand,BaselineCleanupCommand,BaselineRenameChannelsCommand}.php`
и `Command/ChannelRenameReporter.php`** (расширение по второму раунду: предикат «читает
конфигурацию» даёт СЕМЬ команд — `baseline:rename-channels` в их число не входит.
`BaselineRenameChannelsCommand::__construct` берёт только `BaselineChannelRenamer`, и её
докблок называет это прямо: «no `--config`, no measured set» — команда только
переписывает поле `channel` в уже существующем baseline-файле по карте, которую подаёт
пользователь, анализ не запускает и `qmx.yaml` не открывает. Она включена в этот пакет
не по предикату чтения конфигурации, а для симметрии с остальными `baseline:*` —
`ChannelRenameReporter` разделяет с ними носителя и правило конверта, а
`baseline:rename-channels` вдобавок несёт собственный `--format=json`, то есть подпадает
под правило конверта на общих основаниях);
файлы `tests/Analysis/Policy/Baseline/Functional/`, **утверждающие код возврата отказа** —
сегодня это `{BaselineCommandFailureReportingTest,BaselineExplainCommandTest,BaselineCleanupCommandTest,BaselineUpdateCommandTest,BaselineGenerateCommandTest,BaselineRenameChannelsCommandTest,BaselineIncompleteAnalysisTest,BaselineRunBeforeLoadTest}.php`
(критерий и его согласование с 03 — §9).
Зависимости: P01-2. Параллельно: с P01-3, P01-5, P01-6.
Содержимое: поток отказа переезжает со stdout на stderr; клауза
`catch (InvalidArgumentException|RuntimeException)` расщепляется (IAE → 3, RuntimeException
→ 1, `01-refusal-exit-ladder.md` §2.6); `Command/BaselineRun.php:121` → носитель;
`--channel` (синтаксис и адресуемость, `01-refusal-verdicts.md` §5.3);
`baseline:generate` в существующий файл без `--force` → отказ 3 на stderr
(`01-refusal-verdicts.md` §5.5); `BaselineConflictException` остаётся кодом 1 —
под рукотворным тестом.

**Формат протягивается в лестницу.** Клауза одна на все пять команд
(`BaselineCommand::execute()`), а `--format` объявлен только у
`baseline:rename-channels`; поэтому лестница спрашивает формат у наследника
(значение по умолчанию — `null`, то есть «машинной трубы нет»), а не читает опцию,
которой у четырёх команд не существует. Иначе `getOption('format')` уронил бы четыре
команды на `ConsoleExceptionInterface`.

**Стабы `BaselineCommandFailureReportingTest` переписывает этот пакет, и это названо
работой, а не следствием.** Файл семь раз конструирует `ConfigLoadException`,
`ArchitectureConfigurationException` и `ArchitecturePreparationException`
(`:117,166,171,190,193,206,209,245,260`); критерий «утверждает код возврата отказа» отобрал
его в набор, но второй признак — **конструирует умирающие типы** — в критерии
отсутствовал, и без этой строки файл дожил бы до 03/P6 и уронил бы сборку на «class not
found». Стабы переводятся на носителя (`ConfigurationRefusal::aboutDocument`/`at`) в этом
же изменении; 03/P6 получает ребро к P01-4 поимённо.
**DoD:** `grep -rn 'throw new InvalidArgumentException' Command/BaselineRun.php` пуст;
`baseline:generate` с битым `qmx.yaml` даёт `ec=3`, stderr непуст, stdout пуст;
`baseline:generate <существующий файл>` без `--force` даёт `ec=3` и stdout 0 байт;
`baseline:explain` с неизвестным `subject` даёт `ec=3`;
`baseline:rename-channels --format=json` на нечитаемом файле карты (команда `qmx.yaml`
не читает вовсе — см. описание пакета выше) даёт `ec=3` и **конверт** на stdout, а на
верном входе — свой обычный JSON;
`BaselineConflictException` по-прежнему `ec=1`, `IncompleteAnalysisException` — `ec=4`;
`grep -rn 'ConfigLoadException\|ArchitectureConfigurationException\|ArchitecturePreparationException' tests/Analysis/Policy/Baseline/Functional/`
пуст;
`--list-tests` вырос; `composer check:code` зелёный.

### P01-5 — `directives`, `debug:layer-assignment`, `rules`, `graph:export`

Файлы: `Command/{DirectivesCommand,Debug/LayerAssignmentCommand,RulesCommand,GraphExportCommand}.php`,
`DirectiveAuditPresenter.php`,
**`src/Reporting/GraphProjection/Contract/{GraphDirection,GraphExportFormat}.php` (новые),
`src/Reporting/GraphProjection/{DotExporterOptions,DotExporter,DependencyGraphProjector}.php`,
`src/Reporting/GraphProjection/Contract/GraphProjectionRequest.php`,
`src/Reporting/GraphProjection/README.md`**,
`docs/internal/modular-architecture-manifest.json`,
`docs/internal/generated/modular-architecture/*`, `qmx.yaml`,
**`scripts/directive-audit-controls/Probes.php`** (пробники, чьи цели этот пакет
переписывает, — обоснование и измерение в `01-refusal-evidence.md` §13.1);
`tests/Infrastructure/Console/Functional/{DirectivesCommandTest,GraphExportCommandTest}.php`,
`tests/Infrastructure/Console/Unit/DirectiveAuditSummaryProjectionTest.php`,
`tests/Functional/Console/LayerAssignmentCommandTest.php`,
`tests/Infrastructure/Unit/RulesCommandTest.php`, `tests/Infrastructure/Integration/RulesCommandWiringTest.php`,
тесты `Reporting/GraphProjection` в уже перечисленных каталогах.
Зависимости: P01-2. Параллельно: с P01-3, P01-4. **P01-6 идёт после этого пакета** — ребро названо в P01-6.
Содержимое: `debug:layer-assignment` 2 → 3 и 1 → 3 для конфигурационного рода, плюс
недостающая клауза `catch (InvalidArgumentException)` (`01-refusal-exit-ladder.md` §2.6);
**`rules --group`: проверка бросает носителя вместо `<error>` + `return self::FAILURE`**
(`01-refusal-verdicts.md` §5.7) — конверта у команды нет (§2.1), код 3 ей даёт первая
клауза лестницы `Application`, которую заводит P01-6;
**`GraphExportCommand` заводит собственную `catch`-лестницу и получает предъявителя**,
потому что её `--format=json` машинный, а лестница `Application` формата не знает;
словари `--direction` и `--format` получают владельца в
`Reporting/GraphProjection/Contract/` (`01-refusal-verdicts.md` §5.1), проверка — в команде
до анализа; `--output` и несуществующий путь → 3; человеческие отказы `directives`
переезжают на stderr.

**Пробники стенда перенацеливаются в этом же изменении.**
`scripts/directive-audit-controls/Probes.php` планирует поломки по точным фрагментам
`Command/DirectivesCommand.php` (девять пробников) и `DirectiveAuditPresenter.php` (один);
фрагмент, которого не стало, — громкий отказ `Mutation::rewrite` («expects exactly one
occurrence … found 0»), измеренный в `01-refusal-evidence.md` §13.1. Перенацеленный
пробник обязан краснить те же названные кейсы, а не просто перестать планировать поломку.
**Коды 2 и 4 у `bin/qmx directives` сохраняются нетронутыми** — их читает
`composer directives:audit` внутри `check:self`; `exitCodeFor()` не трогается вовсе.
**DoD:**
* `composer directives:audit` даёт тот же код, что до пакета (снять до и после);
* **`composer directives:controls` проходит** — стенд не входит в `composer check`, поэтому
  без этой строки его поломку не увидит ни один пакет раунда;
* `bin/qmx directives <пустой каталог> --format=json` даёт `ec=3` и конверт;
* `graph:export --direction=bogus --format=json` даёт `ec=3` и **разбираемый** stdout
  (конверт), а не ноль байт — это и есть дефект, ради которого затеян раунд, в команде,
  которую он правит;
* `graph:export --direction=bogus` и `--format=bogus` дают `ec=3` **без запуска анализа —
  свидетельство — счётчик вызовов коллектора на подставленном пайплайне, равный нулю.**
  Сравнение времени прогона в DoD не входит: Р-8 сведения меряло разницу в сотые доли
  секунды, шкала на грани разрешения, а §10 требует счётчика;
* `grep -rn "'LR'\|'TB'\|'RL'\|'BT'" src/ | grep -v GraphDirection` пуст — словарь имеет
  ровно одного владельца;
* `grep -n 'return self::FAILURE' src/Infrastructure/Console/Command/RulesCommand.php` пуст —
  проверка `--group` бросает носителя, а не назначает код `return`-ом. **Сквозного кейса
  `ec=3` в DoD этого пакета нет** и это решение, а не пропуск: пока лестница P01-6 не
  вышла, носитель улетает мимо неё в `Application::run()` Symfony, где `getCode()` у него 0
  и код всё ещё 1. Здесь проверяется бросок, а не исход;
* `tests/Infrastructure/Unit/RulesCommandTest.php` больше не утверждает
  `assertSame(1, …)` на неизвестную группу: под `CommandTester` носитель вылетает из
  `execute()`, и старое утверждение краснеет в этом пакете независимо от P01-6;
* `composer architecture:check` зелёный после регенерации (новые декларации владельца
  `Reporting.GraphProjection`, новые точные импорты `Infrastructure.Console` — коарс-ребро
  уже есть, две строки в `production-cross-owner-imports.tsv`);
* `--list-tests` вырос; `composer check:code` зелёный.

### P01-6 — `bin/qmx` и `Application`

Файлы: `bin/qmx`, `src/Infrastructure/Console/Application.php`,
`tests/Unit/Infrastructure/Console/ApplicationTest.php`,
`tests/Infrastructure/Console/Functional/ApplicationRefusalTest.php` (новый).
Зависимости: P01-1, P01-2 **и P01-5** (см. ниже). Параллельно: с P01-3, P01-4.

**Ребро P01-6 → P01-5 заведено третьим раундом, и вот что оно чинит.** Сквозной кейс
`rules --group=bogus → ec=3` требует двух правок в разных пакетах: бросок носителя в
команде (P01-5) и клаузу, которая его ловит (этот пакет). Пока пакеты объявлены
параллельными, любой порядок оставляет исход на 1 — либо носитель летит мимо
несуществующей лестницы в `Application::run()` Symfony (`getCode()` = 0 → 1), либо
лестница уже стоит, а команда всё ещё делает `return self::FAILURE`. Поэтому ребро
названо, а кейс стоит в DoD того пакета, который выходит **вторым**.
**Отвергнуто: обратное направление (P01-5 после P01-6).** Довод за — лестница выглядит
инфраструктурой, а бросок её потребителем. Против: P01-5 уже ждёт только P01-2 и идёт
параллельно трём пакетам, а P01-6 — четыре файла; удлинять критический путь на широком
пакете дороже, чем на узком.
**Промежуточное состояние названо:** между P01-5 и этим пакетом `rules --group=bogus`
даёт `ec=1`, но диагностика уже уходит на stderr сообщением носителя, а не на stdout.
Это не хуже сегодняшнего (сегодня тоже 1) и красным дерево не делает.

Содержимое: `setCatchErrors(true)` в `bin/qmx`; **собственная лестница вокруг всего тела
нашего `doRun()`, назначающая код явно** (`01-refusal-exit-ladder.md` §2.4):
`ConfigurationRefusal` → 3 — **этой клаузой и получает свой код `rules`**, у которой
своей лестницы нет и не будет; `ConsoleExceptionInterface` → 3;
`InvalidArgumentException` → 3 (вторичный признак: отказы, брошенные до `execute()`, и
`InvalidArgumentException` без носителя — §2.4); `Throwable` → 1; `getCode()` пойманного игнорируется; печать — **через предъявителя с
`format: null`**, потому что Symfony пойманное уже не рендерит, а второго обрамления в
дереве быть не должно; `Application` получает предъявителя **вторым аргументом
конструктора** из контейнера в `bin/qmx`; `Application.php:59,66` → носитель, с проверкой
`is_dir`/`is_readable` **до** `chdir`, чтобы Warning не уходил в оба потока.

**Границы лестницы названы, потому что от них зависят два пункта DoD.** Она оборачивает
**всё** тело `doRun()`, включая проверки `--working-dir`, которые бросают выше
`parent::doRun()`: обёртка вокруг одного вызова родителя оставила бы `-d <файл>` Symfony,
и он дал бы 1, а не 3. Она ловит и отказы, брошенные до `execute()` (в том числе из
`configure()`, где `RuleInputValidator::configureCheckCommand()` работает уже в
конструкторе команды, — команды грузятся лениво через `ContainerCommandLoader`). Она
**не** накрывает окно `configureIO()`: там по-прежнему работает `catchExceptions` Symfony,
для `Exception` существовавший и до раунда, и код берётся из `getCode()`.

**Переопределённый `renderThrowable` на путях лестницы больше не вызывается**, поэтому
`stopProgress()` и решение о трассе переехали в предъявителя
(`01-refusal-exit-ladder.md` §3). Сам метод остаётся — он нужен в окне `configureIO`.
**DoD:**
* вход, дававший 255 (крах `computed_metrics` в `directives` и `debug:layer-assignment`),
  даёт `ec=1`, stdout 0 байт, stderr непуст;
* **пробник с исключением, несущим ненулевой код** (`new JsonException('x', 4)` из
  подставленного сервиса), даёт `ec=1`, а не 4 — без него «внутренняя ошибка = 1» остаётся
  утверждением о входах с `getCode() === 0`;
* `-d <файл>` даёт `ec=3`; `-d <каталог chmod 000>` даёт `ec=3` **без** PHP Warning
  (stdout 0 байт); `chek` неинтерактивно даёт `ec=3`. Первый кейс — прямой страж границы
  лестницы: при обёртке вокруг одного `parent::doRun()` он даёт 1;
* **отказ при живом прогресс-фрейме читается целиком** — прогресс включён, отказ поднят
  из позднего валидатора, stderr содержит предложение, а не остаток фрейма поверх него;
* `-vvv` на внутренней ошибке печатает трассу, `-vvv` на отказе по вводу — не печатает;
* `grep -rn 'throw new InvalidArgumentException' src/Infrastructure/Console/Application.php` пуст;
* `rules --group=bogus` даёт `ec=3`, а не 1, и диагностика на stderr. Кейс сквозной и
  потому стоит **здесь**, а не в P01-5: бросок делает P01-5, ловит его первая клауза этой
  лестницы, и зелёным он становится только когда вышли оба (ребро выше). Измерение «до»:
  `ec=1, stdout 172 б, stderr 0 б`;
* `--list-tests` вырос; `composer check:code` зелёный.

### P01-7 — снятие старых `catch` и `ConfigurationFailure`

Файлы: удаление `src/Infrastructure/Console/ConfigurationFailure.php`; снятие `use`/
`catch` умирающих классов в `Command/{CheckCommand,BaselineCommand,DirectivesCommand,Debug/LayerAssignmentCommand,BaselineRunInterface}.php`;
`tests/Infrastructure/Console/Integration/RuntimeConfigurationIsolationTest.php` и
`tests/Infrastructure/Console/Unit/RuleOptionKeyDoorSymmetryTest.php` (**только снятие
импортов**: клаузу первого на носителя переписывает 03/P4 в своём изменении, иначе между
P4 и этим пакетом остаётся красное окно — `01-refusal-evidence.md` §13.2),
**`scripts/directive-audit-controls/Probes.php`** (пробник
`unreadable-config-is-not-a-config-error` нацелен на удаляемый файл),
`src/Infrastructure/README.md`.
Зависимости: **03/P3, 03/P4, 03/P5, 02/P2 и P01-3, P01-4, P01-5.**

**Ребро к 02/P2 добавлено, и вот почему его не было.** `CheckCommand.php:194` ловит
**пару** `ComputedMetricConfigurationException|BaselineLoadException`. Снять её ради
второго типа, не дождавшись 02/P2, значит уронить все семнадцать отказов `ComputedMetrics`
с кода 3 на 1 «Unexpected error» — и прежний DoD-grep этого не ловил, потому что имени
`ComputedMetricConfigurationException` в нём не было. Теперь оно есть, и 02/P3 опирается
на этот пакет поимённо, а не на «пакет 01».

**Оставляет некомпенсированным, если запустить раньше:** снятые `catch` для ещё
бросаемых `ConfigLoadException`/`ArchitectureConfigurationException`/
`ComputedMetricConfigurationException` уронят отказ в `catch (Throwable)` → код 1
«Unexpected error». Пакет исполняется **после** нормализации в источнике, не до.
**DoD:** `grep -rn 'ConfigurationFailure\|ConfigLoadException\|ArchitectureConfigurationException\|ArchitecturePreparationException\|BaselineLoadException\|ComputedMetricConfigurationException' src/Infrastructure bin scripts` пуст
(`scripts` в списке потому, что `Probes.php` — единственный файл вне `src`/`bin`,
называющий удаляемый класс);
**`composer directives:controls` проходит** — пробник перенацелен на то, что заменило
таксономию, и по-прежнему краснит свой названный кейс;
`composer check` зелёный целиком (это же условие снятия блокировки 03/P6 и 02/P3).

### P01-8 — документация, ADR, CHANGELOG, проза X13

Файлы: `docs/adr/NNNN-refusal-is-not-routed-by-command.md` (новый),
`CHANGELOG.md`, `website/docs/usage/cli-options{,.ru}.md`,
`website/docs/usage/output-formats{,.ru}.md`, `website/docs/ci-cd/other-ci{,.ru}.md`,
`website/docs/ci-cd/github-actions{,.ru}.md`, `website/docs/usage/baseline{,.ru}.md`,
`website/docs/getting-started/configuration{,.ru}.md`,
**`website/docs/rules/architecture{,.ru}.md`**,
`docs/internal/plans/rule-option-key-recognition/03-refusal-at-every-depth.md` (строки
389–390).
Зависимости: P01-3…P01-7 (описывается то, что уже сделано). Параллельно: с 03/P6.

**Способ перечисления website-файлов заменён, и записан сам способ, а не его результат.**
Прежний шёл по разделам `usage/` и `ci-cd/` и пропустил `website/docs/rules/architecture.md:754`
— страницу правил с собственной таблицей кодов `debug:layer-assignment` («`2` for invalid
input …, `1` for configuration-load errors»), то есть ровно маршруты 34, 35, 36, которые
P01-5 переводит на 3. Новый способ: `grep -rln 'exit code\|код возврата' website/docs/`
плюс `grep -rln` по именам затронутых команд, по всему дереву сайта. Слепое пятно способа
названо: страница, описывающая коды словами без этих подстрок, им не находится.
Отдельно: в `architecture.ru.md` соответствующий абзац **есть**, только под другим
заголовком — «Коды выхода» (`:757`), со старыми кодами (`2` для невалидного ввода,
`1` для ошибки загрузки конфигурации). Способ перечисления его не нашёл: он искал
подстроку «код возврата», а страница озаглавливает раздел иначе и использует эту фразу
только внутри абзаца. Правило «править одновременно» здесь означает **исправить** уже
существующий RU-абзац под новые коды, а не дописывать отсутствующий; слепое пятно способа
дополнено этим случаем — заголовок раздела тоже нужно грепать, не только тело.

**Правка чужого плана — работа этого пакета, не побочный эффект:**
`…/03-refusal-at-every-depth.md:389` обещает «under `--format=json` with stdout left
parseable (row 52)», а измерено 0 байт. Строка и соседняя проза про `-q` (`:388-390`)
приводятся в соответствие с фактом на `1513bf67` и с тем, что делает этот этап.
Правится **только** эта проза; остальной файл не трогается.

**DoD:** `composer docs:check` зелёный; `composer check:docs` зелёный;
`grep -n 'left parseable' docs/internal/plans/rule-option-key-recognition/` пуст;
`grep -rn '2. for invalid input' website/docs/rules/` пуст;
в `CHANGELOG.md` есть раздел `Breaking` со всеми записями §11.

**Числа «после» этот пакет не снимает, и это решение обзора, а не упущение.** Число
остатка сравнимо только на переставшем меняться дереве, а P01-8 идёт параллельно 03/P6 и
02/P3, которые ещё удаляют типы. Поэтому `CHANGELOG.md` и ADR цитируют **число «до»** и
ссылаются на `measurement/03-catch-clauses.md`; строку «после» дописывает оркестратор
последним действием раунда, как условие приёмки. Пакет обязан оставить в обоих текстах
место под неё, а не выдать «до» за итог.

**Число пакетов работ: 8. Разблокировщик — P01-2.**

---

---

## Продолжение

Порядок пакетов и красные окна (§8), «каких файлов не называет ни один пакет» (§9) —
в `01-refusal-order.md`. Тестовый план (§10), записи `Breaking` и ADR (§11) и возвраты
оркестратору (§12) — в `01-refusal-external-contracts.md`; таблица владения файлами (§13)
— в `01-refusal-evidence.md`.
