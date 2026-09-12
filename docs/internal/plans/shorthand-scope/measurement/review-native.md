# Ревью плана X22 `docs/internal/plans/shorthand-scope/` — находки (reviewer: claude)

Материал — план, а не код. Якоря в `src/` указаны там, где дефект плана доказывается кодом;
`in_scope` для них «нет» (файл не в диффе ветки), но предмет находки — план, который ими управляет.
Все прогоны `bin/qmx check` — на дереве `x22-shorthand-names-what-it-covers` без единой правки
проекта; фикстуры и вывод лежат в `review-x22/raw/`.

---

### claude-01

- **reviewer**: claude
- **severity**: HIGH
- **kind**: contract
- **domain**: architecture
- **title**: Композиция решается там, где принадлежность слою уже потеряна: два документа с противоположным намерением дают `fromArray()` один и тот же массив
- **mechanism**: План ставит лечение в `fromArray()` пяти классов (`02-cure.md:19-27`, «Files»: только пять классов опций и их тесты). Но к моменту вызова `fromArray()` слои уже слиты дважды: пресет↔конфиг-файл в `FindingConfigurationResolver::mergeRuleOptions()` и конфиг-файл↔CLI в `RuleOptionsFactory::deepMerge()`. Отсюда два РАЗНЫХ документа дают один и тот же набор ключей и значений (различается только порядок ключей, который `fromArray()` не наблюдает), а правило «блок называет свой уровень и выигрывает там» обязано ответить на них одинаково:
  - случай X (тот, что план пинит в DoD `01-semantics.md:67-70`): пресет пишет `complexity.ccn: {threshold: 5}`, `qmx.yaml` пишет `{class: {max_warning: 2, max_error: 3}}`. Слитый массив: `{threshold: 5, class: {maxWarning: 2, maxError: 3}}`. Желаемое: блок из верхнего слоя выигрывает.
  - случай Y (тот, что реально лежит в репозитории): пресет пишет `{class: {...}}`, а `qmx.yaml`/CLI пишет `{threshold: 5}`. Слитый массив — тот же набор ключей и значений. Желаемое по ADR 0058 и по приоритету слоёв: выигрывает ключ верхнего слоя, то есть сокращение.
  Правило плана отвечает «блок выигрывает» в обоих случаях, то есть в случае Y верхний слой молча проигрывает нижнему. Это ровно тот дефект, ради устранения которого существует программа, только развёрнутый в другую сторону и на слое с БОЛЬШИМ приоритетом. Случай Y достижим сегодня и измерен: и `strict.yaml`, и `legacy.yaml` пишут `callable:`/`class:`/`namespace:` блоки для всех пяти правил.
- **trigger**: воспроизводится в нормальной работе — `bin/qmx check src/ --preset=strict` при `qmx.yaml` с `complexity.ccn: {threshold: 5}`; то же через `--rule-opt='complexity.ccn:threshold=5'`
- **in_scope**: да (якорь — файлы плана)
- **anchor**: `docs/internal/plans/shorthand-scope/01-semantics.md:8-13,67-70`; `02-cure.md:19-27,64-67`
- **evidence**:
  - Пресет пишет ровно вторую половину пары (`src/Analysis/Configuration/Preset/strict.yaml:7-13`):
    ```yaml
    complexity.ccn:
      callable: { warning: 7, error: 15 }
      class:    { max_warning: 20, max_error: 35 }
    ```
  - Сегодня (`raw/out1.json`, `--preset=strict` + конфиг `{threshold: 5}`):
    `complexity.ccn | error | Cyclomatic complexity is 8, exceeds threshold of 5`
  - Контроль, пресет без конфига (`raw/out2.json`):
    `complexity.ccn | warning | Cyclomatic complexity is 8, exceeds threshold of 7`
    То есть после изменения документ пользователя даст второе, а не первое: `threshold: 5` не повлияет НИ НА ОДИН уровень, потому что оба уровня названы пресетом.
  - Слияние, сохраняющее блок пресета рядом с сокращением: `src/Analysis/Finding/Configuration/FindingConfigurationResolver.php:84-100` (`mergeRuleOptions`, рекурсия в `class:`), `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php:75,354-370`.
  - Проект уже знал, что различить слои в `fromArray()` нельзя — это цитируется в собственной разведке захода (`measurement/enabled-question.md`, факт 2: «information fromArray() cannot recover»).
- **verification**: confirmed
- **verification_note**: обе стороны случая Y измерены живым бинарём (сегодняшнее поведение и поведение пресета в одиночку); предсказание «после изменения будет 7/15» следует буквально из `01-semantics.md:11-13` («блок называет своё и выигрывает там») — иной прочтение текста плана не допускает. `candidates.md` объявил «No preset breaks», проверив пресеты на наличие СОКРАЩЕНИЯ; пресеты дают вторую половину пары — БЛОК, и этот запрос её не видел.
- **fix_direction**: решать вопрос там, где слои ещё различимы, — на швах слияния (где ADR 0058 его и решил), а не в `fromArray()`; либо явно принять и записать, что сокращение верхнего слоя проигрывает блоку любого нижнего, и тогда DoD обязан пинить случай Y, а не только X, а CHANGELOG — называть документ «пресет + голый `threshold`».

