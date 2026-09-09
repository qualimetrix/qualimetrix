# Этап 02, часть 2. Пакеты работ, тесты и ломающиеся контракты

Предмет, решения и контракт — в `02-computed-metric-keys.md`; здесь не повторяются.
Файл отделён, потому что общий текст этапа перевалил за 400 строк.

## 7. Пакеты работ

Наборы файлов не пересекаются с параллельными пакетами; единственное пересечение — четыре
теста набора 01, за которым стоит жёсткое ребро (P2, обоснование там же). Место этапа в общем порядке — шаг 3 обзора, параллельно
с 03/P3–P5.

### P1 — декларация, формулировки, обход

Файлы: `src/Analysis/Evidence/ComputedMetrics/Configuration/{ComputedMetricEntryKeys,ComputedMetricEntryKeyRecognition,ComputedMetricRefusalWording}.php`,
`docs/internal/modular-architecture-manifest.json` (три новые декларации владельца
`Analysis.Evidence.ComputedMetrics` + строки `consumers` у `RuleOptionKeySet`,
`ConfigKeySpelling` и четырёх типов носителя), `docs/internal/generated/modular-architecture/*`,
`qmx.yaml`, `tests/Analysis/Evidence/ComputedMetrics/Unit/**`.

Зависимости: **только 03/P2** (типы носителя должны существовать). От 01/P01-2 не зависит:
обход ещё никем не вызывается, носитель не бросается.
Аддитивный, дерево зелёное.

**DoD:** `composer architecture:generate && composer architecture:check` — зелёный;
`vendor/bin/phpunit --list-tests | wc -l` вырос ровно на число новых тестов пакета;
`composer check:code` зелёный.

### P2 — принуждение: формы значений, имена, замена исключения носителем

Файлы: `ComputedMetricOverrideReader.php`, `ComputedMetricsConfigResolver.php`,
`ComputedMetricFormulaValidator.php`, `Configuration/ComputedMetricContributionReader.php`,
`Contract/Definition/ComputedMetricDefinition.php` (предикат-читатель имени),
`Health/Configuration/HealthFormulaExcluder.php`,
`tests/Analysis/Evidence/ComputedMetrics/{Unit,Integration}/**`,
`docs/internal/modular-architecture-manifest.json`,
`docs/internal/generated/modular-architecture/*`, `qmx.yaml`,
`src/Analysis/Evidence/ComputedMetrics/README.md`,
`website/docs/reference/health-scores.md` и `.ru.md`,
`website/docs/reference/default-thresholds.md` и `.ru.md`, `qmx.yaml.example`,
`CHANGELOG.md`,
**плюс четыре теста набора 01, закрепляющие мои тексты и мои фикстуры** —
`tests/Infrastructure/Console/Functional/Command/{CheckCommandConfigErrorExitCodeTest,CheckCommandInputValidationTest,ChannelExclusionKeySpellingTest}.php`, `tests/Infrastructure/Console/Integration/RuleExclusionStatsWiringTest.php`
(обоснование ниже).

Содержимое: 17 мест броска с вердиктом «отказ» из `measurement/02-throw-sites.md`
становятся `ConfigurationRefusal` нужной формы; два инварианта VO остаются, а отказ
ставится до конструктора; пять дефектов не трогаются; проверки форм значений (§5 первой
части); порядок §6; две позиции глубины 0 (§2).

**Одна точка строит носителя внутри `catch`, и её вердикт взят из общей классификации, а
не придуман здесь.** `ComputedMetricFormulaValidator.php:62` ловит `SyntaxError`
компиляции выражения и перезаворачивает; по §4.1 `03-normalization-verdicts.md` это
**место броска** — тело `try` есть один вызов, а ловимая семья названа контрактом именно
этого вызова. Требование к исполнителю: клауза остаётся узкой и не расширяется до
`Throwable`/`InvalidArgumentException`, иначе продуктовый дефект получит код 3 вопреки
правилу 2 обзора. Причина исходного `SyntaxError` передаётся носителю параметром
`previous` (контракт §2, `03-carrier-and-normalization.md`), потому что позиция в формуле
живёт только в ней.

