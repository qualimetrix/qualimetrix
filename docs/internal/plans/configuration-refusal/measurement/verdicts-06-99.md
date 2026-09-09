# Пересъём вердиктов: `computed_metrics` (76–89), `architecture:` (90–99), позиция 6

Дерево: `1513bf67` (main, v0.26.0 + заход X13). Перечисление снято на `1b225c2e`.
Пробник собран свой, с нуля: `<PROBE>/composer.json` (psr-4 `RecNs\` → `src/`),
`src/Probe.php` (`RecNs\Probe::complex` CCN 15, `::simple` CCN 1), `src/Sub/Other.php`.
Второе дерево `<PROBE>/arch2` — копия пробника, в которой `Probe::complex`
инстанцирует `RecNs\Sub\Other` (нужна РЁБРА графа, без них у `allow`/`relations`/
шаблонных слоёв нет наблюдаемого эффекта — именно на этом застряло прошлое измерение).

Каждый прогон:
`bin/qmx -d <probe> check src --workers=0 --no-cache --no-progress --format=json --fail-on=none [...]`,
`1>runs/<id>.out 2>runs/<id>.err`, `ec=$?` снят сразу и без пайпа.
`n` — число элементов `violations` в JSON; `digest` — первые 10 hex md5 по отсортированному
кортежу `(channel, symbol, severity, threshold)`. YAML пишется через `printf '%b'`.

---

## 1. Контроли (пересняты первыми, до любой позиции)

| id      | конфигурация                                                                                       | n   | digest       | exit |
| ------- | -------------------------------------------------------------------------------------------------- | --- | ------------ | ---- |
| BASE    | `paths:[src]` + `rules.complexity.ccn.callable: {warning:1,error:2}`                               | 6   | `c4748d1b39` | 0    |
| DEFAULT | `paths:[src]` — правило на дефолтах                                                                | 4   | `ad04830bd4` | 0    |
| HALF    | выжил только `error: 2`                                                                            | 4   | `42c529f7ae` | 0    |
| NOCCN   | `complexity.ccn.enabled: false`                                                                    | 3   | `1aa404dfd7` | 0    |
| CM      | `computed_metrics.computed.myscore {formula:"1 + 1", warning:.5, error:3}`, `--only-rule=computed` | 3   | `22d82d1852` | 0    |
| EMPTY   | тот же CM, но `warnign` вместо `warning`, `--only-rule=computed`                                   | 0   | `d41d8cd98f` | 0    |

Второе дерево (`arch2`), свои локальные контроли:

| id        | конфигурация                              | n   | digest       | exit |
| --------- | ----------------------------------------- | --- | ------------ | ---- |
| A2BASE    | `paths:[src]`, без `architecture:`        | 4   | `03f7aebecb` | 0    |
| A2NOALLOW | два слоя `top`/`sub`, `allow` не объявлен | 5   | `c2ecbe16bb` | 0    |
| A2ALLOW   | те же слои + `allow: {top: [sub]}`        | 4   | `03f7aebecb` | 0    |

**Утверждение: контроли воспроизвелись.** Абсолютные значения `n` и дайджесты отличаются от
таблицы `1b225c2e` (BASE 6 против 7, DEFAULT 4 против 5) — это ожидаемо: пробник собран заново
и это ДРУГОЙ пробник, а не тот же. Существенное свойство выполнено: **все шесть контролей
попарно различимы** (6/4/4/3/3/0 при трёх разных дайджестах у трёх четвёрок), и различимы
локальные контроли второго дерева (5 против 4 с разными дайджестами). Без этого ни одна позиция
ниже не была бы доказательной.

Отдельный дискриминатор для секции G, использованный дальше повсеместно:
`CM` (n=3, дайджест `22d82d1852`) против `EMPTY` (n=0) — верный ключ порога наблюдаемо
производит находки, опечатанный — наблюдаемо нет.

---

## 2. КРАХ #88 — `levels:` картой вместо списка

Вход (`P88`):

```yaml
paths: [src]
computed_metrics:
  computed.x:
    formula: "1+1"
    levels:
      class:
        warning: 1
```

**Наблюдение (точно):**

* exit **1**;
* **stdout пуст — 0 байт**. Отчёта нет вообще;
* **stderr**, 163 байта, ровно одна строка:
  `Unexpected error: Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricOverrideReader::mapLevel(): Argument #1 ($level) must be of type string, array given`

**Вердикт: крах ПОДТВЕРЖДЁН на `1513bf67`, изменений против таблицы нет.** Заход X13 сюда не
достал: он тронул секцию `rules:`, а это `computed_metrics:`.

**Дискриминаторы (три, все сняты):**

| id   | вход                                                 | результат                                                                                        |
| ---- | ---------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| C88a | `levels: [methd]` (список, слово неверно)            | exit **3**, stderr `Configuration error: Invalid computed metric level: "methd"` — чистый refuse |
| C88b | `levels: [class]` (список, слово верно)              | exit **0**, n=4 — ключ работает                                                                  |
| C88c | `levels: {class: methd}` (карта, значения — скаляры) | exit **3**, тот же текст про `"methd"`                                                           |

C88c важен: карта САМА ПО СЕБЕ не отвергается. Ключи карты (`class`) молча выбрасываются, а её
ЗНАЧЕНИЯ читаются как названия уровней. То есть краха нет ровно до тех пор, пока значения карты
скалярные; вложенный блок — и падение.

**Где теряется проверка (по чтению кода):**

`src/Analysis/Evidence/ComputedMetrics/ComputedMetricOverrideReader.php:134`

```php
if (!isset($config['levels']) || !\is_array($config['levels'])) {
    return $defaults;
}
return array_values(array_map(self::mapLevel(...), $config['levels']));   // :138
```

`is_array()` на строке 134 истинно и для списка, и для карты — форма значения не различается.
На строке **138** `array_map` идёт по ЗНАЧЕНИЯМ, а сигнатура `mapLevel(string $level)`
(строка **193**) объявлена строгой (`declare(strict_types=1)`), поэтому массив-значение
превращается в `TypeError` вместо `ComputedMetricConfigurationException`. Единственный тип
ошибки, который этот путь умеет маршрутизировать в exit 3, — `ComputedMetricConfigurationException`;
`TypeError` уходит в общий обработчик «Unexpected error» с exit 1.

**Точка потери: `ComputedMetricOverrideReader.php:134` (принимает карту) → `:138` (итерирует
значения без проверки их формы) → `:193` (строгая сигнатура ломается вместо отказа).**

---

## 3. МОЛЧАНИЕ `computed_metrics.health.complexity`

| id      | вход                                          | exit | stdout     | stderr   | `health.complexity.threshold` в отчёте        |
| ------- | --------------------------------------------- | ---- | ---------- | -------- | --------------------------------------------- |
| DEFAULT | секции нет                                    | 0    | отчёт есть | пуст     | `{"warning":50,"error":25}`                   |
| HC_typo | `health.complexity: {warnign: 60, erorr: 30}` | 0    | отчёт есть | **пуст** | `{"warning":50,"error":25}` — дефолт          |
| HC_ok   | `health.complexity: {warning: 60, error: 30}` | 0    | отчёт есть | пуст     | `{"warning":60,"error":30}` — **применилось** |

**Вердикт: молчание ПОДТВЕРЖДЕНО.** exit 0, поток stderr пуст (0 байт), отчёт печатается
полностью и выглядит нормальным, `n=4`, дайджест `ad04830bd4` — БАЙТ В БАЙТ то же, что и
DEFAULT (16269 байт stdout в обоих). Пользователь не получает ни отказа, ни предупреждения, ни
отличия в отчёте.

**Дискриминатор есть и он строгий:** `HC_ok` с ВЕРНЫМИ ключами наблюдаемо меняет отчёт
(50/25 → 60/30). Значит «молчит» — это про ключ, а не про то, что пороги health вообще
ненаблюдаемы на моём пробнике.

**Где теряется проверка (по чтению кода):**

`src/Analysis/Evidence/ComputedMetrics/ComputedMetricOverrideReader.php:229–255`,
метод `thresholds()`:

```php
$hasThreshold = \array_key_exists('threshold', $config);   // :231
$hasWarning   = \array_key_exists('warning',   $config);   // :232
$hasError     = \array_key_exists('error',     $config);   // :233
```

Читатель СПРАШИВАЕТ «есть ли ключ X», и никогда не спрашивает «какие ключи здесь есть».
`array_diff(array_keys($config), <объявленный набор>)` в этом классе отсутствует целиком:
ни `merge()` (`:51`), ни `create()` (`:74`) не перебирают `$config`. Вызывающая сторона —
`ComputedMetricsConfigResolver.php:79` (`merge`) и `:88` (`create`) — тоже не проверяет набор
ключей записи: в резолвере единственное упоминание перечня допустимого (`:140`) относится к
именам health-измерений, а не к ключам внутри записи.

**Точка потери: `ComputedMetricOverrideReader.php:229` (`thresholds()` читает по имени, а не
перебирает) — и, шире, отсутствие проверки набора ключей в `ComputedMetricOverrideReader::merge()`
(`:51`) / `::create()` (`:74`), которую не компенсирует `ComputedMetricsConfigResolver.php:79,88`.**

То же самое место объясняет #83, #84, #85, #86 — но НЕ #89.
Для #89 (`computed.x: 5`) точка другая и до `thresholds()` дело не доходит:
`src/Analysis/Evidence/ComputedMetrics/ComputedMetricsConfigResolver.php:53–55` —
`foreach ($rawConfig as $name => $overrides) { if (!\is_array($overrides)) { continue; } }`:
запись-не-массив молча пропускается ещё до чтения, поэтому `create()` со строгой
сигнатурой `array $config` не вызывается и `TypeError` (как в #88) не возникает. Для #87 точка отдельная:
`ComputedMetricOverrideReader.php:117–119` — `foreach ($config['formulas'] as $levelKey => $formula)`
кладёт любой `$levelKey` в карту формул без сверки со словарём уровней.

---

## 4. Позиции

Столбец «изм.»: **=** совпало с таблицей `1b225c2e`, **≠** разошлось, **NEW** позиция ранее не
была измерена. Все прогоны из `<PROBE>/runs/` (секция G и 90–94) и `<PROBE>/arch2/runs/`
(95–99), id указан.

### Позиция 6 (секция A)

| #   | вход                       | id  | вердикт на `1513bf67`                                                                            | изм.  | точная строка диагностики (stderr)             |
| --- | -------------------------- | --- | ------------------------------------------------------------------------------------------------ | ----- | ---------------------------------------------- |
| 6   | `computed_metrics: [a, b]` | P06 | refuse **3**, stderr, **без префикса** `Configuration error:` и без `Invalid configuration in …` | **=** | `computed_metrics must be an associative map.` |

Замечание: отсутствие префикса подтверждено буквально — строка начинается сразу со слова
`computed_metrics`. Тот же бесперфиксный вид у #76.

### Секция G — `computed_metrics` (76–89)

| #   | вход                                           | id      | вердикт на `1513bf67`                                                        | изм. | точная строка диагностики (stderr, кроме молчаний)                                                                                                                                                                                                                    |
| --- | ---------------------------------------------- | ------- | ---------------------------------------------------------------------------- | ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 76  | `my.score: {formula: "1 + 1"}`                 | P76     | refuse **3**                                                                 | =    | `Computed metric name "my.score" must be "health.<name>" or "computed.<name>", where every segment is lower-case kebab (/^(?:health\|computed)(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)+$/) and the last segment is not the name of an aggregation strategy` (без префикса) |
| 77  | `health.mine: {formula: "1"}`                  | P77     | refuse **3**                                                                 | =    | `Configuration error: Computed metric name "health.mine" uses reserved "health.*" prefix. Use "computed.*" prefix for user-defined metrics.`                                                                                                                          |
| 78  | `computed.class: {formula: "1"}`               | P78     | refuse **3**                                                                 | =    | `Configuration error: Computed metric name "computed.class" must not end in the level word "class". A level is addressed beside the channel name, with ":class", not inside the name.`                                                                                |
| 79  | `health.typng: {enabled: false}`               | P79     | refuse **3** + список валидных                                               | =    | `Unknown health dimension "health.typng" disabled via "computed_metrics.health.typng.enabled: false". Valid dimensions: health.complexity, health.cohesion, health.coupling, health.typing, health.maintainability.` (без префикса)                                   |
| 80  | `formula: "m['cplng']"`                        | P80     | refuse **3**                                                                 | =    | `Configuration error: Computed metric "computed.z" references unknown metric key "cplng" in formula: m['cplng']`                                                                                                                                                      |
| 81  | `levels: [methd]`                              | P81     | refuse **3**                                                                 | =    | `Configuration error: Invalid computed metric level: "methd"`                                                                                                                                                                                                         |
| 82  | `{formulaa: "1 + 1"}`                          | P82     | refuse **3**, но по ПОСЛЕДСТВИЮ; опечатка не названа                         | =    | `Configuration error: Computed metric "computed.z" has no formula for level "namespace"`                                                                                                                                                                              |
| 83  | `{formula, warning, error, frobnicate: 7}`     | P83     | **silent**, exit 0; n=3, digest `22d82d1852` = **CM**                        | =    | stderr пуст (0 байт)                                                                                                                                                                                                                                                  |
| 84  | `{formula, warnign: .5, error: 3}`             | P84     | **silent**, exit 0; n=**0**, digest = **EMPTY** — метрика перестаёт сообщать | =    | stderr пуст (0 байт)                                                                                                                                                                                                                                                  |
| 85  | `{formula, thresholds: {warning:.5, error:3}}` | P85     | **silent**, exit 0; n=**0**, digest = **EMPTY** — блок отброшен              | =    | stderr пуст (0 байт)                                                                                                                                                                                                                                                  |
| 86a | `{…, class: {warning: 9}}`                     | P86a    | **silent**, n=3, digest = **CM**                                             | =    | stderr пуст                                                                                                                                                                                                                                                           |
| 86b | `{…, clas: {warning: 9}}`                      | P86b    | **silent**, n=3, digest = **CM**                                             | =    | stderr пуст                                                                                                                                                                                                                                                           |
| 86c | `{…, foo: {bar: {warning: 9}}}`                | P86c    | **silent**, n=3, digest = **CM**                                             | =    | stderr пуст                                                                                                                                                                                                                                                           |
| 87  | `health.complexity: {formulas: {clas: "1"}}`   | P87typo | **silent**, exit 0; отчёт БАЙТ В БАЙТ равен DEFAULT (score 78.495…)          | =    | stderr пуст                                                                                                                                                                                                                                                           |
| 88  | `{formula, levels: {class: {warning: 1}}}`     | P88     | **crash: exit 1, stdout 0 байт, отчёта нет**                                 | =    | `Unexpected error: Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricOverrideReader::mapLevel(): Argument #1 ($level) must be of type string, array given`                                                                                                  |
| 89  | `computed_metrics: {computed.x: 5}`            | P89     | **silent**, exit 0; n=4, digest = **DEFAULT**                                | =    | stderr пуст                                                                                                                                                                                                                                                           |

Дискриминатор #87: `P87ok` с верными ключами `formulas: {class,namespace,project: "1"}` даёт
`health.complexity.score = 1` вместо 78.495 и n=9 вместо 4. Механизм наблюдаем — молчит именно ключ.

**Изменений против таблицы `1b225c2e` в секции G нет: 14 из 14 позиций совпали.**

### Секция H — `architecture:` (90–99)

| #   | вход                                                   | id   | вердикт на `1513bf67`                                       | изм.                         | точная строка диагностики (stderr)                                                                                                                                                                                                                                                                               |
| --- | ------------------------------------------------------ | ---- | ----------------------------------------------------------- | ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 90  | `architecture: {ruleset: {a: b}}`                      | P90  | refuse **3** + Allowed keys                                 | =                            | `Configuration error: architecture: unknown key "ruleset". Allowed keys: "layers", "allow", "coverage-gap", "max_expanded_layers".`                                                                                                                                                                              |
| 91  | `architecture: {coverage_gap: warn}`                   | P91  | refuse **3** «unknown key» — секция НЕ складывает написания | =                            | `Configuration error: architecture: unknown key "coverage_gap". Allowed keys: "layers", "allow", "coverage-gap", "max_expanded_layers".`                                                                                                                                                                         |
| 92  | `coverage-gap: warning`                                | P92b | ключ узнан; refuse **3** по ЗНАЧЕНИЮ                        | =                            | `Configuration error: architecture.coverage-gap: must be one of 'ignore', 'warn', 'error' (got 'warning').`                                                                                                                                                                                                      |
| 93  | `layers[0]: {name: top, patterns: […], patternz: […]}` | P93b | refuse **3** + Allowed keys                                 | =                            | `Configuration error: architecture.layers[0]: unknown key(s) "patternz". Allowed keys: "name", "patterns", "suffix", "attributes", "implements", "extends", "match", "exclude", "pending".`                                                                                                                      |
| 94  | `layers[0].exclude: {patterms: […]}`                   | P94  | refuse **3** + Allowed keys                                 | **NEW** (было «не измерено») | `Configuration error: architecture.layers[0] ("top"): unknown key(s) "patterms" inside "exclude". Allowed keys: "patterns", "suffix", "attributes", "implements", "extends", "match".`                                                                                                                           |
| 95  | `allow: {top: [{targt: sub}]}`                         | P95  | refuse **3** + Allowed keys                                 | **NEW**                      | `Configuration error: architecture.allow.top[0]: unknown long-form key 'targt'. Allowed keys: 'target', 'relations', 'allow_cross_instance'.`                                                                                                                                                                    |
| 96  | `allow: {tpo: [sub]}`                                  | P96  | refuse **3** «unknown layer»                                | =                            | `Configuration error: architecture.allow.tpo: unknown layer.`                                                                                                                                                                                                                                                    |
| 97  | `allow: {top: [subb]}`                                 | P97  | refuse **3** «unknown layer 'subb'»                         | =                            | `Configuration error: architecture.allow.top[0]: unknown layer 'subb'.`                                                                                                                                                                                                                                          |
| 98  | `allow: {top: [{target: sub, relations: [extendz]}]}`  | P98  | refuse **3** + перечень известных видов                     | **NEW**                      | `Configuration error: architecture.allow.top[0].relations: unknown relation kind 'extendz'. Known direct values: 'attribute', 'catch', 'class_const_fetch', 'extends', 'implements', 'instanceof', 'intersection_type', 'new', 'property_type', 'static_call', 'static_property_fetch', 'trait_use', 'type_hi…'` |
| 99  | шаблонный слой `name: mod-{n}` при `patterns: […{m}…]` | P99  | refuse **3**, переменная названа поимённо                   | **NEW**                      | `Configuration error: architecture.layers[1] ("mod-{n}"): TemplateLayerDefinition: variable(s) "n" referenced in name template "mod-{n}" but not bound by any capture-producing pattern. Add a pattern containing "{n}" or remove the variable from the name template.`                                          |

**Дискриминаторы, которых у прошлого измерения не было (это и есть главный прирост):**

| позиция | контроль «ключ работает»                                         | id                  | наблюдаемое отличие                                                                                                         |
| ------- | ---------------------------------------------------------------- | ------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| 94      | `exclude: {patterns: ["RecNs\Sub\*"]}` при `coverage-gap: error` | P94ctl1 vs P94ctl0  | без exclude n=4 exit 0; с верным `patterns` n=**5** exit 2 (появилась дыра покрытия). Ключ наблюдаемо снимает класс со слоя |
| 95      | `allow: {top: [{target: sub}]}`                                  | P95ctl vs A2NOALLOW | без allow n=5 (`architecture.layer-violation`); с верным `target` n=**4**, нарушение снято                                  |
| 98      | `relations: [extends]` (реальное отношение — `new`)              | P98ctl2 vs P95ctl   | `relations: [extends]` сужает разрешение до `extends`, нарушение по `new` возвращается: n=**5** против n=4                  |
| 99      | `name: mod-{m}` + `patterns: ["RecNs\{m}\**"]`                   | P99ctl              | шаблон РАЗВЕРНУЛСЯ: в сообщении нарушения фигурирует конкретный слой `mod-Sub`, n=5                                         |

Чем это удалось: копией пробника (`arch2`), в которой `Probe::complex` инстанцирует
`Sub\Other` — появляется ребро графа, без которого `allow`/`relations`/шаблоны ненаблюдаемы,
плюс `coverage-gap: error`, который делает наблюдаемым `exclude` без всяких рёбер.
Именно отсутствие этих двух приёмов, а не свойство продукта, оставило 94/95/98/99 неизмеренными
в прошлый раз.

**Изменений против таблицы в секции H нет: 6 совпало, 4 подтвердили заявленный кодом вердикт
измерением (были «не измерены»).**

---

## 5. Побочные наблюдения (вне набора, не чинил, не проверял вглубь)

1. **`namespaces` не является ключом записи `layers[]`** на этом дереве. Допустимые:
   `name, patterns, suffix, attributes, implements, extends, match, exclude, pending`.
   Воспроизводящий вход #93 в таблице (`- {name: sub, namespaces: …}`) отказывает по существу
   верно, но по причине, отличной от заявленной: `namespaces` — не «лишний ключ рядом с верными»,
   а несуществующий ключ; в моём P92 он же перехватил отказ раньше, чем проверялось значение
   `coverage-gap`. Я снял #93 заново на `patterns:` + `patternz:` (id `P93b`).
   **Проверено, что это не смена словаря между деревьями:**
   `git show 1b225c2e:src/Analysis/Policy/Architecture/Configuration/LayersValidator.php`
   даёт `ALLOWED_ENTRY_KEYS` (строки 50–60) БАЙТ В БАЙТ тот же список, что и на `1513bf67`.
   `namespaces` не был допустимым ключом и на `1b225c2e` — то есть #93 остаётся `=`,
   а неточен был воспроизводящий вход в таблице, а не вердикт.
2. **Словарь `relations:` и текст сообщения о нарушении расходятся.** Нарушение печатается как
   `(RecNs\Probe → RecNs\Sub\Other, instantiates)`, но слово `instantiates` в `relations:`
   отвергается (id `P98ctl`): канонично `new`. Пользователь, скопировавший слово из отчёта в
   конфигурацию, получит refuse 3. Это форма механизма M13 («сообщение адресует не то»), в
   перечислении по этому месту записи нет.
3. **`--fail-on=none` не удерживает exit при диагностиках конфигурации.** `coverage-gap: error`
   с реальной дырой даёт **exit 2** (id `P92c`, `P94ctl1`) при `--fail-on=none`. По
   `website/docs/rules/architecture.md` это заявлено намеренно («fails the run unconditionally»);
   для протокола важно другое: в секции H exit-код сам по себе не различает «ключ отвергнут» (3)
   и «политика сработала» (2) без взгляда на stdout.
4. **`levels:` картой со скалярными значениями** (id `C88c`) — ключи карты молча выбрасываются,
   значения читаются как уровни. Отдельного номера в перечислении нет; это подслучай #88,
   который НЕ падает.

---

## 6. Допущения и неизмеренное

Поимённо:

1. **`architecture.max_expanded_layers` — значения.** Не в моём наборе позиций, не мерил.
2. **Поведение при `--workers` > 0.** Все прогоны `--workers=0` по протоколу. Куда уходит
   диагностика в воркере — не измерено, вопрос остаётся открытым, как и у прошлого измерения.
3. **Порядок узнавания при нескольких дефектах сразу в `computed_metrics`/`architecture`.**
   Не мерил: одна проверка P93 случайно показала, что `LayersValidator` собирает НЕСКОЛЬКО
   неизвестных ключей одной записи в одно сообщение (`"namespaces", "namespacess"`), но
   систематически по обеим секциям это не проверялось.
4. **Разные источники (пресет + файл + CLI) с одним неизвестным ключом секции G/H.**
   Не мерил: вне набора.
5. **Допущение о сравнимости с таблицей `1b225c2e`.** Мой пробник — не тот же файл, что у автора
   перечисления, поэтому абсолютные `n`/дайджесты сравнивать с его таблицей нельзя и я этого не
   делаю. Сравниваю только КАЧЕСТВЕННЫЙ вердикт (refuse-код / silent / crash) и текст диагностики.
6. **Причина краха названа по чтению кода, а не по трассировке.** Стека нет: обработчик печатает
   одну строку. Утверждение «строгая сигнатура `mapLevel` ломается вместо отказа» — вывод из
   чтения `:134`, `:138`, `:193` плюс поведение контроля C88c; уверенность высокая, но это
   всё же вывод, а не наблюдение стека.
7. **X13 к моему набору не прикасался — подтверждено измерением, а не чтением диффа.** Все
   14 позиций G и все 6 ранее измеренных позиций H дали тот же качественный вердикт.

---

## 7. Команды для перепроверки

```bash
WT=<путь к рабочему дереву>
PROBE=/private/tmp/claude-501/-Users-fractalizer-PhpstormProjects-github-com-qualimetrix-qualimetrix--claude-worktrees-x12-orchestrator-k0m-8334bb/8e852382-69d5-4894-a87d-58ec05939bf4/scratchpad/m/B

# 1. Крах #88 (exit 1, stdout пуст, stderr одна строка)
"$PROBE/run.sh" P88 'paths: [src]\ncomputed_metrics:\n  computed.x:\n    formula: "1+1"\n    levels:\n      class:\n        warning: 1\n'
wc -c "$PROBE/runs/P88.out"; cat "$PROBE/runs/P88.err"

# 2. Контроль к краху: список вместо карты -> чистый refuse 3
"$PROBE/run.sh" C88a 'paths: [src]\ncomputed_metrics:\n  computed.x:\n    formula: "1+1"\n    levels: [methd]\n'

# 3. Молчание health.complexity + дискриминатор (50/25 против 60/30)
"$PROBE/run.sh" HC_typo 'paths: [src]\ncomputed_metrics:\n  health.complexity:\n    warnign: 60\n    erorr: 30\n'
"$PROBE/run.sh" HC_ok   'paths: [src]\ncomputed_metrics:\n  health.complexity:\n    warning: 60\n    error: 30\n'
for id in DEFAULT HC_typo HC_ok; do php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $argv[1],": ",json_encode($j["health"]["complexity"]["threshold"]),"\n";' "$PROBE/runs/$id.out"; done

# 4. #94 с дискриминатором: без exclude / с верным patterns / с опечаткой
"$PROBE/run.sh" P94ctl0 'paths: [src]\narchitecture:\n  coverage-gap: error\n  layers:\n    - name: top\n      patterns: ["RecNs\\\\*"]\n'
"$PROBE/run.sh" P94ctl1 'paths: [src]\narchitecture:\n  coverage-gap: error\n  layers:\n    - name: top\n      patterns: ["RecNs\\\\*"]\n      exclude:\n        patterns: ["RecNs\\\\Sub\\\\*"]\n'
"$PROBE/run.sh" P94     'paths: [src]\narchitecture:\n  coverage-gap: error\n  layers:\n    - name: top\n      patterns: ["RecNs\\\\*"]\n      exclude:\n        patterms: ["RecNs\\\\Sub\\\\*"]\n'

# 5. #95/#98 на дереве с ребром (arch2): контроль -> нарушение снято, опечатка -> refuse 3
"$PROBE/run2.sh" P95ctl "paths: [src]\narchitecture:\n  layers:\n    - name: top\n      patterns: [\"RecNs\\\\\\\\Probe\"]\n    - name: sub\n      patterns: [\"RecNs\\\\\\\\Sub\\\\\\\\*\"]\n  allow:\n    top:\n      - target: sub\n"
"$PROBE/run2.sh" P95    "paths: [src]\narchitecture:\n  layers:\n    - name: top\n      patterns: [\"RecNs\\\\\\\\Probe\"]\n    - name: sub\n      patterns: [\"RecNs\\\\\\\\Sub\\\\\\\\*\"]\n  allow:\n    top:\n      - targt: sub\n"
```
