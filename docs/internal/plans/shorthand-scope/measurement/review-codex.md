# Codex — review of plan `docs/internal/plans/shorthand-scope/` (X22)

Вердикт Codex (дословно, первая строка ответа): «план пока NO-GO. Выбор композиции сам по себе обоснован, но контракт композиции недоопределён и расходится с ADR 0058 в обратном межслойном направлении».

### codex-01

- **reviewer**: codex
- **severity**: HIGH
- **kind**: contract
- **domain**: reliability
- **title**: Верхнеуровневый shorthand теряет приоритет перед блоком из нижнего слоя (обратное направление ADR 0058)
- **mechanism**: План закрепляет и тестирует только направление «нижний слой (preset) пишет shorthand, верхний слой (`qmx.yaml`/CLI) пишет `class:`-блок» (01-semantics.md DoD: «a preset writing `{threshold: 5}` under a `qmx.yaml` writing `{class: {...}}` composes, and the more specific key from the higher layer is the one that wins»). Обратное направление — нижний слой пишет блок, верхний (более приоритетный) слой пишет shorthand — не рассмотрено вовсе. По факту к моменту вызова `fromArray()` провенанс ключей уже потерян: `RuleOptionsFactory::deepMerge()` разворачивает shorthand в обоих слоях через `RuleOptionThresholdShorthand::unfold($base, ...)` / `unfold($override, ...)` и затем сливает их в один массив (`src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php:354-355`), после чего единственный вызов `fromArray()` (там же, строка ~127: `return $optionsClass::fromArray($merged);`) не знает, какой ключ из какого слоя пришёл. При этом для `complexity.*` top-level shorthand зарегистрирован как `LONE_THRESHOLD_SHAPE`, который не разворачивается в `class`/`callable`-ключи вообще (`src/Analysis/Finding/RuleConfiguration/RuleThresholdKeyGroupRegistry.php:156-163`), а у CBO/Instability разворачивание идёт только в top-level graduated-ключи, не в `class.*`/`namespace.*`. План (порядок «block wins, then shorthand, then constructor default», 02-cure.md) при таком устройстве всегда отдаёт победу вложенному блоку — независимо от того, из какого слоя он пришёл.
- **trigger**: воспроизводится в нормальной работе — пример: preset пишет `complexity.npath: {class: {enabled: true, max_warning: 2, max_error: 3}}`, CLI поверх пишет `complexity.npath:threshold=50`. Сегодня (до X22) более приоритетный CLI-shorthand выигрывает целиком (callable 50/50, class off). После X22 нижний по приоритету `class:`-блок «переоткрывается» и остаётся `2/3`, хотя более приоритетный слой явно просил `50`.
- **in_scope**: да
- **anchor**: контракт «ADR 0058: shorthand разворачивается в каждом слое до merge» vs «X22: в уже слитом документе побеждает блок независимо от слоя»
- **evidence**:
  ```php
  // src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php:354-355
  $result = RuleOptionThresholdShorthand::unfold($base, $ruleName, $path);
  $override = RuleOptionThresholdShorthand::unfold($override, $ruleName, $path);
  ```
  План (01-semantics.md) закрепляет DoD только для направления «нижний слой = shorthand, верхний = блок»; обратное направление нигде не оговорено.
- **verification**: confirmed
- **verification_note**: Проверено чтением `RuleOptionsFactory.php` (`deepMerge`, `create`) и `RuleThresholdKeyGroupRegistry.php` (запись `complexity.ccn` → `LONE_THRESHOLD_SHAPE`) напрямую в репозитории. Подтверждено: к моменту `fromArray()` layer-провенанс действительно потерян, а X22 не оговаривает направление «блок ниже, shorthand выше».
- **fix_direction**: Явно зафиксировать семантику для обеих ориентаций layer-priority × block-vs-shorthand; если specificity должна побеждать layer priority всегда — это отдельное breaking-решение, которое нужно назвать явно, а не выводить из аналогии с ADR 0058 (которая касается другого измерения — целостности значения одного слоя, а не межслойного приоритета specificity).

### codex-02

