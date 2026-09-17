# Stage 03 plan review — external Codex CLI findings

Reviewed material: `docs/internal/plans/test-structure/03-tooling-tests.md` and
`measurement/stage-03/*` at commit `194dbfea` on `x29-stage-03-tooling-tests`
(one commit on top of `main` `c49fc0b4`). Plan review — nothing implemented,
no test moved. Read-only; nothing in the repository was modified.

### codex-01

- **reviewer**: codex
- **severity**: HIGH
- **kind**: contract
- **domain**: reliability
- **title**: Acceptance-команда не запускает полный directive-control
- **mechanism**: DoD требует, чтобы `composer directives:controls` был запущен
  "в полном объёме, один раз, без `--only`" при приёмке. Но
  `composer.json:163-167` определяет `directives:controls` как
  `["@directives:controls:coverage", "php scripts/directive-audit-controls.php"]`,
  а `directives:controls:coverage` на текущем `main` уже красный (baseline.md:
  "already red, so its exit code is not the oracle" — `2 cases guarded by
  nothing`, exit 1). Composer останавливает цепочку скриптов на первом
  ненулевом коде, поэтому `php scripts/directive-audit-controls.php` — тот
  самый full control, который якобы докажет чувствительность к
  переименованию — физически не выполняется.
- **trigger**: воспроизведётся, если приёмочный шаг DoD исполнить буквально на
  текущем baseline (два pre-existing "guarded by nothing").
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:196-200`
- **evidence**:
  ```
  03-tooling-tests.md:196-200:
  directives:controls:coverage reports 0 not as declared ... and
  composer directives:controls is run in full, once, without --only

  composer.json:163-167:
  "directives:controls": [
      "Composer\\Config::disableProcessTimeout",
      "@directives:controls:coverage",
      "php scripts/directive-audit-controls.php"
  ],
  ```
- **verification**: confirmed
- **verification_note**: Проверено фасилитатором независимо от codex-CLI:
  `grep -n -A4 '"directives:controls"' composer.json` подтверждает состав
  цепочки; `grep -n "exit(" scripts/directive-audit-coverage-control.php`
  показывает несколько путей `exit(1)`, включая ветку "guarded by nothing" —
  описанную в `baseline.md` как pre-existing. Composer по умолчанию
  прерывает `run-script`-последовательность на первом ненулевом коде выхода.
  Реальный прогон `composer directives:controls` не выполнялся (запрет
  записи не обязателен для чтения кода, но следующий шаг контроля создаёт
  временные артефакты, и это не проверялось живым прогоном ни codex, ни
  фасилитатором) — вывод сделан по чтению кода обоих скриптов.
- **fix_direction**: Дать полному control (`directive-audit-controls.php`)
  отдельную исполняемую acceptance-команду, не зависящую от зелёного
  `directives:controls:coverage`; coverage-шаг проверять отдельно, сравнивая
  текст вывода с ожидаемым (как и предписывает сам план), а не полагаясь на
  код возврата составной цепочки.

### codex-02

- **reviewer**: codex
- **severity**: HIGH
- **kind**: contract
- **domain**: reliability
- **title**: Формулировка шага 1 "two-step plant" описывает пробник не там, где дыра
- **mechanism**: План говорит: "Put an orphan probe test in a new directory
  **under the old scan scope**. `composer architecture:check` must stay
  **green** — that is the hole, demonstrated." Но литерал старого scan scope
  (`scripts/generate-modular-architecture-test-inventory.php:349`) — это
  `git ls-files -- tests governance scripts/tests …` — если пробник
  действительно попадает "под этот scope" (например, в `scripts/tests/`), он
  будет обнаружен генератором и, скорее всего, провалит `classifyOwner()`/
  `validateInventory()` как "Unclassified test artifact" — то есть даст
  красный, а не зелёный `architecture:check`. Дыра демонстрируется
  пробником, который лежит **вне** старого литерала (например,
  `scripts/promise-effect/tests/`), а не "под" ним — формулировка плана
  меняет местами "внутри" и "снаружи" ровно там, где это критично для
  корректности демонстрации.
- **trigger**: воспроизведётся, если исполнитель буквально положит orphan
  probe в директорию, покрытую литералом (`scripts/tests/...`), ожидая
  увидеть зелёный `architecture:check`, и не увидит.
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:214-218`
- **evidence**:
  ```
  03-tooling-tests.md:211-218:
  1. Put an orphan probe test in a new directory under the old scan scope.
     composer architecture:check must stay green — that is the hole,
     demonstrated. With the root declared, G2 must be red — that is the
     backstop, demonstrated.

  generate-modular-architecture-test-inventory.php:349:
  ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '--',
      'tests', 'governance', 'scripts/tests', ...]
  ```
