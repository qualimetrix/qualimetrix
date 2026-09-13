# M1 — Швы слияния конфигурации: измерение

**6 из 6 вопросов закрыты наблюдением; 0 — только чтением кода.**

Каждый ответ опирается на живой прогон продукта с трассировкой швов; чтение кода
использовано только чтобы назвать `файл:строка` механизма, уже увиденного в трассе.

Репозиторий на сдаче чист: `git status --short` — пустой вывод, HEAD `c755fe00`.
Ни одной правки в `src/`, `tests/`, `docs/`, `website/`, `finding-gate/`.

---

## Метод (общий для всех шести)

**Развилка, решённая мной:** инструментировать не продукт, а его копию.
Копия дерева (`src bin vendor composer.json composer.lock`, без `node_modules`)
лежит в `copy/`. PSR-4-карта копии использует `$baseDir`, поэтому резолвится
внутрь копии — проверено позитивным контролем (памятка «клон с симлинком на
vendor даёт ложно-зелёное» здесь не срабатывает):

```
php -r 'require "<W>/copy/vendor/autoload.php"; echo (new ReflectionClass(
  Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionThresholdShorthand::class))->getFileName();'
# → <W>/copy/src/Analysis/Finding/RuleConfiguration/RuleOptionThresholdShorthand.php
```

Трассировка (`copy/trace.php`, подключается из `copy/bin/qmx`) пишет JSONL в
`$QMX_TRACE`. Точки: `unfold()` вход/выход + вызывающий кадр,
`FindingConfigurationResolver::mergeRuleOptions()`, `resolve()` (число
contributions), `RuleOptionsFactory` (слои / результат merge / что уходит в
`fromArray`), `RuleOptionKeyRecognition::refuseUnknownKeys()` (какие ключи видит),
и `fromArray()` всех пяти классов.

