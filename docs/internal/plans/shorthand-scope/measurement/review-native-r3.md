# Ревью плана X24, раунд 3 — только новый материал (reviewer: claude)

Предмет — дельта `dd27e1c0..f9874e85` по `docs/internal/plans/shorthand-scope/`
(один коммит `f9874e85`, три документа плана + два отчёта раунда 2, приобщённые
как измерение). Код лечения не написан; всё «после переноса» выведено из
контракта плюс измеренной сегодняшней базы, снятой продуктовым бинарём.

**Дерево не менялось.** `git status` чист. Все пробы — в `mktemp -d`
(`/tmp/x24r3.ooYhAG`), продуктовый бинарь запускался с `--no-cache`, целью
анализа и конфигом из пробного каталога, из него же и с `cwd` в нём.

## Гипотеза брифа — короткий ответ

**Подтверждена на две трети из трёх.**

- «правки закрыли обе HIGH раунда 2» — **подтверждено**: r2-01 и r2-02 закрыты;
- «не открыв новых» — **опровергнуто**: одна HIGH и три MEDIUM, две из них
  подтверждены прогоном на продукте;
- «перенос отказа на шов реализуем без потерь» — **подтверждено с одной
  оговоркой**: шов видит всё, что нужно для сегодняшнего текста и кода возврата
  (проверено по коду и прогоном), потерянных отказов нет, но появляется
  **добавленный** отказ на документе, который сегодня проходит.

## Раунд 2 — закрытость одним словом

| находка                 | статус                      |
| ----------------------- | --------------------------- |
| `claude-r2-01` (HIGH)   | закрыта                     |
| `claude-r2-02` (HIGH)   | закрыта                     |
| `claude-r2-03` (MEDIUM) | закрыта                     |
| `claude-r2-04` (MEDIUM) | наполовину → `claude-r3-05` |
| `claude-r2-05` (MEDIUM) | наполовину → `claude-r3-01` |
| `claude-r2-06` (MEDIUM) | закрыта                     |
| `claude-r2-07` (LOW)    | закрыта                     |

Разворачиваю только незакрытые (`claude-r2-04`, `claude-r2-05`) — они ниже, как
`claude-r3-01` и `claude-r3-05`. Про закрытые, по одной строке проверки:
r2-01 — «шесть из пятнадцати переживают P0» совпадает с моим измеренным числом 6,
и причина трёх строк `callable: × threshold` переписана на верную (величины, не
контракт); r2-02 — C4 получил абзац про перенос, K5 — вторую названную форму, P4 —
запрет садиться раньше P3; r2-03 — таблица reach исправлена на `threshold only`
для трёх complexity-правил, и это ровно то, что в коде: `RuleThresholdKeyGroupRegistry::GROUPS['complexity.*']['']`
— `LONE_THRESHOLD_SHAPE` с пустыми `warning`/`error`, `unfold()` условие 4 его
пропускает, а верхнеуровневый `warning` отвергается распознаванием (прогон:
`Option "warning" is not an option of rule "complexity.ccn"`, exit 3); r2-06 —
цена третьего селектора названа поимённо (колонка, чтение в `Declarations`,
ветка в `pairSide()`, сторож) и шесть недостижимых ячеек перечислены; r2-07 —
заголовок переписан на «128 cells in the radius — 124 judged, plus the four
`refuse` rows».

---

### claude-r3-01

