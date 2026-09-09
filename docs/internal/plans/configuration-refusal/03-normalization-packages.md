# Этап 03, часть 2. Пакеты работ, свидетельство и внешние контракты

Продолжение `03-carrier-and-normalization.md` и `03-normalization-verdicts.md`: контракт
носителя и судьба существующих исключений — в первом, метод нормализации, вердикты,
манифест и **оракул полноты класса 1** — во втором. Здесь не повторяются; нумерация
разделов сквозная. Квалификатор обязателен и не сокращается: полнота доказуема только для
«отказа, который бросает»; по классу 2 («молчаливый приём») оракула у раунда нет
(`00-overview.md`, «Полнота и остаток», и `03-normalization-verdicts.md` §6).

---

## 7. Пакеты работ

Наборы файлов не пересекаются **с одним названным исключением**: P4 правит клаузу
`tests/Infrastructure/Console/Integration/RuntimeConfigurationIsolationTest.php` из набора
01 — довод в P4, ребро названо в обоих планах и стоит строкой в таблице владения
(`01-refusal-evidence.md` §13.2), правило верхнего уровня — `00-overview.md`, «Этапы и
наборы файлов».

### P1 — оракул: `catch`-клаузы, места броска, скрипт остатка

Файлы: `docs/internal/plans/configuration-refusal/measurement/03-catch-clauses.md` (новый),
`.../measurement/03-throw-sites.md` (новый), `scripts/enumerate-refusal-fallback.php` (новый).
Src не трогает. Дерево зелёное.
Зависимости: нет. Параллельно: со всем этапом 01 и 02.

Содержимое (§6, `03-normalization-verdicts.md`):

* **`03-catch-clauses.md`** — исчерпывающее перечисление `catch`-клауз `src/` и `bin/`:
  `путь:строка`, ловимая семья, что делает с пойманным. **Это оракул полноты класса 1** —
  «отказ, который бросает»: всякий такой маршрут либо кончается в одной из клауз, либо
  вылетает наружу. Про класс 2 («молчаливый приём») он не говорит ничего и говорить не
  может: молчаливый приём не бросает, и ни одна клауза его не видит. DoD и «Возврат
  оркестратору» этого пакета опираются на класс 1 и только на него.
* **`03-throw-sites.md`** — места броска четырёх каталогов моего набора: `путь:строка`,
  класс, вердикт (`отказ` / `дефект` / `ловится внутри`), для «ловится внутри» —
  `путь:строка` ловящего `catch`, для «отказ» — форма носителя, источник **и `путь:строка`
  ближайшего `catch` между броском и командой либо слово «нет»**.
* **`enumerate-refusal-fallback.php`** — считает **статически перечислимые броски
  `InvalidArgumentException` вне носителя** по AST `src/` и `bin/`; печатает `путь:строка`
  с разбивкой по владельцу и итоговое число (§6.1 `03-normalization-verdicts.md`).
  Достижимость из CLI он не вычисляет и не обещает — прежняя формулировка обещала её и
  была неверна по способу.

Обе таблицы **и скрипт** несут строку «чем получено и чего этот способ не видит»:
у скрипта она печатается им самим в шапке вывода, чтобы число нельзя было процитировать
отдельно от границ его смысла.

**DoD:**
* число строк `03-throw-sites.md` = число `throw` в четырёх каталогах, снятое той же
  командой, записанной в шапке файла; ни одно место броска не осталось без вердикта;
* у каждой строки-«отказа» колонка «ближайший catch» заполнена (`путь:строка` или «нет»);
  у каждой строки «ловится внутри» назван ловящий `catch`;
* `03-catch-clauses.md` содержит все клаузы, которые находит команда из его шапки, и
  отдельно помечает те, что дают код 2 и 4 (их раунд не трогает);
* `php scripts/enumerate-refusal-fallback.php` отрабатывает и печатает **число «до»**
  вместе со строкой слепых пятен; число, команда и определение популяции (`src/` и `bin/`
  целиком, без `tests/`, `scripts/`, `vendor/`) записаны в шапку `03-catch-clauses.md`;
* **число «после» этот пакет не снимает и не обещает** — оно условие приёмки раунда, и
  снимает его оркестратор на переставшем меняться дереве (§6.1);
* `composer check:code` зелёный (скрипт проходит стиль и PHPStan).