- **reviewer**: codex
- **severity**: HIGH
- **kind**: contract
- **domain**: reliability
- **title**: Stage 1 и Stage 2 плана дают РАЗНЫЕ значения непоименованной половине полосы (порогов) одного и того же документа
- **mechanism**: `01-semantics.md` формулирует: «A block that names `max_warning` and not `max_error` still takes the rule's own default for the half it did not name — the shorthand does not reach into a block to fill it» — т.е. непоименованная половина берёт ДЕФОЛТ КОНСТРУКТОРА уровня. `02-cure.md` формулирует порядок «the block's own keys, then the shorthand, then the constructor default — first one that names the key wins» — т.е. непоименованная половина берёт значение SHORTHAND (он стоит перед дефолтом конструктора в порядке). Это два разных источника для одного и того же случая.
- **trigger**: воспроизводится на любом документе с частично заполненным блоком рядом с shorthand, например `coupling.cbo: {threshold: 50, class: {warning: 1}}`: по Stage 1 `class.error` должен взять дефолт конструктора `ClassCboOptions` (`14/20` → error=20), по буквальному алгоритму Stage 2 — значение shorthand (error=50). Аналогично для `complexity.ccn: {threshold: 5, class: {max_warning: 2}}` (дефолт `ClassComplexityOptions` — `30/50`, по Stage 1 error=50, по Stage 2 error=5) и `coupling.instability`.
- **in_scope**: да
- **anchor**: контракт X22 о fallback-источнике для непоименованной половины полосы внутри частично заполненного блока
- **evidence**:
  - `01-semantics.md:60-62`: «A block that names `max_warning` and not `max_error` still takes the rule's own default for the half it did not name — the shorthand does not reach into a block to fill it.»
  - `02-cure.md:19-27` (псевдокод): «the block's own keys, then the shorthand, then the constructor default — first one that names the key wins».
  - Реальные дефолты уровней подтверждены в коде: `ClassComplexityOptions` — `enabled: true` (дефолты порогов заданы отдельно от shorthand-дефолта `10/20` у самого правила); `ClassCboOptions` — свои собственные дефолты порогов, отличные от `14/20` top-level shorthand-дефолта.
- **verification**: confirmed
- **verification_note**: Прочитаны оба текста плана буквально — это не терминологическая путаница, а прямое расхождение источника значения для одного и того же незаполненного поля одного и того же документа. План не может одновременно утверждать оба правила без выбора одного.
- **fix_direction**: Явно выбрать гранулярность композиции (на уровне блока целиком либо на уровне отдельного поля) и привести Stage 1/Stage 2 к одному тексту; дать полную таблицу для полного/частичного/пустого блока по каждому из пяти классов.

### codex-03

- **reviewer**: codex
- **severity**: HIGH
- **kind**: contract
- **domain**: reliability
- **title**: Пустой `class: {}` не имеет определённой семантики для NPath (и рассинхронизирован между семьями)
- **mechanism**: План утверждает «A `class:` block written beside it must re-enable that level» (00-overview.md) — присутствие ключа `class:` само по себе включает уровень. Но `ClassNpathComplexityOptions` имеет дефолт `enabled: false` (в отличие от `ClassComplexityOptions`/`ClassCognitiveComplexityOptions`, у которых `enabled: true`). Обычное построение `ClassNpathComplexityOptions::fromArray([])` из пустого блока даёт `enabled: false` — то есть план не может одновременно "просто передать блок в `fromArray()` уровня" и "гарантировать переоткрытие" для NPath, не введя специальное контекстное правило (принудительный `enabled: true` при самом факте присутствия ключа `class:`, даже пустого).
- **trigger**: воспроизводится на документе `complexity.npath: {threshold: 50, class: {}}` — пустой блок присутствует (значит, по формулировке плана, "называет" уровень), но не содержит полей, дающих `enabled: true`.
- **in_scope**: да
- **anchor**: контракт присутствия level-блока и его enablement по умолчанию (00-overview.md / 01-semantics.md формулировка "block re-enables the level")
- **evidence**:
  ```php
  // src/Analysis/Evidence/Complexity/ClassNpathComplexityOptions.php:23-27
  public function __construct(
      public bool $enabled = false,
      public int $maxWarning = 500,
      public int $maxError = 1000,
  ) {}
  ```
  Для сравнения — `ClassComplexityOptions` объявляет `public bool $enabled = true` конструктором (дефолт включён).