- **reviewer**: claude
- **severity**: HIGH
- **kind**: contract
- **domain**: architecture
- **title**: `claude-r2-05` закрыта только в контракте: `02-cure.md` в теле P3 и P4 по-прежнему предписывает ревизию 2 — «`scope` **не** подаётся на шве» и «композиция в `CboOptions::fromArray()` сохраняется намеренно, с тестом, который падает, если её уронят», — то есть пакет, которым лечение исполняется, велит воспроизвести ровно тот дефект, ради устранения которого правка делалась
- **mechanism**: Правка ревизии 3 прошла по `01-contract.md` и по шапке `02-cure.md`, но не по телу `02-cure.md`. Получились три взаимоисключающих утверждения в двух документах одного плана. `01-contract.md:221-229`: «`coupling.cbo` also accepts a top-level `scope` reaching `class`, and it **is** pushed down like the other two groups… P4 therefore removes that composition when it removes the early returns; P3 declares `scope`'s reach and pushes it». `02-cure.md:13` (шапка ревизии): «`scope` is pushed down rather than excluded». И тут же `02-cure.md:184-186`, в теле P3 — пакета, который это и делает: «`enabled` is declared and pushed the same way. **`scope` is not**: `CboOptions::fromArray()` composes it by key already, and pushing it here too would apply it twice». И `02-cure.md:217-218`, в теле P4: «The `scope` composition inside `CboOptions::fromArray()` is preserved deliberately, with a test that fails if it is dropped along with the branches around it». Исполнитель читает пакет, а не контракт: P3 не объявит reach для `scope`, P4 сохранит композицию и напишет тест, который **закрепит** проигрыш верхнего слоя нижнему, а `01-contract.md:368-370` («a higher layer's `scope` is no longer beaten by a lower layer's block») останется описанием несуществующего поведения. Это ровно тот же класс, что `plan_patching_hazard` в памяти: абзац вставлен, тело не переписано. Мой собственный `fix_direction` в `claude-r2-05` называл вторую половину явно — «внести разнос `scope` в файловый набор P4 как удаляемый, а не как сохраняемый с тестом, иначе P4 будет требовать двух взаимоисключающих вещей», — и эта половина не сделана. Дополнительно противоречие уходит в реестр: при «`scope` is not» файловый набор P3 (реестр + `unfold()` + тесты) вообще не нуждается в правке под `scope`, а при контрактной версии — нуждается, и объём P3 различается.
- **trigger**: исполнение плана — любой агент или человек, реализующий P3/P4 по `02-cure.md`
- **in_scope**: да — обе строки стоят в изменённых ревизией 3 разделах (`02-cure.md:184` внутри хунка, переписавшего абзац вокруг, `:217` — контекст хунка, переписавшего «What changes» P4)
- **anchor**: `docs/internal/plans/shorthand-scope/02-cure.md:184-186`, `:217-218`; против `01-contract.md:221-229`, `:368-370`, `02-cure.md:13`
- **evidence**:
  ```
  $ grep -n "scope" 02-cure.md | grep -v pair-kind
   13: ... `scope` is pushed down rather than excluded;
  184: the top-level key. `enabled` is declared and pushed the same way. **`scope` is
  185: not**: `CboOptions::fromArray()` composes it by key already, and pushing it here
  217: `scope` composition inside `CboOptions::fromArray()` is preserved deliberately,
  ```
- **verification**: confirmed
- **verification_note**: текстовая находка, прогон не нужен; сверено `grep`-ом по обоим документам на `f9874e85`, обе строки присутствуют в текущем состоянии файла, а не только в диффе.
- **fix_direction**: переписать абзац «What changes» в P3 и в P4 целиком (не вставкой), так чтобы P3 объявлял reach `scope` наравне с `enabled`, а P4 **удалял** обе ветки композиции `scope` в `CboOptions::fromArray()` (`:75-78` во flat-ветке и `:94-97` в иерархической) и заводил тест на «верхний слой выигрывает», а не на «композиция цела». Детектор правки: `grep -n "scope" 02-cure.md` не должен возвращать ни «is not», ни «preserved».

---

### claude-r3-02

