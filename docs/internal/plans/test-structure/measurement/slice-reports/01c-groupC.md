# Аудит группы C — `tests/Analysis/Finding/Integration/` (15 файлов)

Прочитаны полностью все 15 файлов. Ниже — категоризация по факту чтения кода,
не по имени файла.

## Таблица

| Путь                                             | SUT                                                                                                                                                                                                                 | Категория                                                                                                                                                            | Правильный каталог                                                                                                      | Дефекты кратко                                                                                                                                                                                                                             |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| AnalysisContextScopeArgumentGuardTest.php        | `AnalysisContext::__construct` 5-й аргумент (`coversProjectScope`)                                                                                                                                                  | control-invariant (AST-скан ВСЕГО src/ на конструкции `new AnalysisContext(...)`)                                                                                    | `tests/Infrastructure/` или отдельный `tests/Architecture/` — не поведение Finding-предмета, а инвариант раскладки кода | Нет утверждения о том, что true — корректное значение хоть где-то; проверяет только «аргумент назван», не «значение измерено»                                                                                                              |
| ChannelCoverageTest.php                          | ~12 конкретных `*Rule::analyze()` + `ChannelDeclarationRegistryInterface` через реальный контейнер                                                                                                                  | integration (реальные rule-классы, стаб-репозиторий, реальный DI-контейнер)                                                                                          | `tests/Analysis/Finding/Integration/` — корректно                                                                       | `#[CoversNothing]` на классе с явным SUT в докблоке — формально верно по PHPUnit, но вводит в заблуждение; `readExcludedFixtureKeys()` дублирует один-в-один хелпер из ChannelEmissionStaticGuardTest (осознанно, см. докблок того файла)  |
| ChannelDeclarationFixtureDriftTest.php           | `ChannelDeclarationRegistryInterface::staticDeclarations()` vs текстовый фикстур `declared.txt`/`excluded.txt`                                                                                                      | control (fixture drift: реестр строится реальным контейнером, но предмет сравнения — синхронность с ручным текстовым файлом)                                         | тот же каталог допустим, но по духу это control, не behavior-тест                                                       | Нет                                                                                                                                                                                                                                        |
| ChannelEmissionStaticGuardTest.php (1059 строк)  | Исходный код ~всех Rule-классов src/ (AST-резолвер `ruleName`/`code` аргументов `new Finding()`)                                                                                                                    | control-invariant (парсит php-parser'ом весь src/, включая собственный self-test резолвера)                                                                          | `tests/Infrastructure/` / статический анализ раскладки, не поведение Finding                                            | Один из крупнейших файлов среза — фактически второй мини-анализатор кода внутри тестов; `skipList()` пуст, значит вся сложность резолвера сейчас не используется ни разу — мёртвый вес до первого реального skip-кейса                     |
| ChannelJudgedMetricDriftTest.php                 | Реальный прогон продукта над внешним корпусом `finding-gate/cases/` (`CorpusCaseRun`), сравнение `finding.metricValue` с каталогом метрик                                                                           | functional (гоняет реальный анализ через `CorpusCaseRun` — это фактически CLI/пайплайн)                                                                              | текущее место приемлемо (пограничный functional/integration тест поведения продукта)                                    | Нет — образцовый тест: измеряет реальное поведение, а не декларации                                                                                                                                                                        |
| ChannelLevelAssemblyTopologyTest.php             | AST-скан src/ (литералы `.class`/`.namespace` и др.) + структурная проверка ключей `staticDeclarations()`                                                                                                           | control-invariant + один self-test резолвера (`itRecognisesARetiredLevelBearingChannelName` тестирует собственный приватный парсер `levelSegmentOf()`, а не продукт) | `tests/Infrastructure/`                                                                                                 | `itRecognisesARetiredLevelBearingChannelName` — тест тестового хелпера, не продукта (тавтология относительно продукта, хотя осознанно документирована как «anti-empty-set» гарантия)                                                       |
| ChannelLevelDeclarationDriftTest.php             | Реальный прогон корпуса (`CorpusCaseRun`) + `ChannelDeclarationRegistryInterface`, сравнение observed vs declared vs `observed-levels.tsv`                                                                          | functional/integration (гоняет реальный анализ)                                                                                                                      | текущее место корректно                                                                                                 | `levelOf()` — собственный парсер subject-строки, намеренно независимый от продукта (документировано и оправдано); `itRecognisesEveryFindingSubjectFormTheCorpusReaches` — self-test покрытия оракула, не продукта                          |
| ChannelLevelRefusalTopologyTest.php              | Текстовый/AST-скан src/ по regex/token_get_all с ручными allow-листами (`LEVEL_READERS`, `LEVEL_WORDING_AUTHORS`)                                                                                                   | control-invariant (топология исходников, pinned списки)                                                                                                              | `tests/Infrastructure/`                                                                                                 | Хрупкий детектор на подстроках (`str_contains('->levelsOf(')` и т.п.) — новая форма вызова тихо проходит мимо; два self-test'а (`itCatches...`) тестируют сами детекторы на синтетических строках, не продукт                              |
| ChannelOrderFixtureDriftTest.php                 | `ChannelUniverseInterface::channels()` порядок vs `order.txt`                                                                                                                                                       | control (fixture drift)                                                                                                                                              | приемлемо                                                                                                               | Нет                                                                                                                                                                                                                                        |
| ChannelPresentationCoverageTest.php              | `ChannelPresentationInterface::presentationFor()` + проверка существования файлов `website/docs/**`                                                                                                                 | control (напрямую использует `is_file()` на docs-дереве — это проверка состояния репозитория, не поведения)                                                          | `website/`-related check в `tests/Reporting/` или отдельный docs-guard, не Finding Integration                          | `DECLARED_CHANNEL_COUNT = 58` захардкожен и продублирован (см. «Дубли»)                                                                                                                                                                    |
| ChannelShapeNotDeclaredByChannelTopologyTest.php | AST-скан src/ (вызовы `ChannelDeclaration::magnitude()/occurrence()` + co-occurring `ChannelShape::` в том же методе)                                                                                               | control-invariant                                                                                                                                                    | `tests/Infrastructure/`                                                                                                 | Явно документированный слепой пятна (реф на приватный хелпер не видна) — честно, но ограничивает ценность                                                                                                                                  |
| ChannelSuggestionTieTest.php                     | `levenshtein()` над жёстко зашитыми строками каналов + `DirectiveNameHints::SUGGESTION_DISTANCE`                                                                                                                    | unit (константа + чистая математика, реальный `ChannelUniverseInterface` используется только для проверки порядка и существования кодов)                             | `tests/Analysis/Policy/Inline/` (ближе к `DirectiveNameHints`, а не к Finding-предмету)                                 | Никогда не вызывает сам `DirectiveNameHints` — пересчитывает Левенштейна вручную и полагается на то, что реализация совпадает с алгоритмом теста; если продукт когда-либо сменит алгоритм подсказки (не Левенштейн), тест этого не заметит |
| ChannelUniverseCoverageTest.php (518 строк)      | `ChannelIdentityInterface`/`ChannelUniverseInterface`, две независимые «свидетеля» (реестр vs прямое чтение rule-классов)                                                                                           | integration (реальный контейнер, реальные rule-классы) — лучший тест среза после ChannelCoverageTest                                                                 | текущее место корректно                                                                                                 | `DECLARED_CHANNEL_COUNT = 58` продублирован с ChannelPresentationCoverageTest без программной сверки между файлами (см. «Дубли»)                                                                                                           |
| ConfigurationErrorClassificationTopologyTest.php | `ChannelDeclaration::asConfigurationError()` — AST-скан src/ (control) **плюс** `ChannelDeclarationCompilerPass`/`RuleExecution` с fixture-классами (`StampRule`, `StampValidator` и т.д.) через `ContainerBuilder` | смешанный: control (2 первых теста — AST/grep скан) + integration (5 следующих — реальный compiler pass и RuleExecution с фикстурными классами)                      | control-часть → `tests/Infrastructure/`; integration-часть остаётся здесь                                               | Один файл смешивает два разных жанра теста под одним заголовком — затрудняет чтение; иначе тесты валидны и полезны                                                                                                                         |
| ConfigurationValidatorSilencingPathsTest.php     | Реальный `CheckCommand` через `CommandTester` над временной директорией с реальными PHP-файлами и `qmx.yaml`                                                                                                        | functional (полный CLI-прогон)                                                                                                                                       | текущее место корректно (либо `tests/Infrastructure/Console/`)                                                          | Нет — образцовый тест поведения silencing-путей                                                                                                                                                                                            |

## Кандидаты в control

Из 15 файлов **9 являются control** (сканируют src/ статическим анализом,
либо сверяют закреплённые текстовые фикстуры без прогона продукта над кодом
пользователя):

- `AnalysisContextScopeArgumentGuardTest.php` — AST-скан src/ на аргумент конструктора.
- `ChannelDeclarationFixtureDriftTest.php` — сверка реестра с ручным текстовым файлом `declared.txt`/`excluded.txt`.
- `ChannelEmissionStaticGuardTest.php` — AST-скан всего src/, второй мини-анализатор кода в тестах.
- `ChannelLevelAssemblyTopologyTest.php` — AST-скан src/ на литералы уровня + self-test парсера.
- `ChannelLevelRefusalTopologyTest.php` — regex/token-скан src/ с pinned allow-листами.
- `ChannelOrderFixtureDriftTest.php` — сверка порядка каналов с `order.txt`.
- `ChannelPresentationCoverageTest.php` — сверка с существованием файлов `website/docs/**` через `is_file()`.
- `ChannelShapeNotDeclaredByChannelTopologyTest.php` — AST-скан src/ на со-встречаемость двух конструкций.
- `ConfigurationErrorClassificationTopologyTest.php` (первые 2 теста из 7) — grep/AST-скан src/ на единственность точки вызова wither-метода.

Ни один из них не относится к «control-invariant, снимаемому рефлексией со
ВСЕХ классов src/» в узком смысле (они целенаправленно ищут конкретные
конструкции/вызовы, а не проверяют инвариант на каждом классе поголовно) —
это, скорее, ручные fitness-function/архитектурные guard'ы поверх
исходников, целенаправленно написанные как ответ на прошлые регрессии
(судя по докблокам — каждый называет конкретный инцидент). Формально они
подпадают под критерий заказчика «сверяет разложенное состояние
репозитория/деклараций» и, видимо, должны быть выведены из `tests/`.

## Дубли и противоречия

1. **`DECLARED_CHANNEL_COUNT = 58`** — захардкожен независимо в
   `ChannelUniverseCoverageTest.php:65` и `ChannelPresentationCoverageTest.php:31`.
   Докблок `ChannelPresentationCoverageTest` прямо признаёт: «Matches
   ChannelUniverseCoverageTest::DECLARED_CHANNEL_COUNT: both read the same
   real container's static declarations, so a divergence between the two
   counts would itself be a regression» — но ничего программно эту сверку не
   делает. Если счётчик поправят в одном файле и забудут в другом, оба теста
   останутся зелёными по отдельности, разойдясь друг с другом. Кандидат на
   вынос константы в общий support-класс (`CorpusCaseRun` или отдельный
   `ChannelFixtures`).

2. **`readExcludedFixtureKeys()`** — идентичный приватный метод в
   `ChannelCoverageTest.php` и `ChannelEmissionStaticGuardTest.php`.
   Осознанно продублирован (докблок второго файла объясняет: «sharing the
   helper would couple two otherwise-independent guards through a third
   file») — не дефект, а сознательный trade-off; упомянуто для полноты.

3. **Триада "Drift/Assembly/Refusal" по уровням** —
   `ChannelLevelAssemblyTopologyTest`, `ChannelLevelDeclarationDriftTest`,
   `ChannelLevelRefusalTopologyTest` действительно проверяют три разных грани
   (синтаксис объявления / соответствие эмиссии / топология отказа), что
   явно и убедительно обосновано докблоками каждого файла со ссылками друг
   на друга. Противоречий не найдено, дублирования по существу нет —
   отмечаю только потому, что задание просило явно проверить такие тройки.

4. **`ChannelCoverageTest` vs `ChannelEmissionStaticGuardTest`** — оба
   проверяют «эмитированный канал резолвится в декларацию», один динамически
   (реальный вызов rule), другой статически (AST рефлексия исходников).
   Докблок `ChannelEmissionStaticGuardTest` прямо объясняет разницу и не
   является дублем по факту (разные failure mode: динамический пропускает
   недостижимые ветки, статический их ловит).

## Допущения

- Не запускал `composer check`/PHPUnit — категоризация «control vs
  integration vs functional» сделана только по чтению кода (использование
  реального DI-контейнера, реальных файлов src/, реального `CommandTester`
  или сравнения с текстовыми фикстурами).
- Понятие «control» применено по букве задания заказчика («сверяет
  разложенное состояние репозитория/деклараций/докстраниц... либо сканирует
  src/ рефлексией/как текст ради инварианта раскладки») — включая AST-сканы
  src/ через `nikic/php-parser`, даже когда они не относятся к
  documentation/qmx.yaml/composer.json буквально, но исследуют исходный код
  как текст ради архитектурного инварианта, а не поведения при вводе
  пользователя.
- Нарушений CLAUDE.md §9 (`#[Test]` + `itXxx`) в этом срезе не найдено — все
  15 файлов используют корректную форму именования тестовых методов.
- Не проверял историю git/blame на предмет того, кто и когда вводил каждый
  guard — оценка дефектов основана только на текущем состоянии кода.
