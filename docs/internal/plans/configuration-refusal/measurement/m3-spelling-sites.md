# M3 — где Qualimetrix складывает написание ключа конфигурации, а где нет

Дерево: worktree `x12-orchestrator-k0m-8334bb`, коммит `1513bf67`. Ничего не менялось,
кроме этого файла.

Строки помечены `[код]` (установлено чтением) или `[прогон]` (установлено запуском
`bin/qmx`). Пробники лежат в
`/private/tmp/claude-501/.../scratchpad/e/G/p01…p32`, каждый — `qmx.yaml` + `out` + `err`,
запуск `bin/qmx check <src> -c <probe>/qmx.yaml --workers=0 --no-cache --no-progress
--format=json --fail-on=none`, код возврата снимался сразу и без пайпа.

## 0. Что именно складывается

`ConfigKeySpelling::normalize()` (`src/Analysis/Configuration/ConfigKeySpelling.php:30`)
— это **не** регистронезависимость:

```
lcfirst(str_replace(['_','-'], '', ucwords(trim($key), '_-')))
```

Поэтому по каждому написанию отдельно:

| написание   | пример                         | складывается в `maxWarning`?       | почему                                                               |
| ----------- | ------------------------------ | ---------------------------------- | -------------------------------------------------------------------- |
| snake       | `max_warning`                  | да                                 | разделитель `_` — граница `ucwords`                                  |
| kebab       | `max-warning`                  | да                                 | то же для `-`                                                        |
| camel       | `maxWarning`                   | да (тождественно)                  | нечего складывать                                                    |
| Title       | `MaxWarning`, `Class`, `Paths` | да                                 | только за счёт `lcfirst`                                             |
| UPPER       | `MAX_WARNING`, `CALLABLE`      | **нет** → `mAXWARNING`, `cALLABLE` | `ucwords` уже ничего не меняет, `lcfirst` роняет только первую букву |
| смешанное   | `maxWARNING`                   | нет                                | внутренние заглавные не трогаются                                    |
| с пробелами | `" max_warning "`              | да                                 | `trim`                                                               |

Эквивалентность snake/camel/kebab **не объявлена нигде в пользовательской
документации ключей уровня**; проверено прогоном: `class: {max_warning: 1}`,
`{maxWarning: 1}`, `{max-warning: 1}` и слот `Class:` дают одинаковый результат —
36 находок против 25 без конфигурации (p20–p23) `[прогон]`.

Второй, **другой** фолд: `RuleOptionsParser::normalizeRuleName()`
(`RuleOptionsParser.php:164`) — `strtolower(trim())`. Он складывает регистр и не
складывает разделители; применяется только к **имени правила** на CLI-двери.

## 1. Где нормализация применяется / не применяется

### 1.1 Применяется (12 строк, 14 мест `путь:строка` — см. §6)