- **reviewer**: claude
- **severity**: HIGH
- **kind**: contract
- **domain**: reliability
- **title**: Перенос отказа на шов **добавляет** отказ там, где продукт сегодня проходит: `coupling.cbo: {enabled: false, threshold: 30, warning: 10}` сегодня exit 2, потому что `fromArray()` возвращается на ветке `enabled === false` ДО flat-ветки, а `unfold()` условие 3 смеси про `enabled` ничего не знает; C4 при этом утверждает «No new refusal is introduced»
- **mechanism**: C4 (`01-contract.md:78-80`) обещает симметрию: ни один отказ не добавлен и ни один не убран. Правка ревизии 3 закрыла вторую половину (K5 + перенос на шов) и не проверила первую. Сегодня верхнеуровневая смесь спеллингов доходит до `ThresholdParser::parse()` только через flat-ветку `CboOptions::fromArray()`/`InstabilityOptions::fromArray()`, а перед этой веткой стоит более ранний возврат: `if (array_key_exists(ENABLED, $config) && $config[ENABLED] === false) return new self(class: …(enabled: false), namespace: …(enabled: false));` (`CboOptions.php:34-40`, `InstabilityOptions.php:34-40`). Смесь до `parse()` не доходит, а распознавание все три ключа принимает — документ проходит. `unfold()` условие 3 срабатывает на одном лишь факте «в слое написаны и `threshold`, и `warning`» и про `enabled` не спрашивает, поэтому после переноса тот же документ получит exit 3. Мера не косметическая: выключить правило «на время», не вычищая его пороги, — обычная форма в конфиге, и она сегодня работает. Хуже межслойная форма: смесь в пресете, `enabled: false` в `qmx.yaml` выше — сегодня тоже проходит (измерено), а шов по построению судит ОДИН слой и воспроизвести это «прохождение» не может в принципе, потому что `enabled` лежит в другом слое. Структурно план этот класс не ловит: приёмка K5 — «прогон каждого ОТКАЗЫВАЮЩЕГО документа популяции против вылеченной сборки» — смотрит только на убранные отказы, а DoD P3 перечисляет `rule × reached level × block form`, где формы «смесь под выключенным правилом» нет. Побочно измерен и третий вход: смесь в нижнем слое, полностью заменённом скалярным `coupling.cbo: false` сверху, — `mergeRules()` заменяет значение целиком, `unfold()` в `FindingConfigurationResolver` его не видит, а при переносе — увидит (шов между двумя пресетами работает, проверено отдельно).
- **trigger**: воспроизводится в нормальной работе — один слой, без CLI, без пресетов
- **in_scope**: да — новый абзац C4 (`01-contract.md:83-96`) и новый второй пункт K5 (`02-cure.md:66-71`) введены ревизией 3
- **anchor**: `docs/internal/plans/shorthand-scope/01-contract.md:78-96` (C4), `02-cure.md:66-71` (K5), `:213-216` (P4); `src/Analysis/Evidence/Coupling/CboOptions.php:34-40,62-68`, `src/Analysis/Evidence/Coupling/InstabilityOptions.php:34-40,53-62`, `src/Analysis/Finding/RuleConfiguration/RuleOptionThresholdShorthand.php:123-133` (условие 3)
- **evidence**:
  ```
  # /tmp/x24r3.ooYhAG, продуктовый бинарь на f9874e85, --no-cache --workers=0
  off-mix.yaml       coupling.cbo:         {enabled: false, threshold: 30, warning: 10}  -> EXIT=2, отказа нет
  off-mix-inst.yaml  coupling.instability: {enabled: false, threshold: 0.5, max_warning: 0.3} -> EXIT=2, отказа нет
  mix-top.yaml       coupling.cbo:         {threshold: 30, warning: 10}                  -> EXIT=3 Cannot mix ...
  # межслойно: смесь в пресете + enabled:false в конфиге
  --preset=pT3.yaml --config=off.yaml -> EXIT=2, отказа нет
  # третий вход: смесь в пресете A, карта в пресете B, скаляр false в конфиге
  --preset=presetA.yaml --preset=presetB.yaml --config=kill.yaml -> EXIT=2, отказа нет
  # шов между двумя пресетами действительно работает (контроль):
  class.threshold в пресете A + class.warning в пресете B -> EXIT=2 (смена режима между слоями принята)
  те же два ключа в ОДНОМ пресете                          -> EXIT=3 Cannot mix ...
  ```
- **verification**: confirmed
- **verification_note**: прогон продуктового бинаря на `f9874e85` из каталога `mktemp -d`; дерево не трогалось, кеш выключен. Сегодняшнее поведение измерено; поведение «после переноса» выведено из кода условия 3 `unfold()`, которое смесь видит безусловно, — это предсказание, а не измерение, и оно проверяемо одной строкой в P3.
- **fix_direction**: решить в C4 явно, чем считается «отказ не добавлен»: либо шов спрашивает про `enabled` так же, как flat-ветка (и тогда межслойная форма всё равно останется отличной от сегодняшней — это надо назвать, а не умолчать), либо C4 получает оговорку «смесь под выключенным правилом начинает отказывать» и документ вносится в `population-gap.tsv` как ОЖИДАЕМОЕ изменение. И добавить в K5 зеркальный пункт: «лечение не добавляет отказа» с приёмкой «прогон каждого ПРОХОДЯЩЕГО документа популяции», иначе половина инварианта остаётся без оракула.

---