**Возврат оркестратору:** место броска, достижимое вводом и лежащее вне четырёх каталогов
и вне таблицы §4 (`03-normalization-verdicts.md`); строка-«отказ», у которой ближайший `catch` — `CollectionOrchestrator`
или `ChannelDeclarationReader` (тогда затронут `src/Analysis/Run/**`, который не
принадлежит ни одному этапу, — это новый шов).

### P2 — тип-носитель, декларация, артефакты

Файлы: `src/Analysis/Configuration/Contract/Refusal/{ConfigurationRefusal,RefusedPosition,ConfigurationOrigin,ConfigurationSource}.php`,
`docs/internal/modular-architecture-manifest.json`,
`docs/internal/generated/modular-architecture/*`, `qmx.yaml`,
`tests/Analysis/Configuration/Unit/` (существующий каталог, `phpunit.xml.dist` не меняется).
Зависимости: нет. Параллельно: с P1.
Аддитивный: носитель ещё никем не бросается, ни один `catch` не изменён.
**Дерево зелёное, и это проверяется, а не предполагается:** объявленный, но не брошенный
тип ничего не ломает, поэтому аггрегат в этой точке обязан быть зелёным (см. §8 «Порядок»
в `01-refusal-order.md`).
**DoD:** `composer architecture:generate && composer architecture:check` — зелёный, четыре
новые декларации в манифесте; `vendor/bin/phpunit --list-tests | wc -l` вырос ровно на
число новых тестов P2; `composer check:code` зелёный.

### P3 — `Configuration` и `Finding/RuleConfiguration`

Файлы: 6 файлов-бросателей `ConfigLoadException` (§3), `RuleOptionsFactory.php`,
`tests/Analysis/Configuration/{Unit,Integration}/**`,
`tests/Analysis/Finding/RuleConfiguration/Unit/**`,
`tests/Analysis/Finding/Unit/RuleOptionsFactoryTest.php`,
`tests/Analysis/Finding/Integration/RuleOptionKeyNormalizationTest.php`,
`src/Analysis/Configuration/README.md`, `src/Analysis/Finding/README.md`.
Зависимости: **P1** (вердикт и колонка «ближайший catch»), **P2** и пакет 01/P01-2,
ловящий носителя. Параллельно с P4 и P5.
**Оставляет некомпенсированным:** пока 01/P01-2 не ловит `ConfigurationRefusal`,
`CheckCommand` роняет его в `catch (Throwable)` → exit 1 «Unexpected error» вместо 3, а
`BaselineCommand` — в свой `catch (InvalidArgumentException|RuntimeException)` → 1. Красные
тесты — `tests/Infrastructure/Console/**`. Пакет исполняется **после** P01-2, не до.
**DoD:**
* `grep -rn 'ConfigLoadException' src/Analysis/Configuration src/Analysis/Finding` пуст
  (кроме самого файла класса, который удаляет P6);
* ни одно место броска не строит `ConfigurationOrigin` с `locator: ''` — регрессия ровно
  на дефект `ConfigLoadException('', …)`, оставленный X13; оракул — строки P1 по этим
  файлам, а не один пример;
* каждая строка-«отказ» этих каталогов доведена входом до носителя тестом;
* `vendor/bin/phpunit --list-tests | wc -l` вырос ровно на число новых тестов пакета;
* `composer check:code` зелёный.

### P4 — `Policy/Architecture`

Файлы: 9 файлов-бросателей `ArchitectureConfigurationException`, `LayerInstantiator.php`,
`LayerExpansionStage.php`, места из вердикта P1 по этому каталогу,
`tests/Analysis/Policy/Architecture/{Unit,Integration}/**` (в т.ч. 13 проверок
`$e->configPath === 'architecture'` в `AllowValidatorTest`, `ExactAllowCycleValidatorTest`,
`CoverageValidatorTest`, `ArchitectureConfigurationFactoryTest`, `LayersValidatorTest`),
`src/Analysis/Policy/Architecture/README.md`,
**`tests/Infrastructure/Console/Integration/RuntimeConfigurationIsolationTest.php`**
(правка клаузы — довод ниже; импорт снимает 01/P01-7).
Зависимости: те же, что у P3. Параллельно с P3 и P5.

