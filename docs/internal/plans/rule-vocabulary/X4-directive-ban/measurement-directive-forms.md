> Измерено 2026-09-03 до планирования Х4, исполнением на физических копиях
> дерева `6f48b2a0`. Стенды жили вне репозитория и не сохранены: воспроизводятся
> командами, которые названы ниже по тексту. Абсолютные пути машины вычищены.

# X4 — измеренная таблица авторских форм для канала `annotation.unused-directive`

Дата: 2026-09-03. Всё измерено исполнением в **физической копии** дерева
`/tmp/x4-forms-clone` (`cp -a` с рабочего worktree `p3-baseline-compaction-adr-d6e926`,
`git rev-parse HEAD` = `6f48b2a0`). Рабочее дерево проекта не изменялось ни одним байтом.

Проверка, что копия физическая (не симлинк на исходный `vendor`):

```
ls -ld /tmp/x4-forms-clone/vendor
test -L /tmp/x4-forms-clone/vendor && echo VENDOR_IS_SYMLINK || echo VENDOR_IS_REAL_DIR
# -> drwxr-xr-x@ 28 ... /tmp/x4-forms-clone/vendor
# -> VENDOR_IS_REAL_DIR
```

## 0. Стенд

Фикстуры — вне репозитория, в `/tmp/x4-fix/cases/<case>/Subject.php`; конфиг
`/tmp/x4-fix/qmx.yaml` минимальный (`paths: [.]`, больше ничего), чтобы `qmx.yaml`
самого проекта не влиял на наблюдение.

Каждый кейс — один файл с двумя директивами:

* **жертва** (строка 7): `// @qmx-ignore-file complexity.cyclomatic -- victim`
  — заведомо протухшая директива другого канала, она и порождает находку
  `annotation.unused-directive`;
* **испытуемая форма** — та, что адресует/накрывает `annotation.unused-directive`
  (строка 7 для next-line, 8 для file, 9 или 11 для символьной).

Три наблюдения на кейс, дословно воспроизводимые (`<case>` — имя каталога):

```bash
cd /tmp/x4-forms-clone
php bin/qmx check /tmp/x4-fix/cases/<case> -c /tmp/x4-fix/qmx.yaml \
    --format=json --workers=0 --no-cache --no-progress --disable-rule='coupling.*'
php bin/qmx check /tmp/x4-fix/cases/<case> -c /tmp/x4-fix/qmx.yaml \
    --format=suppressed --workers=0 --no-cache --no-progress --show-suppressed \
    --disable-rule='coupling.*'
php bin/qmx directives /tmp/x4-fix/cases/<case> -c /tmp/x4-fix/qmx.yaml \
    --format=json --no-progress --disable-rule='coupling.*'
```

`--disable-rule='coupling.*'` — только чтобы убрать шум `coupling.class-rank`
на однклассовой фикстуре; каналы `annotation.*` и `complexity.*` не затронуты.
(Форма `--disable-rule=coupling` без `.*` отвергается: `Rule selector "coupling"
does not match any registered producer, group, or channel`, exit 3.)

Сырые выводы всех прогонов: `/tmp/x4-fix/out/<case>.{check,suppressed,directives}.json`
(+ `.err`), сводка `/tmp/x4-fix/results.json`, генератор `/tmp/x4-fix/gen.py`,
раннер `/tmp/x4-fix/run.py`, таблица собирается скриптом `/tmp/x4-fix/table.py`
(не рукой — рукописные таблицы в этом проекте ошибались трижды).

## 1. Таблица форм

`check prints` — находки канала `annotation.unused-directive`, реально напечатанные
в `--format=json`, с указанием строки и того, какую цель цитирует сообщение.
`--show-suppressed` — прозаическая строка «N violation(s) suppressed by @qmx-ignore tags»,
она **совпала** с машинным `--format=suppressed` во всех 32 кейсах.
Столбец вердикта — только про испытуемую директиву (вердикт жертвы всегда `inert`).