| #   | `путь:строка`                                                                     | уровень документа                                                                                                                                                                                        | какие написания складывает                                         | как установлено                                                                                                                           |
| --- | --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| A1  | `src/Analysis/Configuration/Loader/YamlConfigLoader.php:109`                      | корневой ключ YAML                                                                                                                                                                                       | snake, kebab, camel, Title; UPPER — нет                            | код + p03/p28 (`SUPPRESS_NAMESPACES`, `RULES` отвергнуты), p01 (`Paths:`, `Rules:` приняты)                                               |
| A2  | `.../YamlConfigLoader.php:153`                                                    | всё глубже корня под политикой `NORMALIZE_TO_CAMEL_CASE`; глубже уровня 1 под `PRESERVE_IMMEDIATE_CHILDREN` (т.е. **ключи опций правила** и **ключи внутри слота уровня**, и depth‑2 `computed_metrics`) | то же                                                              | код + p11 (`cache: {Enabled:}` принято), p01/p02/p18                                                                                      |
| A3  | `.../YamlConfigLoader.php:76`                                                     | корневой ключ — построение обратной карты `нормализованный → набранный`                                                                                                                                  | то же                                                              | код                                                                                                                                       |
| A4  | `.../YamlConfigLoader.php:408`                                                    | имя секции — поиск набранного написания                                                                                                                                                                  | то же                                                              | код                                                                                                                                       |
| A5  | `.../YamlConfigLoader.php:425`                                                    | имя секции при поиске подключа                                                                                                                                                                           | то же                                                              | код                                                                                                                                       |
| A6  | `.../YamlConfigLoader.php:430`                                                    | подключ секции — поиск набранного написания                                                                                                                                                              | то же                                                              | код + p12/p15/p30 (`enabld`, `ENABLED`, `en-abled` возвращены как набраны)                                                                |
| A7  | `src/Analysis/Configuration/RetiredSuppressionOptions.php:165`                    | любой ключ, проверяемый на принадлежность к снятому семейству `exclude*`                                                                                                                                 | то же                                                              | код + p08/p09                                                                                                                             |
| A8  | `src/Analysis/Finding/RuleConfiguration/RuleOptionsParser.php:155`                | имя опции в `--rule-opt RULE:OPTION=VALUE`                                                                                                                                                               | то же (точка сохраняется, часть после точки складывается отдельно) | код + p05/p06/p27                                                                                                                         |
| A9  | `.../RuleOptionsParser.php:141` (через `:164`)                                    | **имя правила** в `--rule-opt`                                                                                                                                                                           | только регистр (`strtolower`), не разделители                      | код                                                                                                                                       |
| A10 | `.../RuleOptionsParser.php:104` и `:119` (через `:164`)                           | имена правил `--disable-rule` / `--only-rule`                                                                                                                                                            | только регистр                                                     | код; **у обоих методов нет производственных вызывающих** — фолд мёртв, фактически действует A-нет‑9 ниже (p17 отвергает `Complexity.CCN`) |
| A11 | `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php:279`               | ключи опций правила из файла конфигурации                                                                                                                                                                | то же                                                              | код; **для YAML‑входа это no‑op** — A2 уже сложил                                                                                         |
| A12 | `src/Analysis/Finding/RuleConfiguration/RuleOptionKeyRecognition.php:73` и `:126` | сверка ключа опции (глубина 1) и ключа внутри слота уровня (глубина 2)                                                                                                                                   | то же                                                              | код + p01/p02/p18/p25                                                                                                                     |

Подстрока: фолд **словаря продукта, а не пользователя** — в счёт не входит:
`RuleOptionKeySet::index:114` и `:120` (проверка каноничности объявленного kebab),
`RuleOptionKeyRecognition::normalizedFrameworkKeys:161`,
`RuleOptionsParser::parseShortAlias:89` (имя опции из `#[CliAlias]`),
`scripts/enumerate-rule-option-keys.php:287` и `:291`.

### 1.2 Не применяется (10 мест)

| #   | `путь:строка`                                                                                                                                     | уровень документа                                                           | что происходит с написанием                                                                               | как установлено                                                                                |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| N1  | `ConfigSchema.php:235` (`RULES` → `PRESERVE_IMMEDIATE_CHILDREN`), сверка `Pipeline/RuleNameValidator.php:60`                                      | **имя правила** в `rules:`                                                  | сохраняется дословно, сверяется точным равенством                                                         | p16: `Complexity.CCN` → «Unknown rule "Complexity.CCN"»                                        |
| N2  | `ConfigSchema.php:236`                                                                                                                            | имя метрики в `computed_metrics:`                                           | сохраняется дословно                                                                                      | код                                                                                            |
| N3  | `ConfigSchema.php:244` (`ARCHITECTURE` → `PRESERVE_SUBTREE`), сверка `Policy/Architecture/Configuration/ArchitectureConfigurationFactory.php:300` | **все** ключи секции `architecture` на любой глубине                        | не складывается вовсе                                                                                     | p10: `coverage_gap` → «architecture: unknown key "coverage_gap"» при каноничном `coverage-gap` |
| N4  | `Policy/Architecture/Configuration/LayersValidator.php:277`                                                                                       | ключи записи слоя                                                           | точное равенство                                                                                          | код                                                                                            |
| N5  | `Policy/Architecture/Configuration/ExcludeBlockValidator.php:161`                                                                                 | ключи блока `exclude:`                                                      | точное равенство                                                                                          | код                                                                                            |
| N6  | `ConfigSchema.php:274` (`identifierKeyedOptions`) + `Infrastructure/Console/ChannelExclusionKeyValidator.php:84`                                  | ключи внутри `suppress_namespace_channels` (имена каналов)                  | сохраняются дословно — намеренно, иначе `code-smell.boolean-argument` стал бы `codeSmell.booleanArgument` | код                                                                                            |
| N7  | `Infrastructure/Console/FormatterContextFactory.php:59`                                                                                           | ключ `--format-opt KEY=VALUE`                                               | не складывается **и не проверяется**: неизвестный ключ молча игнорируется                                 | p14: `--format-opt=NOSUCHKEY=1` → exit 0, ни отказа, ни предупреждения                         |
| N8  | `Policy/Baseline/BaselineLoader.php:118`…`:124`                                                                                                   | ключи документа baseline (JSON: `version`, `entries`, `generated`, `scope`) | читаются точными литералами, фолда нет, неизвестные ключи не проверяются                                  | код                                                                                            |
| N9  | `Infrastructure/Console/RuleInputValidator.php:147`                                                                                               | владелец опции в `--rule-opt RULE:…`                                        | сырой `substr`, без фолда — при том что `RuleOptionsParser:141` его сложил бы                             | p19: `--rule-opt=Complexity.CCN:…` → «Rule option owner "Complexity.CCN" does not match…»      |
| N10 | `Infrastructure/Console/RuleInputValidator.php:117`                                                                                               | селектор `--disable-rule` / `--only-rule`                                   | сырой, без фолда                                                                                          | p17: `--disable-rule=Complexity.CCN` → отказ, хотя `strtolower` в A10 совпал бы                |