**Один тест чужого набора правится этим пакетом, иначе он остаётся красным до P01-7.**
`RuntimeConfigurationIsolationTest.php:139-150` ловит `ArchitectureConfigurationException`
вокруг `RuntimeConfigurator::configure()`; после конверсии носитель (`RuntimeException`) в
эту клаузу не попадёт, отказ вылетит из `try` и тест упадёт. Файл числится в наборе 01
(P01-7), который по порядку идёт **после** P3–P5, — то есть между P4 и P01-7 аггрегат был
бы красным, а DoD обоих требует зелёного `check:code`. Механизм тот же, которым 02/P2
синхронизирует четыре чужих теста в своём изменении: **клаузу переписывает тот, кто её
ломает**, а 01/P01-7 остаётся снятие импорта. Пересечение наборов здесь законно, потому
что ребро жёсткое и названо в обоих планах (`01-refusal-evidence.md` §13.2).

**DoD:**
* `grep -rn 'ArchitectureConfigurationException\|ArchitecturePreparationException' src/Analysis/Policy/Architecture`
  пуст (кроме самих файлов классов, которые удаляет P6);
* **все тринадцать проверок `configPath` переписаны, а не удалены** — каждая утверждает
  теперь `origin()->source() === ConfigurationSource::Resolved` и `position()`/`summary()`;
  счёт до и после совпадает, иначе утверждение исчезло вместе с полем;
* докблок `ArchitecturePreparationException`-мест переписан (§3): «runtime error» заменено
  на отказ конфигурации с названным чинящим;
* **клауза `LayersValidator.php:225` осталась исключением, которое можно проверить**
  (§4.1 `03-normalization-verdicts.md`). Страж двусторонний, и вторая сторона добавлена
  третьим раундом: закрепление одного лишь тела `try` ловит расширение клаузы и **не**
  ловит то, отчего довод сломается на самом деле, — новый источник
  `InvalidArgumentException` внутри дерева вызовов, где он был бы дефектом продукта и
  молча получил бы код 3 вопреки правилу 2.
  * тело её `try` — по-прежнему **один** вызов конструктора
    `new LayerDefinition(new MembershipSpec(…))` (`$exclude` строится выше `try` и в дерево
    вызовов клаузы не входит);
  * `grep -c 'throw new InvalidArgumentException'` по трём файлам дерева вызовов даёт
    **0 / 1 / 2**: `Layer/LayerDefinition.php` — ноль (бросает только
    `InvalidLayerDefinitionException`), `Layer/MembershipSpec.php` — одно (все списки
    критериев пусты), `Layer/CriterionListValidator.php` — два. Все три — инварианты VO,
    достижимые вводом, и ровно на этом стоит довод §4.1. Любая четвёртая позиция — либо
    новый отказ, который надо нормализовать, либо дефект продукта, который клауза
    замаскирует кодом 3 вопреки правилу 2; и то и другое — **возврат оркестратору**, а не
    правка числа в этой строке. Соседние файлы `Layer/` в дерево вызовов не входят, и
    грепа по каталогу целиком здесь недостаточно: он даёт 22 места и утверждения о клаузе
    не делает;
  * тест доводит пустые критерии `MembershipSpec` до носителя, второй тест утверждает, что
    `LogicException` из того же места носителем **не** становится;
* `composer check:code` зелёный **включая `tests/Infrastructure/Console/`** — то есть
  переписанная клауза `RuntimeConfigurationIsolationTest` ловит носителя;
* `--list-tests` вырос ровно на число новых тестов пакета;
* `composer check:code` зелёный.

### P5 — `Policy/Baseline`

Файлы: `BaselineLoader.php`, **`BaselineChannelRenamer.php`** (единственный потребитель
`BaselineLoadException` кроме бросателя — довод ниже), места из вердикта P1 по этому
каталогу,
`tests/Analysis/Policy/Baseline/{Unit,Integration}/**`,
`tests/Analysis/Policy/Baseline/Functional/**` **за вычетом файлов, утверждающих код
возврата отказа** (граница с 01/P01-4, §9),
`src/Analysis/Policy/Baseline/README.md`.
Зависимости: те же. Параллельно с P3 и P4.
Отдельно: новое коарс-ребро `Analysis.Policy.Baseline → Analysis.Configuration` (`03-normalization-verdicts.md` §5) — до
регенерации манифеста `architecture:check` красный, поэтому регенерация входит в этот пакет.
**Клаузы-потребители — часть набора, а не побочный эффект конверсии.**
`BaselineChannelRenamer.php:236` ловит `BaselineLoadException` и обрабатывает загрузочный
отказ локально; как только `BaselineLoader` начнёт бросать носителя, клауза перестанет
срабатывать, и поведение `baseline:rename-channels` изменится **молча** — grep по местам
броска этого не увидит. Оракул здесь — строки `03-catch-clauses.md`, попавшие в этот
каталог: **каждая обязана получить вердикт**, а не только места броска.