| case                  | authored target                         | `check` prints (unused-directive)                             | `--show-suppressed`  | `directives` verdict of the tested directive | exit check / directives |
| --------------------- | --------------------------------------- | ------------------------------------------------------------- | -------------------- | -------------------------------------------- | ----------------------- |
| `B0-victim-only`      | `complexity.cyclomatic`                 | L7 complexity.cyclomatic                                      | no suppression prose | **inert**                                    | 0 / 2                   |
| `S-exact`             | `annotation.unused-directive`           | L9 annotation.unused-directive; L7 complexity.cyclomatic      | no suppression prose | **inert**                                    | 0 / 2                   |
| `S-exact-file`        | `annotation.unused-directive:file`      | L9 annotation.unused-directive:file; L7 complexity.cyclomatic | no suppression prose | **inert**                                    | 0 / 2                   |
| `S-exact-class`       | `annotation.unused-directive:class`     | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `S-group`             | `annotation.*`                          | L9 annotation.*; L7 complexity.cyclomatic                     | no suppression prose | **inert**                                    | 0 / 2                   |
| `S-group-file`        | `annotation.*:file`                     | L9 annotation.*:file; L7 complexity.cyclomatic                | no suppression prose | **inert**                                    | 0 / 2                   |
| `S-nofilter`          | `*`                                     | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / addresses-every-channel**     | 0 / 2                   |
| `N-exact`             | `annotation.unused-directive`           | L7 annotation.unused-directive                                | 1 suppressed         | **effective**                                | 0 / 2                   |
| `N-exact-file`        | `annotation.unused-directive:file`      | L7 annotation.unused-directive:file                           | 1 suppressed         | **effective**                                | 0 / 2                   |
| `N-exact-class`       | `annotation.unused-directive:class`     | L8 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `N-group`             | `annotation.*`                          | L7 annotation.*                                               | 1 suppressed         | **effective**                                | 0 / 2                   |
| `N-group-file`        | `annotation.*:file`                     | L7 annotation.*:file                                          | 1 suppressed         | **effective**                                | 0 / 2                   |
| `N-nofilter`          | `*`                                     | (none)                                                        | 1 suppressed         | **unmeasured / addresses-every-channel**     | 0 / 2                   |
| `F-exact`             | `annotation.unused-directive`           | (none)                                                        | 2 suppressed         | **effective**                                | 0 / 2                   |
| `F-exact-file`        | `annotation.unused-directive:file`      | (none)                                                        | 2 suppressed         | **effective**                                | 0 / 2                   |
| `F-exact-class`       | `annotation.unused-directive:class`     | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `F-group`             | `annotation.*`                          | (none)                                                        | 2 suppressed         | **effective**                                | 0 / 2                   |
| `F-group-file`        | `annotation.*:file`                     | (none)                                                        | 2 suppressed         | **effective**                                | 0 / 2                   |
| `F-nofilter`          | `*`                                     | (none)                                                        | 1 suppressed         | **unmeasured / addresses-every-channel**     | 0 / 2                   |
| `LVL-exact-callable`  | `annotation.unused-directive:callable`  | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `LVL-exact-class`     | `annotation.unused-directive:class`     | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `LVL-exact-namespace` | `annotation.unused-directive:namespace` | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `LVL-exact-project`   | `annotation.unused-directive:project`   | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `LVL-group-callable`  | `annotation.*:callable`                 | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `LVL-group-class`     | `annotation.*:class`                    | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `LVL-group-namespace` | `annotation.*:namespace`                | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `LVL-group-project`   | `annotation.*:project`                  | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `S-method`            | `annotation.unused-directive`           | L7 complexity.cyclomatic; L11 annotation.unused-directive     | no suppression prose | **inert**                                    | 0 / 2                   |
| `SELF-only`           | `annotation.unused-directive`           | (none)                                                        | 1 suppressed         | **inert**                                    | 0 / 2                   |
| `S-control`           | `coupling.class-rank`                   | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / producer-disabled**           | 0 / 2                   |
| `TH-channel`          | `annotation.unused-directive`           | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |
| `TH-producer`         | `annotation.directive`                  | L7 complexity.cyclomatic                                      | no suppression prose | **unmeasured / already-refused**             | 2 / 2                   |