Отдельно, не фолд, а **отсутствие проверки**: `ComputedMetricOverrideReader` читает
ключи `computed_metrics.<metric>.*` точными односложными литералами (`formula`,
`formulas`, `levels`, `description`, `inverted`, `threshold`, `warning`, `error`) —
фолд A2 для них no‑op, а неизвестный ключ молча отбрасывается (p13:
`DESCRIPTIION: x` → exit 0).

## 2. Где нормализованное имя попадает в сообщение вместо набранного

Класс (a) — печатается **свёрнутая** строка (буквы сохранены, регистр и разделители
потеряны):

| #   | где имя уходит в текст                                                                          | где текст собирается                            | что попадает                                                                                                        | путь, по которому имя приходит уже свёрнутым                              | прогон                                                                                                                                                 |
| --- | ----------------------------------------------------------------------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| L1  | `RuleConfiguration/RuleOptionKeyRecognition.php:86` (`$key`)                                    | `Contract/Rule/RuleOptionRefusalWording.php:36` | ключ опции глубины 1                                                                                                | YAML: A2 (`applyPolicy:153`) до обхода; CLI: A8 (`RuleOptionsParser:155`) | p01 `CALLABLE`→`cALLABLE`; p04 `suppress_namespace_chanels`→`suppressNamespaceChanels`; p05 `MAX_WARNIGN`→`mAXWARNIGN`; p27 `CLASS.…`→`cLASS`          |
| L2  | `RuleOptionKeyRecognition.php:131` (`$key`)                                                     | `RuleOptionRefusalWording.php:59`               | ключ внутри слота уровня                                                                                            | те же две двери                                                           | p02 `max_warnign`→`maxWarnign`; p07 `exclude_paths`→`excludePaths`; p18 `MAX_ERROR`→`mAXERROR`; p06 `callable.MAX_WARNIGN`→`MAXWARNIGN`                |
| L3  | `RuleOptionKeyRecognition.php:133` (`$level` = `$normalized` из `:73`)                          | `RuleOptionRefusalWording.php:61`               | **имя слота уровня** в том же сообщении                                                                             | A2/A8 плюс собственный фолд на `:73`                                      | p25: набрано `Class:` → в сообщении level `"class"`                                                                                                    |
| L4  | `RuleOptionKeyRecognition.php:117` (`$level`)                                                   | `RuleOptionRefusalWording.php:83` и `:89`       | имя слота уровня в «takes a map of options» (и в подсказке `"%s: {enabled: false}"`)                                | то же                                                                     | p26: набрано `Class: 5` → «Level "class" … takes a map of options»                                                                                     |
| L5  | `RuleOptionsFactory.php:366` (`$fullKey`, собран в `validateNumericFields` из свёрнутых ключей) | там же, `:365–371`                              | ключ **известной** опции с нечисловым значением — путь мимо обхода: обход его пропускает, потому что ключ распознан | A2 (YAML) или A8+`expandDotNotation` (CLI)                                | p31: `class: {max_warning: "abc"}` → «option "class.maxWarning" must be numeric»; p32: `--rule-opt=…:class.max-warning=abc` → то же `class.maxWarning` |

