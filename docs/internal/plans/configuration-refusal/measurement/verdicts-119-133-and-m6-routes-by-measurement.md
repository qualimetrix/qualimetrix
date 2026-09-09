# Пересъём вердиктов на дереве `1513bf67`

Пакет D: секция K (119–127, M9), секция L (128–133, M6/M12), из A — 4, 5, 10 (M3, M12),
из C — 25, 26, 27 (M13).

Пробник — свой, собран заново в `$PROBE`:
`p/` (общий: `composer.json` psr-4 `RecNs\` → `src/`, `src/Probe.php` с `Probe::complex` и
`Probe::simple`, `src/Sub/Other.php`), `d/` — отдельное дерево только для инлайн-директив,
`h/` — одноразовый git-репозиторий для `hook:*`.

Дайджест прогона — md5 (10 hex) по отсортированному кортежу
`(channel, symbol, severity, threshold)` из `violations` JSON-отчёта. Дайджесты НЕ сопоставимы
с дайджестами перечисления: пробник другой. `complex()` берёт 4 параметра, поэтому мой BASE
даёт 8 находок против 7 в перечислении — это свойство пробника, а не дрейф продукта.

Каждый прогон `check`: `bin/qmx -d <probe> check src --workers=0 --no-cache --no-progress
--format=json --fail-on=none`, `1>out 2>err`, `ec=$?` снят сразу и без пайпа.

---

## 1. Контроли

| id          | конфигурация                                                                | n   | digest       | exit | утверждение            | воспроизвелось |
| ----------- | --------------------------------------------------------------------------- | --- | ------------ | ---- | ---------------------- | -------------- |
| **BASE**    | `paths: [src]` + `rules: {complexity.ccn: {callable: {warning:1,error:2}}}` | 8   | `8b72b5ab73` | 0    | ключ принят и применён | да             |
| **DEFAULT** | `paths: [src]`                                                              | 6   | `e62fbe9da8` | 0    | правило на дефолтах    | да             |
| **NOCCN**   | `rules: {complexity.ccn: {enabled: false}}`                                 | 5   | `4d8eb6267d` | 0    | канал ccn выключен     | да             |
| **EMPTY**   | `only_rules: [complexity.ccn]` + порог 900/1000                             | 0   | `d41d8cd98f` | 0    | находок нет            | да             |

Четыре контроля попарно различимы (8/6/5/0 находок, четыре разных дайджеста). Контроль
воспроизведён и на стороне селекторов: `--disable-rule=complexity.ccn` даёт ровно NOCCN
(`4d8eb6267d`) — то есть отказ на `--disable-rule=complexity` (поз. 25) не объясняется
неработающим флагом.

`--fail-on=none` обязателен: без него любой прогон на пробнике выходит с 2 (npath / class-rank
на дефолтах) — см. столбец «check(default)» в разделе 4.

---

## 2. Перечень маршрутов отказа (M6)

Маршрут = (класс исключения) → (место перехвата) → (место записи и поток) → (код возврата).
Все строки — на `1513bf67`, все измерены.

| #   | класс исключения                                                                                                                                             | где ловится (`путь:строка`)                                                                                                                                                                                                      | где пишется                                                                       | команда                                                                                                    | exit    | поток                                               | отчёт                                            |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | ------- | --------------------------------------------------- | ------------------------------------------------ |
| R1  | `ConfigLoadException` \| `ArchitectureConfigurationException`                                                                                                | `src/Infrastructure/Console/Command/CheckCommand.php:172`                                                                                                                                                                        | `ResultPresenter::writeDiagnostic` → `ErrorStream::write` (`ErrorStream.php:103`) | `check`                                                                                                    | **3**   | **stderr**                                          | нет                                              |
| R2  | то же                                                                                                                                                        | `src/Infrastructure/Console/Command/BaselineCommand.php:48`                                                                                                                                                                      | `BaselineCommand.php:82` — `$output->writeln`                                     | `baseline:generate`, `baseline:explain`, `baseline:update`, `baseline:cleanup`, `baseline:rename-channels` | **1**   | **stdout**                                          | нет                                              |
| R3  | то же                                                                                                                                                        | `src/Infrastructure/Console/Command/DirectivesCommand.php:158` (`catch (Exception)`) → `ConfigurationFailure::message` (`ConfigurationFailure.php:41`)                                                                           | `DirectivesCommand.php:300` — `$output->writeln`                                  | `directives`                                                                                               | **3**   | **stdout**                                          | нет                                              |
| R4  | то же                                                                                                                                                        | `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php:160` → `ConfigurationFailure::message`                                                                                                                      | `LayerAssignmentCommand.php:204` — `$output->writeln`                             | `debug:layer-assignment`                                                                                   | **1**   | **stdout**                                          | нет                                              |
| R5  | `RuntimeException` числовой проверки, брошен в `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php:364`                                           | **не ловится специально** → `CheckCommand.php:201` `catch (Throwable)`                                                                                                                                                           | `CheckCommand.php:202` через `ErrorStream`                                        | `check`                                                                                                    | **1**   | **stderr**                                          | нет; шапка `Unexpected error:`                   |
| R6  | тот же `RuntimeException`                                                                                                                                    | `BaselineCommand.php:54` `catch (InvalidArgumentException\|RuntimeException)`                                                                                                                                                    | `BaselineCommand.php:82`                                                          | `baseline:*`                                                                                               | **1**   | **stdout**                                          | нет; **без** шапки `Unexpected error`            |
| R7  | тот же `RuntimeException`                                                                                                                                    | `DirectivesCommand.php:158`, `ConfigurationFailure::message` → `null`                                                                                                                                                            | `DirectivesCommand.php:300`                                                       | `directives`                                                                                               | **1**   | **stdout**                                          | нет; шапка `Unexpected error:`                   |
| R8  | `TypeError` из `ComputedMetricOverrideReader::mapLevel()`                                                                                                    | `CheckCommand.php:201` `catch (Throwable)`                                                                                                                                                                                       | `ErrorStream`                                                                     | `check`                                                                                                    | **1**   | **stderr**                                          | нет; шапка `Unexpected error:` + внутренний FQCN |
| R9  | тот же `TypeError`                                                                                                                                           | `BaselineCommand.php:61` `catch (Throwable)`                                                                                                                                                                                     | `BaselineCommand.php:82`                                                          | `baseline:*`                                                                                               | **1**   | **stdout**                                          | нет; шапка + FQCN                                |
| R10 | тот же `TypeError` (пересмотрен изолированным повтором: свежий каталог `$PROBE/iso`, два прогона подряд, оба `ec=255`, `out=5210`, `err=5785` — байт-в-байт) | **не ловится нигде**: `DirectivesCommand.php:158` ловит `Exception`, а Symfony перебрасывает не-`Exception` (`vendor/symfony/console/Application.php:200`, `catchErrors = false` в `:80`; `bin/qmx` не зовёт `setCatchErrors()`) | PHP fatal handler                                                                 | `directives`                                                                                               | **255** | **stdout И stderr одновременно** (5210 + 5785 байт) | нет; полный PHP-трейс с абсолютными путями       |
| R11 | Symfony `InvalidOptionException` (неизвестная опция CLI), брошен при связывании ввода                                                                        | не ловится командой → `Application::renderThrowable` (`src/Infrastructure/Console/Application.php:44`)                                                                                                                           | `ErrorStream::boundWriter`                                                        | любая                                                                                                      | **1**   | **stderr**                                          | нет; рамка Symfony                               |
| R12 | та же `ConsoleExceptionInterface`, но текст называет retired-флаг (`--exclude-path`, `--exclude-namespace`)                                                  | `CheckCommand.php:141` (перехват в `run()`, а не в `execute()`)                                                                                                                                                                  | `ResultPresenter::writeDiagnostic`                                                | `check`                                                                                                    | **3**   | **stderr**                                          | нет                                              |
| R13 | `InvalidArgumentException` от валидаторов значения (`--fail-on=bogus`, селектор правила)                                                                     | `CheckCommand.php:190`                                                                                                                                                                                                           | `ErrorStream`                                                                     | `check`                                                                                                    | **3**   | **stderr**                                          | нет                                              |
| R14 | `InvalidArgumentException` из `Application::doRun` (неверный `-d`) — до выбора команды                                                                       | не ловится → `Application::renderThrowable`                                                                                                                                                                                      | `ErrorStream::boundWriter`                                                        | любая                                                                                                      | **1**   | **stderr**                                          | нет; рамка `In Application.php line 59`          |

**14 маршрутов, 3 различных кода возврата (1, 3, 255) и 3 варианта потока (stderr, stdout,
оба сразу).**

Перечисление называло пять маршрутов. Из четырнадцати измеренных **в перечислении есть**:
R1 (check 3/stderr), R2 (baseline 1/stdout), R5 («Unexpected error» на числовой проверке),
R8 (TypeError в computed), R11 (неизвестная опция CLI, Symfony 1). **Новые, в перечислении
их нет:** R3 (`directives` — 3/**stdout**), R4 (`debug:layer-assignment` — 1/stdout),
R6 (в `baseline:*` та же `RuntimeException` идёт **без** шапки «Unexpected error»),
R7, R9, **R10 (exit 255, PHP-фатал, вывод в оба потока сразу)**, R12 (retired-флаг в
`check::run` — 3/stderr), R13, R14 (неверный `-d`, до выбора команды).

Про «четыре кода»: формулировка перечисления неточна — пять названных им маршрутов несут
только два кода, 3 и 1. Измерено на `1513bf67`: по всем четырнадцати маршрутам **три** кода
(1, 3, 255).

Что это значит по существу: **код возврата определяется не классом ошибки, а тем, какая
команда её поймала.** Один и тот же битый `qmx.yaml` — 3/stderr в `check`, 3/stdout в
`directives`, 1/stdout в `baseline:generate` и `debug:layer-assignment`. Один и тот же
`TypeError` — 1 в `check` и `baseline:*`, 255 с фатальным трейсом в `directives`.

Названный в задании вход перепроверен и **подтверждён**:
`rules: {complexity.ccn: {callable: {warning: "abc"}}}` → маршрут R5,
exit **1**, stderr, текст
`Unexpected error: Invalid configuration for rule "complexity.ccn": option "callable.warning" must be numeric, got "abc".`
Сообщение верное по существу, шапка «Unexpected error» и код 1 — те же, что были. Не изменилось.
Дискриминатор: тот же ключ на верхнем уровне плоского правила
(`code-smell.count-in-loop: {threshold: "abc"}`) даёт **exit 3 «Configuration error: Option
"threshold" is not an option of rule …»** — то есть отказ X13 перехватывает вход раньше, чем
числовая проверка, и это лишь смещает границу, а не убирает маршрут R5.

---

## 3. Какая команда читает `qmx.yaml`

Дискриминатор двусторонний: (а) битый файл (`rulez: {}`) — что делает; (б) файл, **отдельно
доказанный верным и меняющим вывод**, против отсутствия файла — меняется ли вывод.
Проверка (б) — `paths: [src]` + `rules: {complexity.ccn: {enabled: false}}` +
`suppress_namespaces: ["RecNs\Sub"]`: через `check` он даёт exit 0 и n=4, digest
`30306bf48c`, тогда как без конфига — n=6, `e62fbe9da8`. То есть файл валиден и вывод меняет.

Первая попытка этого дискриминатора была испорченной и отброшена: в ней стояло
`exclude_namespaces:`, а этот ключ retired — `check` на нём отказывает с exit 3. Тот прогон
подавал `rules` и `graph:export` **второй битый файл**, а не верный, и ничего бы не доказал.
Ловушка worktree закрыта на стороне кода: `-d` обрабатывается в `Application::doRun`
(`Application.php:51-72`) через `chdir()` до выбора команды, то есть действует на все команды
одинаково.

| команда                                             | читает `qmx.yaml` | битый файл: exit + поток                                                                                                     | чем установлено                                                                                              |
| --------------------------------------------------- | ----------------- | ---------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `check`                                             | да                | **3**, stderr, `Configuration error: Invalid configuration in …: Unknown configuration key: "rulez" (did you mean "rules"?)` | измерение                                                                                                    |
| `baseline:generate`                                 | да                | **1**, **stdout**, тот же текст; stderr пуст                                                                                 | измерение                                                                                                    |
| `baseline:explain`                                  | да                | **1**, **stdout**, тот же текст                                                                                              | измерение (с реальным baseline-файлом)                                                                       |
| `baseline:update` / `:cleanup` / `:rename-channels` | да                | R2 по коду (общий `BaselineCommand::execute`)                                                                                | код (`BaselineCommand.php:41-66`)                                                                            |
| `directives`                                        | **да**            | **3**, **stdout**, тот же текст; stderr пуст                                                                                 | измерение                                                                                                    |
| `debug:layer-assignment`                            | да                | **1**, **stdout**                                                                                                            | измерение                                                                                                    |
| `rules`                                             | **нет**           | exit **0**, полная тишина                                                                                                    | измерение + код: конструктор (`RulesCommand.php:30-36`) не получает ни загрузчика, ни конвейера конфигурации |
| `graph:export`                                      | **нет**           | exit **0**, полная тишина                                                                                                    | измерение + код (`GraphExportCommand.php:33-40`)                                                             |
| `hook:install` / `hook:uninstall` / `hook:status`   | **нет**           | exit **0**, обычный вывод команды                                                                                            | измерение в `$PROBE/h` + код: конструкторы получают только `GitRepositoryLocatorInterface`                   |

Вторая сторона (та, которую пропустил бы односторонний тест): с этим доказанно верным
конфигом и без конфига вовсе вывод `rules` совпадает байт-в-байт (md5 `171f6837…`), и вывод
`graph:export` тоже (md5 `61ec3da2…`). То есть «тишина» — это не «прочитал и промолчал», а
«не читал».

Отдельно: `rules --config=…` — **exit 1**, `The "--config" option does not exist.` (поз. 133).
Опция `--config` у `rules` и `graph:export` отсутствует, у `baseline:*` и `directives` есть.

---

## 4. Позиции

Сокращения: «изм.» — изменилось ли против таблицы перечисления (снятой на `1b225c2e`).

### A. Корень документа

| #   | вход                                                 | вердикт на `1513bf67`                                                                                                                        | изм.              | точная строка диагностики                                                                                                                          |
| --- | ---------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| 4   | `Paths: [src]` + `Rules:` (Title-case)               | **silent, принят и применён**: exit 0, stderr 0 байт, digest `8b72b5ab73` = BASE                                                             | **не изменилось** | — (диагностики нет)                                                                                                                                |
| 5   | `SUPPRESS_NAMESPACES: ["RecNs"]`                     | **refuse 3**, stderr                                                                                                                         | **не изменилось** | `Configuration error: Invalid configuration in …/qmx.yaml: Unknown configuration key: "SUPPRESS_NAMESPACES" (did you mean "suppress_namespaces"?)` |
| 10  | тот же документ (`rulez: {}`) под именем `.qmx.yaml` | **silent**: exit 0, **stderr пуст**, stdout — обычный отчёт (18924 байта), digest `e62fbe9da8` = DEFAULT, то есть документ не прочитан вовсе | **не изменилось** | —                                                                                                                                                  |

Дискриминатор к 10: тот же документ под именем `qmx.yml` (второе имя из закрытого списка)
даёт exit 3 и отказ по `rulez` — значит молчание вызвано именем файла, а не тем, что документ
внезапно стал корректным. Механизм в коде:
`src/Analysis/Configuration/Pipeline/Stage/ConfigFileStage.php:27`
`private const array CONFIG_FILE_NAMES = ['qmx.yaml', 'qmx.yml'];`, промах —
`findConfigFile()` возвращает `null` (`:90`) без единого слова.

### C. Селекторы (M13)

| #   | вход                                        | вердикт на `1513bf67`                                                                                                | изм.              | точная строка диагностики                                                                   |
| --- | ------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- | ----------------- | ------------------------------------------------------------------------------------------- |
| 25  | `--disable-rule=complexity`                 | **refuse 3**, stderr                                                                                                 | **не изменилось** | `Rule selector "complexity" does not match any registered producer, group, or channel.`     |
| 26  | `only_rules: [complexity]` из файла         | **refuse 3**, stderr, тот же текст                                                                                   | **не изменилось** | `Rule selector "complexity" does not match any registered producer, group, or channel.`     |
| 27  | `disabled_rules: [complexity.cnn]` из файла | **refuse 3**, stderr, текстом инфраструктурного валидатора селекторов, а не `RuleNameValidator` (нет «Did you mean») | **не изменилось** | `Rule selector "complexity.cnn" does not match any registered producer, group, or channel.` |

Вторая половина позиции 25 — что говорит справка. На `1513bf67` `bin/qmx check --help`
по-прежнему рекламирует несуществующую форму, дословно:

```
--disable-rule=DISABLE-RULE  Disable a rule or group by prefix (e.g., complexity, size.class-count).
                             Disabling duplication.clone also skips the memory-intensive detection phase
--only-rule=ONLY-RULE        Run only specified rules or group by prefix (e.g., complexity, code-smell)
```

То есть справка называет `complexity` как пример, а прогон его отвергает. **M13 в этой точке
не сдвинулся.** Позиция 27 тоже не сдвинулась: `disabled_rules`, `only_rules` и
`--disable-rule` отвечают одной и той же строкой инфраструктурного валидатора селекторов, и
она отличается от ответа `RuleNameValidator` на опечатку имени правила в `rules:` (поз. 15,
там есть «Did you mean») — ровно то, что утверждала таблица.

### K. Инлайн-директивы (M9)

Директива ставится в докблок `Probe::simple()` (CCN 1) — чтобы она заведомо ничего не
подавляла. Отдельное дерево `$PROBE/d`. Контроль без директивы: `check --fail-on=none` = 0,
`check` (умолчание) = 2, `directives` = 0, «No inline directives in the analysed scope.»

| #   | вход                                                                         | check `--fail-on=none` | check (умолч.) | `directives` | вердикт                                                                                                                               | изм.              | точная строка                                                                                                                                                                                                |
| --- | ---------------------------------------------------------------------------- | ---------------------- | -------------- | ------------ | ------------------------------------------------------------------------------------------------------------------------------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 119 | `@qmx-ignore complexity.ccnn probe reason`                                   | **2**                  | 2              | **0**        | finding `annotation.unresolved-directive` (severity error), exit 2 **и правда переживает `--fail-on=none`**                           | **не изменилось** | `Suppression "complexity.ccnn" addresses no channel. Addressable names closest to it: complexity.ccn, complexity.wmc, complexity.npath. Prose belongs after "--".`                                           |
| 120 | `@qmx-threshold complexity.ccnn 100`                                         | **2**                  | 2              | **0**        | finding `annotation.unresolved-directive`, exit 2                                                                                     | **не изменилось** | `@qmx-threshold "complexity.ccnn" names no rule. Addressable names closest to it: complexity.ccn, complexity.wmc, complexity.npath.`                                                                         |
| 121 | `@qmx-threshold complexity.ccn.callable 100`                                 | **2**                  | 2              | **0**        | finding `annotation.unresolved-directive`, exit 2                                                                                     | **не изменилось** | `@qmx-threshold "complexity.ccn.callable" names no rule. No declared name is close to it.`                                                                                                                   |
| 122 | `@qmx-threshold complexity.ccn=100 probe reason`                             | **2**                  | 2              | **0**        | finding `annotation.invalid-threshold`, exit 2                                                                                        | **не изменилось** | `@qmx-threshold complexity.ccn: invalid syntax "" — expected a number or warning=N error=N`                                                                                                                  |
| 123 | `@qmx-threshold complexity.ccn warn=5`                                       | **2**                  | 2              | **0**        | finding `annotation.invalid-threshold`, exit 2                                                                                        | **не изменилось** | `@qmx-threshold complexity.ccn: invalid syntax "warn=5" — expected a number or warning=N error=N`                                                                                                            |
| 124 | `@qmx-ignroe complexity.ccn probe reason` (опечатка в теге)                  | **0**                  | 2              | **0**        | **silent**, n=6 = контроль без директивы; `directives` печатает «No inline directives»                                                | **не изменилось** | —                                                                                                                                                                                                            |
| 125 | `@qmx-ignore-nextline complexity.ccn r`                                      | **0**                  | 2              | **0**        | **silent**, n=6                                                                                                                       | **не изменилось** | —                                                                                                                                                                                                            |
| 126 | директива из #119, оба наблюдателя                                           | **2**                  | 2              | **0**        | **инверсия подтверждена**: `check` краснеет, `directives` зелёный и молчит                                                            | **не изменилось** | —                                                                                                                                                                                                            |
| 127 | `@qmx-ignore complexity.ccn nothing to suppress here` (корректная, инертная) | **0**                  | 2              | **2**        | **инверсия подтверждена в обратную сторону**: `check` зелёный (находка есть, но severity **info**), `directives` краснеет как «inert» | **не изменилось** | check: `Suppression "complexity.ccn" matched nothing in this run — the finding it silences is gone.` (info); directives: `src/Probe.php:23 @qmx-ignore complexity.ccn / inert: removing it changes nothing.` |

Оговорка к 127: «`check` exit 0» измеримо только с `--fail-on=none` — без него пробник и так
краснеет от npath/class-rank. Настоящая инверсия видна не в кодах, а в severity: находка
`annotation.unused-directive` выпущена на **info**, а `info` по построению не решает код
возврата.

Механизм exit 2 при `--fail-on=none` (позиции 119–123) — назван в коде и не является
случайностью: `src/Infrastructure/Console/ExitCodeResolver.php:53-55` проверяет
`hasConfigurationError()` **до** чтения политики `fail_on` и возвращает
`Severity::Error->getExitCode()`; каналы `annotation.unresolved-directive` и
`annotation.invalid-threshold` объявлены как configuration-error. Цепочка объявления:
имена каналов — `src/Analysis/Policy/Inline/Contract/Directive/InlineDirectivePolicyInterface.php:49`
и `:55`; их продюсер `InlineDirectiveValidator` реализует
`ConfigurationValidatorInterface` (`src/Analysis/Policy/Inline/Directive/InlineDirectiveValidator.php:50`)
и объявляет оба канала на `:108` и `:110`; флаг ставится **единственным в продакшне вызовом**
`ChannelDeclaration::asConfigurationError()` при сборке реестра —
`src/Infrastructure/DependencyInjection/CompilerPass/ChannelDeclarationCompilerPass.php:602`
(сам метод — `src/Analysis/Finding/Contract/ChannelDeclaration.php:168`). Комментарий на
`ExitCodeResolver.php:28-34` объявляет это намеренным.

Замеченное сверх позиций (в отчёт как наблюдение, не как позиция): в случаях 122 и 123
`directives` не просто зелёный — он печатает «**No inline directives in the analysed scope**»
при том, что директива в файле есть и `check` её видит. Это сильнее, чем у 119–121, где
`directives` хотя бы называет её как «unmeasured».

### L. Один ключ в разных командах (M6, M12)

| #   | вход                                      | вердикт на `1513bf67`                                                         | изм.                                                       | точная строка                                                                                                          |
| --- | ----------------------------------------- | ----------------------------------------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| 128 | `qmx.yaml` с `rulez: {}`, команда `check` | **refuse 3**, **stderr** (310 байт), stdout 0 байт                            | **не изменилось**                                          | `Configuration error: Invalid configuration in …/qmx.yaml: Unknown configuration key: "rulez" (did you mean "rules"?)` |
| 129 | тот же файл, `baseline:generate`          | **exit 1**, **stdout** (310 байт), stderr 0 байт                              | **не изменилось** (таблица уже несла исправленный вердикт) | `Configuration error: Invalid configuration in …/qmx.yaml: Unknown configuration key: "rulez" (did you mean "rules"?)` |
| 130 | тот же файл, `rules`                      | **exit 0, полная тишина**; вывод байт-в-байт совпадает с прогоном без конфига | **не изменилось**                                          | —                                                                                                                      |
| 131 | тот же файл, `graph:export src`           | **exit 0, полная тишина**; вывод байт-в-байт совпадает с прогоном без конфига | **не изменилось**                                          | —                                                                                                                      |
| 132 | `check src --treshold=5`                  | **exit 1**, stderr, рамка Symfony                                             | **не изменилось**                                          | `The "--treshold" option does not exist.`                                                                              |
| 133 | `rules --config=…`                        | **exit 1**, stderr, рамка Symfony                                             | **не изменилось**                                          | `The "--config" option does not exist.`                                                                                |

---

## 5. Допущения и неизмеренное

* **`baseline:update`, `baseline:cleanup`, `baseline:rename-channels`** — маршрут R2 назван
  по коду (общий `final protected function execute` в `BaselineCommand.php:41`), прогоном не
  снят: каждый требует непротиворечивого существующего baseline и в двух случаях пишет файл.
  Уверенность высокая (метод `final`, переопределить его подкласс не может), но это вывод из
  кода, а не измерение.
* **`hook:install` / `hook:uninstall`** — измерены в одноразовом `git init`-репозитории
  `$PROBE/h`, не в worktree продукта. Развилка выбрана сознательно: команды пишут в
  `.git/hooks`, а `core.hooksPath` в этом worktree абсолютный и указывает в основной checkout.
* **Позиции 62, 63, 88** использованы как поставщики входов для маршрутов R5–R10 и в таблицу
  позиций не внесены: они вне моего набора.
* **Разбор, почему `directives` при `TypeError` роняет процесс с 255** доведён до
  `vendor/symfony/console/Application.php:200` и флага `catchErrors` (`:80`). Не проверялось,
  меняет ли поведение `--format=json` у `directives` — при 255 форматтер до вывода не доходит.
* **Не измерялось**, попадает ли `debug:layer-assignment` в остальные маршруты (R5–R10):
  снят только маршрут R4. Команда вне названного в задании списка, взята для полноты
  таблицы «кто читает конфиг».
* Дайджесты моего пробника **не сопоставимы** с дайджестами перечисления. Совпадение
  вердиктов установлено по вердикту (exit/поток/текст/применён ли ключ), а не по хешам.

---

## 6. Команды для перепроверки

```bash
PROBE=<этот каталог>
WT=<путь к рабочему дереву>

# 1. Контроль BASE (ожидание: exit 0, stderr пуст, n=8, digest 8b72b5ab73)
"$PROBE/run.sh" BASE 'paths: [src]\nrules:\n  complexity.ccn:\n    callable:\n      warning: 1\n      error: 2\n'

# 2. M6: один и тот же битый файл в пяти командах (ожидание: 3/stderr, 1/stdout, 3/stdout, 0, 0)
printf '%b' 'rulez: {}\npaths: [src]\n' > "$PROBE/p/qmx.yaml"
for c in "check src --workers=0 --no-cache --no-progress --format=json --fail-on=none" \
         "baseline:generate $PROBE/runs/bl.json src --no-progress" \
         "directives src" "rules" "graph:export src"; do
  "$WT/bin/qmx" -d "$PROBE/p" $c 1>o 2>e; echo "ec=$? out=$(wc -c<o) err=$(wc -c<e) :: $c"
done

# 3. Маршрут R10 (ожидание: exit 255, PHP fatal в ОБА потока)
printf '%b' 'paths: [src]\ncomputed_metrics:\n  computed.myscore:\n    formula: "1 + 1"\n    levels:\n      class:\n        warning: 1\n' > "$PROBE/p/qmx.yaml"
"$WT/bin/qmx" -d "$PROBE/p" directives src 1>o 2>e; echo "ec=$? out=$(wc -c<o) err=$(wc -c<e)"

# 3b. Дискриминатор к разделу 3: тот же верный конфиг через check (ожидание: exit 0, n=4, digest 30306bf48c)
"$PROBE/run.sh" DISC2 'paths: [src]\nrules:\n  complexity.ccn:\n    enabled: false\nsuppress_namespaces: ["RecNs\\\\Sub"]\n'

# 4. Инверсия наблюдателей (ожидание: 119 → check 2 / directives 0; 127 → check 0 / directives 2)
#    (директива вписывается в докблок Probe::simple в дереве $PROBE/d)
"$PROBE/dir.sh" K119 '@qmx-ignore complexity.ccnn probe reason'
"$PROBE/dir.sh" K127 '@qmx-ignore complexity.ccn nothing to suppress here'

# 5. Поз. 25: справка против прогона (ожидание: справка учит "complexity", прогон даёт exit 3)
"$WT/bin/qmx" check --help 2>&1 | grep -- '--disable-rule'
"$PROBE/run.sh" P25 'paths: [src]\n' --disable-rule=complexity
```