### Три поведенческих класса, которые из таблицы вычитываются

**A. Файловая форма — работает целиком и прячет обе находки.**
`F-exact`, `F-exact-file`, `F-group`, `F-group-file`: `check` печатает **ноль**
находок канала, `--show-suppressed` показывает **две** подавленные (жертву на
строке 7 и собственную жалобу директивы на строке 8), `directives` называет
испытуемую `effective`, а жертву `inert` и выходит 2. То есть `check` молчит там,
где `directives` кричит.

**B. Соседская (next-line) форма — работает наполовину, и `check` при этом
противоречит сам себе.** `N-exact`, `N-exact-file`, `N-group`, `N-group-file`:
жертва на строке 8 подавлена (1 suppressed), но `check` печатает
`annotation.unused-directive` **на строке самой испытуемой директивы** с текстом
`Suppression "annotation.unused-directive" matched nothing in this run`, —
хотя она демонстрируемо погасила соседнюю находку. `directives` говорит
`effective`.

**C. Символьная форма — не делает ничего никогда.** `S-exact`, `S-exact-file`,
`S-group`, `S-group-file`, `S-method`: `suppression = 0` во всех, обе жалобы
напечатаны, и **обе команды согласны**: `directives` тоже даёт `inert`.

**Отдельно: форма без фильтра правил.** `@qmx-ignore-file` без аргумента и
`@qmx-ignore-next-line *` **гасят** находку канала (1 suppressed), но вердикта не
получают вовсе — `unmeasured / addresses-every-channel`. Символьный `@qmx-ignore *`
не гасит ничего (та же причина, что у C).

**Отдельно: `:level`, кроме `file`.** Все восемь пар «селектор × неверный уровень»
(`callable`, `class`, `namespace`, `project` для обоих селекторов) ведут себя
одинаково: `check` выходит **2** с `annotation.unresolved-directive` (severity
`error`) — «addresses "annotation.unused-directive", and it does not report at
level "class"», ничего не подавлено; `directives` — `unmeasured / already-refused`.
Это и есть тот путь отказа, который план 11 предлагает переиспользовать для запрета.

**Отдельно: `@qmx-threshold`.** Обе формы (`annotation.unused-directive` — имя
канала, `annotation.directive` — имя правила) уже отвергнуты сегодня: `check`
выходит 2, `directives` — `unmeasured / already-refused`. Порог этот канал не
трогает вообще.

### Положительный контроль символьной формы

Чтобы «символьная форма ничего не гасит» не оказалось артефактом стенда, тот же
стенд прогнан с директивой, чей канал **имеет** субъект-объявление:

```bash
cd /tmp/x4-forms-clone
php bin/qmx check /tmp/x4-fix/cases/S-control -c /tmp/x4-fix/qmx.yaml \
    --format=json --workers=0 --no-cache --no-progress
php bin/qmx directives /tmp/x4-fix/cases/S-control -c /tmp/x4-fix/qmx.yaml \
    --format=json --no-progress
```

`/** @qmx-ignore coupling.class-rank */` в докблоке класса → `directives`:
`effective`; `--format=suppressed`: `byMechanism.suppression = 1`,
подавлена `coupling.class-rank` на строке 13. **Символьная форма исправна;
её бессилие против `annotation.unused-directive` — свойство пары
«символьное подавление × файловый субъект», а не стенда.**

## 2. Обоснование полноты перечисления форм