Класс (b) — печатается **каноничный литерал схемы**, а не набранное:

| #   | где                                                                  | что печатается                           | прогон                                                                     |
| --- | -------------------------------------------------------------------- | ---------------------------------------- | -------------------------------------------------------------------------- |
| L6  | `YamlConfigLoader.php:377` (`$resultKey` из `ConfigSchema::ENTRIES`) | `cache.enabled` независимо от набранного | p29: набрано `cache: {Enabled: "x"}` → «Invalid value for "cache.enabled"» |

L6 — **единственный** экземпляр класса (b), взятый на уровне загрузчика. Семейство
каноничных литералов внутри `fromArray()`-тел (`ThresholdParser:72`,
`UnassignedClassOptions:113/159/175`, `ComputedMetricOverrideReader::thresholds`)
существует и здесь **не перечислено** — см. §4, «чего этот способ не видит».

Не попало в счёт, хотя выглядело кандидатом: `RetiredSuppressionOptions::refuseRuleOption`
(`:132–133`), вызванный из `RuleOptionsFactory.php:92`, действительно видит уже
свёрнутые ключи — но **пользовательским входом недостижим**. Он обходит только
верхний уровень `$userConfig`; всякий снятый ключ, написанный там, перехватывается
раньше и с набранным написанием (`refuseInRules` на `YamlConfigLoader:264` для YAML,
`RuleOptionsParser:153` для `--rule-opt`), а вложенный (`callable: {exclude_paths}`)
имеет верхним ключом `callable` и достаётся обходу как L2 — именно это и напечатал
p07. Докблок `RetiredSuppressionOptions.php:121–126` называет этот вызов бэкстопом
для опций, собранных кодом, а не набранных пользователем.

Итого мест, возвращающих автору не то написание: **6** (пять класса (a) — L1–L5,
одно класса (b) — L6).

Для знаменателя — места, которые **уже** отвечают набранным написанием:
`YamlConfigLoader::originalKey:172` (корневые ключи, через карту A3 — p03),
`YamlConfigLoader::findOriginalSubKey:421` (подключи секции — p12/p15/p30),
`RetiredSuppressionOptions::refuseRootKey:104` (корень, `$keyMap` + `rewriteLike` —
p09), `RetiredSuppressionOptions::refuseInRules:65` (глубина 1 блока правила, сырой
документ — p08), `RuleOptionsParser:153` (`--rule-opt`, отказ до фолда),
`RuleNameValidator:72` (имена правил — p16), архитектурные валидаторы N3–N5 (p10).
Три независимых инверсии фолда уже существуют: `ConfigKeySpelling::rewriteLike:41`,
`YamlConfigLoader:213` и `YamlConfigLoader:453` (обе — `preg_replace('/[A-Z]/','_$0')`).

## 3. Точки асимметрии

Пары соседних уровней/дверей, дающих разный ответ на одну и ту же форму:

1. **Корень vs подключ секции по UPPER.** `SUPPRESS_NAMESPACES:` — отказ 3 (p03);
   `cache: {ENABLED:}` — тоже отказ (p15). Согласовано. Но **Title** принимается
   обоими (`Paths:`, `Rules:`, `cache: {Enabled:}` — p01/p11), а UPPER нет: одна
   и та же таблица фолда даёт «регистр не важен» для Title и «неизвестный ключ»
   для UPPER. Молча — предупреждения о Title нет нигде.
2. **Ключ опции правила vs имя правила в `rules:`.** Опция складывается (A2),
   имя правила рядом — нет (N1). `rules: {Complexity.CCN: {maxWarning: 1}}`
   отвергается по имени, а не по опции (p16).