- **verification**: confirmed
- **verification_note**: Прочитан код генератора вокруг строк 348-370 и
  389-434 (scan scope → `classifyOwner()` → `validateInventory()`): файл,
  реально находящийся "under the old scan scope" (внутри перечисленных
  путей), попадает в `$worktreePaths` и подвергается тем же громким
  проверкам. Дыра — это как раз файл ВНЕ этого списка (например,
  `scripts/promise-effect/tests/OrphanProbeTest.php`, поскольку литерал
  называет буквально `scripts/tests`, а не `scripts/**`). Физический plant в
  рабочее дерево не проводился (read-only запрет); проверка — по чтению
  исходного кода генератора, что достаточно, поскольку логика ветвления
  безусловна и не зависит от состояния окружения.
- **fix_direction**: Переписать шаг 1, явно указав, что пробник кладётся в
  директорию, которую старый scan-scope литерал НЕ покрывает (например,
  будущий `scripts/promise-effect/tests/`), а не "под" старый scope; отдельно
  проверить, что пробник внутри буквального `scripts/tests/` действительно
  даёт другой (красный) результат, чтобы не спутать эти два случая.

### codex-03

- **reviewer**: codex
- **severity**: HIGH
- **kind**: contract
- **domain**: architecture
- **title**: `generate-rename-enumeration.php::surfaces()` найден измерением стадии, но не назначен ни одному пакету и не входит в DoD
- **mechanism**: `scripts/generate-rename-enumeration.php::surfaces()` держит
  `'tests' => ['roots' => ['tests', 'governance'], ...]` — без `scripts`/
  `tools`. Само измерение стадии (`registration-addresses.md`, раздел
  "Addresses CLAUDE.md's table does not name") называет именно этот адрес и
  прямо предупреждает: перенос тестов в `scripts/`/`tools/` "reads … as a
  pure drop in the `tests` surface's count with no compensating add
  anywhere" — то есть после переноса и штатной регенерации
  `finding-gate/enumeration-renames*.tsv` инструмент молча зафиксирует
  уменьшение населения `tests`-поверхности как норму. Ни таблица "Work
  packages" (P0-P7, столбец "Also owns"), ни раздел DoD, ни раздел "Files of
  this stage's subject that no package owns" не называют этот файл. План
  просто не подхватил находку собственного измерения.
- **trigger**: воспроизведётся после первого же переноса файлов и штатной
  регенерации `enumeration-renames*.tsv`/`composer enumeration:renames:check`.
- **in_scope**: да
- **anchor**: контракт `scripts/generate-rename-enumeration.php::surfaces()`
- **evidence**:
  ```
  generate-rename-enumeration.php:56-61:
  'tests' => [
      'roots' => ['tests', 'governance'],
      'files' => [],
      'excludeDirs' => ['__pycache__'],
      'excludeFiles' => [],
  ],

  registration-addresses.md:75-76:
  scripts/generate-rename-enumeration.php::surfaces() ... confirmed to omit
  both scripts and tools.
  ```
- **verification**: confirmed
- **verification_note**: Проверено фасилитатором: `sed -n '30,62p'
  scripts/generate-rename-enumeration.php` подтверждает, что `roots` для
  `'tests'` — ровно `['tests', 'governance']`, без `scripts`/`tools`. Сверено
  с таблицей "Work packages" в плане (`03-tooling-tests.md:233-242`) и
  разделом "Files of this stage's subject that no package owns"
  (`:244-248`) — файл `generate-rename-enumeration.php` там не упомянут ни
  разу.