Зависимости: P1 **и** 01/P01-2 («ловим носителя»).

**Манифест обязателен именно здесь.** P1 регистрирует только три новых класса; P2 заводит
**новые точные импорты носителя в шесть существующих файлов** (Reader, Resolver,
FormulaValidator, ContributionReader, HealthFormulaExcluder, Definition — по факту
итоговой раскладки), а каждый такой импорт — отдельная строка `consumers` в манифесте,
которую коарс-ребро не разрешает. Отдельно: `HealthFormulaExcluder` принадлежит владельцу
**`Analysis.Evidence.ComputedMetrics.Health`**, у которого ребра в `Analysis.Configuration`
сегодня нет ни одного (проверено по
`docs/internal/generated/modular-architecture/production-cross-owner-imports.tsv`:
все импорты этого владельца идут в `ComputedMetrics.Contract`, `Measurement` и `Core`).
Это **новое коарс-ребро** — та же ситуация, что у 03/P5 с `Policy.Baseline`, и именно оно
роняет `architecture:check`, пока манифест не обновлён и артефакты не перегенерированы.

**Развилка, решённая здесь: четыре чужих теста синхронизируются этим же пакетом, и
красного окна не остаётся.** Четыре теста набора 01 закрепляют сегодняшнее поведение моей
секции: `CheckCommandConfigErrorExitCodeTest` закрепляет **тексты моих отказов** («Invalid
formula syntax», «references unknown metric») вместе с пустым stdout, а
`ChannelExclusionKeySpellingTest`, `RuleExclusionStatsWiringTest` и
`CheckCommandInputValidationTest` подают секцию `computed_metrics` в своих фикстурах
(проверено грепом по `tests/Infrastructure`). Тексты меняю я — значит и правку этих
утверждений делаю я, в том же изменении.

**Пересечение с набором 01 здесь законно, потому что есть жёсткое ребро.** Те же четыре
файла правит потом 01/P01-3 (конверт, `-q`), но **после** меня: непересечение наборов —
правило для пакетов, идущих параллельно, а между 02/P2 и P01-3 порядок жёсткий и назван в
обоих планах. Это тот же механизм, по которому P01-7 правит файлы, которых до него касался
P01-3.

**Отвергнута альтернатива «файлы остаются у 01, а мой DoD спрашивает только свои сьюты».**
Довод за — наборы буквально не пересекаются. Против, решающее: тогда аггрегат
`composer check:code` красный от моего выхода до конца P01-3, а в этом окне параллельно
идут 03/P3–P5 и 01/P01-4, P01-5, P01-6, и **у каждого в DoD стоит зелёный `check:code`**.
Их DoD стали бы невыполнимыми по чужой причине — ровно тот дефект, из-за которого пакет,
корректный по своему DoD, оставляет `main` хуже, чем до и после.

Второй хвост: `ComputedMetricConfigurationException` перестаёт бросаться, но класс ещё
существует, а `src/Infrastructure/Console/{ConfigurationFailure.php,Command/CheckCommand.php}`
на него ещё ссылаются. Дерево от этого не краснеет — мёртвая ветка `catch` компилируется.

**DoD:** `composer architecture:generate && composer architecture:check` зелёный;
**`composer check:code` зелёный** — включая четыре синхронизированных теста, то есть
аггрегат после пакета не красный ни минуты;
`composer docs:check` зелёный; рост `--list-tests` на число новых тестов;
`grep -rn 'ComputedMetricConfigurationException' src/Analysis` даёт только сам файл класса.
Свидетельство «exit 3 у пользователя» — **не моё**: оно принадлежит поверхности 01
(`tests/Infrastructure/Console/**`); мой пакет доказывает, что на границе капабилити
выходит `ConfigurationRefusal`, а `TypeError` недостижим.

### P3 — удаление исключения

Файлы: удаление `src/Analysis/Evidence/ComputedMetrics/ComputedMetricConfigurationException.php`,
`docs/internal/modular-architecture-manifest.json`,
`docs/internal/generated/modular-architecture/*`, `qmx.yaml`,
`src/Analysis/Evidence/ComputedMetrics/README.md` (строка 43 структуры).