---

### claude-02

- **reviewer**: claude
- **severity**: HIGH
- **kind**: contract
- **domain**: reliability
- **title**: `01-semantics` и `02-cure` задают разную гранулярность композиции; на полуназванной полосе они дают разные значения, и одно из них инвертирует полосу
- **mechanism**: `01-semantics.md:60-62` — «Ни один уровень не приобретает и не теряет дефолт. Блок, назвавший `max_warning` и не назвавший `max_error`, берёт собственный дефолт правила для половины, которую не назвал — сокращение не тянется внутрь блока» (гранулярность УРОВНЯ). `02-cure.md:22-26` — «для каждого уровня: собственные ключи блока, потом сокращение, потом дефолт конструктора — выигрывает первый, кто назвал ключ» (гранулярность КЛЮЧА). Это два разных правила, и на документе, где блок назвал одну половину полосы, они дают разные числа. Ни одна строка таблицы `01-semantics.md:15-21` такой документ не содержит, а DoD обоих этапов — «таблица построчно, теми же словами», поэтому тесты выбор не сделают: его сделает исполнитель, молча. Безусловно противоречие видно на `coupling.cbo`, где имена ключей верхнего уровня и уровня совпадают: `coupling.cbo: {threshold: 50, class: {warning: 1}}` (после разворота верх = `warning: 50, error: 50`) даёт `class.error = 20` по 01 и `class.error = 50` по 02. Для complexity добавляется вторая неопределённость: имена ключей уровней РАЗНЫЕ (`warning`/`error` против `max_warning`/`max_error`) и шкалы разные, поэтому «выигрывает первый, кто назвал КЛЮЧ» допускает два прочтения — сокращение либо вовсе не заполняет `max_error` (и 02 схлопывается в 01), либо заполняет его значением callable-шкалы, и тогда при `maxError < maxWarning` полоса инвертируется: `getSeverity()` проверяет `error` первым, так что весь диапазон, который автор просил как warning, становится error. План не говорит, какое из двух прочтений имеется в виду.
- **trigger**: воспроизводится в нормальной работе — документ пишется в одну строку
- **in_scope**: да
- **anchor**: `docs/internal/plans/shorthand-scope/01-semantics.md:60-62` против `02-cure.md:22-26`
- **evidence**:
  - Разные шкалы и разные имена ключей у уровней одного правила:
    ```php
    // MethodComplexityOptions:  warning = 10, error = 20      (ключи warning/error)
    // ClassComplexityOptions:   maxWarning = 30, maxError = 50 (ключи max_warning/max_error)
    // ClassNpathComplexityOptions: maxWarning = 500, maxError = 1000; топ-сокращение npath: 200/1000
    ```
  - Инверсия полосы (`src/Analysis/Evidence/Complexity/ClassComplexityOptions.php:69-80`):
    ```php
    if ($value >= $this->maxError) { return Severity::Error; }
    if ($value >= $this->maxWarning) { return Severity::Warning; }
    ```
    Документ `complexity.ccn: {threshold: 5, class: {max_warning: 40}}` при заполняющем прочтении `02-cure` даёт `maxWarning=40, maxError=5` → любой класс с maxCCN ≥ 5 становится ERROR; по `01-semantics` — `40/50`, то есть ровно то, что автор просил.
  - Безусловный случай (одинаковые имена ключей на обоих уровнях): `coupling.cbo: {threshold: 50, class: {warning: 1}}` → `class.error = 20` (по 01) или `50` (по 02).
- **verification**: confirmed
- **verification_note**: противоречие текстовое и проверяется чтением двух абзацев; следствия проверены по коду (константы конструкторов, порядок сравнений в `getSeverity`). Дополнительный довод в пользу ключевой гранулярности уже есть в продукте: `CboOptions::fromArray()` (`src/Analysis/Evidence/Coupling/CboOptions.php:94-97`) композирует `scope` по ключу — верхний уровень заполняет `class`, только если блок его не назвал.
- **fix_direction**: выбрать одну гранулярность и переписать оба этапа под неё целиком (правка вставкой абзаца даст документ, спорящий сам с собой); добавить в таблицу строку с блоком, назвавшим ровно половину полосы, и строку, где перенос сокращения дал бы `error < warning` — и решить, отказ это или дефолт.

---

### claude-03

