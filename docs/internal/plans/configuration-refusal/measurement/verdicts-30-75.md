# Пересъёмка позиций 30–75 на дереве `1513bf67`

Пробник: `$PROBE` (`composer.json` psr-4 `RecNs\` → `src/`; `src/Probe.php`
с `RecNs\Probe::complex` CCN 15 и `::simple` CCN 1; `src/Sub/Other.php`
с `RecNs\Sub\Other::f` CCN 1). Собран заново, не заимствован.

Каждый прогон:
`bin/qmx -d <probe> check src --workers=0 --no-cache --no-progress --format=json --fail-on=none [...]`,
`1>runs/<id>.out 2>runs/<id>.err`, `ec=$?` снят сразу и без пайпа. YAML собирался
через `printf '%b'`. Дайджест — md5 (10 знаков) по отсортированному кортежу
`(channel, symbol, severity, threshold)` из `violations`.

Дерево не изменялось: всё писалось только в каталог пробника.

**Проверка тождества дерева** (не взята с брифа на веру):
`git -C $WT rev-parse --short HEAD` → **`1513bf67`**, `Merge pull request #59 from
qualimetrix/x13-orchestrator`. `$WT/vendor` — настоящий каталог, не симлинк;
`vendor/composer/autoload_psr4.php` резолвит `Qualimetrix\` в `$baseDir . '/src'`, где
`$baseDir` — корень этого worktree. То есть исполнялся `src/` именно измеряемого дерева.

## 1. Контроли (сняты ПЕРВЫМИ)

| id       | конфигурация                                                  | n   | digest       | exit |
| -------- | ------------------------------------------------------------- | --- | ------------ | ---- |
| BASE     | `rules: {complexity.ccn: {callable: {warning: 1, error: 2}}}` | 7   | `11c4ae823e` | 0    |
| DEFAULT  | только `paths: [src]`                                         | 5   | `7a7cf1283b` | 0    |
| HALF     | `callable: {error: 2}`                                        | 5   | `cca38732a6` | 0    |
| CLASSLVL | `class: {max_warning: 1, max_error: 2}`                       | 7   | `26499a5245` | 0    |
| FLAT     | `threshold: 1`                                                | 7   | `04c330cc25` | 0    |
| NOCCN    | `enabled: false`                                              | 4   | `df82e194fa` | 0    |
| EMPTY    | `--only-rule=architecture.circular-dependency`                | 0   | `d41d8cd98f` | 0    |

**Контроли воспроизвелись: да.** Все семь дайджестов попарно различны, структура
7/5/*/7/7/4/0 совпадает с таблицей перечисления.

Единственное отличие от таблицы — **HALF даёт 5 находок, а не 6**. Причина — свойство
моего пробника, а не продукта: `::simple` и `::f` имеют CCN 1, при `error: 2` (без
`warning`) они порога не переходят, поэтому HALF отличается от DEFAULT не количеством, а
переразметкой `Probe::complex` с `warning@10` на `error@2`. Дайджесты HALF и DEFAULT
различны (`cca38732a6` ≠ `7a7cf1283b`), то есть HALF остаётся полноценным
дискриминатором «выжил только `error`». Решение развилки: контроль принят как
воспроизведённый, различимость важнее равенства n таблице.

Разложение находок по контролям (для перепроверки):

* BASE: `complexity.ccn` error@2 на `complex`, warning@1 на `simple` и на `Other::f`
  + `code-smell.long-parameter-list`, `complexity.npath`, 2× `coupling.class-rank`.
* DEFAULT: `complexity.ccn` warning@10 только на `complex` + те же 4 фоновые.
* NOCCN: только 4 фоновые.

## 2. Позиции 30–75

Столбец «поток» — куда ушла диагностика. `refuse 3` во всех случаях означает: stdout
**пуст** (JSON не печатается вовсе), текст на stderr, exit 3.

### D. Опции правила, глубина 1

| #   | вход                                                                                                | вердикт на `1513bf67`                                                                                                                          | изменилось                                                                                               | диагностика                                                                                                                                                                                                            |
| --- | --------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 30  | `{callabel: {...}}`                                                                                 | **refuse 3**, stderr                                                                                                                           | **да** (было warn/DEFAULT)                                                                               | `Configuration error: Option "callabel" is not an option of rule "complexity.ccn". Options here: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.`               |
| 31  | `{method: {warning: 1}}`                                                                            | **refuse 3**, stderr                                                                                                                           | **да**                                                                                                   | то же с `"method"`                                                                                                                                                                                                     |
| 32  | `{namespace: {warning: 1}}`                                                                         | **refuse 3**, stderr                                                                                                                           | **да**                                                                                                   | то же с `"namespace"`                                                                                                                                                                                                  |
| 33  | `{Callable: {warning: 1, error: 2}}`                                                                | **silent**, exit 0, digest = **BASE**                                                                                                          | нет                                                                                                      | — (дискриминатор `p34b`: `{Callable: {WARNING: 1, ERROR: 2}}` отказывает словами *at level "callable"* — значит Title-case слот именно **нормализован**, а не совпал случайно)                                         |
| 34  | `{CALLABLE: {...}}`                                                                                 | **refuse 3**, stderr; **имя в сообщении по-прежнему искажено**                                                                                 | **да** (было warn)                                                                                       | `Option "cALLABLE" is not an option…` (искажение сменило форму: было `c-a-l-l-a-b-l-e`, стало `cALLABLE`)                                                                                                              |
| 35  | `coupling.class-rank: {warnign: 0.01, error: 0.02}`                                                 | **refuse 3**, stderr                                                                                                                           | **да**                                                                                                   | `Option "warnign" is not an option of rule "coupling.class-rank". Options here: enabled, error, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold, warning.`                                 |
| 36  | `complexity.ccn: {max_distance_warning: 1}`                                                         | **refuse 3**, stderr; написание автора перевёрнуто в camelCase                                                                                 | **да**                                                                                                   | `Option "maxDistanceWarning" is not an option of rule "complexity.ccn". …`                                                                                                                                             |
| 37  | `code-smell.eval: {threshold: 3}`                                                                   | **refuse 3**, stderr                                                                                                                           | **да**                                                                                                   | `Option "threshold" is not an option of rule "code-smell.eval". Options here: enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths.`                                                              |
| 38  | `{thresholds: 1}`                                                                                   | **refuse 3**, stderr                                                                                                                           | **да**                                                                                                   | `Option "thresholds" is not an option…`                                                                                                                                                                                |
| 39  | `{enabled: false, callable: {...}}`                                                                 | **silent**, exit 0, digest = **NOCCN** — правило выключено                                                                                     | **да** — ложная тревога исчезла (было warn)                                                              | —                                                                                                                                                                                                                      |
| 40  | `{enable: false, callable: {...}}`                                                                  | **refuse 3**, stderr                                                                                                                           | **да** (было warn + инертен)                                                                             | `Option "enable" is not an option of rule "complexity.ccn". …`                                                                                                                                                         |
| 41  | `{severity: warning, callable: {...}}`                                                              | **refuse 3**, stderr                                                                                                                           | **да**                                                                                                   | `Option "severity" is not an option…`                                                                                                                                                                                  |
| 42  | `{suppress_namespaces: ["RecNs"], callable: {...}}`                                                 | **silent**, exit 0, digest = **NOCCN** — принят и **работает**                                                                                 | нет                                                                                                      | —                                                                                                                                                                                                                      |
| 43  | `{suppress_namespace_chanels: {...}}`                                                               | **refuse 3**, stderr; имя перевёрнуто нормализатором                                                                                           | **да** (было warn)                                                                                       | `Option "suppressNamespaceChanels" is not an option…`                                                                                                                                                                  |
| 44  | `{suppress_namespace_channels: {complexity.cnn: [...]}}`                                            | **refuse 3**, stderr                                                                                                                           | нет                                                                                                      | `Option "suppress_namespace_channels" for rule "complexity.ccn" is keyed by "complexity.cnn", which addresses no channel: no channel is named "complexity.cnn". The channels of "complexity.ccn" are: complexity.ccn.` |
| 45  | `{suppress_namespace_channels: {complexity.ccn: ["RecNs"]}}` и варианты `["*"]`, `["RecNs\\Probe"]` | **silent**, exit 0, digest = **BASE** во всех трёх вариантах — **но это НЕ no-op, а область действия опции** (см. §3, «Опровергнутая позиция») | **формулировка позиции неверна**; продукт не изменился                                                   | —                                                                                                                                                                                                                      |
| 46  | `{exclude_paths: ["*"]}` (глубина 1)                                                                | **refuse 3**, stderr, с префиксом пути к `qmx.yaml`                                                                                            | нет                                                                                                      | `The "exclude_paths" option was retired. To suppress findings the analysis already produces, use "suppress_paths". To exclude files from analysis entirely… — it is a different mechanism, not a renamed one.`         |
| 47  | `--rule-opt='complexity.ccn:exclude-paths=*'`                                                       | **refuse 3**, stderr, ответ в написании автора (kebab)                                                                                         | нет                                                                                                      | `The "exclude-paths" option was retired. …use "suppress-paths"…`                                                                                                                                                       |
| 48  | `{callable: {warning: 1, error: 2, exclude_paths: ["*"]}}`                                          | **refuse 3**, stderr                                                                                                                           | **да** (было silent/BASE)                                                                                | `Option "excludePaths" is not an option of rule "complexity.ccn" at level "callable". Options at that level: enabled, error, threshold, warning. Other levels of this rule take different options.`                    |
| 49  | `rules: {complexity.ccn: "yes"}`                                                                    | **refuse 3**, stderr                                                                                                                           | нет                                                                                                      | `Invalid configuration in <qmx.yaml>: Rule "complexity.ccn" configuration must be an array, boolean, or null`                                                                                                          |
| 50  | `annotation.directive: {unused_directive_severity: loud}`                                           | **refuse 3**, stderr                                                                                                                           | нет                                                                                                      | `Option "unused_directive_severity" for rule "annotation.directive" has unknown value "loud"; expected one of 'info', 'warning', 'error'.`                                                                             |
| 51  | вход #30 + `-q`                                                                                     | **refuse 3**, **обе трубы пусты (0 байт)** — текст отказа полностью съеден `-q`                                                                | **да** частично: раньше было warn + exit 0 + тишина; теперь exit 3 несёт сигнал, но объяснения нет нигде | — (диагностики нет)                                                                                                                                                                                                    |
| 52  | вход #30 + `--format=json`                                                                          | **refuse 3**, stderr; **stdout пуст, парсить нечего**                                                                                          | **да** (было warn на stderr + валидный JSON на stdout)                                                   | тот же текст, что в #30                                                                                                                                                                                                |

### E. Опции правила, глубина 2

| #   | вход                                                                             | вердикт на `1513bf67`                                                      | изменилось                | диагностика                                                                                                                                                                                    |
| --- | -------------------------------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 53  | `{callable: {warnign: 1, error: 2}}`                                             | **refuse 3**, stderr                                                       | **да** (было silent/HALF) | `Option "warnign" is not an option of rule "complexity.ccn" at level "callable". Options at that level: enabled, error, threshold, warning. Other levels of this rule take different options.` |
| 54  | `{callable: {warnign: 1, errro: 2}}`                                             | **refuse 3**, stderr (назван первый ключ)                                  | **да**                    | тот же текст, `"warnign"`                                                                                                                                                                      |
| 55  | `{callable: {warn: 1, errors: 2}}`                                               | **refuse 3**, stderr                                                       | **да**                    | тот же текст, `"warn"`                                                                                                                                                                         |
| 56  | `{callable: {WARNING: 1, ERROR: 2}}`                                             | **refuse 3**, stderr; имя искажено                                         | **да**                    | `Option "wARNING" is not an option … at level "callable"…`                                                                                                                                     |
| 57  | `{callable: {max_warning: 1, max_error: 2}}`                                     | **refuse 3**, stderr                                                       | **да**                    | `Option "maxWarning" is not an option … at level "callable"…`                                                                                                                                  |
| 58  | `{class: {warning: 1, error: 2}}`                                                | **refuse 3**, stderr                                                       | **да**                    | `Option "warning" is not an option of rule "complexity.ccn" at level "class". Options at that level: enabled, max-error, max-warning, threshold. …`                                            |
| 59  | `{callable: 10}`                                                                 | **refuse 3**, stderr                                                       | **да**                    | `Level "callable" of rule "complexity.ccn" takes a map of options, got int.`                                                                                                                   |
| 60  | `{callable:}` (null)                                                             | **silent**, exit 0, digest = **DEFAULT** — уровень испаряется              | **нет — позиция ОТКРЫТА** | — (дискриминатор #59: `callable: 10` отказывает `takes a map of options, got int` — проверка рода значения уровня **есть**, `null` проходит сквозь неё)                                        |
| 61  | `class: {max_warning}` / `{maxWarning}` / `{max-warning}`                        | **silent**, все три → digest **CLASSLVL**, эквивалентны                    | нет                       | —                                                                                                                                                                                              |
| 62  | `{callable: {warning: "abc", error: 2}}`                                         | **crash: exit 1**, stderr, отчёта нет                                      | нет                       | `Unexpected error: Invalid configuration for rule "complexity.ccn": option "callable.warning" must be numeric, got "abc".`                                                                     |
| 63  | `{threshold: "abc"}`                                                             | **crash: exit 1**, stderr                                                  | нет                       | `Unexpected error: Invalid configuration for rule "complexity.ccn": option "threshold" must be numeric, got "abc".`                                                                            |
| 64  | `{callable: {warning: true, error: 2}}`                                          | **silent**, exit 0, digest = **BASE** (`true` → 1)                         | **нет — позиция ОТКРЫТА** | — (дискриминатор #62: `warning: "abc"` отказывает `must be numeric` — числовая проверка **есть**, `bool` проходит сквозь неё)                                                                  |
| 65  | пресет `callable: {warning:1,error:2}` + `--rule-opt=complexity.ccn:threshold=1` | **silent**, exit 0, digest = **FLAT**; контроль без `--rule-opt` даёт BASE | **нет — позиция ОТКРЫТА** | —                                                                                                                                                                                              |
| 66  | `{threshold: 1, class: {max_warning: 1, max_error: 2}}`                          | **silent**, exit 0, digest = **FLAT** — блок `class` отброшен              | **нет — позиция ОТКРЫТА** | —                                                                                                                                                                                              |

### F. `--rule-opt`

| #   | вход                                                                           | вердикт на `1513bf67`                                  | изменилось                | диагностика                                                                                                                                                                                    |
| --- | ------------------------------------------------------------------------------ | ------------------------------------------------------ | ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 67  | `--rule-opt='complexity.ccn.callable.warning=1'`                               | **refuse 3**, stderr                                   | нет                       | `Invalid --rule-opt "complexity.ccn.callable.warning=1". Expected RULE:OPTION=VALUE.`                                                                                                          |
| 68  | `--rule-opt='threshold=1'`                                                     | **refuse 3**, stderr                                   | нет                       | `Invalid --rule-opt "threshold=1". Expected RULE:OPTION=VALUE.`                                                                                                                                |
| 69  | `--rule-opt='complexity.ccnn:callable.warning=1'`                              | **refuse 3**, stderr                                   | нет                       | `Rule option owner "complexity.ccnn" does not match any registered producer rule.`                                                                                                             |
| 70  | `--rule-opt='…:callable.warning=1'` + `'…:callable.error=2'`                   | принято, exit 0, digest = **BASE**                     | нет                       | —                                                                                                                                                                                              |
| 71  | `--rule-opt='complexity.ccn:callable.warning'` (без `=value`)                  | **silent**, exit 0, digest = **DEFAULT**               | **нет — позиция ОТКРЫТА** | —                                                                                                                                                                                              |
| 72  | `--rule-opt='complexity.ccn:method.warning=1'`                                 | **refuse 3**, stderr                                   | **да** (было warn)        | `Option "method" is not an option of rule "complexity.ccn". Options here: …`                                                                                                                   |
| 73  | `--rule-opt='…:callable.warnign=1'` + `'…:callable.error=2'`                   | **refuse 3**, stderr                                   | **да** (было silent/HALF) | `Option "warnign" is not an option of rule "complexity.ccn" at level "callable". …`                                                                                                            |
| 74  | `--rule-opt='complexity.ccn:warning=1'`                                        | **refuse 3**, stderr                                   | **да** (было warn)        | `Option "warning" is not an option of rule "complexity.ccn". Options here: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.`             |
| 75  | `--rule-opt='complexity.ccn:suppress_namespace_channels.complexity.ccn=RecNs'` | **refuse 3**, stderr, **сообщение по-прежнему ложное** | нет                       | `Option "suppress_namespace_channels.complexity" for rule "complexity.ccn" must be a non-empty list of strings` — имя обрезано на второй точке, жалоба на форму значения, а не на разбор ключа |

## 3. Что изменил X13

**Перешли из warn/silent в refuse 3 — 23 позиции:**
30, 31, 32, 34, 35, 36, 37, 38, 40, 41, 43, 48, 52, 53, 54, 55, 56, 57, 58, 59, 72, 73, 74.

Ещё две сменили класс, но не в refuse, и в счёт 23 не входят:

* **51** — была warn + exit 0 при `-q` (тишина). Стала exit 3 при **полностью подавленном
  тексте**: 0 байт и на stdout, и на stderr. Сигнал переехал в код возврата, объяснения нет.
* **39** — была warn (ложная тревога) + правило всё-таки выключалось. Стала полностью
  молчаливым и корректным выключением (digest NOCCN). Ложная тревога исчезла.

Гипотеза брифа «M1 закрыт» подтверждается **частично**. Внутри уровня (глубина 2) закрыты
53–59 и 73; закрыт и 48 (retired-ключ на глубине 2). Но в моём наборе **остались молчащими**:

* **60** — `callable:` с пустым значением: уровень исчезает молча, DEFAULT. Дело не в
  неузнанном ключе, а в дыре проверки рода значения уровня: соседний #59 (`callable: 10`)
  отказывает `takes a map of options, got int`, а `null` через ту же проверку проходит.
* **61** — три написания внутри уровня (`max_warning` / `maxWarning` / `max-warning`)
  эквивалентны и все дают CLASSLVL; не дефект, но нигде не объявлено.
* **64** — `warning: true` молча приводится к 1. Числовая проверка есть (#62 отказывает на
  `"abc"`), `bool` проходит сквозь неё.
* **65** — ключи нижнего слоя вытесняются при слиянии бесследно (пресет BASE +
  `--rule-opt=…:threshold=1` → FLAT).
* **66** — верхнеуровневый `threshold` молча отменяет явно написанный блок `class` → FLAT.
* **71** — `--rule-opt` с двоеточием, но без `=value`: опция отбрасывается молча, DEFAULT.
* **33** — слот уровня в Title-case принимается молча. Нормализатор написаний (M3) жив и
  за пределами этой позиции: он же коверкает имена в текстах отказов 34 (`cALLABLE`),
  36 (`maxDistanceWarning`), 43 (`suppressNamespaceChanels`), 56 (`wARNING`),
  57 (`maxWarning`), 48 (`excludePaths`) — автору возвращают не то написание, что он писал.

**Остались крашащими (exit 1, отчёта нет):** 62 и 63 — нечисловое значение порога на обеих
глубинах, обе через префикс `Unexpected error:`, то есть мимо обработчика конфигурации.

**Не изменились, оставаясь отказами:** 44, 46, 47, 49, 50, 67, 68, 69, 75 (75 — с
по-прежнему ложным текстом: имя ключа обрезано на второй точке); 70 — базис, принят.

### Опровергнутая позиция: 45

Я мерил `suppress_namespace_channels` на `complexity.ccn` и получил BASE во всех трёх
вариантах значения — ровно то, что таблица называет «silent no-op». Прежде чем записать
дефект, прочитал потребителя,
`src/Analysis/Finding/Exclusion/RuleNamespaceExclusionProvider.php`:
`isChannelExcluded()` сопоставляет селектор **только с уровнем `SymbolLevel::Namespace_`** —
опция по построению предлагается namespace-агрегатам и никому больше.
`complexity.ccn` объявляет уровни `callable` и `class`, namespace-находок не производит,
поэтому подавлять там нечего.

Перемерил на правиле, которое namespace-находку производит:

* `size.class-count: {warning: 0, error: 5}`, `--only-rule=size.class-count` → 1 находка
  `size.class-count | RecNs\Sub | ns:RecNs\Sub warning 0`;
* то же + `suppress_namespace_channels: {size.class-count: ["RecNs"]}` → **0 находок**;
* то же + значение `["NoSuchNs"]` → снова 1 находка.

Опция **работает**. Формулировка позиции 45 («валидируется и не работает») неверна, а
дискриминатор, на котором она держалась (`suppress_namespaces` на том же правиле работает),
сравнивал разные предметы: `suppress_namespaces` фильтрует находки всех уровней,
`suppress_namespace_channels` — только namespace-агрегаты.

Остаточное наблюдение, более узкое и не входившее в перечисление: ключ
`suppress_namespace_channels: {complexity.ccn: [...]}` **принимается молча на правиле,
которое namespace-канала не имеет вовсе**, то есть объявление заведомо не может сработать.
Валидатор ключа (#44) проверяет, что селектор адресует канал правила, но не проверяет, что
у канала есть уровень `namespace`. Докблок в том же файле обещает отказ по имени для ключа,
«суженного на любой другой уровень» — этот случай (уровень не сужен, но и не существует)
в обещание не попадает.

## 4. Допущения и неизмеренное

* **Неизмеренных позиций в наборе 30–75 нет** — измерены все 46.
* Тождество дерева и источник исполняемого `src/` проверены явно (см. §1), а не приняты
  с брифа.
* Допущение по HALF: принял контроль как воспроизведённый при n=5 вместо 6 таблицы, потому
  что дайджест отличает его от DEFAULT. Разложение находок приведено в §1, развилка решена
  мной.
* Позиция 61 проверена тремя написаниями только на уровне `class`; на `callable`
  не проверялась — у него нет ключа из двух слов.
* Позиция 45 измерена тремя значениями на `complexity.ccn` и тремя прогонами на
  `size.class-count`. Другие правила с namespace-каналом (`coupling.cbo`,
  `coupling.instability`, `coupling.distance`) не проверялись; вывод «опция работает»
  установлен на одном правиле плюс чтением единственного потребителя.
* Позиция 34 сравнивалась с таблицей по классу вердикта. Форма искажения имени изменилась
  (`c-a-l-l-a-b-l-e` → `cALLABLE`); это наблюдение на текущем дереве, а не сверка со старым
  выводом, которого у меня нет.
* Тексты диагностики цитируются с текущего дерева дословно; путь к `qmx.yaml` в цитатах
  сокращён до `<qmx.yaml>`.
* Позиции вне 30–75 не трогались.

## 5. Команды для перепроверки

```sh
PROBE=<этот каталог>
WT=<путь к рабочему дереву>

# Контроль BASE (7 находок, digest 11c4ae823e)
printf '%b' 'paths: [src]\nrules:\n  complexity.ccn:\n    callable:\n      warning: 1\n      error: 2\n' > $PROBE/qmx.yaml
$WT/bin/qmx -d $PROBE check src --workers=0 --no-cache --no-progress --format=json --fail-on=none 1>/tmp/a.out 2>/tmp/a.err; echo $?

# #53 — глубина 2 теперь ОТКАЗЫВАЕТ (было silent/HALF)
printf '%b' 'paths: [src]\nrules:\n  complexity.ccn:\n    callable:\n      warnign: 1\n      error: 2\n' > $PROBE/qmx.yaml
$WT/bin/qmx -d $PROBE check src --workers=0 --no-cache --no-progress --format=json --fail-on=none 1>/tmp/b.out 2>/tmp/b.err; echo $?; cat /tmp/b.err

# #45 — ОПРОВЕРГНУТА: опция работает, но только на namespace-агрегатах.
# 1 находка -> 0 находок -> снова 1 находка
printf '%b' 'paths: [src]\nrules:\n  size.class-count:\n    warning: 0\n    error: 5\n' > $PROBE/qmx.yaml
$WT/bin/qmx -d $PROBE check src --workers=0 --no-cache --no-progress --format=json --fail-on=none --only-rule=size.class-count 1>/tmp/c1.out 2>&1; echo $?
printf '%b' 'paths: [src]\nrules:\n  size.class-count:\n    warning: 0\n    error: 5\n    suppress_namespace_channels:\n      size.class-count: ["RecNs"]\n' > $PROBE/qmx.yaml
$WT/bin/qmx -d $PROBE check src --workers=0 --no-cache --no-progress --format=json --fail-on=none --only-rule=size.class-count 1>/tmp/c2.out 2>&1; echo $?
printf '%b' 'paths: [src]\nrules:\n  size.class-count:\n    warning: 0\n    error: 5\n    suppress_namespace_channels:\n      size.class-count: ["NoSuchNs"]\n' > $PROBE/qmx.yaml
$WT/bin/qmx -d $PROBE check src --workers=0 --no-cache --no-progress --format=json --fail-on=none --only-rule=size.class-count 1>/tmp/c3.out 2>&1; echo $?
grep -o '"violationCount": [0-9]*' /tmp/c1.out /tmp/c2.out /tmp/c3.out

# #71 — ОТКРЫТА: двоеточие есть, "=value" нет → молча DEFAULT
printf '%b' 'paths: [src]\n' > $PROBE/qmx.yaml
$WT/bin/qmx -d $PROBE check src --workers=0 --no-cache --no-progress --format=json --fail-on=none --rule-opt='complexity.ccn:callable.warning' 1>/tmp/e.out 2>/tmp/e.err; echo $?; wc -c /tmp/e.err

# #62 — крашит мимо обработчика конфигурации (exit 1)
printf '%b' 'paths: [src]\nrules:\n  complexity.ccn:\n    callable:\n      warning: "abc"\n      error: 2\n' > $PROBE/qmx.yaml
$WT/bin/qmx -d $PROBE check src --workers=0 --no-cache --no-progress --format=json --fail-on=none 1>/tmp/f.out 2>/tmp/f.err; echo $?; cat /tmp/f.err
```