### claude-r3-03

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: reliability
- **title**: Спуск `scope` требует формы, которой у объявляющего механизма нет: `unfold()` условие 2 проверяет `RuleOptionValueForm`, в котором нет случая «закрытый набор слов», поэтому опечатка в верхнеуровневом `scope` уедет в `class` и отказ назовёт уровень, которого автор не писал, — ровно дефект K1, измеренный на обоих сообщениях
- **mechanism**: K1 (`02-cure.md:22-34`) требует: «the push-down must check the target level's declared form and, when it does not fit, leave the value where the author wrote it». Единственная реализация этого требования сегодня — условие 2 `unfold()`: `if (!$group['form']->accepts($value)) continue;`, где `form` — перечисление `RuleOptionValueForm` (`Boolean`, `WholeNumber`, `Number`, `Text`, `NonEmptyText`, `Block`). Закрытого набора слов там нет; он живёт в другом типе — `RuleOptionShape::oneOf()`, и именно им объявлен `scope` в обоих классах (`CboOptions::acceptedOptionKeys()` и `ClassCboOptions::acceptedOptionKeys()`: `RuleOptionShape::oneOf('all', 'application')->orNull()`). Объявить `scope` формой `Text` — значит принять `applicaton` как «подходящую форму» и спустить её в `class`. Распознавание идёт ПОСЛЕ шва (`RuleOptionsFactory::create()`: `deepMerge` — шаг 2, `refuseUnknownKeys` — шаг 5; K1 это и сам констатирует), поэтому отказ будет сформулирован про позицию `class.scope`. Разница измерена: верхний уровень печатает «Option "scope" of rule "coupling.cbo" must be one of …», уровень — «Option "scope" of rule "coupling.cbo" **at level "class"** must be one of …». Для `enabled` тот же спуск безопасен — `Boolean` в перечислении есть. То есть цена спуска `scope` — не «ещё одна строка в реестре», а новый вид объявления в `RuleThresholdKeyGroupRegistry` плюс расширение `RuleOptionThresholdShorthand` плюс новая половина сторожа полноты (сегодня он доказывает совпадение `form` с объявлением класса по трём ключам группы). P0 в этом же раунде цену своего нового селектора называет поимённо (`02-cure.md:93-97`), P3 цену этого — нет.
- **trigger**: воспроизводится в нормальной работе — опечатка в `scope` в верхнеуровневом блоке правила
- **in_scope**: да — спуск `scope` введён ревизией 3 (`01-contract.md:218,221-229`)
- **anchor**: `docs/internal/plans/shorthand-scope/02-cure.md:171-186` (P3, файловый набор и «What changes»), `:22-34` (K1); `src/Analysis/Finding/RuleConfiguration/RuleOptionThresholdShorthand.php:135-140` (условие 2), `src/Analysis/Finding/Contract/Rule/RuleOptionValueForm.php:22-40`, `src/Analysis/Evidence/Coupling/ClassCboOptions.php:92-100`
- **evidence**:
  ```
  # сегодняшние два сообщения, продуктовый бинарь, --no-cache
  scope-top-bad.yaml    coupling.cbo: {scope: applicaton}
    EXIT=3  Option "scope" of rule "coupling.cbo" must be one of "all", "application" or null, got "applicaton".
  scope-class-bad.yaml  coupling.cbo: {class: {scope: applicaton}}
    EXIT=3  Option "scope" of rule "coupling.cbo" at level "class" must be one of "all", "application" or null, got "applicaton".
  # контроль, что Boolean-случай в перечислении есть, а oneOf — нет
  RuleOptionValueForm: Boolean | WholeNumber | Number | Text | NonEmptyText | Block
  RuleOptionShape::oneOf(...) — отдельный механизм, wordsDeclared(): ?RuleOptionWordSet
  ```
- **verification**: confirmed
- **verification_note**: оба сообщения сняты прогоном продукта на `f9874e85`; отсутствие случая «набор слов» в `RuleOptionValueForm` — чтение файла целиком, не `grep`.
- **fix_direction**: в P3 назвать, чем объявляется форма спускаемого не-полосного ключа, и включить это в файловый набор: либо реестр начинает хранить `RuleOptionShape` вместо `RuleOptionValueForm` для таких ключей, либо `scope` получает собственную проверку членства. И записать в K1 третий пункт: для ключа, чья форма — закрытый набор, «не подходит форме» означает «не входит в набор», иначе проверка пройдёт и отказ переедет.

---