- **verification**: confirmed
- **verification_note**: Дефолты конструкторов трёх classes-уровня семьи complexity различаются (`ClassComplexityOptions`/`ClassCognitiveComplexityOptions` включены по умолчанию, `ClassNpathComplexityOptions` — выключен); план описывает "one shared shape" для всех трёх без учёта этого расхождения.
- **fix_direction**: Отдельно определить семантику `class: null` (сегодня трактуется как отсутствие блока, см. `RuleOptionKeyRecognition::refuseUnknownKeysInsideLevel`, `$value === null` → return) и `class: {}` (пустой массив), и явно решить: считается ли сам факт присутствия ключа `class` включением уровня, или включение должно исходить только из явного `enabled: true`/заполненного поля внутри блока.

### codex-04

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: Утверждение "M1 (enabled:false) не наблюдаем" верно только для валидного непротиворечивого блока
- **mechanism**: `01-semantics.md` формулирует категорически: наблюдаемый исход `enabled:false` рядом с блоком идентичен исходу без блока — «Nothing a user can observe depends on whether the block was read». Но recognition-проверка (`RuleOptionKeyRecognition::refuseUnknownKeys`/`refuseUnknownKeysInsideLevel`) уже сегодня валидирует форму вложенного блока ДО вызова `fromArray()` — независимо от того, включено правило или нет. Malformed-значение внутри блока (например, нечисловое значение `max_warning`) сегодня даёт `ConfigurationRefusal` уже на этапе recognition, а не "нет эффекта". Кроме того, при переходе на "читать блок сначала" (02-cure.md) внутренний конфликт `threshold` + `max_warning` в одном блоке, сегодня скрытый ранним возвратом `enabled:false`-ветки, впервые дойдёт до `ThresholdParser::parse()` и вызовет refusal «mixed modes», которого раньше не было для документов с `enabled:false`.
- **trigger**: воспроизводится в нормальной работе на невалидном документе, например `complexity.ccn: {enabled: false, class: {max_warning: 'nope'}}` (refusal уже сегодня, до `fromArray()`) и `complexity.ccn: {enabled: false, class: {threshold: 5, max_warning: 2}}` (сегодня — тихо выключено, после X22 — потенциальный same-level refusal при разборе уровня до применения off).
- **in_scope**: да
- **anchor**: контракт M1 «outcome identical either way» (01-semantics.md) vs фактический порядок «recognition → fromArray» в `RuleOptionsFactory::create()`
- **evidence**:
  ```php
  // src/Analysis/Finding/Contract/Rule/ThresholdParser.php:71 (мешанина threshold+graduated)
  if (self::hasAnyWrittenKey($config, $candidates['warning']) || self::hasAnyWrittenKey($config, $candidates['error'])) {
      throw ConfigurationRefusal::atResolvedKey(...);
  }
  ```
  Recognition вызывается в `RuleOptionsFactory::create()` до `fromArray()` (комментарий в коде: «5. Refuse every option key nothing at its depth answers for» перед «6. Create instance using fromArray»).
- **verification**: confirmed
- **verification_note**: Для валидного непротиворечивого блока разницы в findings не найдено (наблюдение плана верно в этом узком случае). На malformed/внутренне противоречивом блоке поведение уже отличается сегодня (recognition-refusal) либо может измениться при переходе на "читать блок сначала".
- **fix_direction**: Сузить формулировку M1 до «для валидного непротиворечивого блока»; отдельно и явно описать поведение malformed/mixed-block рядом с `enabled: false` (валидировать несмотря на off, или явно объявить содержимое полностью неинтерпретируемым и пропустить валидацию).

## Coverage