**DoD:**
* `grep -rn 'BaselineLoadException' src/Analysis/Policy/Baseline` пуст (кроме самого файла
  класса, который удаляет P6) — та же машинная проверка, что у P3 и P4, которой у этого
  пакета не было;
* каждая строка оракула `03-catch-clauses.md`, лежащая в `src/Analysis/Policy/Baseline`,
  имеет вердикт: переписана на носителя, названа «ловит внутренний сигнал» либо названа
  недостижимой;
* `baseline:rename-channels` с нечитаемым baseline даёт тот же исход, что до пакета
  (тест — рукотворный, потому что клауза перестаёт срабатывать бесшумно);
* `composer architecture:check` зелёный после регенерации; `--list-tests` вырос;
  `composer check:code` зелёный; `BaselineConflictException` не превращается в носитель —
  рукотворный страж (§10).

### P6 — удаление старых типов, ADR, CHANGELOG

Файлы: удаление `src/Analysis/Configuration/Contract/Exception/ConfigLoadException.php`
(и пустого каталога), `src/Analysis/Policy/Architecture/Contract/ArchitectureConfigurationException.php`,
`.../ArchitecturePreparationException.php`, `src/Analysis/Policy/Baseline/BaselineLoadException.php`;
манифест и артефакты; `docs/adr/NNNN-configuration-refusal-carrier.md` (новый);
`CHANGELOG.md`.
Зависимости: P3, P4, P5 **и** пакеты 01/02, снявшие свои `use`/`catch` этих классов —
`src/Infrastructure/Console/{ConfigurationFailure.php,Command/BaselineRunInterface.php,Command/BaselineCommand.php,Command/CheckCommand.php}`,
`tests/Infrastructure/Console/{Unit/RuleOptionKeyDoorSymmetryTest.php,Integration/RuntimeConfigurationIsolationTest.php}`
(это 01/P01-7), **плюс 01/P01-4**, который переписывает стабы
`tests/Analysis/Policy/Baseline/Functional/BaselineCommandFailureReportingTest.php`:
файл семь раз **конструирует** три удаляемых класса, и признак «конструирует умирающий
тип» в критерии раздела §9 отсутствовал, поэтому ребро названо здесь поимённо, а не
подразумевается через «пакет 01». Отдельно: `src/Analysis/Configuration/Loader/ConfigLoaderInterface.php`
(`use` + `@throws` умирающего класса) и строка `src/Core/README.md:362` готовятся **P3** —
оба нашлись только вторым свидетелем таблицы владения и до второго раунда были ничьими.
Удаление до этого — красная сборка на fatal «class not found».
**DoD:** `grep -rn 'ConfigLoadException\|ArchitectureConfigurationException\|ArchitecturePreparationException\|BaselineLoadException' src tests` пуст;
`composer check` зелёный целиком.

**Число пакетов работ: 6.**

---

## 8. Порядок внутри этапа и что остаётся красным

1. P1 и P2 — параллельны, зависимостей нет, дерево зелёное на обоих.
2. 01/P01-2 (чужой) — ловит носителя.
3. P3, P4, P5 — взаимно параллельны, после шага 2.
4. P6 — после P3–P5 и после 01/P01-7.

**Пакетов, оставляющих дерево красным до следующего, в этапе нет** — но это верно только
вместе с двумя правками, которые второй раунд и потребовал, а прежняя редакция
утверждала голословно:

* **P4 переписывает клаузу `RuntimeConfigurationIsolationTest` в своём же изменении.** Без
  этого тест чужого набора остаётся красным от P4 до 01/P01-7, то есть через весь шаг 3 и
  шаг 4, где у каждого параллельного пакета в DoD стоит зелёный `check:code`.
* **P6 имеет ребро к 01/P01-4**, который готовит стабы
  `BaselineCommandFailureReportingTest`; без ребра удаление классов роняет сборку на
  «class not found» в файле, которого никто не готовил.