### claude-r3-04

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: maintainability
- **title**: Нормативная таблица C2 по-прежнему говорит «Two groups are pushed down» и перечисляет только полосу и `enabled`, тогда как таблица reach в том же документе называет три вещи; единица заполнения `scope` и его поведение при `~` есть только в прозе Q3
- **mechanism**: C2 (`01-contract.md:41-46`) — та самая таблица, по которой реализуется семантика заполнения: колонки «group / fill unit / "already written" means / fallback». Ревизия 3 вынула `scope` из списка «ключи уровня, которых спуск не касается» (`:56-57`), но в таблицу его не внесла, а заголовок над таблицей так и читается «Two groups are pushed down». Таблица reach на `:218` при этом объявляет спуск трёх вещей: «band, `enabled`, `scope`». Единица заполнения `scope` («it is one key, so it fills by key») сказана только в ответе Q3 на `:157-161` — в разделе, который сам называет себя объяснением, а не нормой, и который на `:212` помечен «read the table rather than this summary». Ни один из двух текстов не отвечает на вопрос, который для `scope` встаёт сразу: что значит «уровень уже написал свой `scope`», если уровень написал `scope: ~` (сегодня `ClassCboOptions::parseScope()` читает `~` как «не написан» и возвращает `all`, а `CboOptions::fromArray()` проверяет `isset($classConfig['scope'])`, то есть тоже «не написан»). Плюс остаточный след старой ревизии: `:316` всё ещё ставит `scope` в один ряд с `min_class_count` как не-полосный ключ уровня, отбрасываемый верхней полосой, — сегодня это верно, но после спуска `scope` перестаёт быть примером той же категории, и читатель получит два разных ответа про один ключ.
- **trigger**: исполнение плана — реализация семантики заполнения по нормативной таблице
- **in_scope**: да — список на `:56-57` и таблица reach на `:218` переписаны ревизией 3, таблица C2 на `:41-46` — нет
- **anchor**: `docs/internal/plans/shorthand-scope/01-contract.md:40-57` (C2), `:157-161` (Q3), `:218` (reach), `:316`
- **evidence**:
  ```
  01-contract.md:41  *What the top-level key may fill.* Two groups are pushed down, ...
  01-contract.md:43-46  | group | fill unit | ... |   -> строки: «the band», «`enabled`».  `scope` отсутствует
  01-contract.md:218    | coupling.cbo | ... | band, `enabled`, `scope` |
  01-contract.md:316    ... the same for `scope` and `min_class_count`.
  ```
- **verification**: confirmed
- **verification_note**: чтение документа на `f9874e85`; сегодняшнее чтение `~` для `scope` сверено по коду (`ClassCboOptions::parseScope()` — `$config['scope'] ?? null`, `CboOptions::fromArray()` — `isset()`), прогон не требовался.
- **fix_direction**: внести третью строку в таблицу C2 — `scope` / единица «ключ» / «уровень несёт собственный **написанный** `scope`» / «fallback неприменим», — и переписать `:316` так, чтобы `scope` там стоял как пример из ДО-лечебного измерения, а не как постоянный не-полосный ключ уровня.

---

