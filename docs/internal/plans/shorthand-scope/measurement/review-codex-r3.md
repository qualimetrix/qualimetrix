**0 CRITICAL / 2 HIGH / 2 MEDIUM / 0 LOW — вердикт Codex: NO-GO. Гипотеза брифа ОПРОВЕРГНУТА (обе HIGH раунда 2 закрыты не полностью, перенос отказа открывает новый достижимый разрыв). Фактический код возврата Codex: 0.**

# Ревью плана X24, раунд 3 (узкий) — внешний ревьюер Codex

- **reviewer**: codex (внешний CLI `codex exec` через `codex-eval.sh`, модель по умолчанию, `--sandbox read-only`, cwd — корень репозитория)
- **прогон**: exit **0**, промпт передан файлом, 109 строк ответа; предупреждений обёртки нет
- **сырой ответ**: `<scratchpad>`
- **материал**: `git diff dd27e1c0..f9874e85 -- docs/internal/plans/shorthand-scope/` (HEAD `f9874e85`, ветка `x24-shorthand-scope`)
- **verification**: 2 `confirmed`, 2 `unverifiable`; все якоря перепроверены фасилитатором по коду и по файлам плана

## Статус четырёх находок Codex раунда 2 (ответ Codex, перепроверен фасилитатором)

