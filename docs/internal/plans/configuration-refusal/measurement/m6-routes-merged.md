# M6. Сведение двух перечислений маршрутов отказа конфигурации

Дерево: `1513bf67`. Третий свидетель — сводящий. Входы: перечисление по измерению
(`verdicts-119-133-and-m6-routes-by-measurement.md`, §2, 14 маршрутов) и перечисление по коду
(`m6-routes-by-code.md`, 37 маршрутов). Каждое расхождение разрешено **своим** прогоном на
**своём** пробнике; ни одна строка ниже не взята у свидетеля на слово.

## 0. Как сведено

**Гранулярность маршрута** (развилка решена мной, названа здесь, потому что без неё три числа
несравнимы ни с одним из свидетелей): **один маршрут = (класс исключения или вид отказа) ×
(обработчик)**. Это гранулярность свидетеля по коду. Пять `baseline:*` команд дают **одну**
строку, а не пять: `BaselineCommand::execute` объявлен `final`, и я измерил все пять по
отдельности (см. §2, расхождение Р-5). Свидетель по измерению разворачивал ту же строку в
одну на команду — отсюда часть разницы 14 против 37.

**Колонка «отчёт»** (вторая развилка): у измерителя «нет» стоит на всех 14, у кода «да (json)»
на конверте `{error, exit_code}`. Это разница определений, а не факта. Здесь принято:
**отчёт = машинно-читаемый документ, в котором отказ выражен структурно**. Измерено: такой
конверт выдают `directives` и `debug:layer-assignment` при `--format=json` — и только они.

**Что вне предмета** — как у свидетеля по коду: неполнота разбора (код 4), git-скоуп, запись
отчёта. Одно исключение сделано и помечено: отказ `graph:export --format` включён (разбор
пользовательского ввода), и рядом с ним записаны три соседних наблюдения о `graph:export`,
которых нет ни у одного свидетеля.