### claude-r3-05

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: process
- **domain**: reliability
- **title**: DoD блокирующего P0 остался прежним и внутренне несовместим с собственными правками раунда: он требует «distinguishable magnitudes per side» там, где требование (3) само называет шесть недостижимых ячеек, и сверяет прогон P0 с таблицей, которую P0 же и перегенерирует, не назвав, чем таблица выводится независимо от прогона
- **mechanism**: `02-cure.md:105-110` (новое требование 3) устанавливает: у `bool` и у перечисления `scope` третьей величины нет, шесть ячеек остаются NOT OBSERVABLE навсегда, «A DoD stated as a property to reach is not reachable on them, and run #1 is judged without them rather than despite them». Но сам DoD живёт не здесь, а в `03-acceptance.md:39-45`, и он ревизией 4 не тронут: «Distinguishable magnitudes per side, declared rather than computed…». Исполнитель читает раздел «DoD» и получает недостижимое свойство; поправка лежит в другом документе и в другом разделе. Вторая половина острее. Шапка ревизии 4 (`03-acceptance.md:5-11`) признаёт: «P0 … invalidates the very table this file predicts from, so the table is re-derived as part of it». Новая таблица ожиданий (`:155-160`) все три прогона судит формулой «the count the regenerated table gives». А DoD требует, чтобы прогон P0 «matched the number the row-by-row measurement predicts». Если перегенерация таблицы делается прогоном стенда на новых величинах, то предсказание и измерение — один свидетель, и совпадение гарантировано построением; это ровно класс «перечисление и оракул заполнены одним агентом» из `two_witness_enumeration`. План не говорит, чем таблица выводится: вручную из C1-C4 (тогда DoD осмыслен) или прогоном (тогда нет). Третье, мельче: требование (2) оставляет открытым решение — переживёт ли наблюдательный откат `pairSide()`, — а `03-acceptance.md:165-171` уже приняло одну из его ветвей, назвав именно четыре строки `complexity.npath | *.enabled × enabled` несравнимыми построчно между прогонами; при другой ветви решения этот абзац станет ложным. И те же четыре строки одновременно объявлены «permanently NOT OBSERVABLE by the shape of their values» (`:130-135`) — если у стороны нет второй величины, то «величина едет вместе с продуктом» на них нечему; одно из двух утверждений холостое.
- **trigger**: исполнение плана — приёмка P0, от которой зависят все остальные пакеты
- **in_scope**: да — требования (1)-(3) и таблица ожиданий введены ревизией 3/4; сам DoD не менялся, и это и есть находка
- **anchor**: `docs/internal/plans/shorthand-scope/03-acceptance.md:39-45` (DoD P0), `:5-11`, `:130-135`, `:155-171`; `02-cure.md:91-110` (три требования)
- **evidence**:
  ```
  03-acceptance.md:39  **DoD.** Distinguishable magnitudes per side, declared rather than computed, ...
  02-cure.md:105-110   ... These are the six cells that stay NOT OBSERVABLE after P0 ...
                       A DoD stated as a property to reach is not reachable on them ...
  03-acceptance.md:10-11  ... P0 ... invalidates the very table this file predicts from,
                          so the table is re-derived as part of it.
  03-acceptance.md:157-160 | P0 | the count the **regenerated** table gives ... |
  03-acceptance.md:130-135 **Six of the fifteen survive P0.** Four `complexity.npath | *.enabled × enabled` ...
  03-acceptance.md:165-171 ... on four `complexity.npath | *.enabled × enabled` rows the stand's own
                           choice of magnitude moves with the product ...
  # арифметика пятнадцати проверена и сходится: 3 + 3*2 + 2 + 2 + 2 = 15, из них 4+2 = 6 переживают P0
  ```
- **verification**: confirmed
- **verification_note**: текстовая находка; арифметика таблицы пятнадцати пересчитана вручную по строкам таблицы `03-acceptance.md:107-114` и сходится — то есть числа верны, неверна связка «DoD ↔ требования ↔ таблица ожиданий».
- **fix_direction**: переписать раздел DoD P0 в `03-acceptance.md` целиком: (а) свойство формулируется как «различимые величины на сторону там, где форма их допускает, и поимённый список шести, где не допускает»; (б) названо, чем выводится перегенерированная таблица и почему этот вывод независим от прогона, которым она проверяется (иначе DoD не фальсифицируем); (в) требование (2) решается В ПЛАНЕ, а не «P0 решит» — блокирующий первый пакет не может нести открытую развилку, от ветви которой зависит текст уже написанного раздела. И снять одно из двух конфликтующих утверждений про четыре строки npath.

---

### claude-r3-06

- **reviewer**: claude
- **severity**: LOW
- **kind**: contract
- **domain**: reliability
- **title**: Перенос меняет, КАКОЙ отказ печатается для документа с двумя дефектами: сегодня смесь проигрывает распознаванию неизвестного ключа, после переноса выигрывает — обещание «today's message and exit code» верно только для документа, у которого смесь единственный дефект
- **mechanism**: В `RuleOptionsFactory::create()` порядок жёсткий: `deepMerge` (шаг 2, здесь живёт `unfold()`), `RetiredSuppressionOptions::refuseRuleOption` и `refuseMalformedFrameworkKeys` (шаг 3), `refuseUnknownKeys` (шаг 5), `fromArray()` (шаг 6). Сегодня смесь отказывает на шаге 6 — последней; после переноса на шов она станет первой. Измерено: документ со смесью и неизвестным ключом сегодня печатает про неизвестный ключ. Ни K5, ни C4 этого не обещают и не запрещают, но `02-cure.md:69-70` формулирует обещание как «with today's message and exit code», и на таких документах оно не выполняется. Популяция этот класс не покрывает: её формы — одиночные дефекты.
- **trigger**: конфиг с двумя дефектами сразу; встречается при правке руками
- **in_scope**: да — обещание введено ревизией 3 (`02-cure.md:66-71`, `01-contract.md:83-96`)
- **anchor**: `docs/internal/plans/shorthand-scope/02-cure.md:66-71`; `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php:70-126`
- **evidence**:
  ```
  mix-top.yaml          {threshold: 30, warning: 10}              -> Cannot mix "threshold" with "warning"/"error".
  mix-top-unknown.yaml  {threshold: 30, warning: 10, bogus_key:1} -> Option "bogusKey" is not an option of rule "coupling.cbo".
  оба EXIT=3
  ```