3. **`rules:` vs `architecture:`.** Соседние корни: под первым snake↔kebab↔camel
   одно и то же, под вторым `coverage_gap` при каноничном `coverage-gap` — отказ
   (p10). Сама секция `architecture` при этом внутренне непоследовательна в
   каноне: `coverage-gap` (kebab) и `max_expanded_layers` (snake) в одном списке
   `ALLOWED_TOP_LEVEL_KEYS` (`ArchitectureConfigurationFactory.php:63`).
4. **`--rule-opt` vs `rules:` по одному и тому же ключу.** Оба складывают, но
   **по-разному ломают** UPPER: YAML‑дверь на `CALLABLE` печатает `cALLABLE`
   (p01), CLI‑дверь на `callable.MAX_WARNIGN` печатает `MAXWARNIGN` целиком
   заглавными (p06) — потому что `lcfirst` в CLI‑пути срабатывает на части
   строки до точки, а `expandDotNotation` уже режет по точке.
5. **Имя правила: парсер vs валидатор.** `RuleOptionsParser:164` складывает
   регистр имени правила, `RuleInputValidator:147`/`:117` сверяет сырое. Итог —
   фолд не наблюдается снаружи: `--rule-opt=Complexity.CCN:…` (p19) и
   `--disable-rule=Complexity.CCN` (p17) отвергаются. У `parseDisabledRules` /
   `parseOnlyRules` производственных вызывающих нет вовсе.
6. **`--rule-opt` vs `--format-opt`.** Первый складывает написание и отвергает
   неизвестный ключ (p05); второй не складывает и не отвергает (p14).
7. **Ключ опции vs ключ внутри `suppress_namespace_channels`.** Родитель
   складывается (`suppress_namespace_chanels` → `suppressNamespaceChanels`, p04),
   его дети — намеренно нет (N6).
8. **Глубина 1 vs глубина 2 для снятого `exclude_paths`.** На глубине 1
   отказ приходит с набранным написанием (p08), на глубине 2 — со свёрнутым
   (p07). Один и тот же ключ, одно и то же семейство, разный ответ.
9. **`computed_metrics` depth‑2 vs `rules` depth‑2.** Оба складываются (A2), но
   опечатка в первом молча игнорируется (p13), а во втором — отказ (p02).

## 4. Чем получено и чего этот способ не видит

Перебранные каналы ссылки:

- **прямой импорт / символические ссылки (Serena):** `initial_instructions`, затем
  `find_referencing_symbols` по `ConfigKeySpelling/normalize`,
  `ConfigKeySpelling/rewriteLike`, `RuleOptionRefusalWording`. Дало **дельту к
  grep**: `scripts/enumerate-rule-option-keys.php` (grep по `src`/`tests`/`bin`
  его не видел). Обратного не случилось — все места вызова, найденные grep, Serena тоже нашла (упоминания в `{@see}` и README она, как и положено, не индексирует как ссылки).
- **строковый литерал:** ручной проход по `src/Analysis/Configuration/`,
  `src/Analysis/Finding/RuleConfiguration/`, `src/Analysis/Finding/Contract/Rule/`,
  `src/Analysis/Policy/Architecture/Configuration/`,
  `src/Analysis/Policy/Baseline/`, `src/Analysis/Evidence/ComputedMetrics/`,
  `src/Infrastructure/Console/`.
- **константа схемы:** `ConfigSchema::ENTRIES`, `sectionPolicies()`,
  `identifierKeyedOptions()`, `allowedRootKeys()`, `allowedSectionSubKeys()`,
  `ALLOWED_TOP_LEVEL_KEYS` / `ALLOWED_ENTRY_KEYS` / `ALLOWED_EXCLUDE_KEYS`.
- **интерполяция в сообщении:** grep по `sprintf` вокруг `$key`/`$name`/`$option`
  по всему `src` (без `node_modules`), плюс grep по «Unknown key», «did you
  mean», «is not an option».
- **YAML-нормализация:** `SectionNormalizationPolicy` и `applyPolicy`, ADR 0009.
- **прогон:** 32 пробника, поимённо перечисленных в таблицах.

Чего этот способ **не** видит:

- **DI:** контейнер не перебирался. Если какой-то дверью служит сервис,
  зарегистрированный только строкой class-string, его фолд здесь не учтён.