- **reviewer**: claude
- **severity**: HIGH
- **kind**: contract
- **domain**: tests
- **title**: Арифметика леджера в `03-acceptance` неверна: часть строк M2/M3 обещает `refuse`, поэтому заявленное падение оси B на 61 ячейку недостижимо
- **mechanism**: `03-acceptance.md:12-13` утверждает: «61 строка M2/M3 НЕ нуждается в новом значении: после этапа 2 они композируют, а `compose` — это то, что они уже говорят». Измеримо неверно. Среди 18 строк `kind=3-shorthand-vs-level-block` (same-source) **семь** несут `coexistence=refuse`, и ещё группа кросс-уровневых строк `X.threshold × threshold` — тоже `refuse`. Эти строки получили `refuse` по ADR 0052 row 4 («носитель не называет победителя → отказать, а не выбирать молча»). После лечения продукт будет композировать — значит, эти ячейки останутся `MISCOMPOSED` («composed where refusal promised», ровно как описано в `axis-b-mechanisms.tsv` для M2/M3), и DoD `02-cure.md:58` («ось B падает с 81 на 61 ячейку M2/M3») не выполнится. `00-overview.md:24-36` обобщает на все 61 строку цитату ОДНОЙ строки (`complexity.ccn class: threshold`), которая как раз несёт `compose`.
- **trigger**: воспроизводится в нормальной работе — приёмка этапа 2 упрётся в это на первом же прогоне стенда
- **in_scope**: да
- **anchor**: `docs/internal/plans/shorthand-scope/03-acceptance.md:5-13`; `02-cure.md:58`
- **evidence**: из `docs/internal/plans/promise-effect/measurement/promise-ledger.tsv`, строки `kind=3-shorthand-vs-level-block`, same-source половина (18 строк), колонка `coexistence`:
  ```
  complexity.ccn        | callable: | threshold | refuse
  complexity.cognitive  | callable: | threshold | refuse
  complexity.npath      | callable: | threshold | refuse
  coupling.cbo          | class:    | threshold | refuse
  coupling.cbo          | namespace:| threshold | refuse
  coupling.instability  | class:    | threshold | refuse
  coupling.instability  | namespace:| threshold | refuse
  ```
  Семь строк из восемнадцати; остальные одиннадцать несут `compose`.
  Вторая половина тех же 18 пар (cross-source) несёт ПУСТУЮ `coexistence` и статус `DEFERRED`: «DEFERRED with axis C … routed through RuleOptionThresholdModeResolver / RuleThresholdKeyGroupRegistry, which this round freezes whole» — то есть кросс-слойная координата этой самой пары объявлена отложенной и неизмеренной, а лечение её двигает.
- **verification**: confirmed
- **verification_note**: посчитано по самому леджеру (`awk` по колонке 6, фильтр по пяти правилам): среди них 45 строк `refuse`, и часть из них — не одноуровневый mix `warning`×`threshold`, а именно пары «блок × сокращение» и «`X.threshold` × `threshold`». Счёт 20+25+36=81 из `axis-b-mechanisms.tsv` с планом сходится; расходится именно вывод о значениях колонки.
- **fix_direction**: перечислить затрагиваемые строки леджера поимённо с их текущим значением `coexistence` и сказать по каждой, чем она станет после лечения; DoD этапа 2 переформулировать в числах, полученных из этого перечисления, а не из допущения «все 61 уже говорят compose».

---

### claude-04

- **reviewer**: claude
- **severity**: HIGH
- **kind**: contract
- **domain**: reliability
- **title**: Различение M1 и M2/M3 не держит: блок рядом с `enabled: false` не инертен — ни по исходу, ни на шве распознавания
- **mechanism**: `01-semantics.md:29-40` обосновывает «M1 — это поправка леджера, а не изменение продукта» тем, что «исход идентичен в обе стороны — ничего», и что «никакое изменение продукта не заставит выключенное правило чтить порог». Оба утверждения ложны:
  1. блок несёт не только пороги. `Class*Options::acceptedOptionKeys()` объявляет `enabled`, а `HierarchicalRuleOptions::isEnabled()` — это `class->isEnabled() || namespace->isEnabled()`, то есть «правило выключено» не отдельное состояние, а следствие состояний уровней. Документ с `class: {enabled: true, …}` рядом с верхним `enabled: false` по собственной фразе плана («ключ настраивает то, что называет») обязан включить уровень класса; сегодня он даёт ноль находок;
  2. продукт блок ЧИТАЕТ — на шве `RuleOptionKeyRecognition::refuseUnknownKeys()`, который в `RuleOptionsFactory::create()` (шаг 5) вызывается на всём `$userConfig`, до и независимо от ветвления в `fromArray()`. Неизвестный ключ или значение неверной формы ВНУТРИ блока рядом с `enabled: false` даёт отказ с кодом 3. То есть продукт читает блок ровно настолько, чтобы отказать по нему, и недостаточно, чтобы его исполнить — это не «законная инертность», а асимметрия.
  Кросс-слойно это уже сегодня теряет ключ САМОГО ВЕРХНЕГО слоя.