Перечисление выведено из кода скриптом `/tmp/x4-fix/enumerate-forms.php`
(запуск: `cd /tmp/x4-forms-clone && php /tmp/x4-fix/enumerate-forms.php`), а не из прозы.
Он читает сами типы:

* **ось «тег»** — `SuppressionType::cases()` → ровно 3: `symbol`, `next-line`, `file`.
  Место написания директивы **не** добавляет форм: тип задаётся тегом, а не
  местом (`SuppressionExtractor::PATTERN_SYMBOL/NEXT_LINE/FILE`); кейс `S-method`
  (символьная в докблоке метода вместо класса) подтверждает это исполнением —
  результат тот же, что у `S-exact`.
* **ось «селектор»** — грамматика `NameSelector::tryParse()` имеет ровно две
  продукции: равенство (совпадает только с собой) и `X.*` (совпадает, если
  субъект начинается с `X.`). Значит полное множество селекторов, попадающих в
  `annotation.unused-directive`, — это точное имя плюс по одному групповому
  селектору на каждый собственный точечный префикс. Скрипт **выводит** это
  множество и **перепроверяет перебором** по расширенному пространству кандидатов
  (`annotation`, `annotation.*`, `annotation*`, `unused-directive`, `a`, `*`, `**`,
  `annotation.unused`, `annotation.unused-directive.x`, пустая строка, каждый ещё
  и с суффиксами `.*` и `*`): совпадение выведенного и перебранного — `AGREE: yes`.
  Итог: `{annotation.unused-directive, annotation.*}` — и ничего больше.
  В частности `annotation` (голый префикс) селектором **не** является:
  `expand()` даёт 0 каналов.
* **ось «уровень»** — `SymbolLevel::cases()` → 5 (`callable, class, file,
  namespace, project`); канал объявлен только на `file`
  (`UnusedDirectiveRule::channelDeclarations()`, подтверждено через
  `levelsOf() = file`).
* **ось «без фильтра»** — `SuppressionTarget` имеет ровно два состояния:
  селектор и `appliesToEveryChannel()`; спеллинги `*` (symbol/next-line) и
  опущенный аргумент (file) десахарятся в одно и то же
  (`SuppressionTarget::NO_RULE_FILTER`, `SuppressionExtractor::authoredArgument()`).

Итого сетка подавлений: `3 тега × (2 селектора × (без уровня + 5 уровней) + 1 форма
без фильтра) = 3 × 13 = 39`. Измерены все 39: 18 «интересных» кейсов (`S/N/F` ×
`{exact, exact:file, exact:class, group, group:file, nofilter}`) плюс 8 кейсов
`LVL-*`, доказавших, что четыре «неверных уровня» — один класс эквивалентности
(все восемь дали побайтно один и тот же исход), плюс `S-method`, `SELF-only`,
`B0-victim-only`, `S-control` и две формы `@qmx-threshold`. Семейство
`@qmx-threshold` — четвёртый тег, но он адресует **правило**, а не канал, и потому
не входит в сетку подавлений; обе его формы измерены отдельно.

## 3. Вердикт по двум проверяемым утверждениям

**Утверждение 1 — «символьная форма `@qmx-ignore annotation.unused-directive`
в докблоке не может сработать никогда» — ПОДТВЕРЖДЕНО.**
Чем: кейсы `S-exact`, `S-exact-file`, `S-group`, `S-group-file`, `S-method` —
`byMechanism.suppression = 0`, обе жалобы напечатаны, `directives` даёт `inert`.
Важно, что **обе команды согласны**: `directives` судит по универсуму, который
поздний канал уже содержит (`AnalysisPipeline::auditDirectives()` доклеивает его),
и всё равно говорит `inert`. Значит причина — не «канала нет в универсуме», а
именно сопоставление субъектов: `SuppressionFilter::applies()` для
`SuppressionType::Symbol` требует
`$suppression->subject->toCanonical() === $finding->subject->toCanonical()`, а
`StaleDirectiveFinding::of()` строит `MetricSubject::aggregate(SymbolPath::forFile(...))`.
Положительный контроль (`S-control`) исключает объяснение «стенд не даёт
символьной форме сработать в принципе».