- **тесты как источник поведения:** тестовые деревья читались только там, куда
  привела Serena; отдельного прохода по `tests/` на предмет «ещё одна дверь,
  покрытая только тестом» не делалось.
- **документация сайта (`website/docs/`):** не проверялась вовсе. Утверждение
  «эквивалентность snake/camel/kebab нигде не объявлена» опирается на
  отсутствие такого текста в README компонентов и в докблоках, а не на обход
  сайта — считать это гипотезой средней уверенности.
- **прочие двери конфигурации, не перечисленные явно:** `--exclude-health`,
  `@qmx-threshold` / `@qmx-ignore` (инлайн-директивы), переменные окружения,
  `composer.json`-секции. Пресеты проверены только по коду: `PresetStage`
  использует тот же `ConfigLoaderInterface`, то есть тот же `YamlConfigLoader`
  и те же A1/A2 — отдельным прогоном не подтверждено.
- **не PHP-двери:** JS-часть HTML-отчёта не смотрелась.
- Строки, помеченные `[код]` без прогона, — суждение о поведении по чтению; для
  A3–A5, A9, A11 и N2, N4, N5, N8 наблюдаемого выхода я не снимал.

## 5. Смежное (предмет соседнего агента, не разворачиваю)

- `RetiredSuppressionOptions::refusalText:158` выбирает `--exclude` или `exclude`
  по префиксу `--` набранного — то есть адресует поверхность (флаг vs ключ YAML),
  а не написание.
- `ChannelLevelRefusalWording.php:138–141` в отказе про порог советует
  `--rule-opt …`, то есть отвечает флагом на то, что могло быть написано в YAML.
- N7 и N8 (`--format-opt`, baseline) и `ComputedMetricOverrideReader` вообще не
  отвечают на неизвестный ключ — это «нет сообщения», а не «не то написание».

## 6. Числа

Единица — `путь:строка` (см. §7.1). Строки A10 и A12 держат по две таких единицы,
поэтому строк в таблице 12, а мест — 14.

- Мест, применяющих нормализацию к пользовательскому ключу: **14**
  (12 строк таблицы 1.1; плюс 6 мест, складывающих словарь самого продукта, — вне
  счёта). Из них **10 стирают написание** (A1, A2, A7, A8, A9, A10 = 2 строки,
  A11, A12 = 2 строки) и **4 применяют фолд, чтобы написание восстановить**
  (A3–A6) — вторая группа и есть уже имеющийся прецедент лечения.
- Мест, её не применяющих: **10**.
- Мест, возвращающих автору не то написание, которое он набрал: **6**
  (5 печатают свёрнутое имя — L1–L5, 1 — каноничный литерал схемы, L6;
  семейство каноничных литералов внутри `fromArray()`-тел не перечислялось).

## 7. Развилки, решённые самостоятельно

1. **Единица счёта** — `путь:строка`, где `normalize()` (или `strtolower` в роли
   фолда имени) применяется к **пользовательскому** ключу, либо где переменная с
   именем ключа уходит в `sprintf`/исключение. Фолд объявленного словаря продукта
   вынесен в подстроку: он ничего не говорит автору.
2. **L5 переопределён после проверки достижимости.** Первая редакция ставила на это место бэкстоп `RetiredSuppressionOptions` из фабрики; проверка показала, что он обходит только верхний уровень и пользовательским входом недостижим, а p07 был свидетельством L2. Место освободил `validateNumericFields`, найденный после этого и подтверждённый p31/p32.
3. **Считать ли L6 (каноничный литерал) утечкой** — да, но отдельным классом:
   лечение у него другое (там вообще нет переменной с набранным именем).
4. **`--format-opt` и baseline** отнесены к «не применяет», хотя у них нет и
   проверки: вопрос M3 — про фолд написания, и ответ «фолда нет» здесь верен.
5. **Мёртвый фолд A10** оставлен в таблице «применяется» с явной пометкой, что
   вызывающих нет: план должен знать, что правка там ничего не изменит.
6. **Докблок `RuleOptionRefusalWording.php:22–28`** прямо объявляет текущее
   поведение намеренным («no authored spelling left to quote … `max_warnign`
   отвечается как `maxWarnign`»). Это не обход, это решение, которое лечение
   обязано пересмотреть явно, а не залатать мимо.