- **trigger**: воспроизводится в нормальной работе — измерено тремя прогонами
- **in_scope**: да
- **anchor**: `docs/internal/plans/shorthand-scope/01-semantics.md:29-47`; `03-acceptance.md:5-10`
- **evidence** (фикстура `raw/fx2/src`, A→B, A→C):
  - `coupling.cbo: {class: {enabled: true, warning: 1, error: 1}}` → **3 находки** (`raw/out-on.json`)
  - тот же блок + `enabled: false` на верхнем уровне → **0 находок** (`raw/out-off.json`)
  - кросс-слойно, где верхний слой — CLI: конфиг `rules: {coupling.cbo: false}` + `--rule-opt='coupling.cbo:class.enabled=true' --rule-opt='coupling.cbo:class.warning=1' --rule-opt='coupling.cbo:class.error=1'` → **0 находок** (`raw/out-cli.json`); три ключа самого приоритетного слоя выброшены молча
  - блок не инертен на шве: `{enabled: false, class: {bogus_key: 1}}` → exit 3; `{enabled: false, class: {warning: "abc"}}` → exit 3
  - строка леджера для этой пары: `coupling.cbo | class.enabled | enabled | same-source | compose` («ADR 0052 row 3: оба применяются на своей глубине») — то есть леджер уже обещает композицию именно там, где план объявляет законную инертность.
- **verification**: confirmed
- **verification_note**: `measurement/user-documents.md` doc2 наблюдал единственную форму блока — только пороги (`max_warning`/`max_error`). Именно на ней исход одинаков; на форме с `enabled` — нет. Обобщение «ничего наблюдаемого не зависит от того, прочитан ли блок» сделано по одному документу. `@qmx-threshold` дискриминатором быть не может: `withOverride()` сохраняет `$this->enabled`, поэтому на выключенном уровне разницы не даёт — проверено по коду, не находка.
- **fix_direction**: либо назвать верхний `enabled` ГЕЙТОМ, единственным исключением из «ключ настраивает то, что называет», и тогда объяснить, почему шов распознавания блок всё-таки читает, и поправить строку леджера `enabled × class.enabled`, которая сегодня обещает `compose`; либо признать M1 тем же предметом, что M2/M3, и лечить вместе — но не оставлять обоснование «исход одинаков», которое опровергается однострочным документом.

---

### claude-05

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: architecture
- **title**: Список файлов и DoD не видят механику сокращения на швах слияния, которая зеркалит переписываемые call-site'ы — и для двух из пяти правил сокращение до `fromArray()` вообще не доживает
- **mechanism**: `02-cure.md:64-67` — «Пять классов опций, какую бы общую форму они ни приняли, и их тесты. Ни одного файла стенда и ни одного файла, который трогал ADR 0058». Но `RuleThresholdKeyGroupRegistry` объявлен зеркалом ИМЕННО тех вызовов `ThresholdParser::parse()`, которые лечение переписывает, и его полнота проверяется `RuleThresholdKeyGroupRegistryCompletenessTest` — это файлы ADR 0058. Дальше: для `coupling.cbo` и `coupling.instability` запись `''` в реестре — `BARE_PAIR` / `MAX_PREFIXED_PAIR`, поэтому `deepMerge()` РАЗВОРАЧИВАЕТ верхний `threshold` в `warning`/`error` (соотв. `max_warning`/`max_error`) ещё до `fromArray()`. Следствия, которых план не называет: (а) «голое сокращение `threshold`» для двух правил из пяти в `fromArray()` не приходит вовсе — приходит верхняя градуированная пара, неотличимая от явно написанной; (б) тесты DoD, написанные «словами таблицы» как `fromArray(['threshold' => …])`, проверяют форму, которую продукт на этом шве не производит; (в) записи `''` для cbo/instability теряют зеркалимый вызов; (г) комментарий `LONE_THRESHOLD_SHAPE` («их сокращение кросс-путевое и выключает соседний уровень») перестаёт быть верным для complexity.
- **trigger**: воспроизводится в нормальной работе — любой документ cbo/instability с верхним `threshold`
- **in_scope**: нет (якорь в `src/`, предмет — раздел «Files» плана)
- **anchor**: `src/Analysis/Finding/RuleConfiguration/RuleThresholdKeyGroupRegistry.php:141-203`; `RuleOptionThresholdShorthand.php:107-149`; план — `02-cure.md:64-67`
- **evidence**:
  ```php
  // RuleThresholdKeyGroupRegistry::GROUPS
  'complexity.ccn'       => ['' => [[...LONE_THRESHOLD_SHAPE, ...]], 'callable' => [[...BARE_PAIR]], 'class' => [[...MAX_PREFIXED_PAIR]]],
  'coupling.cbo'         => ['' => [[...BARE_PAIR, ...]], 'class' => [...], 'namespace' => [...]],
  'coupling.instability' => ['' => [[...MAX_PREFIXED_PAIR, ...]], ...],
  ```
  плюс `RuleOptionsFactory::deepMerge()` (`:356-357`) вызывает `unfold()` на обоих слоях на каждом пути.