- **fix_direction**: Назначить правку `surfaces()` конкретному пакету (или
  явно вынести за скоуп с обоснованием, как это сделано для других
  адресов), и добавить в DoD проверку "до/после" значения `tests`-surface
  count, а не полагаться только на freshness-сравнение регенерированного
  файла.

### codex-04

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: Число 16 подано как "the population", но является пересечением двух узких свипов, а не полным множеством
- **mechanism**: Оба свидетеля используют один и тот же критерий отбора
  файла-кандидата — литеральная подстрока `scripts`/`tools`/имя
  инструментального неймспейса в самом тесте. Файл, достигающий SUT
  косвенно (через helper без такой строки в теле теста, Composer-алиас или
  подобное), невидим обоим одинаково — согласие свидетелей в этом случае не
  говорит ничего об отсутствии такого файла (сам `population.md:123-129`
  формулирует это как "the blind spot both share, and it is not small").
  Тем не менее основной текст плана и DoD оперируют "16" и "all 16 move" как
  установленным полным множеством стадии.
- **trigger**: проявится, если среди 616 непрочитанных из 632 `*Test.php`
  файлов существует tooling-тест с непрямым (helper/alias/config-driven)
  достижением SUT.
- **in_scope**: да
- **anchor**: контракт полноты популяции stage 03
- **evidence**:
  ```
  population.md:123-129:
  Neither witness read all 632 files. Both narrowed to files carrying
  the literal substring scripts, tools or a tooling namespace. ...
  agreement between them says nothing about it.
  ```
- **verification**: confirmed
- **verification_note**: Фасилитатор подтвердил `632` независимо:
  `git ls-tree -r --name-only c49fc0b4 -- tests` + фильтр `*Test.php`.
  Конкретный 17-й файл ни codex, ни фасилитатором не найден за отведённое
  время; узкая истинная формулировка (по самому измерению) — "16
  PHP-файлов, попавших хотя бы в один из literal/namespace-каналов двух
  свидетелей", а не "полная популяция репозиторных tooling-тестов".
- **fix_direction**: Либо явно сузить формулировку DoD/плана до "16
  найденных двумя каналами файлов", убрав слова, подразумевающие
  доказанную полноту, либо потратить бюджет на непрямой (non-literal)
  канал и явно закрыть/задокументировать остаточный риск как принятый.

### codex-05

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: architecture
- **title**: D3-1 опирается на неверную арифметику ("три из пяти" вместо измеренных "четыре из пяти") и не рассматривает гибридный вариант
- **mechanism**: Раздел D3-1 отклоняет "один root на директорию инструмента"
  словами: "a convention that holds for three tools out of five is worse than
  one that holds for all". Собственная таблица измерения
  (`population.md`, "PSR-4 compliance of the tool directories") даёт
  **четыре** совместимых директории из пяти (`tools/phpstan`,
  `scripts/directive-audit`, `scripts/directive-audit-controls`,
  `scripts/finding-gate`) и одну несовместимую (`scripts/promise-effect`).
  Кроме числовой ошибки, план сравнивает только два варианта — "root на
  тест-директорию для всех" vs "root на tool-директорию для всех" — и не
  рассматривает третий: смешанную схему (tool-root для четырёх совместимых
  инструментов, test-root только для `promise-effect`), хотя измерение само
  показывает, что вложенные root'ы безопасны (`NamespacePathAllowList`
  выбирает самый длинный совпадающий root, `TestTree::testFiles()`
  дедуплицирует по пути).