Единственное красное окно раунда — между P3–P5 и 01/P01-2, и порядок его именно
предотвращает: P01-2 обязан выйти раньше. Между P2 и P01-2 дерево зелёное и обязано
проверяться (см. `01-refusal-order.md` §8).

---

## 9. Каких файлов не называет ни один пакет

**Полный ответ — таблица владения файлами в `01-refusal-evidence.md` §13**, единая на все
тринадцать файлов плана. Здесь — строки, требующие довода; таблицу этот список не заменяет.

Отвечаю прямо, потому что в прошлом заходе протекло именно тут.

* **`phpunit.xml.dist` не называет ни один пакет — и это решение, а не пропуск.** Все
  новые тесты кладутся в каталоги, уже перечисленные поимённо: `tests/Analysis/Configuration/Unit`
  (стр. 48), `.../Integration` (стр. 68), `tests/Analysis/Finding/RuleConfiguration/Unit`
  (стр. 38), `tests/Analysis/Finding/Unit` (стр. 37), `tests/Analysis/Policy/Architecture/{Unit,Integration}`
  (стр. 34, 64), `tests/Analysis/Policy/Baseline/{Unit,Integration,Functional}` (стр. 35, 65, 80).
  Новый каталог тестов заводить **запрещено**: он не будет исполняться молча. Страж —
  рост `--list-tests` в DoD каждого пакета, а не факт написания теста.
* `tests/Analysis/Finding/Unit/RuleOptionsFactoryTest.php` и
  `tests/Analysis/Finding/Integration/RuleOptionKeyNormalizationTest.php` лежат в
  `tests/Analysis/Finding/`, а не под `RuleConfiguration/`, и по буквальному чтению набора
  из `00-overview.md` в него не попадают. Тест следует своему предмету — классы под тестом
  мои, поэтому файлы **явно взяты в P3**.
* **`tests/Analysis/Policy/Baseline/Functional/**` — граница с 01/P01-4, критерий
  содержательный.** Файл этого каталога, **утверждающий код возврата отказа**, принадлежит
  01/P01-4; остальные — мне (P5). Сегодня критерий отбирает восемь файлов, перечисленных в
  P01-4, и оставляет мне пять (`BaselineCommandOptionSurfaceTest`, `BaselineLifecycleTest`,
  `BaselineMeasuredSetSeamTest`, `BaselineMigrateCommandTest`, `ConfiguredWarningBoundaryMapTest`
  — они утверждают успех либо `assertNotSame(0, …)`). Поимённый глоб развалится от первого
  нового файла, поэтому записан критерий, а список — его сегодняшнее значение.
  Это изменение границы между этапами относительно таблицы `00-overview.md`;
  **ратифицировано обзором** (шов 1), повторного решения не требует.
* `tests/Infrastructure/Console/Unit/RuleOptionKeyDoorSymmetryTest.php` проверяет мою
  `RuleOptionKeyRecognition`, но лежит в наборе 01 — назван зависимостью P6, правят его 01.
* **`tests/Analysis/Policy/Baseline/Support/**` и `tests/Analysis/Finding/Support/**`** —
  общие вспомогательные каталоги, которыми пользуются файлы из обоих наборов. Владелец —
  **P5** для первого, **P3** для второго; 01/P01-2, которому нужен резолвер конфигурации,
  бросающий носителя, кладёт свой дубль в `tests/Infrastructure/Console/`, а эти каталоги
  не правит. Названо, потому что «ничей каталог, которым пользуются двое» — форма, которой
  течёт.
* `website/docs/**` (EN и RU) — коды возврата и форма отказа пользовательские, владелец 01.
  Мой этап пользовательского поведения **в одиночку** не меняет: до пакета 01 нормализация
  наружу не видна.
* `CHANGELOG.md` — общий файл трёх этапов. Мой пакет P6 пишет свои записи; сведение раздела
  делает оркестратор. Конфликт слияния здесь ожидаем и не является дефектом пакета.
* `docs/adr/` — 01 владеет ADR про отмену маршрутизации по команде и семантику `-q`; P6
  заводит **отдельный** ADR про размещение и контракт носителя, чтобы два этапа не правили
  один файл. ADR этапа 01 на него ссылается.
* `scripts/**` — каталог не назван ни одним этапом обзора; `enumerate-refusal-fallback.php`
  заводится P1 и владельца получает там же.

---

## 10. Тестовый план