- **verification**: confirmed
- **verification_note**: разворот подтверждается и кодом, и измерением самого захода: `measurement/branches.tsv` фиксирует, что ветка `CboOptions` открывается в том числе голым `warning`/`error`, — это ровно то, во что развёрнут `threshold`. Слово «unfolding» в плане встречается ровно один раз — `02-cure.md:36`, и только как аналогия для ловушки формы значения; нигде не сказано, что разворот выполняется ДО `fromArray()` над тем самым входом, который лечат, и что для cbo/instability он меняет спеллинг, под которым сокращение приходит.
- **fix_direction**: внести реестр, `RuleOptionThresholdShorthand` и тест полноты в список затрагиваемых файлов и в DoD; в стадии 1 указать, в какой ФОРМЕ сокращение приходит в точку лечения для каждого из пяти правил, и писать приёмочные тесты через реальную дверь (конфиг-документ/CLI), а не только вызовом `fromArray()`.

---

### claude-06

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: reliability
- **title**: Естественная реализация подачи сокращения в уровень затеняет написанный автором ключ через алиас — то же самое молчаливое поглощение, ради устранения которого заход существует
- **mechanism**: `ThresholdParser::parse()` ищет ключ полосы по списку кандидатов, ПЕРВИЧНЫЙ спеллинг первым (`max_warning`, затем легаси `maxWarning`). Все двери нормализуют ключи рекурсивно, включая вложенные блоки, поэтому написанный автором `max_warning:`/`max-warning:` внутри `class:` приходит в `fromArray()` как `maxWarning`. Существующая ветка сокращения строит конфиг уровня с литеральным `'max_warning'` (`InstabilityOptions.php:62-66`). Если лечение построит массив уровня как «ключи блока плюс значения сокращения» (буквально то, что предписывает `02-cure.md:22-26`), в массиве окажутся ОБА спеллинга, и `ThresholdParser` возьмёт первичный — то есть значение СОКРАЩЕНИЯ, а не написанное автором. Юнит-тест по DoD («теми же словами таблицы», то есть `max_warning`) этого не увидит: дефект проявляется только в спеллинге, который производит реальная дверь.
- **trigger**: воспроизводится в нормальной работе при одной из естественных реализаций; на сегодняшнем коде недостижим (ветки ещё нет)
- **in_scope**: нет
- **anchor**: `src/Analysis/Finding/Contract/Rule/ThresholdParser.php:102-135`; `src/Analysis/Evidence/Coupling/InstabilityOptions.php:62-66`; `src/Analysis/Configuration/Loader/YamlConfigLoader.php:159-178`
- **evidence**:
  ```php
  // ThresholdParser::candidateKeys() — первичный спеллинг впереди легаси
  'warning' => [$warningKey, ...($legacyKeys['warning'] ?? [])],
  // InstabilityOptions::fromArray() — сокращение строит уровень под литеральным 'max_warning'
  $levelConfig = [ ..., 'max_warning' => $thresholds['warning'], 'max_error' => $thresholds['error'] ];
  ```
  Рекурсивная нормализация вложенных ключей проверена живым прогоном: `coupling.instability: {class: {max-warning: 0.99, max-error: 0.99, min-afferent: 0}}` (kebab, ни одному литералу в `fromArray()` не равен) даёт находку — значит ключи свернулись в camelCase до `fromArray()` (`raw/out-inst3.json`).
- **verification**: confirmed
- **verification_note**: подтверждена предпосылка (рекурсивная нормализация + порядок кандидатов + литеральный спеллинг в существующей ветке). Сам дефект — свойство ещё не написанной реализации, поэтому это требование к форме лечения, а не найденный баг.
- **fix_direction**: в стадии 2 записать требование: значения сокращения подаются в уровень ПОСЛЕ приведения ключей блока к одному спеллингу (или мимо массива — прямыми аргументами), и приёмочный тест обязан писать ключ блока в спеллинге, который производит дверь, а не в спеллинге таблицы.

---

### claude-07

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: reliability
- **title**: Подача сокращения в уровень превращает непротиворечивый документ в отказ по ключу, которого автор не писал
- **mechanism**: `ThresholdParser::parse()` отказывает, когда в ОДНОМ массиве несут значение и `threshold`, и градуированный ключ группы. Если лечение подаёт сокращение в массив уровня (буквальное прочтение `02-cure.md:22-26`), то `complexity.ccn: {threshold: 5, callable: {warning: 3}}` соберёт массив уровня с `threshold` (из сокращения) рядом с `warning` (от автора) и отказ произойдёт на mix — хотя в документе автора одноуровневого противоречия нет, а `threshold` внутри `callable:` автор не писал. `02-cure.md:36-40` предупреждает про соседнюю ловушку («форма значения, а не только ключ… подача не должна менять, какой ключ называет отказ»), но не про создание отказа там, где его не было. DoD `02-cure.md:57` («существующие тесты одноуровневого отказа проходят нетронутыми») этого не поймает: документ новый.
- **trigger**: воспроизводится в нормальной работе при одной из естественных реализаций
- **in_scope**: нет
- **anchor**: `src/Analysis/Finding/Contract/Rule/ThresholdParser.php:82-87`; план — `02-cure.md:19-27,36-40,48-57`
- **evidence**:
  ```php
  if (self::hasAnyWrittenKey($config, $candidates['warning']) || self::hasAnyWrittenKey($config, $candidates['error'])) {
      throw ConfigurationRefusal::atResolvedKey(... self::mixedModesMessage($warningKey, $errorKey, $thresholdKey));
  }
  ```
  Тот же класс дефекта уже ловился на слое выше — условие 3 в `RuleOptionThresholdShorthand::unfold()` существует ровно затем, чтобы не создать mix слиянием.