- **verification**: confirmed
- **verification_note**: прогон продукта на `f9874e85`; порядок шагов сверен по телу `create()`.
- **fix_direction**: сузить обещание до «для документа, единственный дефект которого — смесь» и внести пару (смесь + неизвестный ключ) в `population-gap.tsv` как строку, чья смена сообщения ожидаема, а не как регрессию.

---

## Coverage — что проверено и признано чистым

- **Q1, половина «текст сообщения воспроизводим»** — чисто. Шов получает всё:
  `unfold($layer, $ruleName, $path)` несёт имя правила и путь; написанный ключ
  `threshold` вычисляется до условия 3; имена группы (`$group['threshold'][0]`,
  `['warning'][0]`, `['error'][0]`) совпадают с аргументами
  `ThresholdParser::parse()` у обоих coupling-правил — сверено построчно
  (`CboOptions.php:66` → `BARE_PAIR`, `InstabilityOptions.php:54-61` →
  `MAX_PREFIXED_PAIR` + legacy, и прогон печатает именно `max_warning`/`max_error`).
  `RefusedPosition::open([$thresholdSourceKey], $thresholdSourceKey)` — один
  сегмент, без имени правила и пути; ни один потребитель в `src/` не печатает
  `segments()`/`display()`, то есть позиция сегодня не рендерится и
  воспроизводить её точь-в-точь не требуется.
- **Q1, межслойный случай** — чисто и подтверждает план. `{threshold}` в одном
  слое и `{warning}` в другом сегодня НЕ отказывает (измерено на верхнем уровне
  и на уровне `class`, оба EXIT=2), и после переноса не будет: `unfold()`
  судит один слой. Контроль: те же два ключа в одном слое — EXIT=3.
- **Q1, потерянных отказов нет.** Проверено, что flat-ветка — единственный
  верхнеуровневый вызов `parse()` у обоих coupling-правил (`grep` по
  `Evidence/Coupling/` и `Evidence/Complexity/`), и что для complexity-семейства
  верхнеуровневая смесь недостижима вовсе: `warning` на верхнем уровне
  отвергается распознаванием (EXIT=3, «is not an option»). Утверждение диффа про
  вторую сборку («с удалённой flat-веткой exit 2, оба ключа молча пропали») не
  перепроверялось прогоном — выводится из кода прямо: без ветки документ уходит
  в иерархический путь, где верхнеуровневые `threshold`/`warning` не читает никто.
- **Q2, семантика `scope` кроме формы** — чисто. `parseScope()` переживает
  перенос: `~` читается как «не написан» в обоих механизмах, спуск пишет
  односложный ключ `scope` (K2 неприменим — нормализация спеллинга тождественна),
  правило «спуск только ДОБАВЛЯЕТ» даёт для `scope` ровно сегодняшний смысл
  (верхний доходит до `class` только если блок его не назвал), `NamespaceCboOptions`
  `scope` не принимает и reach его туда не объявляет, а `ClassCboOptions::fromArray()`
  с ранним `if ($config === []) return new self()` после спуска даёт тот же объект.
- **r2-03 закрыта по коду, не по тексту**: `LONE_THRESHOLD_SHAPE` и условие 4
  прочитаны в исходниках; таблица reach ревизии 3 им соответствует.
- **Арифметика нового материала** — чисто: 15 = 3+6+2+2+2, 6 = 4+2, 128 = 124+4.
- Не проверялось (вне брифа): пол, числа леджера, ось B как неблокирующая, Q2/Q4
  контракта, шесть мест сайта, `deeper-wins`, 128 ячеек назначений, C1-C4 на 757
  клетках, `composer promise-effect` (запрещён брифом).

## Refuted — что я проверял и не подтвердилось

- **«Шов не видит одиночный слой, и перенос потеряет самый частый случай».**
  Опровергнуто: `RuleOptionsFactory::create()` зовёт `deepMerge()` безусловно,
  даже когда CLI-слой пуст, и `unfold()` на пути `''` отрабатывает на конфиге из
  одного файла. Измерено косвенно — `mix-top.yaml` без пресетов и CLI отказывает.