Зависимости: P2 **и 01/P01-7**, снимающий `use`/`catch` этого класса в
`ConfigurationFailure.php:8,47` и `CheckCommand.php:10,194`. Пакет назван поимённо, и
`ComputedMetricConfigurationException` внесён в его набор файлов и в его DoD-grep
(`01-refusal-packages.md`, P01-7) — раньше эта зависимость была односторонней: я на неё
опирался, а у P01-7 её не было ни в списке зависимостей, ни в проверке. Удаление раньше —
красная сборка на «class not found».

**DoD:** `grep -rn 'ComputedMetricConfigurationException' src tests` пуст;
`composer architecture:check` зелёный после регенерации; `composer check` зелёный целиком.

**Число пакетов работ: 3.**

## 8. Каких файлов не называет ни один пакет

- **`phpunit.xml.dist` — не называет никто, и это решение.** Все тесты кладутся в
  `tests/Analysis/Evidence/ComputedMetrics/Unit` (стр. 53) и `/Integration` (стр. 73) —
  каталоги, перечисленные поимённо. **Новый каталог тестов заводить запрещено:** он не
  будет исполняться молча, ровно как в прошлом заходе. Страж — рост `--list-tests`
  в DoD пакета, а не факт написания теста. Заметь: тесты новых классов из
  `Configuration/` кладутся в плоский `Unit/`, как уже лежит
  `ComputedMetricContributionReaderTest.php`. Третий уже перечисленный каталог —
  `tests/Analysis/Evidence/ComputedMetrics/Health/Unit` (стр. 54): он тоже разрешён,
  запрет касается только **нового** каталога.
- **`docs/internal/modular-architecture-manifest.json` — назван в P1 и P3**, но строки
  `consumers` пишутся в узлы **чужих** владельцев (`RuleOptionKeySet`,
  `ConfigKeySpelling`, четыре типа носителя из 03/P2). Это те же узлы, которые правят
  03/P3–P5. Конфликт слияния ожидаем и дефектом пакета не является — как `CHANGELOG.md`
  в 03.
- **`qmx-baseline.json`** — ратчет сдвигается тремя новыми классами и одним удалённым.
  Как 03 §12: переставшая совпадать запись разбирается, а не перегенерируется молча.
- **`finding-gate/**` — изменений не требует, и это проверено, а не предположено.**
  Разбор всех 16 корпусных `qmx.yaml` даёт объединение ключей
  `{description, enabled, error, formula, inverted, levels, warning}` и имена
  шести `health.*` плюс `computed.branch-load` — всё принимается после этапа, гейт
  зелёный по построению. Гейт гоняется как сторож исходов 0 и 2.
- **`src/Analysis/Configuration/Preset/{ci,strict,legacy}.yaml` и корневой `qmx.yaml`** —
  секции `computed_metrics` не содержат (`grep -n 'computed'` пуст). Это закрывает
  слепое пятно №1 перечисления M5: отказ не может ударить по продукту через пресет.
- **`src/Infrastructure/Rule/ChannelUniverse.php:265`** — пятый запрет на имя метрики,
  достижимый вводом `InvalidArgumentException` вне всех наборов X14. **Возврата
  оркестратору больше нет: вопрос закрыт решением обзора** — каталог остаётся на общем
  `catch (InvalidArgumentException) → 3` вместе с `src/Infrastructure/Git` и `--output`,
  и **считается** в остатке, который меряет `scripts/enumerate-refusal-fallback.php`
  (владелец — 03/P1). Пакета на него раунд не заводит, и после раунда этот вход
  по-прежнему даёт 3 — не через носителя, а через названный вторичный признак.
- **`src/Infrastructure/Console/**`, `bin/qmx`, `docs/adr/`** — набор 01; предъявление
  отказа и ADR про маршрутизацию мои не трогают, за вычетом четырёх тестов, названных в P2
  поимённо; эта граница **ратифицирована обзором** вместе с прочими расширениями наборов
  и стоит строкой в таблице владения (`01-refusal-evidence.md` §13.2). Своего ADR этап не заводит: решение
  «объявленный набор ключей + отказ на обеих глубинах» уже принято ADR 0049 для секции
  `rules:`, и здесь оно применяется, а не пересматривается.