- **verification**: confirmed
- **verification_note**: механизм отказа прочитан в коде; достижимость зависит от формы реализации, поэтому это требование к плану, а не воспроизведённый отказ.
- **fix_direction**: записать в DoD документ «сокращение рядом с блоком, назвавшим одну половину полосы» как обязательный тест с ожиданием НЕ отказа, и явно запретить подачу сокращения в виде ключа `threshold` внутрь массива уровня.

---

### claude-08

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: reliability
- **title**: Правило «блок переоткрывает уровень» не классифицирует пустой блок и `~`, а `isset()` склеивает `class: ~` с отсутствием ключа
- **mechanism**: `01-semantics.md:23-27` делает включённость уровня следствием ПРИСУТСТВИЯ блока. Тогда `complexity.ccn: {threshold: 5, class: {}}` включает уровень класса дефолтами 30/50, то есть пустая карта включает правило — а `class: ~` неотличим от отсутствия блока, потому что все пять классов читают блок через `isset($config[$key]) && is_array(...)`, и `~` там не массив. ADR 0058 отдельным решением закрепил, что `~` означает молчание, а не сброс; здесь то же самое написание получает третий смысл — «уровень не назван». Ни одной строки таблицы под это нет, а заход X19 уже горел ровно на `~` внутри `rules:`.
- **trigger**: воспроизводится в нормальной работе — `class:` без содержимого пишется при правке конфига постоянно
- **in_scope**: да
- **anchor**: `docs/internal/plans/shorthand-scope/01-semantics.md:15-27`
- **evidence**:
  ```php
  // ComplexityOptions::fromArray():66-68
  $classConfig = isset($config[$classKey]) && \is_array($config[$classKey]) ? $config[$classKey] : [];
  // ClassComplexityOptions::fromArray():50-52 — пустой массив = дефолты, уровень ВКЛЮЧЁН
  if ($config === []) { return new self(); }
  ```
- **verification**: confirmed
- **verification_note**: обе ветки прочитаны в коде; вопрос в том, что план не выбирает между ними, а не в том, что код неверен сегодня.
- **fix_direction**: добавить в таблицу строки для `class: {}`, `class: ~` и `class: {enabled: ~}` рядом с сокращением и сказать по каждой, назван уровень или нет, согласовав ответ с правилом `~`-молчания из ADR 0058.

---

### claude-09

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: judgement
- **domain**: architecture
- **title**: Граница «одноуровневый отказ / кросс-уровневая композиция» не выводится из фразы плана, и для complexity обе стороны совпадают в одном документе
- **mechanism**: `00-overview.md:70-73` удерживает отказ для `threshold` рядом с `warning`/`error` на одном уровне как «два написания одного значения в одном слоте». Но это верно только если читать `threshold` как называющий ОБА слота — ровно то прочтение, которое план отвергает уровнем выше (там сокращение как раз называет и соседний уровень: оно его выключает). Получаются два противоположных ответа на соседних написаниях: `coupling.cbo: {threshold: 30, class: {warning: 1}}` композирует, `coupling.cbo: {threshold: 30, warning: 1}` отказывает — при одном намерении автора. Для complexity эти две стороны прямо совпадают: верхнее сокращение ЕСТЬ callable-полоса, поэтому `complexity.ccn: {threshold: 5, callable: {threshold: 3}}` — «один слот записан дважды на двух глубинах». Критерий противоречия из самого плана говорит «отказ», правило «блок выигрывает» говорит «композиция», леджер для этой пары говорит `refuse`. Плюс таблица `01-semantics` не содержит ни одной строки с верхней ГРАДУИРОВАННОЙ парой (`warning:`/`error:` у cbo/instability) рядом с блоком, хотя это и объявленное написание (`CboOptions::acceptedOptionKeys()`), и та форма, в которой сокращение приходит после разворота (см. claude-05).
- **trigger**: воспроизводится в нормальной работе
- **in_scope**: да
- **anchor**: `docs/internal/plans/shorthand-scope/00-overview.md:65-73`; `01-semantics.md:55-57`
- **evidence**: леджер, `complexity.ccn | callable.threshold | threshold | same-source | refuse`, примечание: «C6c ставит верхнее сокращение ровно в этот слот, так что оба пишут одну полосу дважды … ADR 0052 row 4 → refuse». `CboOptions::acceptedOptionKeys()` объявляет верхние `warning`/`error` (`src/Analysis/Evidence/Coupling/CboOptions.php:111-119`).
- **verification**: unverifiable
- **verification_note**: это суждение о когерентности правила, а не свойство кода; проверяемая его часть (леджер обещает `refuse` на совпадающей паре, и верхняя градуированная пара объявлена) подтверждена и вынесена в claude-03 и claude-05.
- **fix_direction**: сформулировать критерий, отделяющий «два написания одного слота» от «два ключа на разных глубинах», так, чтобы он давал ответ и для `{threshold, callable.threshold}`; добавить в таблицу строку с верхней градуированной парой рядом с блоком.