Что проверяем; тестов здесь нет.

* **Носитель.** Каждая из трёх форм строится и отдаёт ровно то, чем построена. `position()`
  равен `null` у `aboutDocument`/`aboutInput` и не равен у `at`. `summary()` не содержит ни
  рамки, ни префикса, ни кода возврата — это проверка того, что обрамление не просочилось
  в капабилити.
* **`RefusedPosition`.** `closed()` с пустым `accepted()` невозможен (отказ на построении);
  `open()` всегда отдаёт пустой `accepted()` и `isClosed() === false`; `display()` склеивает
  сегменты точками, `written()` равен последнему сегменту.
* **`ConfigurationOrigin`.** `of()` — единственный способ построить; `locator()` равен
  `null` только у `Resolved`.
* **У каждого случая `ConfigurationSource` есть производитель** — страж по таблице P1: для
  каждого из пяти случаев названа хотя бы одна строка-«отказ», строящая его. Это то, чего
  не выдержали `Defaults` и `ComposerJson` (§2), и то, что не даст следующему заходу
  добавить случай «на будущее».
* **Отсутствие пустого источника.** Ни одно место броска не строит `ConfigurationOrigin`
  с `locator: ''` — проверяется перечислением P1, а не одним примером.
* **Каждое место броска с вердиктом «отказ»** имеет тест, доводящий вход до носителя, с
  проверкой формы, источника и позиции. Оракул — таблица P1: число таких тестов равно
  числу строк-«отказов».
* **Каждое место броска с вердиктом «дефект»** остаётся не-носителем. Страж, а не
  формальность: именно тут ломается правило 2 обзора.
* **Каждое «ловится внутри»** — тест, что наружу по-прежнему выходит `InertBaselineEntry`
  (или иной внутренний результат), а не исключение.
* **Строки с непустой колонкой «ближайший catch»** — тест, что после раунда носитель
  доходит до команды, а не превращается в код 4 или в `LogicException`. Для
  `RuleOptionsFactory:364` это уже измерено (§4), для остальных — по факту P1.
* **`BaselineConflictException` сохраняет свой класс и не превращается в носитель** —
  **рукотворный** страж: достижимого входа у него нет (§3), поэтому естественным прогоном
  он не проверяется и легко сметается заодно.
* **Гейт как сторож.** `composer gate -- --reference=<коммит начала пакета>` на P3–P6:
  исходы 0 и 2 корпуса не сдвигаются. GREEN доказывает «не сломали», а не «починили».

---

## 11. Что ломается наружу

Работа, а не пожелание: записи и ADR входят в P6.

* **`CHANGELOG.md`, `Breaking`.** Отказ конфигурации, ранее приходивший из `baseline:*` как
  «Unexpected error» с кодом 1, приходит отказом с кодом 3. Старое и новое названы по коду
  и по тексту шапки; шаги миграции — с точки зрения CI-обёртки, читающей код возврата.
* **`CHANGELOG.md`, `Breaking`.** Ошибка шаблонных слоёв (`ArchitecturePreparationException`)
  переклассифицирована из «runtime» в отказ конфигурации: код и шапка меняются.
* **ADR `docs/adr/NNNN-configuration-refusal-carrier.md`.** Предмет: почему носитель в
  `Analysis/Configuration`, а не в `Core`; почему род выражен тождеством типа, а не полем;
  почему тождество — **первичный**, а не единственный признак кода 3, и какие вторичные
  признаки названы долгом; почему у источника есть случай `Resolved` и при каком условии он
  исчезнет; почему `Defaults` и `ComposerJson` в перечисление не вошли.
* **README четырёх компонентов** (`Analysis/Configuration`, `Analysis/Finding`,
  `Analysis/Policy/Architecture`, `Analysis/Policy/Baseline`) — новый контракт в схеме
  структуры, удалённые классы вычеркнуты. Правятся в своих пакетах, не в P6.

---

## 12. Ловушка, которой нет в обзоре

`qmx-baseline.json` — ратчет. Удаление четырёх классов и добавление четырёх новых сдвигает
измеряемое множество; записи, переставшие совпадать, обнаружатся на `composer selfcheck`
(`architecture:check` — его первая половина, поэтому красный манифест красит и его).
Регенерация ратчета — осознанное действие, а не побочный эффект пакета: переставшая
совпадать запись разбирается, а не перегенерируется молча.