- **`website/docs/usage/baseline{,.ru}.md` и `website/docs/rules/annotation{,.ru}.md`** —
  правки не требуют, и это **измерено, а не отложено на исполнение**. `grep -n
  'computed\|health'` даёт три строки, все про `@qmx-ignore health.cohesion` и про
  висячую ссылку на удалённую метрику: страницы говорят, что секция определяет метрику, и
  не описывают ни одного ключа записи. Этап меняет ключи записи и тексты отказов — то, чего
  на этих страницах нет.
  **Условное владение снято третьим раундом.** Прежняя редакция писала «если правка нужна,
  файл принадлежит P2» — а `website/docs/**` целиком принадлежит 01/P01-8, который идёт
  параллельно, и при срабатывании условия два пакета правили бы один файл мимо таблицы
  владения. Владелец безусловно **P01-8**. Если исполнение P2 всё же покажет, что правка
  нужна, это **возврат оркестратору** — строка отдаётся P01-8, а не правится на месте.

## 9. Тестовый план

Что проверяем; тестов здесь нет. Оракул — таблица `m5-computed-keys.md` §1.

**Регрессия на два измеренных входа:**

1. `computed_metrics: {computed.x: {formula: "1+1", levels: {class: {warning: 1}}}}` —
   вместо `TypeError` поднимается `ConfigurationRefusal` с позицией
   `computed_metrics.computed.x.levels`; отдельным утверждением — что `mapLevel()`
   не вызывается (проверяется формой отказа, а не мокой).
2. `computed_metrics: {health.complexity: {warnign: 60, erorr: 30}}` — отказ на первом
   неизвестном ключе в порядке документа, `segments()` =
   `[computed_metrics, health, complexity, warnign]` (правило нарезки §2),
   `written()` = `warnign`, `accepted()` = девять имён, `isClosed()` истинно.

**По кейсу на каждый ключ, который сегодня молчит** (12 позиций): неизвестный ключ
глубины 1; неизвестный ключ внутри `formulas:` (`clas` — не уровень; `callable` — уровень
вне отчётных, два разных предложения); `formula: 5`; `formulas: "1+1"` и `formulas: 5`
(**отказ обхода**, позиция `…​.formulas`, `open()`); элемент `formulas:` не строка
(отказ читателя); `levels: "class"`; `levels: [clas]`; `description: 5`;
`inverted: "yes"`; `threshold: abc`; `warning: abc` и `error: abc` — **отдельно и
обязательно**, потому что сегодня они не игнорируются, а снимают порог и меняют код
возврата; `enabled: "false"`.

**Глубина 0, две позиции:** запись-`null` (`health.complexity:` без значения) —
**принимается**, поведение как без записи; запись-`false` — отказ с подсказкой про
`{enabled: false}`; запись-не-карта (`computed.x: 5`); имя не по грамматике; имя,
оканчивающееся словом уровня; имя, оканчивающееся стратегией агрегации;
`health.<неизвестное>` — **один** отказ на оба сегодняшних пути (с формулой и с
`enabled: false`), позиция `computed_metrics.health.<x>`, `isClosed()` истинно,
`accepted()` = **шесть** коротких имён из `HealthDimension::cases()` (сегодня печатается
пять — изменение объявлено в §10); имя вне `health.*` — позиция `computed_metrics.<имя>`,
`isClosed()` ложно, `accepted()` пуст.
Отдельный кейс на различимость: `health.bogus` и `bogus` дают позиции разной длины и
разной замкнутости — это и есть проверка того, что смешанного пространства не осталось.

**Порядок и границы:**

- неизвестный ключ в записи с `enabled: false` отказывает;
- негодная форма `formulas:` в записи с `enabled: false` отказывает (обход, §3.2);
- негодное **листовое** значение известного ключа в записи с `enabled: false` **не**
  отказывает — тест закрепляет принятую цену §6, а не сожалеет о ней;