| находка                                                             | статус Codex         | перепроверка фасилитатора                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------------------- | -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `codex-02` (HIGH, `scope` обходит per-layer unfold)                 | **не закрыта**       | **Подтверждено.** `01-contract.md:220-229` теперь требует обратного прежнему: «P4 therefore removes that composition …; P3 declares `scope`'s reach and pushes it», и `:218` в таблице reach даёт `band, enabled, scope`. Но исполнительные пакеты не переписаны: `02-cure.md:184-186` (P3) дословно сохраняет «**`scope` is not**: `CboOptions::fromArray()` composes it by key already, and pushing it here too would apply it twice», а `02-cure.md:217-218` (P4) — «The `scope` composition inside `CboOptions::fromArray()` is preserved deliberately, with a test that fails if it is dropped». Дефект `codex-02` воспроизводится исполнителем, следующим пакетам. Переоформлено Codex как `codex-09`. |
| `codex-06` (HIGH, доP0-числа как oracle)                            | **закрыта**          | **Подтверждено.** Строка грида `03-acceptance.md:156-158` заменена: все три ожидания теперь «the count the **regenerated** table gives», плюс явное `:162-164` — «"Rises" and "0" are not acceptance criteria; revision 3 wrote both, and wrote "15 NOT OBSERVABLE" from a table P0 invalidates». Заголовок 15 ячеек стал условным (`:105` — «at today's magnitudes»), и ложная причина «property of the contract, not of the magnitudes» заменена на противоположную (`:116-123`). Остаток — не число, а недостижимый DoD шести ячеек, вынесен Codex отдельной находкой `codex-12`.                                                                                                                         |
| `codex-07` (MEDIUM, `deeper-wins:` не запрещает эффект вне региона) | **закрыта частично** | **Подтверждено, с уточнением в пользу Codex.** `03-acceptance.md:80-85` действительно добавляет недостающую клаузу — но формулировкой «One clause is still **missing** from that definition and must be added before the branch lands», то есть само определение по-прежнему одностороннее. Список из восьми отрицательных контролей (`:91-95`) не изменён: контроля «в `both` появился лист, которого не писала ни одна сторона» среди них нет. Закрыта регистрация проблемы, не проблема.                                                                                                                                                                                                                  |
| `codex-08` (MEDIUM, `Stand.php` в двух наборах)                     | **закрыта**          | **Подтверждено.** `02-cure.md:3-8` переписан: непересечение обещано только для пакетов, которые «may run AT THE SAME TIME», `Stand.php` явно назван общим владением P0 и P2, и «P2 may not start until P0 has landed». Файл по-прежнему назван в обоих наборах (`:80` и `:150`) — но это ровно второй вариант `fix_direction` раунда 2, а не оставшийся дефект.                                                                                                                                                                                                                                                                                                                                              |

---

### codex-09

- **reviewer**: codex
- **severity**: HIGH
- **kind**: pattern
- **domain**: reliability
- **title**: Решение по `scope` не дошло из контракта в исполняемые пакеты
- **mechanism**: Контракт (ревизия 3) переносит `scope` на per-layer seam и требует удалить layer-blind композицию в `CboOptions::fromArray()`. Пакеты P3 и P4 не переписаны под это решение: P3 прямо ЗАПРЕЩАЕТ push-down `scope`, P4 обязывает СОХРАНИТЬ композицию и добавить тест, падающий при её удалении. Исполнитель, следующий пакетам (а именно они — исполняемая часть плана), воспроизведёт дефект `codex-02` дословно: нижний слой с `class: {scope: all}` продолжит побеждать верхний слой с top-level `scope: application`, что нарушает C3 в межслойной ориентации.
- **trigger**: воспроизводится в нормальной работе — два авторских слоя (свой preset/config плюс config/CLI), нижний с `class.scope`, верхний с top-level `scope`
- **in_scope**: да (якорь — файлы плана в диффе `dd27e1c0..HEAD`)
- **anchor**:
  - `docs/internal/plans/shorthand-scope/01-contract.md:218` (таблица reach), `:220-229` (решение)
  - `docs/internal/plans/shorthand-scope/02-cure.md:184-186` (P3 запрещает), `:217-218` (P4 сохраняет)
- **evidence**:
  > P4 therefore removes that composition when it removes the early returns; P3 declares `scope`'s reach and pushes it. (`01-contract.md:228-229`)

  > **`scope` is not**: `CboOptions::fromArray()` composes it by key already, and pushing it here too would apply it twice. (`02-cure.md:184-186`)

  > The `scope` composition inside `CboOptions::fromArray()` is preserved deliberately, with a test that fails if it is dropped along with the branches around it. (`02-cure.md:217-218`)
- **verification**: confirmed
- **verification_note**: перепроверено фасилитатором дословным чтением всех четырёх мест — требования противоположны буквально, а не по интонации. Шапка `02-cure.md:13` при этом объявляет «`scope` is pushed down rather than excluded», то есть документ противоречит и самому себе, и своей же шапке: тела разделов P3 и P4 не переписаны, хотя шапка заявляет, что переписаны. Это ровно класс `plan_patching_hazard` (правка вставкой абзаца без переписывания тела). Продуктовый код подтверждает, что сохранённый P4-механизм остаётся layer-blind: `src/Analysis/Evidence/Coupling/CboOptions.php:94-97` копирует top-level `scope` в `classConfig` только при `!isset($classConfig['scope'])`, то есть побеждает нижний слой; второе место той же логики — `:73-75` (плоская ветка, копирование безусловное). Оговорка по достижимости, перенесённая из раундов 1-2 и не снятая: shipped-пресеты `strict`/`legacy`/`ci` слова `scope` не содержат, поэтому пару слоёв автор пишет сам — триггер достижим, но не «из коробки». Фасилитатор severity Codex не менял.
- **fix_direction**: переписать тела P3 и P4 целиком под решение контракта (одно решение, а не два): `scope` — отдельная одноключевая группа с объявленным reach `class`, push-down на каждом слое до слияния, и снятие top-level композиции из `fromArray()` вместе с ранними ветками. Проверить grep'ом, что отменённая формулировка исчезла из обоих пакетов, а не соседствует с новой.

---

### codex-10

- **reviewer**: codex
- **severity**: HIGH
- **kind**: contract
- **domain**: reliability
- **title**: Безусловный отказ на шве расширяет множество отказов относительно сегодняшнего продукта
- **mechanism**: C4 требует перенести top-level mix-отказ на шов «с сегодняшним сообщением и кодом возврата». Но сегодня этот отказ достигается не безусловно: `CboOptions::fromArray()` и `InstabilityOptions::fromArray()` обрабатывают top-level `enabled: false` РАНЬШЕ вызова `ThresholdParser::parse()`, поэтому документ `{enabled: false, threshold: 30, warning: 10}` сегодня проходит. Шов же видит смесь раньше и, отказывая безусловно по условию 3, начнёт отказывать там, где сегодня проход. Второй канал того же класса: шов вызывается ДО `RuleOptionKeyRecognition`, поэтому для смеси, где один из ключей имеет невалидный тип, mix-отказ вытеснит сегодняшний shape-отказ — меняется не факт отказа, а его текст и причина.
- **trigger**: воспроизводится в нормальной работе — `coupling.cbo: {enabled: false, threshold: 30, warning: 10}`; второй триггер — `{threshold: 30, warning: "abc"}`
- **in_scope**: да (якорь — C4 и K5 в диффе; продуктовый код лежит в базе `main` и служит доказательством сегодняшнего порядка)
- **anchor**:
  - `docs/internal/plans/shorthand-scope/01-contract.md:83-96` (C4), `02-cure.md:56-70` (K5)
  - `src/Analysis/Evidence/Coupling/CboOptions.php:34-39`, `src/Analysis/Evidence/Coupling/InstabilityOptions.php:35-40`
  - `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php:75`, `:94`, `:126`, `:129`
- **evidence**:
  ```php
  // Explicit top-level enabled: false disables all levels
  if (\array_key_exists(RuleOptionKey::ENABLED, $config) && $config[RuleOptionKey::ENABLED] === false) {
      return new self(
          class: new ClassCboOptions(enabled: false),
          namespace: new NamespaceCboOptions(enabled: false),
      );
  }
  ```
  > K5. The cure must remove no refusal that exists today. (`02-cure.md:56`)
- **verification**: unverifiable
- **verification_note**: перепроверено фасилитатором — все ПОСЫЛКИ подтверждены кодом, но следствие относится к ещё не написанной реализации, поэтому статус `unverifiable` корректен по правилу 2, а не смягчение. Подтверждено: ранний возврат по `enabled: false` стоит в `CboOptions.php:34-39` и `InstabilityOptions.php:35-40` ДО плоской ветки, внутри которой находится единственный top-level вызов `ThresholdParser::parse()` обоих coupling-правил. Подтверждён и порядок в `RuleOptionsFactory`: `deepMerge()` (шов, внутри которого `unfold()`) — строка 75; `refuseMalformedFrameworkKeys()` — 94; `refuseUnknownKeys()` — 126; `fromArray()` — 129. Шов, таким образом, действительно предшествует обеим стадиям recognition. Отдельно проверена посылка «документ с `enabled: false` вообще доходит до шва»: `RuleOptionsCompilerPass` регистрирует Options КАЖДОГО тегированного правила через `RuleOptionsFactory::create()` как DI-фабрику (`src/Infrastructure/DependencyInjection/CompilerPass/RuleOptionsCompilerPass.php:24`, `:43-45`), и пред-фильтра по `enabled` до построения быть не может по построению: отключённость правила выражается ИМЕННО в построенном объекте опций (ранний возврат отдаёт `ClassCboOptions(enabled: false)`), то есть до вызова фабрики она неизвестна. Посылка держит, первый триггер достижим. Отдельно фасилитатор фиксирует, что K5 в этой же ревизии заявлен как ОБЩЕЕ требование («remove no refusal that exists today») с приёмкой «прогон каждого отказывающего документа популяции против вылеченной сборки» — но эта приёмка ловит только УТРАЧЕННЫЕ отказы, не ПРИОБРЕТЁННЫЕ; документ с `enabled: false` в сегодняшней популяции не отказывает и в этот прогон не попадёт. Симметричного требования «лечение не добавляет отказа» в K5 нет. Severity Codex фасилитатор не менял; отмечает, что триггер — обычный документ, а не рукотворный вход, чем HIGH при `unverifiable` и оправдан.
- **fix_direction**: перечислить сегодняшний ПОЛНЫЙ precedence отказов на top level (что отказывает, в каком порядке и что его опережает) и переносить на шов только ту смесь, которая сегодня действительно доходит до `ThresholdParser`; сохранить приоритет shape-отказа над mix-отказом и сегодняшнее поведение `enabled: false`. K5 дополнить симметричной половиной и приёмкой на приобретённые отказы.

---

### codex-11

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: judgement
- **domain**: architecture
- **title**: Первый блокирующий пакет оставляет ключевое решение о магнитудах исполнителю, а приёмка уже написана под один из вариантов
- **mechanism**: Требование (2) P0 оставляет выбор — сохранять ли product-dependent fallback в `pairSide()` — «решением, которое P0 обязан принять явно». От этого выбора зависят схема `pairSide()`, файловый набор, сравнимость строк между тремя прогонами и список исключений oracle. При этом `03-acceptance.md` уже описывает четыре строки `complexity.npath | *.enabled × enabled` так, как будто fallback сохранён. Если P0 решит его убрать — этот раздел приёмки неверен; если оставит — требование «выбор величины не зависит от продукта под проверкой» не выполнено буквально.
- **trigger**: воспроизводится при исполнении P0 — до принятия решения нельзя однозначно определить ни реализацию, ни проверяемый результат первого блокирующего пакета
- **in_scope**: да
- **anchor**: `docs/internal/plans/shorthand-scope/02-cure.md:98-104` (требование 2) против `03-acceptance.md:166-172` (исключение четырёх строк)
- **evidence**:
  > Whether the observational fallback survives is a decision P0 must take explicitly; if it does, the rows where inertness moves with the product are not comparable between runs and must be named. (`02-cure.md:102-104`)

  > on four `complexity.npath | *.enabled × enabled` rows the stand's own choice of magnitude moves with the product … Those four rows are about different documents on run #1 and run #3, so their numbers are not compared row-wise. (`03-acceptance.md:166-172`)
- **verification**: unverifiable
- **verification_note**: перепроверено фасилитатором чтением обоих мест — оба текста наличествуют дословно, и приёмка действительно написана в одной из двух веток («if it does»), не назвав её условной. Обе альтернативы технически возможны, поэтому кодом это не разрешается: `kind: judgement` и правило 1 дают потолок MEDIUM, что Codex и поставил. Смягчающее обстоятельство, фиксируемое фасилитатором: план сам помечает ветвление явно («if it does»), то есть автор о развилке знает — неверна не картина мира, а асимметрия между открытой развилкой в блокирующем пакете и закрытой формулировкой в приёмке.
- **fix_direction**: принять решение до начала исполнения (в самом плане, не в пакете) и согласовать с ним алгоритм `pairSide()`, файловый набор P0, guards и правила сравнения трёх прогонов; либо оставить развилку, но написать раздел приёмки в обеих ветках.

---

### codex-12

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: DoD P0 требует недостижимой различимости для шести ячеек
- **mechanism**: DoD P0 сформулирован без оговорок — «Distinguishable magnitudes per side, declared rather than computed» — тогда как тот же план в этой же ревизии доказывает, что для четырёх `bool`-ячеек и двух `scope`-ячеек третьего значения не существует в принципе (`bool` имеет два значения, одно из которых — дефолт уровня; enum `scope` — `all`/`application`, где `all` — дефолт). Блокирующий пакет формально невозможно принять по его собственному DoD.
- **trigger**: воспроизводится при штатной приёмке P0
- **in_scope**: да
- **anchor**:
  - `docs/internal/plans/shorthand-scope/03-acceptance.md:39-45` (DoD P0)
  - `docs/internal/plans/shorthand-scope/02-cure.md:105-110` (требование 3), `03-acceptance.md:130-135` (те же шесть как постоянные)
- **evidence**:
  > **DoD.** Distinguishable magnitudes per side, declared rather than computed, in the file whose subject this already is; the duplicate documents counted once. (`03-acceptance.md:39-41`)

  > These are the six cells that stay NOT OBSERVABLE after P0 … A DoD stated as a property to reach is not reachable on them, and run #1 is judged without them rather than despite them. (`02-cure.md:107-110`)
- **verification**: confirmed
- **verification_note**: перепроверено фасилитатором. Обе формулировки наличествуют дословно и противоречат друг другу; существенно, что блок DoD P0 в `03-acceptance.md` диффом `dd27e1c0..HEAD` НЕ тронут — правка внесена только в `02-cure.md` и в раздел про пятнадцать ячеек, а сам DoD остался прежним. То есть это не спор двух редакций, а недоведённая правка: требование (3) в `02-cure.md` прямо называет DoD недостижимым, но не переформулирует его там, где он записан. Отягчающее: `02-cure.md:110` говорит «run #1 is judged without them», а DoD `03-acceptance.md:41-45` требует совпадения числа прогона с предсказанием row-by-row таблицы, не называя исключаемых строк, — то есть сам числовой oracle P0 остаётся неоднозначным.
- **fix_direction**: переформулировать DoD P0 на месте: различимость для всех ДОСТИЖИМЫХ ячеек плюс отдельный поимённый oracle `NOT OBSERVABLE` для шести недостижимых, и указать в числовом критерии, по какому знаменателю считается совпадение.

---

## Coverage (что проверено и признано чистым)

- **Вопрос 1 брифа, реализуемость переноса отказа на шов — частично чисто.** Шов располагает `$layer`, `$ruleName`, `$path` и группой из `RuleThresholdKeyGroupRegistry`; сегодняшнее сообщение `ThresholdParser::mixedModesMessage()` строится ровно из трёх имён ключей и не печатает ни имени правила, ни пути YAML-документа (позиция — `ConfigurationSource::Resolved` с `$key = null`). Информации на шве, таким образом, ДОСТАТОЧНО для сегодняшнего текста. Перепроверено фасилитатором: `RuleOptionThresholdShorthand::unfold()` принимает `(array $layer, string $ruleName, string $path)`; `ThresholdParser.php:159-169` — сообщение только из имён ключей; `:83-86` — `RefusedPosition::open([$thresholdSourceKey], $thresholdSourceKey)`. Нечистой осталась только ширина множества отказов — `codex-10`.
- **Межслойный случай `{threshold}` в одном слое и `{warning}` в другом — чисто.** Перенос его не ломает: `unfold()` применяется к каждому слою отдельно ДО слияния, условие 3 смотрит только внутрь своего слоя, поэтому межслойная пара по-прежнему не отказывает. Перепроверено фасилитатором по `unfold():124-133` и по докблоку `RuleOptionsFactory:327-336`.
- **Вопрос 2 брифа, `scope` на шве — чисто по семантике.** При КОРРЕКТНОМ push-down `ClassCboOptions::parseScope()` переживает перенос: строка `application` попадает в тот же class-slot, `~` остаётся молчанием, `all` — тем же дефолтом. Правило «спуск только ДОБАВЛЯЕТ ключи» соблюдается, если `scope` объявлен отдельной одноключевой группой: он добавляется только при отсутствии написанного `class.scope`. Enum-природа ключа продуктовой семантике не мешает; два значения мешают только различимости стенда (см. `codex-12`). Фасилитатор отдельно проверил гипотезу, что сегодняшняя композиция (`isset()`, `CboOptions.php:94`) и шов (`RuleOptionValueWrittenness::isWritten()`) разойдутся на `scope: ~`, — гипотеза ОПРОВЕРГНУТА: `isWritten()` есть буквально `$value !== null` (`RuleOptionValueWrittenness.php:28-31`), а `isset()` на ключе со значением `null` тоже даёт `false`; предикаты на `~` совпадают.
- **`ThresholdParser` вне file set P3** — отмечено Codex как незакрытый хвост: метод `mixedModesMessage()` приватен, а P3 не включает `ThresholdParser.php` в свой набор файлов, поэтому механизм точного переиспользования сегодняшнего wording планом не размещён. Находкой не оформлено.
- Дифф `dd27e1c0..f9874e85` проходит `git diff --check`.
- Не перепроверялось по указанию брифа: пол, числа леджера, неблокирующая ось B, Q2, Q4, шесть мест сайта, определение `deeper-wins` как таковое, семантика назначений на 128 ячейках, C1-C4 на 757 клетках.

## Refuted (гипотезы, которые Codex выдвинул и сам опроверг)

- `codex-R01 | Шов не знает rule/path, чтобы сохранить сегодняшнюю диагностику | refuted: оба передаются в unfold() явно, registry даёт имена группы`
- `codex-R02 | Enum-природа scope не переживёт переноса без правки parseScope() | refuted: при корректном push-down ClassCboOptions получает тот же скаляр в том же слоте`
- `codex-R03 | Межслойные {threshold} и {warning} начнут отказывать после переноса | refuted: условие смеси применяется отдельно к каждому слою до слияния`

## Допущения и границы фасилитатора

- **Дерево не менялось.** Правок не вносилось, git-состояние не трогалось; `composer promise-effect` не запускался.
- **Исполняемых проб не было ни у Codex, ни у фасилитатора.** Sandbox `read-only` делает `mktemp -d` недоступным, поэтому `codex-10` остаётся `unverifiable` до сравнительного прогона двух названных документов на pristine и cured сборках. Это и есть главный непроверенный хвост раунда.
- **Два файла диапазона `dd27e1c0..HEAD` вне материала брифа не оценивались**: `docs/internal/generated/modular-architecture/documentation-ownership.tsv` и `scripts/.../generate-modular-architecture-production-inventory.php` (по +2 строки каждый). Бриф просил только `docs/internal/plans/shorthand-scope/`.
- **Severity Codex не менялась ни в одной находке.** Мои уточнения вынесены в `verification_note` отдельными предложениями.
- **Нумерация** продолжает сквозную нумерацию раундов 1-2: `codex-09` … `codex-12`.