**Фикстура** — `fixture/src`, 30 файлов, `Fixture\` PSR-4 (`fixture/composer.json`
нужен, иначе coupling считается неточно). Величины из контрольного прогона без
конфигурации (`runs/base.json`):

| символ                                     | величина                                                         |
| ------------------------------------------ | ---------------------------------------------------------------- |
| `Fixture\Monster::big`                     | CCN 35, cognitive 34, NPath > 1M                                 |
| `Fixture\Monster` (класс)                  | max-method CCN 35 → **находка класс-уровня на дефолтах** (30/50) |
| `Fixture\Tangled::m0..m11`, `Mild::n0..n2` | CCN 6 (между 3 и 10)                                             |
| `Fixture\HighCbo`                          | CBO 25; `Fixture\LowCbo` — CBO 3 (между 1 и 5)                   |
| `Fixture\Dep0..Dep24`                      | афферентный CBO 1–2                                              |

Класс-уровень complexity считает **максимум по методам**, не сумму — поэтому
`Monster` заведён отдельно: без него «класс выключен» и «класс на дефолте» дают
одинаково пустой результат, и дискриминатора бы не было.

**Повторяемый запуск.** `run.sh <имя> <config.yaml> [доп. аргументы]` — удаляет
кэш и трассу, идёт из `fixture/` (репозиторный `qmx.yaml` подхватить неоткуда),
запускает
`QMX_TRACE=runs/<имя>.trace php copy/bin/qmx check fixture/src --config=<doc> --format=json --workers=0 --cache-dir=runs/<имя>.cache`.
Находки читаются из `violations`.

---

## Q1. Доходит ли одиночный слой до шва

**Да — до шва в `RuleOptionsFactory`, и только на пути `''`. До шва в
`FindingConfigurationResolver` одиночный слой не доходит вовсе.**

1. **Фабричный шов исполняется всегда.** `RuleOptionsFactory::create()` зовёт
   `deepMerge($file, $cli, …)` безусловно, и `deepMerge` зовёт `unfold()` на
   обоих слоях, даже когда CLI-слой пуст. В прогоне `doc_a` (только
   `--config`, без пресета и без `--rule-opt`) трасса даёт ровно две строки на
   правило: `in={"threshold":3,"class":{…}}` и `in=[]`, обе
   `from=RuleOptionsFactory::deepMerge`, обе `path=''`.
   Код: `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php:75`
   (вызов `deepMerge`), `:356-357` (два `unfold`).
2. **Резолверный шов при одном слое не исполняется.** `resolve.contributions
   count=1` → `mergeRules()` кладёт `$base[$name] = $value` без рекурсии, и
   `mergeRuleOptions()` (единственный носитель `unfold` в резолвере) не
   вызывается ни разу. Подтверждено отсутствием строк
   `resolver.mergeRuleOptions` во всех одно-слойных прогонах и их появлением
   в `q6_R1`/`q6_R2` (два contributions).
   Код: `src/Analysis/Finding/Configuration/FindingConfigurationResolver.php:48-62`.
3. **Вложенные пути шов не посещает, если блок написан только в одном слое.**
   Перепись по ВСЕМ прогонам этой работы (23 прогона, финальное состояние
   `runs/`): `unfold()` вызван **3224 раза — 3220 на `path=''`
   (3212 из `RuleOptionsFactory::deepMerge`, 8 из
   `FindingConfigurationResolver::mergeRuleOptions`) и ровно 4 на `path='class'`,
   все четыре — из двух специально построенных прогонов `q1_nested` (2) и
   `q1_nested2` (2)**. Ни один из документов, воспроизводящих реальные формы
   написания, вложенный путь не открыл.
   Рекурсия в `deepMerge` управляется ключами `$override`
   (`RuleOptionsFactory.php:359-362`), поэтому вложенный уровень посещается
   только когда ОБА слоя написали один и тот же блок. Прогон `q1_nested`
   (файл `class: {max_warning: 5}` + CLI `class.max_error=9`) — единственная
   форма, где появляется `unfold path='class'`; прогон `q1_nested2`
   (`class: {threshold: 5}` + CLI `class.enabled=true`) показывает
   настоящий вложенный разворот `{"threshold":5} → {"maxWarning":5,"maxError":5}`.

Команда:
```
run.sh doc_a docs/doc_a.yaml
run.sh q1_nested   docs/doc_bothclass.yaml   --rule-opt='complexity.ccn:class.max_error=9'
run.sh q1_nested2  docs/doc_nestedshort.yaml --rule-opt='complexity.ccn:class.enabled=true'
cat runs/*.trace | python3 -c "import sys,json;from collections import Counter;c=Counter();[c.update([(json.loads(l)['path'],json.loads(l)['from'].split('\\\\')[-1])]) for l in sys.stdin if json.loads(l)['tag']=='unfold'];print(c)"
```

**Слепое пятно.** Перепись «3220 из 3224 на `path=''`» — свойство пары
«мои документы × мой прогон», а не продукта: она доказывает, что вложенный
разворот не случается на проверенных формах, но не что он невозможен —
`q1_nested`/`q1_nested2` его и показывают. Абсолютные числа зависят от состава
`runs/`: команду переписи имеет смысл перезапускать только на полном наборе. Трасса видит только вызовы в процессе
`--workers=0`; параллельный режим не измерялся.

---

## Q2. Порядок распознавания и разворота

**Распознавание исполняется ПОСЛЕ `unfold()` и видит уже развёрнутые ключи.**

В `RuleOptionsFactory::create()` порядок: `deepMerge` (шаг 2, `:75`) →
`refuseUnknownKeys` (шаг 5, `:126`). Трасса `doc_e6`:

```
unfold path='' in={"threshold":50,"class":{…}} -> out={"class":{…},"warning":50,"error":50}
recognition.refuseUnknownKeys sees: ["class","warning","error"]     ← "threshold" уже нет
```

То же для `coupling.instability` (`doc_inst`): распознавание видит
`["class","maxWarning","maxError"]`. Для трёх complexity-правил разворота нет
(`LONE_THRESHOLD_SHAPE`), распознавание видит `["threshold","class"]` — ровно то,
что написал автор.

**Назовёт ли отказ ключ, который автор писал.** Да, но не из-за порядка шагов:
разворот гейтится проверкой формы (условие 2,
`RuleOptionThresholdShorthand.php:136-140`). Наблюдение — `q2_badform`
(`coupling.cbo: {threshold: abc}`):

```
exit 3
Configuration error: Option "threshold" of rule "coupling.cbo" must be a whole number or null, got a string.
```

Команда: `run.sh doc_e6 docs/doc_e6.yaml`, `run.sh q2_badform docs/q2_badform.yaml`.

**Слепое пятно.** Проверена одна ветка «не та форма» — строка вместо числа.
Ветку «правильная форма, разворот произошёл, отказ потом по вложенному
содержимому» я не строил. Утверждение «отказ ВСЕГДА называет написанный ключ»
моим измерением не покрыто — покрыто «в проверенном случае назвал».

---

## Q3. Инвертированная полоса

**Сегодня продукт НИГДЕ её не отвергает. Оба документа приняты (exit 2, ни одного
отказа), и `getSeverity()` схлопывает полосу в сплошной `error`: ветка `warning`
становится недостижимой.**

| документ                                                   | exit | находки                                                                                                                               |
| ---------------------------------------------------------- | ---- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `coupling.cbo: {class: {warning: 5, error: 1}}`            | 2    | 27 находок, **все `error`**, 0 `warning`; среди них `Fixture\LowCbo` (CBO 3) и `Fixture\Dep10` (CBO 1) — НИЖЕ порога предупреждения 5 |
| `complexity.ccn: {class: {max_warning: 40, max_error: 5}}` | 2    | 3 класс-находки, **все `error`**: `Mild` (6), `Tangled` (6), `Monster` (35) — все ниже порога предупреждения 40                       |

Механизм: `ClassCboOptions::getSeverity()` сравнивает с `error` первым
(ветка `$cbo >= $this->error` стоит до `$cbo >= $this->warning`).
Ordering-валидации нет; два комментария в продукте заявляют это намеренным:
`src/Analysis/Evidence/Design/DataClass/DataClassOptions.php:91` («W below E is
not an ordering error») и
`src/Analysis/Finding/Contract/Rule/Override/IndependentAxisValidator.php:16`.

Команда: `run.sh q3_cbo docs/q3_cbo.yaml`, `run.sh q3_ccn docs/q3_ccn.yaml`.

**Слепое пятно.** Измерены два правила из пяти и только класс-уровень.
`callable`-уровень, плоские правила, путь `@qmx-threshold` и путь пресетов не
снимались; «нигде не отвергает» в этой части опирается на отсутствие
ordering-проверки в коде.

---

## Q4. В какой ФОРМЕ сокращение доходит до `fromArray()`

**Утверждение брифа подтверждено полностью, наблюдением.** Документ во всех пяти
случаях: `{threshold: <X>, class: {<градуированная пара>}}`.

| правило                | что видит `unfold`                                     | что уходит в `fromArray()`                          | разворот                                 |
| ---------------------- | ------------------------------------------------------ | --------------------------------------------------- | ---------------------------------------- |
| `complexity.ccn`       | `{threshold:3, class:{maxWarning:1,maxError:2}}`       | `{threshold:3, class:{maxWarning:1,maxError:2}}`    | **нет** (`LONE_THRESHOLD_SHAPE`)         |
| `complexity.cognitive` | `{threshold:4, class:{…}}`                             | `{threshold:4, class:{…}}`                          | **нет**                                  |
| `complexity.npath`     | `{threshold:60, class:{…}}`                            | `{threshold:60, class:{…}}`                         | **нет**                                  |
| `coupling.cbo`         | `{threshold:50, class:{warning:1,error:1}}`            | `{class:{warning:1,error:1}, warning:50, error:50}` | **да**, `BARE_PAIR` на пути `''`         |
| `coupling.instability` | `{threshold:0.3, class:{maxWarning:0.1,maxError:0.2}}` | `{class:{…}, maxWarning:0.3, maxError:0.3}`         | **да**, `MAX_PREFIXED_PAIR` на пути `''` |

Дополнительно снято:

- CLI-дверь отдаёт значение **уже типизированным**: `--rule-opt='coupling.cbo:threshold=7'`
  → `cliTypes={"threshold":"int"}`, и разворот на CLI-слое срабатывает
  (`{"threshold":7} → {"warning":7,"error":7}`).
- Когда пользователь не написал для правила ничего, `fromArray()` получает не
  `[]`, а конструкторские дефолты (ветка `RuleOptionsFactory.php:123`) — трасса
  показывает `{"callable":{…},"class":{…}}`. **То, что внутри лежат ОБЪЕКТЫ
  уровней, а не массивы, — вывод из чтения** (`extractDefaults()` берёт
  `getDefaultValue()` конструктора, а параметры типизированы
  `MethodComplexityOptions`/`ClassComplexityOptions`): `json_encode` массива
  массивов выглядел бы так же, тип в трассу я не писал. Следствие — `is_array()`
  в `fromArray` по ним ложен — тоже чтение, не наблюдение.

Команда: `run.sh doc_a|doc_cog|doc_np|doc_e6|doc_inst docs/<…>.yaml`, затем чтение
строк `unfold` / `factory.toFromArray` из соответствующей `.trace`.

**Слепое пятно.** Таблица снята на форме «сокращение + блок `class`». Формы
«сокращение + `namespace`» и «сокращение + `callable`» не мерились; реестр
объявляет для них те же группы, но это чтение, а не наблюдение.

---

## Q5. Что продукт делает сегодня — живые прогоны

Все `exit 2` (находки есть, отказов нет). «Класс-уровень» — символ без `::`.

| #        | документ                                                           | класс-находок                        | вывод                                                                 |
| -------- | ------------------------------------------------------------------ | ------------------------------------ | --------------------------------------------------------------------- |
| контроль | `complexity.ccn: {callable:{warning:3,error:4}}`                   | **1** (`Monster`, порог 30 — дефолт) | класс-уровень жив, когда сокращения нет                               |
| (a)      | `complexity.ccn: {threshold:3, class:{max_warning:1,max_error:2}}` | **0**                                | блок не прочитан И уровень выключен                                   |
| (b)      | `complexity.ccn: {threshold:3}` в одиночку                         | **0**                                | **сокращение ВЫКЛЮЧАЕТ класс-уровень, а не оставляет его на дефолте** |
| (c)      | `complexity.npath: {threshold:50, class:{}}`                       | 0                                    | exit 2, отказа нет; см. контроль ниже                                 |
| (d1)     | `complexity.ccn: {threshold:5, class: ~}`                          | 0                                    | exit 2, отказа нет; идентично (b)                                     |
| (d2)     | `complexity.ccn: {threshold:5, class:{enabled: ~}}`                | 0                                    | exit 2, отказа нет; идентично (b)                                     |
| (e/doc5) | `coupling.cbo: {class:{warning:1,error:1}}`                        | **27**                               | блок работает                                                         |
| (e/doc6) | `coupling.cbo: {threshold:50, class:{warning:1,error:1}}`          | **0**                                | сокращение 50 применено к ОБОИМ уровням, блок молча проигнорирован    |

Ключевой дискриминатор (b) закрыт: контроль даёт 1 класс-находку, (b) даёт 0 —
дело не в «дефолт не сработал», а в `class: enabled false` внутри ветки
сокращения (`ComplexityOptions.php:56`).

(c) сам по себе ничего не различает: класс-уровень npath на дефолтах даёт 0
находок (дефолт класса npath — `enabled: false`, видно в трассе). Мой контроль:

| контроль к (c)                                                                     | класс-находок |
| ---------------------------------------------------------------------------------- | ------------- |
| `complexity.npath: {class:{enabled:true,max_warning:2,max_error:3}}`               | **3**         |
| `complexity.npath: {threshold:50, class:{enabled:true,max_warning:2,max_error:3}}` | **0**         |

(d): ни `class: ~`, ни `class: {enabled: ~}` ничего не меняют и не вызывают
отказа — трасса показывает, что `null` доезжает до `fromArray()` как есть
(`{"threshold":5,"class":null}`), а ветка сокращения до него не добирается.

Команда: `run.sh <имя> docs/<имя>.yaml`, затем `python3 summarize.py <имя> <префикс правила>`.
Имена: `doc_bctl doc_a doc_b doc_c doc_cctl doc_cctl2 doc_d1 doc_d2 doc_e5 doc_e6`.

**Слепое пятно.** Различение «уровень выключен» и «уровень включён, но порог
недостижим» делается по ОТСУТСТВИЮ находок — по половине «промах». Прямого
наблюдения `isLevelEnabled()==false` нет: это читается из кода. Контроль
`doc_bctl` закрывает подмену «дефолт не срабатывает вообще», но не подменяет
наблюдение самого флага.

---

## Q6. Обе ориентации слоёв

Флаг кусается: `--rule-opt='complexity.npath:threshold=abc'` → **exit 3**,
`Option "threshold" of rule "complexity.npath" must be a whole number or null,
got a string.` Позитивный контроль `q6_Bctl`: CLI-блок сам по себе даёт 3
класс-находки.

Измерены **четыре** ориентации на `complexity.npath` — по две на каждом шве:

| прогон    | нижний слой                            | верхний слой            | шов         | класс-находок | применившиеся пороги              |
| --------- | -------------------------------------- | ----------------------- | ----------- | ------------- | --------------------------------- |
| `q6_Actl` | пресет-файл: блок `class{enabled,2,3}` | —                       | —           | **3**         | callable 1000 (дефолт), class 2/3 |
| `q6_A`    | пресет-файл: блок                      | CLI: `threshold=50`     | фабричный   | **0**         | callable 50/50, class выключен    |
| `q6_B`    | конфиг: `threshold: 50`                | CLI: блок `class.*`     | фабричный   | **0**         | callable 50/50, class выключен    |
| `q6_R1`   | пресет: `threshold: 50`                | конфиг: блок            | резолверный | **0**         | callable 50/50, class выключен    |
| `q6_R2`   | пресет: блок                           | конфиг: `threshold: 50` | резолверный | **0**         | callable 50/50, class выключен    |

Результат симметричен: **в обеих ориентациях и на обоих швах сокращение
побеждает, а блок уровня молча теряется.** Трасса объясняет почему: на этих
правилах `unfold` — тождественное отображение (`LONE_THRESHOLD_SHAPE`), оба слоя
доезжают до `fromArray()` целиком (`{"class":{…},"threshold":50}` или
`{"threshold":50,"class":{…}}`), и ветка сокращения срабатывает независимо от
порядка ключей.

Команда:
```
run.sh q6_bite  docs/empty.yaml --rule-opt='complexity.npath:threshold=abc'
run.sh q6_Actl  docs/empty.yaml --preset=docs/preset_block.yaml
run.sh q6_A     docs/empty.yaml --preset=docs/preset_block.yaml --rule-opt='complexity.npath:threshold=50'
run.sh q6_B     docs/preset_short.yaml --rule-opt='complexity.npath:class.enabled=true' \
                --rule-opt='complexity.npath:class.max_warning=2' --rule-opt='complexity.npath:class.max_error=3'
run.sh q6_R1    docs/preset_block.yaml --preset=docs/preset_short.yaml
run.sh q6_R2    docs/preset_short.yaml --preset=docs/preset_block.yaml
```

### Те же четыре ориентации на `coupling.cbo` (правило, где разворот ЕСТЬ)

Контроль: `coupling.cbo: {class:{warning:1,error:1}}` в одиночку — **27 находок**
(прогон `doc_e5`).

| прогон      | нижний слой               | верхний слой                    | шов         | находок | что ушло в `fromArray()`                |
| ----------- | ------------------------- | ------------------------------- | ----------- | ------- | --------------------------------------- |
| `q6_cbo_A`  | конфиг: блок `class{1,1}` | CLI: `threshold=50`             | фабричный   | **0**   | `{"class":{…},"warning":50,"error":50}` |
| `q6_cbo_B`  | конфиг: `threshold: 50`   | CLI: блок `class.warning/error` | фабричный   | **0**   | `{"warning":50,"error":50,"class":{…}}` |
| `q6_cbo_R1` | пресет: `threshold: 50`   | конфиг: блок                    | резолверный | **0**   | `{"warning":50,"error":50,"class":{…}}` |
| `q6_cbo_R2` | пресет: блок              | конфиг: `threshold: 50`         | резолверный | **0**   | `{"class":{…},"warning":50,"error":50}` |

Исход тот же, что у npath, но механизм другой и он важен для выбора носителя:
сокращение РАЗВОРАЧИВАЕТСЯ в верхнюю градуированную пару на своём слое
(`{"threshold":50} → {"warning":50,"error":50}`, видно в трассе на обоих швах),
merge кладёт эту пару РЯДОМ с блоком `class` — и именно её наличие открывает
плоскую ветку `CboOptions::fromArray()`, которая применяет 50/50 к обоим уровням
и блок игнорирует. То есть разворот на шве не спасает блок, а гарантированно его
топит: после разворота сокращение неотличимо от «автор написал плоскую пару».

Команда:
```
run.sh q6_cbo_A  docs/cbo_block.yaml --rule-opt='coupling.cbo:threshold=50'
run.sh q6_cbo_B  docs/cbo_short.yaml --rule-opt='coupling.cbo:class.warning=1' --rule-opt='coupling.cbo:class.error=1'
run.sh q6_cbo_R1 docs/cbo_block.yaml --preset=docs/cbo_short.yaml
run.sh q6_cbo_R2 docs/cbo_short.yaml --preset=docs/cbo_block.yaml
```

**Слепое пятно.** Сняты два правила из пяти (`complexity.npath` без разворота,
`coupling.cbo` с разворотом) — по одному представителю каждого класса. Уровень
`namespace` и уровень `callable` в ориентациях не участвовали.

---

## Что из ответов опровергает посылки

**1. «Ветка голого сокращения строит объект ДО чтения вложенных блоков» — верно,
но формулировка занижает эффект.** Для трёх complexity-правил ветка не просто «не
читает» блок: она явно ставит `class: enabled false` (`ComplexityOptions.php:56`).
Наблюдение (b) против контроля: 0 против 1 класс-находки. Исход — не «уровень
остался на дефолте», а «уровень выключен». Лечение, которое лишь донесёт блок до
`fromArray()`, чинит половину.

**2. «В `fromArray()` происхождение ключей по слоям уже потеряно» — верно, но
потеряно ЗНАЧИТЕЛЬНО раньше.** `RuleOptionsFactory` получает в качестве
«файлового слоя» уже слитый выход `FindingConfigurationResolver`: в `q6_R1` трасса
показывает `resolve.result = {"threshold":50,"class":{…}}`, и ровно это приходит в
`factory.layers file=`. К фабричному шву различие «пресет против конфига» УЖЕ
потеряно. «Швы слияния» — это ДВА шва с разной видимостью; лечение на одном
фабричном не увидит пару пресет↔конфиг вовсе.

**3. «Лечение должно жить на швах, потому что там видно слои» — держится хуже
всего.** `unfold()` посещает путь `''` и путь уровня по РАЗНЫМ правилам:
вложенный путь посещается только когда ОБА слоя написали один и тот же блок.
За всё измерение 3224 вызова `unfold`, 3220 на `path=''`; на `path='class'` — 4,
и все четыре в специально построенных `q1_nested`/`q1_nested2`. Самый частый документ
(`coupling.cbo: {threshold: 50, class: {warning: 1, error: 1}}`) до шва ДОХОДИТ —
но только верхним уровнем: блок `class` в момент разворота для шва непрозрачен
(он просто значение ключа). Посылка «одиночный слой до шва не доходит» ложна;
посылка «шов видит уровень, на котором написан блок» ложна для одно-слойного
случая.

**4. «Отказ будет называть ключ, который автор писал» — сегодня держится, но не
из-за порядка шагов.** Распознавание стоит ПОСЛЕ разворота и видит развёрнутые
ключи (`doc_e6`: `sees: ["class","warning","error"]`). Инвариант держит только
проверка формы перед разворотом. Лечение, расширяющее разворот, обязано сохранить
это условие, иначе отказ начнёт называть ключ, которого в документе нет.

**5. «Инвертированная полоса — следствие будущего лечения» — опровергнуто: это
предсуществующее свойство.** `error < warning` принимается сегодня обоими
проверенными правилами, exit 2, полоса схлопывается в сплошной `error`
(27 находок в `q3_cbo`, ни одной `warning`). Продукт объявляет это намеренным в
двух местах кода. Цена лечения по этой оси нулевая, но и ссылаться на «мы не
вводим новую инвертированную полосу» как на аргумент нельзя.

**6. Побочно: посылка «CLI отдаёт строку и потому не развернётся» опровергнута.**
`--rule-opt` отдаёт `int`, CLI-слой разворачивается наравне с файловым
(`q4_cli_cbo`: `{"threshold":7} → {"warning":7,"error":7}`).

---

## Сырые выводы

- `runs/*.json` — вывод продукта (ключ `violations`), `runs/*.err` — stderr,
  `runs/*.trace` — JSONL трассы швов.
- `docs/*.yaml` — все документы, `fixture/` — фикстура, `copy/` —
  инструментированная копия дерева, `run.sh`, `summarize.py` — оснастка.