**Утверждение 2 — «в `src/` лазейкой не пользуется ни одна директива» —
ПОДТВЕРЖДЕНО, и для остального репозитория тоже, с тремя оговорками (см. §5).**
Чем: собственным перечислением продукта.

```bash
cd /tmp/x4-forms-clone && php bin/qmx directives src/ --format=json --no-progress
# exit 0; scope: 893 файла, complete=true, produced_findings=433; 43 директивы
```

Ни одна из 43 не адресует и не накрывает канал. Полный частотный список целей в
`src/`: `coupling.cbo` ×13, `health.cohesion` ×10,
`code-smell.constructor-overinjection` ×5, `code-smell.long-parameter-list` ×4,
`complexity.cyclomatic` ×3, `coupling.class-rank` ×2, `coupling.instability` ×2,
`complexity.wmc`, `complexity.npath`, `code-smell.empty-catch`,
`coupling.instability:class` — по одной. Форм без фильтра правил в `src/` нет вовсе.

## 4. Разворачивание селектора

Прямой замер, `/tmp/x4-fix/expand-probe.php`
(`cd /tmp/x4-forms-clone && php /tmp/x4-fix/expand-probe.php`), через
`ChannelIdentityInterface::expand()` реального контейнера:

```
annotation.*                 -> 4: annotation.invalid-threshold, annotation.unresolved-directive,
                                   annotation.unsupported-threshold, annotation.unused-directive
                                CONTAINS annotation.unused-directive: YES
annotation                   -> 0                                    CONTAINS: NO
annotation.unused-directive  -> 1                                    CONTAINS: YES
producerOf(annotation.unused-directive)               = 'annotation.directive'
levelsOf(annotation.unused-directive)                 = file
supportsThresholdOverride('annotation.directive')     = false
hasRule('annotation.unused-directive')                = false
```

**Да, разворачивание селектора канал покрывает.** `@qmx-ignore-file annotation.*`
сегодня даёт самоссылку — и она рабочая: кейс `F-group` подавляет обе находки и
получает вердикт `effective`. То есть запрет обязан вырезать канал не только из
точного адреса, но и из результата `expand()` для группового селектора, иначе
`annotation.*` останется дырой ровно того же размера.

**Форма без фильтра правил — отдельный, третий путь, и `expand()` он не
использует вообще.** `SuppressionTarget::matches()` при `everyChannel = true`
возвращает `true`, не спрашивая ни селектор, ни каталог. Измерено:
`F-nofilter` и `N-nofilter` гасят находку канала (`1 suppressed`, `check` печатает
ноль находок канала), при этом `directives` отказывается их судить
(`unmeasured / addresses-every-channel`). Значит правка одного `expand()`
запрет **не** закроет: `@qmx-ignore-file -- reason` продолжит гасить канал молча.
Это третье место, которого в разделе «во что обойдётся запрет» плана 11 нет.

## 5. Все места в репозитории, где директива адресует канал или накрыла бы его

Два независимых перечисления.

**(а) Продуктом, по `src/`:** `php bin/qmx directives src/ --format=json` — **ноль**
совпадений (см. §3).

**(б) Текстовым сканом по всему репозиторию:** `/tmp/x4-fix/repo-scan.py`
(`cd /tmp/x4-forms-clone && python3 /tmp/x4-fix/repo-scan.py`) — обход `git ls-files`,
теми же тремя регулярками, что и `SuppressionExtractor`, плюс `@qmx-threshold`,
с тем же вырезанием бэктиковых регионов. Классификация: `exact` (точное имя),
`group` (селектор, накрывающий канал), `no-rule-filter` (форма без фильтра),
`producer-name` (`annotation.directive` для `@qmx-threshold`).
Всего 20 текстовых вхождений; полный вывод — `/tmp/x4-fix/repo-scan.txt`.