- **«Смесь под отключённым правилом сегодня не отказывает, потому что опции
  выключенного правила не строятся».** Опровергнуто: `--disable-rule=coupling.cbo`
  и `--only-rule=complexity.ccn` оба дают EXIT=3 на `mix-top.yaml`, то есть
  фабрика отрабатывает независимо от выбора правил. Асимметрии «выключено
  флагом» нет; асимметрия есть только по `enabled: false` в самом документе
  (`claude-r3-02`).
- **«Ревизия 3 завысила число NOT OBSERVABLE»**. Не подтвердилось: разложение
  15 → 9 артефактов величин + 6 постоянных сходится с моим r2-01 и с таблицей.

## Вердикт

**NO-GO** — из-за `claude-r3-01` в одиночку: пакеты P3 и P4, которыми лечение
исполняется, предписывают противоположное собственному контракту по `scope`.
Правка на один абзац, но до неё план исполнять нельзя. `claude-r3-02` —
второй блокер по существу: инвариант C4 в сторону «отказ не добавлен» не держится
и у плана нет оракула на эту половину.

## Допущения

1. «После переноса» везде выведено из кода шва плюс измеренной сегодняшней базы;
   вылеченной сборки не существует, и ни одно «после» не измерено.
2. Пресет-файл считается отдельным конфигурационным слоем — проверено
   поведением (смена режима между двумя пресетами принимается, внутри одного
   пресета отказывает), а не чтением загрузчика пресетов.
3. Порядок `deepMerge → распознавание → fromArray` прочитан в теле
   `RuleOptionsFactory::create()` и не проверялся на путях, обходящих фабрику.

---

## Приписка: незакоммиченная правка рабочего дерева

`git status` на момент сдачи отчёта НЕ пуст — параллельный писатель этой же
сессии правит `00-overview.md`, `01-contract.md`, `02-cure.md`, `03-acceptance.md`
(+88/−35). Я ревьюирую закоммиченный `f9874e85`, как предписано брифом; ниже —
что из моих находок эта правка уже закрывает, по каждой отдельно.

| находка        | закрыта незакоммиченной правкой? | чем                                                                                                                                                                                                                                                    |
| -------------- | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `claude-r3-01` | да, целиком                      | `02-cure.md:194-197` — «`enabled` and `scope` are declared and pushed the same way — `scope` by key»; `:229-233` — P4 удаляет композицию                                                                                                               |
| `claude-r3-02` | да, но выбором ДРУГОЙ ветви      | добавлен B4 (`01-contract.md:285-296`) и абзац в K5 (`02-cure.md:73-81`): расширение отказа взято НАМЕРЕННО, с моим же измерением, и объявлено breaking-изменением с отдельной записью                                                                 |
| `claude-r3-03` | **нет**                          | ни `oneOf`, ни `RuleOptionShape`, ни «closed set», ни «word set» в трёх документах не встречаются                                                                                                                                                      |
| `claude-r3-04` | **нет**                          | `01-contract.md:41-46` по-прежнему «Two groups are pushed down» и две строки в таблице                                                                                                                                                                 |
| `claude-r3-05` | наполовину                       | DoD P0 переписан в две части, шесть ячеек исключены поимённо, «only the regenerated table is an oracle». НО чем выводится перегенерированная таблица независимо от прогона, которым она проверяется, по-прежнему не сказано (`03-acceptance.md:52-58`) |
| `claude-r3-06` | **нет**                          | обещание «with today's message and exit code» (`02-cure.md:70`) не сужено                                                                                                                                                                              |

Вердикт по закоммиченному состоянию остаётся **NO-GO**. По рабочему дереву, если
правка будет закоммичена в этом виде, блокеров не остаётся: открытыми будут
`claude-r3-03` (MEDIUM, цена спуска `scope` под K1), `claude-r3-04` (MEDIUM,
нормативная таблица C2), половина `claude-r3-05` (MEDIUM, независимость оракула
P0) и `claude-r3-06` (LOW). Ни одна из четырёх не блокирует старт P0, потому что
все четыре живут в P3 и в формулировках, а не во входе инструмента.

Замечание в сторону, за пределами брифа: B4 объявлен breaking-изменением
(«an entry of its own»), а по `CLAUDE.md` это значит запись `Breaking` в
`CHANGELOG.md` с названием старой и новой поверхности — в файловых наборах
пакетов `CHANGELOG.md` сейчас нет ни в одном.