---

### claude-10

- **reviewer**: claude
- **severity**: LOW
- **kind**: contract
- **domain**: architecture
- **title**: Фраза захода шире его перечисления: `code-smell.long-parameter-list` под неё попадает, а в перечислении его нет
- **mechanism**: Заголовок и фраза («сокращение называет уровни, которые покрывает, и ничего больше»; «сокращение — для уровней, которые НИКТО НЕ НАЗВАЛ») сформулированы универсально, а перечисление ограничено пятью иерархическими классами. `code-smell.long-parameter-list` — плоский класс с ДВУМЯ независимыми группами полос на одном уровне, и его голый `threshold` покрывает только первую; вторую (`vo-*`) он не покрывает и не должен. Буквальное прочтение фразы предписывает противоположное. Общая форма, в которую `02-cure.md:29-32` предлагает сложить пять классов, ровно этот тип рассуждения и провоцирует, а DoD («ни в одной из пяти `fromArray()` не осталось раннего возврата») шестого правила не видит.
- **trigger**: только при обобщении исполнителем; сегодня недостижим
- **in_scope**: да
- **anchor**: `docs/internal/plans/shorthand-scope/00-overview.md:1`; `01-semantics.md:5-13`; `02-cure.md:29-32`
- **evidence**:
  ```php
  // LongParameterListOptions::fromArray() — две независимые группы, обе читаются всегда
  $thresholds   = ThresholdParser::parse($config, 'warning', 'error', 4, 6);
  $voThresholds = ThresholdParser::parse($config, 'vo-warning', 'vo-error', 8, 12, 'vo-threshold', legacyKeys: [...]);
  ```
  реестр подтверждает, что это отдельный признанный случай: `'code-smell.long-parameter-list' => ['' => [BARE_PAIR, vo-группа]]`.
- **verification**: confirmed
- **verification_note**: `measurement/branches.tsv` осознанно исключил плоские классы (проверены 43 реализации `fromArray()`); дефект не в перечислении, а в том, что фраза его перерастает.
- **fix_direction**: сузить формулировку до «сокращение верхнего уровня иерархического правила» либо явно записать, что́ фраза НЕ меняет, назвав `long-parameter-list` как случай, где сокращение сознательно покрывает одну группу из двух.

---

### claude-11

- **reviewer**: claude
- **severity**: LOW
- **kind**: contract
- **domain**: tests
- **title**: DoD требует тест на отсутствие раннего возврата — свойство формы исходника, а не поведения
- **mechanism**: `02-cure.md:56` — «ни в одной из пяти реализаций `fromArray()` не осталось раннего возврата; тест утверждает это по построению, а не чтением, чтобы шестое правило не смогло его вернуть незамеченно». Ранний возврат — синтаксическое свойство; тест по построению его не увидит (либо это AST-сторож, то есть отдельный механизм со своей ценой и своим ложно-зелёным режимом, который план не проектирует). Проверяемый инвариант здесь другой: «для любого документа каждый написанный блок уровня прочитан».
- **trigger**: недостижим (дефект приёмки, не поведения)
- **in_scope**: да
- **anchor**: `docs/internal/plans/shorthand-scope/02-cure.md:53-56`
- **evidence**: формулировка DoD не называет ни носителя проверки, ни её вход; остальные пункты того же DoD заданы через наблюдаемый исход (doc5/doc6, doc8).
- **verification**: unverifiable
- **verification_note**: это требование к формулировке DoD; кодом не проверяется, потому что механизма ещё нет.
- **fix_direction**: заменить формулировку на поведенческую («блок уровня, написанный рядом с любым верхним ключом, читается — перечислить пары ключ×уровень, которыми это проверяется»), либо назвать носителя структурного стража и его цену явно.

---

### claude-12