- **trigger**: воспроизведётся, если D3-1 исполнить как единственно
  возможное решение без пересмотра сравнения.
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:36-46`
- **evidence**:
  ```
  03-tooling-tests.md:44-46:
  a convention that holds for three tools out of five is worse than one
  that holds for all.

  population.md:88-95 (table):
  tools/phpstan                    0 violations  yes (already is)
  scripts/directive-audit          0 violations  yes
  scripts/directive-audit-controls 0 violations  yes
  scripts/finding-gate             2 violations  yes (classless helpers)
  scripts/promise-effect          13 violations  no
  ```
- **verification**: confirmed
- **verification_note**: Таблица в `population.md` прочитана буквально:
  четыре строки помечены "yes" в столбце "can be an autoload-dev root
  as-is?", одна — "no". План говорит "три из пяти" — расходится с
  собственным измерением на единицу. Гибридный вариант измерением не
  исключён явно (безопасность вложенных root подтверждена тем же
  документом), но и не обсуждён как альтернатива в плане.
- **fix_direction**: Либо исправить формулировку на "четыре из пяти" и явно
  обосновать, почему единая конвенция всё равно предпочтительнее гибридной
  (например, по числу регистрационных адресов или по стоимости
  сопровождения), либо сравнить гибридный вариант по тем же критериям, что и
  два рассмотренных.

### codex-06

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: judgement
- **domain**: architecture
- **title**: Принятая альтернатива D3-3 создаёт пять новых предметных директорий, чей код фактически лежит на уровень выше
- **mechanism**: Для шести "плоских" инструментов принятое решение —
  `scripts/<subject>/tests/` рядом с `scripts/<subject-ish>.php` — создаёт
  директорию, которая называет предмет ("subject"), но не содержит кода
  этого предмета (только его тесты); код лежит соседним файлом на уровень
  выше. План сам называет это "recorded debt" с условием закрытия
  ("when the tool grows a second file"), но признаёт совместный перенос
  entry-скрипта "correct by ADR 0016 and the right eventual shape" и
  откладывает его как отдельную стадию.
- **trigger**: воспроизведётся для всех пяти новых директорий,
  создаваемых пакетом P5.
- **in_scope**: да
- **anchor**: архитектурная оценка D3-3 (`03-tooling-tests.md:63-82`)
- **evidence**:
  ```
  03-tooling-tests.md:78-82:
  A flat tool ends this stage as scripts/<subject>/tests/ beside
  scripts/<subject-ish>.php, so the directory names a subject whose
  code sits one level up. Owner: whoever next touches that tool.
  ```
- **verification**: unverifiable
- **verification_note**: Фактическая раскладка (директория без кода
  предмета) подтверждена буквальным текстом плана — это не спорный факт.
  Спорна оценка "принятая альтернатива лучше отложенной, учитывая ADR 0016 и
  ADR 0022" — это архитектурное суждение, а не измеримый факт, поэтому
  `verification: unverifiable` по правилу 2 схемы находок. По ADR 0016
  (co-change test) обе альтернативы имеют цену: отложение оставляет один
  существующий remnant (`RuleVocabulary` с одним файлом, что план сам
  считает нарушением DoD), принятие — создаёт пять новых частичных
  расщеплений с неопределённым по времени условием закрытия.
- **fix_direction**: Либо переносить entry-скрипт вместе с тестом атомарно
  для каждого плоского инструмента (компенсируя стоимость правкой
  артефактов, названной в "Rejected — move the entry scripts in too"), либо
  явно взвесить в тексте плана цену пяти новых зафиксированных долгов против
  цены одного отложенного remnant, а не полагаться на утверждение "correct
  by ADR 0016" без встречного взвешивания.

### codex-07

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: pattern
- **domain**: tests
- **title**: P3 не опустошает `tests/Unit/RuleVocabulary/`, как заявлено — контрпример к собственному аргументу D3-3
- **mechanism**: Таблица пакетов говорит: "P3 ... directive-audit and its
  controls ... empties `tests/Unit/RuleVocabulary/`". Но `RuleVocabulary`
  содержит пять `*Test.php`-файлов (строки 4,5,6,10,12 в таблице
  популяции); P3 переносит только строки 4,5,6,10. Строка 12
  (`RenameEnumerationRetirementTest.php`) отнесена к P5 ("The flat-script
  tools"). Значит после P3 в `tests/Unit/RuleVocabulary/` остаётся ровно
  один тестовый файл — та самая ситуация ("остаётся один файл"), которую
  сам план в разделе D3-3 приводит как решающий довод ПРОТИВ отклонённой
  альтернативы ("Rejected — defer the six... it leaves
  `tests/Unit/RuleVocabulary/` alive with exactly one file"). План
  воспроизводит критикуемое им самим состояние — только как промежуточный,
  а не финальный результат, и нигде явно не признаёт это состояние как
  временное и допустимое.
- **trigger**: воспроизведётся, если пакеты коммитятся последовательно
  как описано (P3 перед P5).
- **in_scope**: да
- **anchor**:
  - `docs/internal/plans/test-structure/03-tooling-tests.md:233-242` (таблица Work packages)
  - `docs/internal/plans/test-structure/03-tooling-tests.md:66-69` (аргумент D3-3)
- **evidence**:
  ```
  03-tooling-tests.md:238:
  | P3  | directive-audit and its controls | rows 4, 5, 6, 10 + ... | ...
  empties tests/Unit/RuleVocabulary/ |
  03-tooling-tests.md:240:
  | P5  | The flat-script tools | rows 11-16 | five new subject directories |

  03-tooling-tests.md:66-69:
  Rejected — defer the six. It reads cheaper and is not: it leaves
  tests/Unit/RuleVocabulary/ alive with exactly one file
  (RenameEnumerationRetirementTest), which contradicts this stage's own DoD
  ```
- **verification**: confirmed
- **verification_note**: Фасилитатор подтвердил список файлов командой
  `git ls-tree -r --name-only HEAD -- tests/Unit/RuleVocabulary`: пять
  `*Test.php` (`DirectiveAuditControlsSuiteKeyTest`,
  `DirectiveAuditGateTest`, `DirectiveAuditReportReadingTest`,
  `RenameEnumerationRetirementTest`, `ThresholdPopulationAgreementTest`) и
  одна fixture. P3 (строки 4,5,6,10) переносит четыре из пяти,
  `RenameEnumerationRetirementTest` (строка 12) остаётся до P5. DoD-раздел
  плана также требует `tests/Unit/RuleVocabulary/` "no longer exist" —
  формально не нарушено (директория перестаёт существовать после P5), но
  формулировка "P3 ... empties" для промежуточного состояния фактически
  неверна (пуста директория станет только после P5, не после P3).
- **fix_direction**: Либо исправить формулировку "Also owns" для P3, указав
  точно, что директория остаётся с одним файлом до завершения P5, и явно
  признать это временное состояние приемлемым (если это так), либо
  перенести строку 12 в P3, если порядок P3/P5 можно поменять без потери
  причины последовательности пакетов.

### codex-08

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: reliability
- **title**: У per-suite prediction нет машинного оракула, способного отказать
- **mechanism**: DoD требует, чтобы per-suite числа совпали с
  `prediction.md` (`Tooling` 179; `Unit` 7148 → 6987; `Integration` 437 →
  419). Но `scripts/phpunit-aggregate.py` (`discover_partition()` /
  `assert_partition()` / `run_shards()`) сравнивает только партицию (что
  каждый обнаруженный кейс принадлежит ровно одному suite) и код возврата
  шардов — нигде не сравнивает фактические per-suite числа с
  зафиксированными в `prediction.md` значениями. DoD-пункт формально
  проверяется только человеком, читающим вывод, а не командой, способной
  вернуть ненулевой код при несовпадении.
- **trigger**: воспроизведётся, если перенос потеряет или переложит в
  неверный suite часть кейсов, а `test-phpunit-discovery.txt` будет
  регенерирован под уже испорченное дерево — партиция при этом всё ещё
  сойдётся, и `assert_partition()` не заметит проблему.
- **in_scope**: да
- **anchor**: контракт per-suite prediction (DoD, `03-tooling-tests.md:186-191`)
- **evidence**:
  ```
  03-tooling-tests.md:186-191:
  The two totals are unchanged: 9198 discovered, 9196 executed, and the
  per-suite rows match measurement/stage-03/prediction.md ... A run whose
  totals match but whose per-suite rows do not is a file that landed in
  the wrong suite — the defect a total-only check cannot see.
  ```
- **verification**: confirmed
- **verification_note**: Прочитан `scripts/phpunit-aggregate.py` (функции
  `discover_partition`/`assert_partition`/`run_shards`) — сравнения с
  конкретными числами `179`/`6987`/`419` в коде нет, есть только проверка
  партиции и агрегированного кода возврата шардов. Сами значения prediction
  сверены с `prediction.md` (179 = 161 Unit + 18 Integration после
  переноса, суммарно 9198/9196 без изменения). Реальный прогон не
  выполнялся (ничего не перенесено), верификация — по чтению кода
  сравнивающей логики.
- **fix_direction**: Добавить read-only компаратор (например, отдельный
  шаг acceptance-проверки), сверяющий фактические per-suite числа из
  `--list-tests`/aggregate-вывода с зафиксированными в `prediction.md`
  значениями и возвращающий ненулевой код при расхождении — а не полагаться
  на визуальную сверку исполнителем.

### codex-09

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: Свип написаний закреплённых ссылок в `Probes.php` не исчерпывающий — план подаёт его как полный
- **mechanism**: 119 dot-FQN литералов в `Probes.php` найдены и точно
  посчитаны (16+38+65), и это написание действительно механически безопасно
  переименовать. Но `pinned-references.md` (раздел "How obtained / what
  each spelling's sweep cannot see", пункт 4) сам признаёт, что полный
  проход по bare-method-name написанию не был доведён до конца
  ("was not run to completion given budget"), а пункт про `path:line`
  внутри TSV-прозы (`enumeration-unguarded-cases.tsv`) был найден случайно
  при чтении, а не системным свипом. План опирается на "полную таблицу"
  пиннинга, не отражая эту неполноту в DoD или в шагах пакета P3.
- **trigger**: воспроизведётся, если один из трёх переименовываемых
  методов закреплён где-то bare-именем без классового квалификатора (форма,
  прямо названная как непроверенная).
- **in_scope**: да
- **anchor**: контракт полноты `pinned-references.md`
- **evidence**:
  ```
  pinned-references.md (раздел 5, пункт 4):
  A bare itXxx method name pinned with no class qualifier anywhere ...
  would not be found by a basename-only sweep; a second pass grepping
  every declared #[Test] method name of the 16 files individually would
  be needed to rule this out exhaustively, and was not run to completion
  given budget.
  ```
- **verification**: confirmed
- **verification_note**: Фасилитатор независимо пересчитал форму
  dot-FQN: `grep -oE "Qualimetrix\.Tests\.[A-Za-z.]+\.[a-zA-Z0-9]+"
  scripts/directive-audit-controls/Probes.php` даёт ровно 363 совпадения
  всего, из них 16 для `RuleVocabulary.DirectiveAuditGateTest`, 38 для
  `RuleVocabulary.DirectiveAuditReportReadingTest`, 65 для
  `RuleVocabulary.ThresholdPopulationAgreementTest` — точное совпадение с
  `baseline.md`/`pinned-references.md` (119 из 363). Для этих 119 замена
  механична и безопасна; неполнота касается ИНЫХ написаний, что подтверждено
  самим текстом измерения, а не домыслом.
- **fix_direction**: Перед переименованием трёх классов перечислить полный
  список их `#[Test]`-методов и явно прогнать по всем tracked-файлам поиск
  каждого метода как отдельной строки (bare name), закрыв конкретно
  указанный измерением пробел, прежде чем объявлять работу по P3
  завершённой.