Проверено Codex и признано чистым:
- Полнота множества из пяти классов, реализующих `HierarchicalRuleOptionsInterface` — шестого не найдено.
- Все пять wrapper-классов и десять level-options классов — конструкторы и `fromArray()`.
- `code-smell.long-parameter-list` (`LongParameterListOptions`) — проверен и признан НЕ шестым случаем: класс плоский (реализует `RuleOptionsInterface`, не `HierarchicalRuleOptionsInterface`), обе независимые группы порогов (`warning`/`error` и `vo-warning`/`vo-error`) читаются на одном уровне последовательно без взаимоисключающего раннего возврата.
- Legacy-алиасы (`maxWarning`/`maxError` camelCase) — сохраняются через `ThresholdParser::legacyKeys`, сама смена композиции их не ломает при условии передачи исходного блока в level-options.
- `scope` в `CboOptions` — имеет отдельную, отличную от threshold-групп семантику (nested `class.scope` выигрывает у top-level `scope` в иерархической ветке); межслойный риск для него учтён в рамках codex-01.
- Потребители помимо самого правила (health, computed metrics, baseline, `@qmx-threshold`) — не найдено чтения порогов выключенного уровня ни одним из них для валидного блока.

## Refuted

- `codex-R01 | LongParameterListOptions — шестой hierarchical случай | refuted: класс плоский, не реализует HierarchicalRuleOptionsInterface, обе группы на одном уровне, взаимоисключающего раннего возврата нет — независимость групп уже покрыта тестом.`
- `codex-R02 | Переход на "читать блок сначала" неизбежно ломает legacy-алиасы maxWarning/maxError | refuted: level-options явно передают алиасы в ThresholdParser; потеря возможна только при ошибке нового merge, а не по построению.`
- `codex-R03 | Health/computed-metrics/baseline читают пороги выключенного wrapper-уровня | refuted: прямого production-потребителя не найдено — правила прекращают обработку на isEnabled(), baseline работает с уже произведёнными findings.`

## Прямые ответы Codex на четыре вопроса задания

1. **Документ, при котором Stage 2 значит НЕ то, что утверждает Stage 1**: `coupling.cbo: {threshold: 50, class: {warning: 1}}` — Stage 1 требует `class.error` = дефолт конструктора (`20`), буквальный псевдокод Stage 2 даёт `error` = значение shorthand (`50`). Настоящее противоречие плана самого с собой (см. codex-02). Аналогично расходятся CCN/cognitive/npath и instability. `LongParameterListOptions` не является пропущенным шестым случаем. Легаси-алиасы сохраняемы. `scope` требует отдельного контракта — его семантика не threshold-группа.

2. **Держит ли различение M1/M2-M3**: для валидного непротиворечивого блока — да, наблюдаемой разницы не найдено (нет consumer'а, делающего пороги выключенного уровня наблюдаемыми). Но общее утверждение "ничего не наблюдаемо" неверно в широком смысле: recognition уже сегодня валидирует вложенный блок ДО `fromArray()` независимо от enablement, поэтому malformed-блок рядом с `enabled:false` уже сегодня даёт refusal, а не "нет эффекта"; и переход на "читать блок сначала" способен впервые сделать наблюдаемым внутренний конфликт `threshold`+`max_warning`, сегодня скрытый ранним возвратом (см. codex-04).

3. **Правило "блок переоткрывает выключенный shorthand'ом уровень" (комплексная семья)**: неразрешённый контрпример — `complexity.npath: {threshold: 50, class: {}}`. План обещает переоткрытие, но чистые дефолты `ClassNpathComplexityOptions` (в отличие от CCN/cognitive) оставляют уровень выключенным (`enabled: false` по умолчанию). Если принудительно включать при самом факте присутствия ключа `class`, то одинаковый пустой блок даёт разный результат в присутствии и отсутствии shorthand — план должен явно выбрать одно поведение (см. codex-03).

4. **Совместимость с ADR 0058**: план совместим только в направлении «нижний слой = shorthand, верхний слой = блок» (это единственное направление, зафиксированное в DoD 01-semantics.md). В обратном направлении план расходится с заявленной аналогией: `RuleOptionThresholdShorthand::unfold()` разворачивает shorthand по каждому слою и текущему path ДО их слияния (`RuleOptionsFactory::deepMerge()`), но top-level shorthand `complexity.*` зарегистрирован как `LONE_THRESHOLD_SHAPE` и не разворачивается в `class`/`callable`-ключи вовсе, а CBO/Instability разворачиваются только в top-level graduated-ключи, не в `class.*`/`namespace.*`. После merge `fromArray()` уже не различает происхождение блока/shorthand по слоям — поэтому нижний по приоритету nested-блок всегда выигрывает у более приоритетного shorthand, что означает потерю layer-priority для этого сценария (см. codex-01).