- **reviewer**: claude
- **severity**: LOW
- **kind**: contract
- **domain**: style
- **title**: Удаление «абзаца обходного пути» уносит с сайта единственный показ канонической иерархической формы
- **mechanism**: `03-acceptance.md:28` — «Абзац обходного пути уходит: он существует только чтобы обойти дефект». Абзац на `configuration.md:206-217` показывает форму `{callable: {threshold: 5}, class: {max_warning: 2, max_error: 3}}`. После изменения она перестаёт быть обходным путём, но не перестаёт быть канонической: ровно в ней написаны оба поставляемых пресета, и она остаётся единственным способом сказать «callable N, class off» рядом с активным пресетом (см. claude-01). Удаление абзаца целиком убирает показ всё ещё верной и рекомендуемой формы.
- **trigger**: недостижим (документация)
- **in_scope**: да
- **anchor**: `docs/internal/plans/shorthand-scope/03-acceptance.md:25-29`
- **evidence**: `website/docs/getting-started/configuration.md:206-217` (блок «To configure both levels, do not write the bare key»), `src/Analysis/Configuration/Preset/strict.yaml:7-13` — та же форма.
- **verification**: confirmed
- **verification_note**: текст сайта и пресеты прочитаны; спор только о том, удалять абзац или переписать его рамку.
- **fix_direction**: переписать абзац как показ канонической формы вместо обходного пути, а не удалять.

---

## Coverage — что проверено и признано чистым

- **Пять классов опций целиком** (`ComplexityOptions`, `CognitiveComplexityOptions`, `NpathComplexityOptions`, `CboOptions`, `InstabilityOptions`): конструкторы, обе ранние ветки, иерархическая ветка, `acceptedOptionKeys()`, `isEnabled/forLevel/isLevelEnabled`. Описание веток в `measurement/branches.tsv` сверено построчно — расхождений с кодом не найдено.
- **Утверждение разведки «ровно пять классов»**: перепроверено независимо — `grep -rl "implements HierarchicalRuleOptionsInterface" src/` даёт те же пять файлов.
- **Утверждение «Cognitive/Npath байт-идентичны Complexity»**: структура веток идентична (diff по области `fromArray()` даёт только смену имён классов), НО константы дефолтов различаются: топ-сокращение ccn 10/20, cognitive 15/30, npath 200/1000; классовые уровни 30/50, 30/50, 500/1000. Учтено в claude-02, отдельной находкой не выношу.
- **Уровневые классы опций** (`Method*`/`Class*`Complexity, `ClassCbo`, `NamespaceCbo`, `ClassInstability`, `NamespaceInstability`): дефолты, имена ключей, `getSeverity`, `withOverride`, объявленные ключи. У cbo и instability дефолты class и namespace совпадают (14/20 и 0.8/0.95) — утверждение докблока `CboOptions` верно.
- **`ThresholdParser`**: режимы, семантика `~`, порядок кандидатов, легаси-алиасы, отказ на mix — прочитан целиком.
- **Слои и швы слияния**: `RuleOptionsFactory::create()` (порядок шагов, где именно стоит отказ по неизвестным ключам, `normalizeKeys`, `expandDotNotation`, `deepMerge`), `FindingConfigurationResolver::mergeRules/mergeRuleOptions`, `RuleOptionThresholdShorthand::unfold()` (все пять условий), `RuleThresholdKeyGroupRegistry::GROUPS` целиком, `YamlConfigLoader::applyPolicy` (рекурсивность нормализации подтверждена живым прогоном с kebab-ключами внутри блока).
- **Пресеты** `strict.yaml`, `legacy.yaml`, `ci.yaml` прочитаны целиком; `ci.yaml` правил из пяти не касается — там только `failOn`.
- **`measurement/user-documents.md`, doc8** — единственное место разведки, помеченное как непроверенное («worth flagging as a discrepancy»): гипотеза автора воспроизведена независимо и подтверждена. `coupling.instability: {threshold: 0.99}` на классе с Ca=0 даёт 0 находок, а тот же порог с `min_afferent: 0` внутри блока — 1 находку: молчание объясняется дефолтом `min_afferent: 1`, второго эффекта нет. Находки нет; DoD `02-cure.md:53` на этом стоит корректно.
- **Счёт ячеек оси B**: 20 (M1) + 25 (M2) + 36 (M3) = 81 — сходится с `00-overview.md`. Расхождение обнаружено только в значениях колонки `coexistence` (claude-03).
- **Текст сайта** `website/docs/getting-started/configuration.md:183-217` прочитан: сегодняшнее описание M2/M3 точное, цитаты `measurement/docs-today.md` верны дословно, включая слово «silently». Отдельная находка о полноте документации не заводится — план верно называет три страницы и пробел `complexity.md`.
- **Что НЕ проверялось**: тела юнит-тестов пяти классов (читал только их перечень и описания в `candidates.md`); `promise-effect`-стенд и его классификатор (по запрету не запускался, код не читался); `finding-gate`-корпус (утверждение `candidates.md`, что ни один кейс не пишет сокращение рядом с блоком, не перепроверялось); `bin/qmx rules`, `baseline:explain` и HTML-отчёт как возможные вторые читатели объекта опций — из читателей проверен только шов распознавания (claude-04) и `withOverride`.

## Отклонённые (refuted)

Нет.