- `threshold: null`, `warning: null`, `error: null` продолжают означать «умолчания»;
- `bogus_key` отвечается как `bogusKey` — закрепление того, что этап не обещает
  авторского написания;
- каждое из 17 мест броска с вердиктом «отказ» доводится входом до носителя с проверкой
  формы (`at`), источника (`ConfigurationSource::Resolved`) и позиции; число таких тестов
  равно числу строк-«отказов» таблицы;
- **у каждой строки-«отказа» с непустой колонкой «ближайший `catch` наружу»** — тест, что
  носитель доходит до команды, а не превращается в код 4 (`CollectionOrchestrator`) или в
  `LogicException` (`ChannelDeclarationReader`);
- пять мест с вердиктом «дефект» остаются не-носителем — страж правила 2 обзора,
  а не формальность;
- гейт как сторож: `composer gate -- --reference=<коммит начала пакета>` на P2 и P3 —
  исходы 0 и 2 корпуса не сдвигаются.

**Источник отказа.** Все отказы этапа несут `ConfigurationSource::Resolved`:
`ComputedMetricContributionReader` берёт вклады из `ConfigurationDocument::contributions()`,
которые метки источника не несут (довод `03-carrier-and-normalization.md` §2, случай
`Resolved`). Отвергнуто протягивать провенанс через `ConfigurationDocument` — это
контракт чужого владельца и отдельный заход, названный там же.

## 10. Что ломается наружу

Работа, а не пожелание; входит в P2, кроме строки структуры README, которая в P3.

- **`CHANGELOG.md`, `Breaking`.** Ключ записи `computed_metrics`, ранее молча
  игнорировавшийся, теперь отказывает с кодом 3. Названы обе стороны: старое поведение
  (ключ отброшен, exit 0, отчёт как без ключа) и новое (отказ с перечнем допустимых
  ключей). Шаги миграции — с точки зрения владельца существующего `qmx.yaml`:
  прогнать `bin/qmx check` и вычистить то, на что он отказал.
- **`CHANGELOG.md`, `Breaking`.** Негодная форма значения известного ключа теперь
  отказывает. Отдельной строкой — `warning`/`error` с нечисловым значением: они меняли
  код возврата молча, и конфигурация, полагавшаяся на это, начнёт отказывать.
- **`CHANGELOG.md`, `Changed`.** Отказ на неизвестном `health.<x>` перечисляет **шесть**
  измерений вместо пяти: `health.overall` переопределяем, а из сегодняшнего перечня он
  выпадал, потому что список строился из под-измерений.
- **`CHANGELOG.md`, `Fixed`.** `levels:` в форме карты роняло прогон, и **три команды
  роняли его по-разному**: `check` — exit 1 с пустым stdout и шапкой `Unexpected error`,
  `directives` и `debug:layer-assignment` — exit **255** с трассой в оба потока
  (`m6-routes-merged.md:60-61`, маршруты 9 и 10). Миграционная ценность записи — именно в
  том, какой код обёртка видела раньше, поэтому названы все три, а не один.
- **README владельца** — три новых класса в схеме структуры, удалённый класс вычеркнут,
  раздел про чтение секции называет объявленный набор ключей.
- **`website/docs/reference/health-scores.md` + `.ru.md`** — раздел про
  `computed_metrics`: список принимаемых ключей записи и `formulas:`, фраза, что всё
  остальное отказывает, и перечень шести переопределяемых `health.*`.
  **`threshold` сайтом не документирован вовсе** (слепое пятно, названное в M5 §4):
  решение — документировать его как принятый ключ вместе с правилом взаимоисключения с
  `warning`/`error`, потому что публиковать отказ «допустимо: …, threshold, …» и не
  описывать `threshold` — завести третий источник расхождения.
- **`website/docs/reference/default-thresholds.md` + `.ru.md`** — упоминание секции
  сверяется с новым перечнем; EN и RU правятся одновременно.
- **`qmx.yaml.example:331-346`** — закомментированный пример секции: убедиться, что он
  не содержит ключа, который начнёт отказывать.