**Живые директивы, которые запрет затронет — четыре, все вне `src/`:**

| место                                                                                | форма                                          | что это                                                                                                                                                                                                                                                             |
| ------------------------------------------------------------------------------------ | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Infrastructure/Console/Functional/DirectivesCommandTest.php:155`              | `@qmx-ignore-file annotation.unused-directive` | heredoc-фикстура, которую тест пишет на диск и прогоняет продуктом; ассертит `['annotation.unused-directive' => 'effective', 'complexity.cyclomatic' => 'inert']`. **Запрет её сломает.** Мой кейс `F-exact` — точная её копия и воспроизвёл ровно эти два вердикта |
| `tests/Infrastructure/Console/Functional/DirectivesCommandTest.php:230`              | `@qmx-ignore-file annotation.unused-directive` | вторая heredoc-фикстура (`itDoesNotLetADirectiveJustifyItselfWithItsOwnComplaint`), ассертит `inert`. **Запрет её сломает.** Кейс `SELF-only` воспроизвёл `inert`                                                                                                   |
| `tests/Analysis/Policy/Inline/Fixtures/NarrowControl/EveryChannelSuppression.php:18` | `@qmx-ignore *` (без фильтра)                  | настоящий файл-фикстура; канал накрывает, но через путь `everyChannel`, не через `expand()`. Затронута, только если запрет вырежет канал и из формы без фильтра                                                                                                     |
| `finding-gate/cases/annotations/src/Directives.php:15`                               | `@qmx-threshold annotation.directive`          | корпусная фикстура гейта; **уже** отвергается сегодня (`unsupported-threshold`), запрет её не меняет                                                                                                                                                                |

**Не живые директивы (проза, строковые литералы, исходники самого парсера) — 16 вхождений**, перечисляю поимённо, чтобы список был проверяем:
`docs/adr/0039-directive-audit-command-and-contract.md:145`,
`docs/internal/plans/rule-vocabulary/X2-directive-audit/04-command.md:144`,
`docs/internal/plans/rule-vocabulary/X3-followups/09-heterogeneous-control.md:175`,
`docs/internal/plans/rule-vocabulary/enumeration-suppression-mechanisms.tsv:32`,
`src/Analysis/Policy/Inline/Contract/SuppressionExtractor.php:56` (сама регулярка),
`src/Analysis/Policy/Inline/Directive/Audit/DirectiveUsage.php:174` (докблок),
`src/Infrastructure/Console/DirectiveAuditPresenter.php:267` (строка-ярлык формы),
`tests/Analysis/Finding/Integration/ConfigurationValidatorSilencingPathsTest.php:117`
(`@qmx-threshold annotation.directive` в heredoc — уже отвергается),
`tests/Analysis/Policy/Inline/Integration/ThresholdAnnotationParserPathTest.php:247`,
`tests/Analysis/Policy/Inline/Unit/SuppressionExtractorTest.php:237,363,407,751`,
`tests/Analysis/Policy/Inline/Unit/ThresholdOverrideExtractorTest.php:220`,
`tests/Infrastructure/Console/Functional/DirectivesCommandTest.php:107`,
`website/docs/usage/baseline.md:129`.

Три из них (`src/.../SuppressionExtractor.php:56`,
`src/.../DirectiveUsage.php:174`, `src/Infrastructure/Console/DirectiveAuditPresenter.php:267`)
сканер поймал только потому, что мой сканер не различает «комментарий» и «строковый
литерал/регулярка»; продуктовый прогон по `src/` их не видит — что и есть
перекрёстная проверка двух перечислений между собой.

Документация, которую запрет сделает неверной, — не директивы, но перечислю, раз
искал по всему дереву: `website/docs/usage/baseline.{md,ru.md}:184`
(«его можно принять в baseline **или подавить как любой другой канал**»),
`website/docs/rules/annotation.{md,ru.md}:35` (то же утверждение),
`website/docs/reference/default-thresholds.{md,ru.md}:113`.

## 6. Побочно измеренное, полезное для проектирования запрета

* `isConfigurationError` (`/tmp/x4-fix/decl-probe.php`): `annotation.unused-directive`
  → `false`; три остальных `annotation.*` → `true`; `complexity.cyclomatic` → `false`.
  Подтверждает посылку плана 11: пометить канал этим флагом нельзя, нужен новый признак.
* `--sweep=full` вердикт не меняет: `F-exact` под `--sweep=full` даёт те же
  `(L7 complexity.cyclomatic, inert)`, `(L8 annotation.unused-directive, effective)`,
  exit 2. Ожидаемо — sweep влияет только на пороговую половину.
* Путь отказа, который план предлагает переиспользовать, действительно уже
  существует и уже даёт нужную пару исходов: `check` → `annotation.unresolved-directive`
  (severity `error`, exit 2), `directives` → `unmeasured / already-refused`.
  Измерено восемь раз (кейсы `LVL-*`).

## 7. Что оказалось не так, как я ожидал

1. **Файловая форма прячет обе находки, а не одну.** Я ожидал, что
   `@qmx-ignore-file annotation.unused-directive` погасит жертву и оставит
   собственную жалобу. Нет: `check` печатает **ноль** находок канала, потому что
   в универсуме `check` испытуемая директива судится как `inert` (поздний канал
   в её универсум не входит), порождает жалобу на своей строке — и тут же гасит
   её сама, будучи файловой. `--show-suppressed` показывает две. То есть автор
   получает полностью тихий `check` и `directives`, который на том же дереве
   выходит 2.
2. **`check` печатает «matched nothing» про директиву, которая демонстрируемо
   что-то погасила.** Соседская форма (`N-exact`): находка канала на строке 7
   с текстом `Suppression "annotation.unused-directive" matched nothing in this run`,
   при том что подавление на строке 8 засчитано и видно в `--show-suppressed`.
   Это не «расхождение двух команд», это самопротиворечивый вывод одной.
3. **Форма без фильтра правил — третий путь, мимо `expand()`.** Я шёл проверять
   «покрывает ли expand()», и ответ «да» оказался только половиной: `everyChannel`
   не спрашивает каталог вовсе, так что правка `expand()` оставит
   `@qmx-ignore-file -- reason` рабочим глушителем канала. В разделе «во что
   обойдётся запрет» плана 11 этого места нет.
4. **`SELF-only` даёт `inert`, а докблок `withoutOwnComplaint()` описывает
   `effective`.** Противоречия нет — докблок описывает состояние *до* введения
   `withoutOwnComplaint()`, — но прочитанный быстро он читается как утверждение о
   сегодняшнем поведении. Сегодня измерено: `inert`.
5. **Групповой селектор `annotation.*` разворачивается в 4 канала, включая
   диагностические.** То есть `@qmx-ignore-file annotation.*` — это не только
   самоссылка на staleness, но и попытка заглушить три конфигурационные ошибки;
   последнее уже не работает (`DirectiveUsage::suppressible()` их отфильтровывает),
   а первое работает. Одна авторская строка, две разные судьбы.
6. **Собственная методическая ошибка, которую пришлось откатить.** Первый замер
   «`--show-suppressed` ничего не печатает» был **неверен**: проза уходит в
   **stderr**, а я её выбросил через `2>/dev/null`. После повтора с сохранённым
   stderr проза совпала с `--format=suppressed` во всех 32 кейсах. Ровно тот
   случай, о котором предупреждает бриф: «моё наблюдение испорчено» надо исключать
   раньше, чем «система сломана». В таблице выше все значения столбца
   `--show-suppressed` взяты из сохранённых `.err`-файлов.