**Пробник** — свой, `…/scratchpad/e/R`: `p/` (`composer.json` psr-4 `RecNs\` → `src/`,
`src/Probe.php` с `Probe::complex` CCN 4 и `Probe::simple` CCN 1, `src/Sub/Other.php`),
`h/` — одноразовый `git init` для `hook:*`, `iso/` — свежий каталог для изолированных
повторов. Контроль: `paths: [src]` + `check src --workers=0 --no-cache --no-progress
--format=json --fail-on=none` → **exit 0, stderr 0 байт, stdout 13248 байт**. Без него любой
последующий «отказ» неотличим от битого пробника. Код возврата снят сразу и без пайпа,
байты обоих потоков зафиксированы в каждом прогоне.

**Окружение, которое видно в маршрутах 255:** `display_errors=1`, `log_errors=1`,
`error_log` пуст, **xdebug загружен**. Отсюда дублирование PHP-фатала в оба потока и трасса с
колонкой таймингов в stdout — это факты о данной машине, а не о коде; свидетель по коду
оставил поток как «зависит от php.ini» и был прав. Размеры вывода при 255 (4708/5210 байт) на
машине без xdebug не воспроизведутся; воспроизводится код возврата и то, что пишется в оба
потока.

---

## 1. Сведённая таблица маршрутов

«Оба» = маршрут есть у обоих свидетелей И подтверждён моим прогоном. «Только код,
подтверждено» = у измерителя строки нет, я её воспроизвёл. «Только код, недостижимо» = у
измерителя нет и из CLI не достигается (причина в §3). «Моё» = нет ни у одного.

| #   | Класс исключения / вид отказа                                                                                                                                                      | Где поймано (`путь:строка`)                      | Команда                           | Код                          | Поток                      | Отчёт  | Чем установлено                                      |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------ | --------------------------------- | ---------------------------- | -------------------------- | ------ | ---------------------------------------------------- |
| 1   | `InvalidArgumentException`: `-d` не каталог                                                                                                                                        | не ловится; `Application.php:196` (vendor)       | любая                             | **1**                        | stderr                     | нет    | оба                                                  |
| 2   | `InvalidArgumentException`: `chdir` не удался                                                                                                                                      | там же                                           | любая                             | **1**                        | оба потока (PHP Warning)   | нет    | только код, подтверждено                             |
| 3   | `CommandNotFoundException`, неинтерактивно                                                                                                                                         | `vendor/…/Application.php:319` (ветка `else`)    | —                                 | **1**                        | stderr                     | нет    | только код, подтверждено                             |
| 4   | тот же класс, одна альтернатива + интерактивный ввод                                                                                                                               | `vendor/…/Application.php:300-316`               | —                                 | **1**                        | **stdout** (вопрос)        | нет    | только код, подтверждено                             |
| 5   | `ConsoleExceptionInterface`, текст называет retired-флаг                                                                                                                           | `CheckCommand.php:141` (в `run()`)               | `check`                           | **3**                        | stderr                     | нет    | оба                                                  |
| 6   | тот же класс, текст не про retired-флаг                                                                                                                                            | переброшено `CheckCommand.php:167` → Symfony     | `check`                           | **1**                        | stderr                     | нет    | оба                                                  |
| 7   | тот же класс                                                                                                                                                                       | не ловится нигде; Symfony                        | остальные 12                      | **1**                        | stderr                     | нет    | оба                                                  |
| 8   | `ConflictingCliAliasException`                                                                                                                                                     | `CheckCommand.php:163` — **мёртв**               | `check`                           | (1)                          | (stderr)                   | нет    | **только код, недостижимо**                          |
| 9   | `Error`/`TypeError`, не пойман                                                                                                                                                     | нигде: `catch (Exception)` + `catchErrors=false` | `directives`                      | **255**                      | оба потока                 | нет    | оба                                                  |
| 10  | тот же                                                                                                                                                                             | нигде                                            | `debug:layer-assignment`          | **255**                      | оба потока                 | нет    | **только код, подтверждено**                         |
| 11  | тот же                                                                                                                                                                             | нигде                                            | `rules`, `graph:export`, `hook:*` | (255)                        | —                          | —      | **только код, недостижимо**                          |
| 12  | `ConfigLoadException` \| `ArchitectureConfigurationException`                                                                                                                      | `CheckCommand.php:172`                           | `check`                           | **3**                        | stderr                     | нет    | оба                                                  |
| 13  | `ArchitecturePreparationException`                                                                                                                                                 | `CheckCommand.php:179`                           | `check`                           | **3**                        | stderr                     | нет    | только код, подтверждено                             |
| 14  | `InvalidArgumentException` от валидаторов ввода                                                                                                                                    | `CheckCommand.php:190`                           | `check`                           | **3**                        | stderr                     | нет    | оба                                                  |
| 15  | `ComputedMetricConfigurationException` \| `BaselineLoadException`                                                                                                                  | `CheckCommand.php:194`                           | `check`                           | **3**                        | stderr                     | нет    | только код, подтверждено                             |
| 16  | прочий `Throwable` (в т.ч. `RuntimeException` лимитов, `TypeError`)                                                                                                                | `CheckCommand.php:201`                           | `check`                           | **1**                        | stderr, «Unexpected error» | нет    | оба                                                  |
| 17  | `ConfigLoadException` \| `ArchitectureConfigurationException`                                                                                                                      | `BaselineCommand.php:48`                         | 5 × `baseline:*`                  | **1**                        | **stdout**                 | нет    | оба                                                  |
| 18  | `ArchitecturePreparationException`                                                                                                                                                 | `BaselineCommand.php:50`                         | 5 × `baseline:*`                  | **1**                        | stdout                     | нет    | только код, подтверждено                             |
| 19  | `BaselineConflictException`                                                                                                                                                        | `BaselineCommand.php:52`                         | 5 × `baseline:*`                  | **1**                        | stdout                     | нет    | только код, подтверждено                             |
| 20  | `InvalidArgumentException` \| `RuntimeException`                                                                                                                                   | `BaselineCommand.php:54`                         | 5 × `baseline:*`                  | **1**                        | stdout                     | нет    | оба                                                  |
| 21  | прочий `Throwable`                                                                                                                                                                 | `BaselineCommand.php:61`                         | 5 × `baseline:*`                  | **1**                        | stdout, «Unexpected error» | нет    | оба                                                  |
| 22  | `InvalidArgumentException` от `new FindingChannel` (`--channel` с `#` или `:`)                                                                                                     | `BaselineExplainCommand.php:153`                 | `baseline:explain`                | **2**                        | stdout                     | нет    | только код, подтверждено (частично опровергнуто, §4) |
| 23  | отказ без исключения: неизвестный `subject`                                                                                                                                        | `BaselineExplainCommand.php:119-124`             | `baseline:explain`                | **2**                        | stdout                     | нет    | только код, подтверждено                             |
| 24  | `Throwable` при построении опций правила — **проглочен**                                                                                                                           | `BaselineConfiguredThresholds.php:137`           | `baseline:explain`                | (0)                          | никуда                     | нет    | **только код, недостижимо**                          |
| 25  | `Throwable` при `forLevel()` — **проглочен**                                                                                                                                       | `BaselineConfiguredThresholds.php:175`           | `baseline:explain`                | (0)                          | никуда                     | нет    | **только код, недостижимо**                          |
| 26  | `InvalidArgumentException`                                                                                                                                                         | `DirectivesCommand.php:142`                      | `directives`                      | **3**                        | stdout / JSON-конверт      | **да** | только код, подтверждено                             |
| 27  | `Exception`, признанный конфигурационным                                                                                                                                           | `DirectivesCommand.php:158`                      | `directives`                      | **3**                        | stdout / JSON-конверт      | **да** | оба                                                  |
| 28  | `Exception`, не признанный конфигурационным                                                                                                                                        | `DirectivesCommand.php:158`                      | `directives`                      | **1**                        | stdout / JSON-конверт      | **да** | оба                                                  |
| 29  | `--format` не из `text,json`                                                                                                                                                       | `DirectivesCommand.php:128-137`                  | `directives`                      | **3**                        | stdout **текстом**         | нет    | только код, подтверждено                             |
| 30  | `--sweep` не из `narrow,full`                                                                                                                                                      | `DirectivesCommand.php:191-205`                  | `directives`                      | **3**                        | stdout / JSON-конверт      | **да** | только код, подтверждено                             |
| 31  | несуществующий путь                                                                                                                                                                | `DirectivesCommand.php:214-216`                  | `directives`                      | **3**                        | stdout / JSON-конверт      | **да** | только код, подтверждено                             |
| 32  | несуществующий путь                                                                                                                                                                | `CheckCommand.php:264-270`                       | `check`                           | **3**                        | stderr                     | нет    | только код, подтверждено                             |
| 33  | сконфигурированный скоуп не дал ни одного файла                                                                                                                                    | `DirectivesCommand.php:237-244`                  | `directives`                      | **3**                        | stdout / JSON-конверт      | **да** | только код, подтверждено                             |
| 34  | **любой** `Exception`, включая конфигурационный                                                                                                                                    | `LayerAssignmentCommand.php:160`                 | `debug:layer-assignment`          | **1** всегда                 | stdout / JSON-конверт      | **да** | оба                                                  |
| 35  | `--format` не из `text,json`                                                                                                                                                       | `LayerAssignmentCommand.php:110-117`             | `debug:layer-assignment`          | **2**                        | stdout текстом             | нет    | только код, подтверждено                             |
| 36  | невалидный FQN-аргумент                                                                                                                                                            | `LayerAssignmentCommand.php:127`                 | `debug:layer-assignment`          | **2**                        | stdout / JSON-конверт      | **да** | только код, подтверждено                             |
| 37  | `--group` не из списка семейств                                                                                                                                                    | `RulesCommand.php:52-60`                         | `rules`                           | **1**                        | stdout                     | нет    | только код, подтверждено                             |
| 38  | `InvalidArgumentException`: неизвестный `--format`                                                                                                                                 | не ловится нигде; Symfony                        | `graph:export`                    | **1**                        | stderr                     | нет    | только код, подтверждено                             |
| 39  | **не-отказ**: `--direction` — любое значение принято                                                                                                                               | —                                                | `graph:export`                    | **0**                        | нет диагностики            | нет    | **моё**                                              |
| 40  | **не-отказ**: `--channel` синтаксически верный, но несуществующий                                                                                                                  | —                                                | `baseline:explain`                | **0**                        | нет диагностики            | нет    | **моё**                                              |
| 41  | несуществующий путь                                                                                                                                                                | нет обработчика; отчёт «No files found»          | `graph:export`                    | **1**                        | stdout                     | нет    | **моё**                                              |
| 42  | **не-отказ**: `--output` в незаписываемый путь                                                                                                                                     | —                                                | `graph:export`                    | **0**                        | оба потока (PHP Warning)   | нет    | **моё**                                              |
| 43  | **справка учит отвергаемому вводу**: описание `--channel` (`BaselineExplainCommand.php:80`) называет форму `"rule-name#violation-code"`, которую `FindingChannel.php:93` отвергает | —                                                | `baseline:explain`                | **2** при следовании справке | stdout                     | нет    | **моё**                                              |

Строки 8, 11, 24, 25 недостижимы (§3). Строка 43 — не отдельный обработчик, а маршрут 22,
увиденный со стороны справки; в счёт §5 она не входит. Строки 39, 40, 42 — отказа нет вовсе; они в таблице,
потому что предмет сформулирован как «что делает конфигурация/ввод, который пользователь
считает отказным».

---

## 2. Расхождения и как разрешены

**Р-1. `debug:layer-assignment` и `Error` → 255.** Свидетель по коду поместил команду в
строку #9 «не ловится нигде», измеритель эту команду по маршрутам R5–R10 не гонял вовсе и
прямо это оговорил. Разрешено прогоном: код читает конфигурацию на
`LayerAssignmentCommand.php:135` внутри `try` с клаузой `catch (Exception)`, поэтому `TypeError`
проходит насквозь. **Свидетель по коду прав.**

```
printf '%b' 'paths: [src]\ncomputed_metrics:\n  computed.myscore:\n    formula: "1 + 1"\n    levels:\n      class:\n        warning: 1\n' > $R/p/qmx.yaml
bin/qmx -d $R/p debug:layer-assignment 'RecNs\Probe' 1>out 2>err; ec=$?
→ ec=255  out=4708  err=5107
```

Изолированный повтор по протоколу: свежий каталог `$R/iso`, два прогона подряд обеих команд
(`debug:layer-assignment` и `directives`) — `ec=255` в обоих, **stderr байт-в-байт идентичен**,
stdout идентичен по длине, но не побайтно: в нём xdebug-трасса с колонкой времени
(`0.1221` против `0.1233` в строке 8). Уточнение к измерителю: его «байт-в-байт» верно для
stderr и неверно для stdout — совпадают длины, а не байты.

**Р-2. `--format=json` при 255.** Измеритель оставил как непроверенное предположение
(«форматтер до вывода не доходит»). Разрешено прогоном: с `--format=json` и без него —
одинаковые `ec=255 out=5210 err=5785` для `directives` и `ec=255 out=4708 err=5107` для
`debug:layer-assignment`. **Предположение подтверждено.**

**Р-3. Маршруты с кодом 2.** У измерителя кода 2 нет ни в одной из 14 строк, у кода — четыре
таких маршрута (#26, #31, #32, #34). Все четыре достижимы, но #26 — не тем входом, который
подразумевал свидетель (см. §4):

```
bin/qmx -d $R/p baseline:explain '<subject>' src --no-progress --channel='complexity.ccn#high' → ec=2, stdout 158 б
bin/qmx -d $R/p baseline:explain '<subject>' src --no-progress --channel='complexity.ccn:class' → ec=2, stdout 132 б
bin/qmx -d $R/p baseline:explain 'declaration:class:RecNs\NoSuch@src/NoSuch.php' src --no-progress → ec=2, stdout 127 б
bin/qmx -d $R/p debug:layer-assignment 'RecNs\Probe' --format=bogus → ec=2, stdout 55 б
bin/qmx -d $R/p debug:layer-assignment 'not a fqn!!'                → ec=2, stdout 53 б
```

Итог: **измеритель не полон по кодам**, кодов у достижимых маршрутов не три, а четыре
(1, 2, 3, 255).

**Р-4. JSON-конверт `{error, exit_code}`.** Измеритель не гонял `--format=json` на отказных
маршрутах и поставил «отчёт: нет» везде. Разрешено прогоном: конверт реален и у `directives`,
и у `debug:layer-assignment`.

```
bin/qmx -d $R/p directives src --sweep=bogus --format=json
→ ec=3, stdout {"error":"Unknown sweep \"bogus\". …","exit_code":3}
bin/qmx -d $R/p directives emptydir --format=json
→ ec=3, stdout {"error":"Error: the configured scope analysed no PHP files…","exit_code":3}
bin/qmx -d $R/p directives src --format=json          (с битым qmx.yaml)
→ ec=3, stdout 353 б, JSON-конверт
```

Исключение, которое свидетель по коду назвал верно: **`--format=bogus` печатается текстом, а
не в запрошенном формате** — конверта нет (`ec=3, stdout 55 б`, строка 29).

**Р-5. `baseline:update` / `:cleanup` / `:rename-channels`.** Измеритель назвал маршрут R2 по
коду и честно пометил как неизмеренный. Разрешено прогоном с битым `rulez: {}`:
`baseline:update` → `ec=1, stdout 310 б, stderr 0`; `baseline:cleanup` → то же.
`baseline:rename-channels` требует второй аргумент и до чтения конфига не доходит: без него —
`ec=1, stderr 197 б` (маршрут 7), с валидным путём и негодным map-файлом — `ec=1, stdout 255 б`
(маршрут 20, `BaselineCommand.php:54`). **Строка R2 подтверждена для четырёх команд из пяти;**
для пятой подтверждено, что общий `execute` до неё вообще не добирается при этом вводе.

**Р-6. Маршруты, которых нет у измерителя, — все проверены.** Кроме перечисленных выше:

```
--memory-limit=1            check      → ec=1, stderr «Unexpected error: Cannot set requested memory_limit.»  (маршрут 16)
--baseline=<битый json>     check      → ec=3, stderr 65 б      (маршрут 15)
--baseline=<битый json>     baseline:* → ec=1, stdout 44 б      (маршрут 20)
битая формула computed      check      → ec=3, stderr 188 б     (маршрут 15)
битая формула computed      baseline:* → ec=1, stdout 167 б     (маршрут 20)
битая формула computed      directives → ec=3, stdout 188 б     (маршрут 27)
max_expanded_layers: 1 при 2 тьюплах  check      → ec=3, stderr «Architecture configuration error: …»  (маршрут 13)
                                       baseline:* → ec=1, stdout 291 б                                  (маршрут 18)
                                       directives → ec=3, stdout «Failed to load configuration: …»      (маршрут 27)
baseline:generate в существующий файл  → ec=1, stdout 359 б     (маршрут 19)
--group-by=bogus / --format-opt=bogus / --namespace+--class / --disable-rule=bogus  check → ec=3, stderr (маршрут 14)
--disable-rule=bogus        directives → ec=3, stdout + JSON    (маршрут 27)
rules --group=bogus         → ec=1, stdout 172 б                (маршрут 37)
graph:export --format=bogus → ec=1, stderr 399 б                (маршрут 38)
chek (интерактивно)         → ec=1, stdout 163 б, вопрос «Do you want to run "check" instead?»  (маршрут 4)
chek --no-interaction       → ec=1, stderr 212 б                (маршрут 3)
-d <файл, не каталог>       → ec=1, stderr 510 б                (маршрут 1)
-d <каталог chmod 000>      → ec=1, out 3345 / err 3814, PHP Warning в оба потока + рамка  (маршрут 2)
```

**Р-7. Маршруты, которые есть только у измерителя.** Таких нет: все 14 его строк нашлись в
коде и в моих прогонах. R1→12, R2→17, R3→27, R4→34, R5→16, R6→20, R7→28, R8→16, R9→21,
R10→9, R11→7, R12→5, R13→14, R14→1. (R5 и R8 — маршрут 16; R6 — маршрут 20; R9 — маршрут 21.) Ни одна не осталась без `путь:строка`.

**Р-8. Цена отказа во времени.** Свидетель по коду подозревал, но не проверил, что
`check --format=bogus` отказывает после полного анализа. Измерено: `--format=json` и
`--format=bogus` дают 0.25 с оба; `graph:export --format=dot` и `--format=bogus` — 0.21 с оба.
**Подозрение подтверждено для обеих команд.**

---

## 3. Недостижимые из CLI

**Н-1. `CheckCommand.php:163` — `catch (ConflictingCliAliasException)` мёртв. Подтверждено.**
Цепочка проверена по звеньям, каждое названо:

* единственное место броска — `src/Infrastructure/Rule/RuleRegistry.php:59`, внутри
  `getAllCliAliases()`;
* единственный производственный вызов `getAllCliAliases()` —
  `src/Infrastructure/Console/CheckCommandDefinition.php:325` (проверено `grep` по всему `src/`:
  кроме объявления в `RuleRegistry.php:48` и в `RuleRegistryInterface.php:34` вызовов нет);
* он достижим только из `RuleInputValidator::configureCheckCommand()`
  (`src/Infrastructure/Console/RuleInputValidator.php:205`), единственный вызов которого —
  `src/Infrastructure/Console/Command/CheckCommand.php:66`, то есть тело `configure()`;
* `configure()` вызывается конструктором: `vendor/symfony/console/Command/Command.php:116`;
* конструирование идёт в `ContainerCommandLoader::get()` внутри `Application::find()`, вызванного
  на `vendor/symfony/console/Application.php:298` (сам `try` открыт на `:295`).

**Уточнение к свидетелю по коду.** Он написал «до любого `try` команды», и этого мало:
`:298` сам стоит внутри `try { … } catch (\Throwable $e)` (`vendor/…/Application.php:295-299`).
`ConflictingCliAliasException` — не `CommandNotFoundException`, поэтому исполняется ветка `else`
на `:319`, то есть исключение уходит в `renderThrowable`, exit 1. Обработчик у маршрута есть, но
это Symfony, а не `CheckCommand`.

**Второй, независимый повод недостижимости:** алиасы читаются рефлексией с классов правил
(`CliAliasReader::read($ruleClass)`), а не из конфигурации; никакой пользовательский ввод не
может создать коллизию. Маршрут — про дефект продукта, а не про конфигурацию пользователя.
Прогоном такое не строится по определению, и подмена реестра собственным bootstrap была бы
проверкой не «из CLI», поэтому вердикт выведен перечислением вызовов, а не измерением.

**Н-2. `BaselineConfiguredThresholds.php:137` — проглатывание недостижимо. Утверждение
свидетеля по коду ОПРОВЕРГНУТО.** Он написал: «Маршрут достижим; какой именно пользовательский
вход туда доходит, чтением кода не устанавливается». Измерено — не доходит никакой.

Механизм: `resolve()` (`:99`) действительно обходит **все** объявленные классы правил, и
`baseline:explain` действительно его зовёт (маршрут виден: при валидном конфиге строка
`qmx.yaml: 0.02` печатается). Но `resolve()` вызывается **после** `baselineRun->measure()`, а
измерительный прогон строит опции всех правил независимо от селекторов, поэтому правило, чьи
опции не строятся, роняет прогон раньше — на `BaselineCommand.php:54`, exit 1, stdout.
Пять конфигураций, каждая из которых должна была бы обойти основной прогон, — и все пять
отказали раньше, тем же текстом:

```
rules: {coupling.class-rank: {enabled: false, warning: "abc"}}          → ec=1, stdout 99 б
rules: {coupling.class-rank: {warning: "abc"}}                          → ec=1, stdout 99 б
only_rules: [complexity.ccn] + то же                                    → ec=1, stdout 99 б
--only-rule=complexity.ccn + то же                                      → ec=1, stdout 99 б
--disable-rule=coupling.class-rank + то же                              → ec=1, stdout 99 б
```

Что `resolve()` вообще виден в выводе, доказывает отдельный прогон с валидным `paths: [src]`:
`explain` печатает `qmx.yaml: 0.02` — число, которое неоткуда взять, кроме как из `resolve()`.
(Отдельно: та же конфигурация с `warning: 0.5` и `enabled: false` даёт `ec=0` и
«Nothing currently reports on this measured subject» — там исчезает весь канал, потому что
правило выключено и находки нет; это не про строку порога и в доказательство Н-2 не входит.)

**Что именно теряется:** ничего, что достижимо конфигурацией. При гипотетическом срабатывании
терялась бы одна строка `qmx.yaml:` в отчёте `explain`, и она печаталась бы как
`(not resolvable from configuration)` — **тем же текстом, что и для канала, которого не
существует** (маршрут 40). Это и есть настоящая цена конструкции: два разных факта («порог не
настроен» и «опции правила не строятся») неразличимы в выводе.

**Н-3. `BaselineConfiguredThresholds.php:175` — `catch` вокруг `forLevel()` сегодня не ловит
ничего.** Он срабатывает, только если правило объявило канал на уровне, который его опции не
поддерживают. Сверены все пять иерархических правил: `ComplexityRule:71`,
`CognitiveComplexityRule:59`, `NpathComplexityRule:63` объявляют `[Callable, Class_]`, и их
`forLevel()` (`ComplexityOptions.php:83`, `CognitiveComplexityOptions.php:83`,
`NpathComplexityOptions.php:83`) поддерживает ровно эти два; `CboRule:76` и `InstabilityRule:67`
объявляют `[Class_, Namespace_]`, `CboOptions.php:141` и `InstabilityOptions.php:127`
поддерживают ровно их. Совпадение точное во всех пяти. Клауза не мертва структурно — она
ловила бы будущее рассогласование, — но пользовательским вводом сегодня не достигается.

**Н-4. `FindingChannel.php:77` (пустой код канала) недостижим.**
`BaselineExplainCommand::readChannel()` (`:145-150`) возвращает `null` для `''` до вызова
конструктора. Достижимы только `:84` (`:` в значении) и `:93` (`#` в значении).

**Н-5. `Error` в `rules`, `graph:export`, `hook:*` — маршрут теоретический.** Ни одна из этих
команд не читает пользовательскую конфигурацию. Перепроверено двусторонне и побайтно: с битым
`rulez: {}` и **без файла конфигурации вовсе** `rules` и `graph:export src` дают `ec=0` и
stdout, совпадающий по `cmp` байт-в-байт; то же для `hook:status` в `$R/h`. То есть тишина —
это «не читал», а не «прочитал и промолчал». Значит единственный вход —
собственные CLI-опции, а Symfony отдаёт их как `string|bool|null|array`. Полная поверхность
ввода перебрана:

* `rules`: одна опция `--group` (`RulesCommand.php:36-42`), читается как `string|null` и сразу
  проверяется `in_array` (`:52`). Прогоны: `--group=` → `ec=1` (маршрут 37), `-g` без значения →
  `ec=1` через Symfony (маршрут 7). Типизированного параметра, куда попадёт неожиданный тип,
  нет.
* `graph:export`: аргумент `paths` и опции `namespace`, `exclude-namespace`, `format`,
  `direction`, `no-clusters`, `output` (`GraphExportCommand.php:45-88`); все скалярные чтения
  приведены `(string)` на `:140,143`, массивные читаются как массивы. Прогоны:
  `--direction=bogus` → `ec=0` (значение уходит в DOT как есть), `--namespace= --exclude-namespace=`
  → `ec=0`, `--output=/nonexistent-dir-xyz/out.dot` → `ec=0` с PHP Warning в оба потока,
  `graph:export nosuchpath` → `ec=1`. Ни один не даёт `Error`.
* `hook:install` — только `--force` (`VALUE_NONE`, читается сравнением с `true` на `:69`);
  `hook:uninstall` — только `--restore-backup` (то же, `:65`); `hook:status` — опций нет.
  Прогоны в одноразовом `git init`-репозитории `$R/h` (не в worktree продукта: `core.hooksPath`
  здесь абсолютный и указывает в основной checkout): `hook:status` → `ec=0`,
  `hook:uninstall --restore-backup` → `ec=0`, `hook:install --force` → `ec=0`.

Вердикт: строка #9 свидетеля по коду верна для `directives` (уже измерено) и для
`debug:layer-assignment` (измерено мной), и **не подтверждается** для остальных четырёх — там
она описывает следствие дефекта продукта, а не маршрут пользовательского ввода.

**Н-6. `LayerExpansionStage.php:89`** (`max_expanded_layers < 1`) недостижим: значение
отвергается раньше валидатором конфигурации как `ArchitectureConfigurationException`. Измерено:
`max_expanded_layers: 0` даёт `Configuration error: architecture.max_expanded_layers: must be a
positive integer (>= 1) …` (маршрут 12), а не `Architecture configuration error:` (маршрут 13).
Прочие броски `ArchitecturePreparationException` достижимы — см. Р-6, ceiling exceeded.

**Н-7. `bin/qmx:75`** — `exit($application->run())` как выражение выхода недостижим
(`autoExit = true`). Свидетель по коду назвал строку 74, фактически это 75; сама строка в
трассе фатала присутствует, то есть вызов `run()` живой, мёртв только `exit(...)` вокруг него.

---

## 4. Что я опроверг

1. **«`BaselineConfiguredThresholds.php:137` достижимо»** (свидетель по коду, §«Про достижимость
   #36-#37»). Опровергнуто пятью прогонами: опции всех правил строятся в `measure()` до
   `resolve()`, и отказ всегда приходит раньше — `BaselineCommand.php:54`, exit 1. См. Н-2.

2. **«`--memory-limit` неверной формы: 1 в `baseline:*` и `debug:layer-assignment`»**
   (свидетель по коду, §«Заметные расхождения», и строки #20/#25). Опции `--memory-limit` у этих
   команд **нет вовсе**: `ec=1, stderr` с рамкой `The "--memory-limit" option does not exist.` —
   это маршрут 7, а не 20 и не 34. У `check` опция есть, и она даёт **два разных**
   маршрута, которые свидетель склеил в один: `--memory-limit=bogus` → `ec=3, stderr 69 б`,
   текст `Invalid memory_limit "bogus". Expected bytes or a K, M, or G suffix.` **без** шапки
   «Unexpected error» — это `RuntimeLimits.php:14` → `:190` → маршрут **14**; а
   `--memory-limit=1` → `ec=1, stderr 53 б`, `Unexpected error: Cannot set requested
   memory_limit.` — это `RuntimeLimitsController` → `:201` → маршрут **16**.

3. **«`InvalidArgumentException` из `new FindingChannel($raw)` — маршрут неизвестного
   `--channel`»** (свидетель по коду, #26). Неизвестное имя канала **не отвергается**:
   `--channel=bogus` → `ec=0`, отчёт печатается со строкой `(not resolvable from configuration)`.
   Маршрут существует, но его вход — `#` или `:` внутри значения, а не «канала не существует».
   Строка `FindingChannel.php:77` (пустой код) недостижима отдельно (Н-4).

4. **«Байт-в-байт» у изолированного повтора маршрута 255** (свидетель по измерению, R10).
   stderr действительно идентичен побайтно, stdout — только по длине: в нём xdebug-трасса с
   колонкой времени, которая меняется между прогонами. Вывод свидетеля («маршрут устойчив») в
   силе, формулировка — нет.

5. **«14 маршрутов, 3 кода, 3 варианта потока»** (свидетель по измерению, §2). Кодов у
   достижимых маршрутов четыре: код **2** ему не встретился ни разу, а он достижим четырьмя
   разными входами (Р-3). Потоков — пять, а не три (§5).

6. **«Отчёт: нет» на всех 14 маршрутах** (свидетель по измерению). Пять отказных маршрутов
   `directives` и два `debug:layer-assignment` выдают структурный конверт `{error, exit_code}`
   при `--format=json` (Р-4).

7. **`bin/qmx:74`** (свидетель по коду, §4.3) — строка 75. Мелочь, но она названа как
   `путь:строка`.

Ни одно утверждение свидетеля по измерению о **кодах и потоках уже названных им маршрутов** не
опровергнуто: все 14 воспроизвелись. Опровергнута только его полнота.

---

## 5. Итог тремя числами

* **Достижимо из CLI: 38 маршрутов** из 42 сведённых; из них **35 — собственно отказы**, а
  три (39, 40, 42) — маршруты не-отказа, где негодный ввод принят молча. Недостижимы четыре: 8
  (`ConflictingCliAliasException` в `CheckCommand`), 11 (`Error` в `rules`/`graph:export`/`hook:*`),
  24 и 25 (оба проглатывания в `BaselineConfiguredThresholds`).
* **Различных кодов возврата у достижимых: 5** — `0`, `1`, `2`, `3`, `255`. Из них **4** несут
  собственно отказ (`1`, `2`, `3`, `255`); `0` стоит на трёх маршрутах не-отказа (39, 40, 42),
  где негодный ввод принят молча. Код `4` — вне предмета (неполнота разбора).
* **Вариантов потока: 5** — (1) stderr через `ErrorStream` / `renderThrowable`; (2) stdout через
  `$output->writeln`; (3) stdout как JSON-конверт `{error, exit_code}`; (4) оба потока сразу
  (PHP fatal при uncaught `Error`; PHP Warning при неудавшемся `chdir` и при незаписываемом
  `--output`); (5) ни в один поток — молчаливое принятие.

Главный вывод обоих свидетелей подтверждён и усилен: **код возврата определяется не классом
ошибки, а тем, какая команда её поймала.** Один и тот же битый `qmx.yaml` (`rulez: {}`) —
`3`/stderr в `check`, `3`/stdout в `directives`, `1`/stdout в пяти `baseline:*` и в
`debug:layer-assignment`. Один и тот же `TypeError` из чтения конфигурации — `1` в `check` и
`baseline:*`, `255` с фатальным трейсом в `directives` **и** в `debug:layer-assignment`. Один и
тот же неизвестный `--format` — `3` в `check`, `3` в `directives`, `2` в
`debug:layer-assignment`, `1` в `graph:export`.

---

## 6. Команды

`R` — каталог пробника, `WT` — дерево на `1513bf67`. Код возврата снимать сразу и без пайпа.

```bash
R=<probe>
WT=<worktree на 1513bf67>   # абсолютный путь не записан: CLAUDE.md §10

# 1. Контроль. Без него ни один отказ ниже не является свидетельством.
#    Ожидание: ec=0, stdout 13248 б, stderr 0 б.
printf '%b' 'paths: [src]\n' > "$R/p/qmx.yaml"
"$WT/bin/qmx" -d "$R/p" check src --workers=0 --no-cache --no-progress --format=json --fail-on=none 1>o 2>e
echo "ec=$? out=$(wc -c<o) err=$(wc -c<e)"

# 2. Маршрут 10 — самая ценная находка: debug:layer-assignment роняет процесс так же, как
#    directives. Ожидание: обе команды ec=255, вывод в ОБА потока; --format=json ничего не меняет.
printf '%b' 'paths: [src]\ncomputed_metrics:\n  computed.myscore:\n    formula: "1 + 1"\n    levels:\n      class:\n        warning: 1\n' > "$R/p/qmx.yaml"
for c in "directives src" "directives src --format=json" \
         "debug:layer-assignment RecNs\\Probe" "debug:layer-assignment RecNs\\Probe --format=json"; do
  "$WT/bin/qmx" -d "$R/p" $c 1>o 2>e; echo "ec=$? out=$(wc -c<o) err=$(wc -c<e) :: $c"
done

# 3. Н-2: проглатывание на BaselineConfiguredThresholds.php:137 недостижимо — основной прогон
#    отказывает раньше при ЛЮБОМ способе вывести правило из-под селекторов.
#    Ожидание: все пять ec=1, stdout 99 б, stderr 0 б, один и тот же текст.
S='declaration:class:RecNs\Probe@src/Probe.php'
for cfg in 'rules:\n  coupling.class-rank:\n    enabled: false\n    warning: "abc"\n' \
           'rules:\n  coupling.class-rank:\n    warning: "abc"\n' \
           'only_rules: [complexity.ccn]\nrules:\n  coupling.class-rank:\n    warning: "abc"\n'; do
  printf '%b' "paths: [src]\n$cfg" > "$R/p/qmx.yaml"
  "$WT/bin/qmx" -d "$R/p" baseline:explain "$S" src --no-progress 1>o 2>e; echo "ec=$? out=$(wc -c<o) err=$(wc -c<e)"
done
printf '%b' 'paths: [src]\nrules:\n  coupling.class-rank:\n    warning: "abc"\n' > "$R/p/qmx.yaml"
"$WT/bin/qmx" -d "$R/p" baseline:explain "$S" src --no-progress --only-rule=complexity.ccn 1>o 2>e; echo "ec=$?"
"$WT/bin/qmx" -d "$R/p" baseline:explain "$S" src --no-progress --disable-rule=coupling.class-rank 1>o 2>e; echo "ec=$?"

# 4. Код 2, которого у измерителя нет ни разу. Ожидание: четыре раза ec=2, всё в stdout.
printf '%b' 'paths: [src]\n' > "$R/p/qmx.yaml"
for c in "baseline:explain $S src --no-progress --channel=complexity.ccn#high" \
         "baseline:explain declaration:class:RecNs\\NoSuch@src/NoSuch.php src --no-progress" \
         "debug:layer-assignment RecNs\\Probe --format=bogus"; do
  "$WT/bin/qmx" -d "$R/p" $c 1>o 2>e; echo "ec=$? out=$(wc -c<o) err=$(wc -c<e) :: $c"
done
# Четвёртый — отдельной командой: аргумент содержит пробелы, в цикле с неквотированным $c
# он распался бы на два и дал бы «Too many arguments», exit 1, то есть другой маршрут.
"$WT/bin/qmx" -d "$R/p" debug:layer-assignment 'not a fqn!!' 1>o 2>e
echo "ec=$? out=$(wc -c<o) err=$(wc -c<e)"   # ожидание: ec=2, stdout 53 б
# И опровержение: несуществующий канал НЕ отвергается — ожидание ec=0.
"$WT/bin/qmx" -d "$R/p" baseline:explain "$S" src --no-progress --channel=bogus 1>o 2>e; echo "ec=$? out=$(wc -c<o)"

# 5. Один битый ключ в девяти командах — таблица кодов и потоков целиком.
#    Ожидание: 3/stderr, 1/stdout ×5, 3/stdout, 1/stdout, 0/тишина ×2.
printf '%b' 'rulez: {}\npaths: [src]\n' > "$R/p/qmx.yaml"
cp "$R/runs/bl.json" "$R/runs/blu.json"
for c in "check src --workers=0 --no-cache --no-progress --fail-on=none" \
         "baseline:generate $R/runs/bl4.json src --no-progress" \
         "baseline:explain $S src --no-progress" \
         "baseline:update $R/runs/blu.json src --no-progress" \
         "baseline:cleanup $R/runs/blu.json src --no-progress" \
         "directives src" "directives src --format=json" \
         "debug:layer-assignment RecNs\\Probe" "rules" "graph:export src"; do
  "$WT/bin/qmx" -d "$R/p" $c 1>o 2>e; echo "ec=$? out=$(wc -c<o) err=$(wc -c<e) :: $c"
done
```