### codex-10

- **reviewer**: codex
- **severity**: LOW
- **kind**: pattern
- **domain**: reliability
- **title**: `registration-addresses.md` продолжает цитировать устаревший (уже опровергнутый той же стадией) вердикт об отсутствующей `<directory>`
- **mechanism**: `baseline.md` этой же стадии (раздел "What a green suite run
  does not prove") измеряет и опровергает утверждение `CLAUDE.md` про
  отсутствующую директорию: на пиннутом PHPUnit 12.5.25 это даёт **exit 2**
  (`TestDirectoryNotFoundException`), а не "warns, exit 0, empty suite".
  Но `registration-addresses.md` (раздел C5) в своей строке про "a path with
  zero test files" всё ещё дословно цитирует старую формулировку CLAUDE.md
  ("PHPUnit warns, exits 0, and hands back an empty suite") как актуальную
  характеристику "the single scariest address in the whole table", не
  указывая рядом, что это относится к другому случаю (директория
  СУЩЕСТВУЕТ, но пуста от тестов) — не к случаю "директория отсутствует на
  диске", который тот же документ (раздел выше) отдельно не пересматривает.
  Для исполнителя, читающего оба документа стадии как единый источник
  истины, эти две формулировки читаются как противоречащие друг другу.
- **trigger**: воспроизведётся, если `registration-addresses.md` использовать
  как рабочую инструкцию для P1/P7 без сверки с `baseline.md`.
- **in_scope**: да
- **anchor**:
  - `docs/internal/plans/test-structure/measurement/stage-03/baseline.md:55-70`
  - `docs/internal/plans/test-structure/measurement/stage-03/registration-addresses.md:49-58`
- **evidence**:
  ```
  baseline.md:57-66:
  Probed four ways ... Test directory "…" not found, exit 2

  registration-addresses.md (C5, строка про "zero test files"):
  Silent — this is the single scariest address in the whole table, and
  CLAUDE.md already flags it: "a <directory> naming a path that no longer
  exists — PHPUnit warns, exits 0, and hands back an empty suite."
  ```
- **verification**: confirmed
- **verification_note**: Проверено чтением обоих файлов измерения стадии и
  версии PHPUnit (`composer.json:44` — `^12.0`, соответствует пиннутой
  12.5.25 из baseline.md). Технически это два разных сценария (директория
  отсутствует на диске → exit 2, измерено в baseline.md; директория
  существует, но не содержит тест-файлов → тихо, exit 0, отдельно измерено
  в `baseline.md` тоже — "declaring an empty tests/Vanished/Unit left Unit
  at exactly 7148, exit 0"), и `registration-addresses.md` формально пишет
  про второй сценарий ("path with zero test files"). Проблема — не в
  фактах, а в том, что `registration-addresses.md` для этого дословно
  переиспользует цитату CLAUDE.md об ОТСУТСТВУЮЩЕЙ директории, которую
  `baseline.md` той же стадии уже опроверг, не пометив разницу — что создаёт
  риск смешения этих двух разных кейсов при исполнении P7.
- **fix_direction**: В `registration-addresses.md` явно развести два случая
  словами (директория отсутствует на диске — loud, exit 2; директория
  существует и пуста от тестов — silent, exit 0) и не цитировать
  опровергнутую формулировку CLAUDE.md без пометки, что она относится к
  другому кейсу.

## Coverage

- Прочитаны все семь обязательных файлов измерения стадии 03 плюс сам план
  `03-tooling-tests.md`, дополнительно — `stage-02-review/REPORT.md`,
  `AGENTS.md`/`CLAUDE.md` (раздел "Decision framework for new capabilities"),
  и фрагменты кода, на которые план и измерение ссылаются
  (`composer.json`, `scripts/directive-audit-coverage-control.php`,
  `scripts/generate-modular-architecture-test-inventory.php`,
  `scripts/generate-rename-enumeration.php`, `scripts/phpunit-aggregate.py`,
  `scripts/directive-audit-controls/Probes.php`).
- **Вопрос 1** (полнота популяции 16): отвечен. 17-й файл не найден ни
  внешним ревьюером, ни фасилитатором за отведённое время; узкая истинная
  формулировка — codex-04. Непокрытый явно класс каналов: helper/inheritance
  indirection, Composer-алиасы, subprocess-таргет без литеральной подстроки
  `scripts`/`tools` в самом файле теста, CI workflow YAML.
- **Вопрос 2** (доказывает ли two-step plant дыру): отвечен, находка
  codex-02 — формулировка шага 1 путает "внутри" и "снаружи" старого
  scan-scope литерала, что обесценивает демонстрацию, если исполнить
  буквально. Физический plant не проводился (read-only), вывод сделан по
  безусловной логике генератора.
- **Вопрос 3** (проверяем ли DoD): отвечен, находки codex-01 (полный
  directive-control недостижим из-за составной цепочки composer-скриптов) и
  codex-08 (per-suite prediction не имеет машинного оракула).
- **Вопрос 4** (D3-1 форсирован измерением?): отвечен, находка codex-05 —
  арифметика "три из пяти" расходится с собственной таблицей ("четыре из
  пяти"), гибридный вариант не рассмотрен явно.
- **Вопрос 5** (арифметика D3-3 по `RuleVocabulary`): отвечена дважды —
  число файлов подтверждено (`git ls-tree`, пять `*Test.php`), и найден
  дополнительный дефект (codex-07): промежуточное состояние после P3
  воспроизводит именно ту ситуацию ("один файл в RuleVocabulary"), которую
  план сам приводит как решающий довод против отклонённой альтернативы —
  архитектурная оценка альтернатив дана в codex-06 (`unverifiable` по
  правилу схемы для суждений).
- **Вопрос 6** (разрезание пакетов, ownership): отвечен, находки codex-03
  (`generate-rename-enumeration.php::surfaces()` не назначен ни одному
  пакету) и codex-07 (P3 не опустошает `RuleVocabulary`, вопреки заявлению
  в таблице пакетов).
- **Вопрос 7** (119 закреплённых литералов, полнота свипа написаний):
  отвечен, находка codex-09. Точный пересчёт (16+38+65=119 из 363
  dot-FQN-вхождений) подтверждён фасилитатором независимо от codex-CLI и
  совпадает с измерением стадии. Для этих 119 замена механична и безопасна;
  неполнота касается bare-method-name и path:line-в-TSV написаний, что
  прямо признано самим измерением стадии.
- **Вопрос 8** (рецидив дефектов ревью stage 02): отвечен. Соответствие
  найденных находок пяти классам stage-02-review: A (ссылка в прозе без
  резолвера) — пять dead-prose ссылок сознательно оставлены необновлёнными,
  отдельной находки не заводилось, т.к. план сам называет это "documentation
  debt" явно; B (рукописный список разошёлся с предметом) — codex-03
  (`surfaces()`) и частично codex-10 (`registration-addresses.md` расходится
  с `baseline.md` той же стадии); C (заявленная полнота уже сужена
  инструментом) — codex-04; D (директория названа по форме, а не по
  предмету) — codex-06 (`scripts/<subject>/tests/` без кода предмета); E
  (нет нижнего порога на размер группы/суммы) — codex-08 (per-suite
  prediction без машинного оракула).
- Живые mutation/plant-прогоны (кроме уже выполненных орфографом стадии и
  зафиксированных в `pinned-references.md`/`baseline.md`) не проводились —
  запрет на запись в рабочее дерево. Все verdicts, отмеченные "confirmed",
  основаны на независимом чтении точного кода отказной ветки фасилитатором
  и/или внешним Codex CLI, либо на точном пересчёте измеримых величин
  (количество файлов, количество литералов) независимо от текста плана и
  измерения.
- Ни одна команда `git add/commit/stash/restore/checkout/clean` не
  выполнялась; ветка не переключалась; в репозитории не создано и не
  изменено ни одного файла, кроме этого файла находок.

## Отклонённые находки (refuted)

- codex-11 | Исправление CLAUDE.md про отсутствующий `<directory>` (пункт 1
  раздела "CLAUDE.md corrections P7 owes") неверно | refuted: пиннутый
  PHPUnit 12.5.25 действительно бросает `TestDirectoryNotFoundException`
  (exit 2) на отсутствующей директории; старое утверждение CLAUDE.md
  ("warns, exit 0, empty suite") устарело, и план прав, требуя его
  исправить.
- codex-12 | Все 119 dot-FQN литералов в `Probes.php` невозможно проверить
  автоматически | refuted: точные подсчёты 16/38/65=119 подтверждены
  независимым пересчётом (`grep -oE`), а stale-declaration arithmetic
  `directive-audit-coverage-control.php` демонстрированно (в
  `pinned-references.md`, раздел 3) ловит устаревшее имя после
  переименования — дефект относится к НЕДОСМОТРЕННЫМ написаниям (bare
  method name, path:line в TSV), а не к этим 119.
- codex-13 | `tests/Unit/RuleVocabulary/` содержит шесть тестовых файлов |
  refuted: `git ls-tree -r --name-only HEAD -- tests/Unit/RuleVocabulary`
  показывает ровно пять `*Test.php` и одну fixture (`AuthoredThresholdForms.php`);
  число шесть было бы верно только если считать fixture тестом.
