# Что сломается, если `src/Reporting/Template/` перестанет существовать по этому пути

Разведка B — от эксперимента и контрактов, не от свипа по ссылкам.
Дерево `main @ 4a701bb0`, рабочее дерево после разведки чистое (`git status` пуст).

## Как получены факты

Полная копия дерева (`rsync -a`, **настоящие** `vendor/` и `.git/`, без
симлинков — симлинк на `vendor` заставил бы `__DIR__` резолвиться в исходное
дерево и дал бы ложно-зелёное) в `mktemp -d` внутри scratchpad. В копии:

```
git mv src/Reporting/Template frontend
git -c user.name=recon -c user.email=recon@local commit --no-verify
```

Коммит обязателен: страж поставки читает `git archive HEAD`, и при простом `mv`
он бы мерил нетронутый HEAD и остался зелёным.

**Назначение переноса — корневой `frontend/`.** Часть ответов зависит от
назначения; там, где зависит, это сказано.

Контроль до переноса в копии: `--format=html` отдал те же 159 598 байт, что и в
основном дереве; `governance/DistributedPackage` — `OK (1 test, 5 assertions)`.

---

## A. Рантайм продукта

### A1. HTML-отчёт перестаёт собираться — проверено экспериментом

`src/Reporting/Formatter/Html/HtmlFormatter.php:38`

```php
$templateDir = \dirname(__DIR__, 2) . '/Template';
```

и четыре чтения с диска (строки 40-43): `report.html`, `report.css`,
`dist/d3.min.js`, `dist/report.min.js`. Никакой вкомпиленности — ассеты
читаются файлами и подставляются `str_replace` вместо плейсхолдеров
`__CSS__/__DATA__/__D3_JS__/__APP_JS__`.

`php bin/qmx check <dir> --format=html --workers=0`, exit **1**, stdout пуст,
stderr дословно:

```
Internal error: Template file not found: <root>/src/Reporting/Template/report.html. Run "cd src/Reporting/Template && npm run build" to generate dist/ files.
```

Путь в сообщении назвал **копию**, а не исходное дерево, — это и есть
доказательство, что резолв локален и опыт не ложно-зелёный.

### A2. Литеральная командная строка внутри текста отказа — проверено экспериментом

Строка лечения `Run "cd src/Reporting/Template && npm run build"` зашита в
`HtmlFormatter.php:83`. Это второй из двух классов, переживающих свип по путям
и именам: команда, в которую вшит путь. После переноса она продолжит советовать
несуществующий каталог. Тесты её не проверяют — она видна только человеку,
который уже упёрся в отказ.

### A3. Потребитель через composer — выведено из кода и из `git archive`

`__DIR__` формирователя у потребителя — это
`vendor/qualimetrix/qualimetrix/src/Reporting/Formatter/Html/`, значит
`$templateDir` = `vendor/qualimetrix/qualimetrix/src/Reporting/Template`.
Сейчас в dist-пакете под этим деревом ровно то, что читается (см. B1), поэтому
`--format=html` у потребителя работает.

**Следствие для переноса:** новый путь обязан остаться **внутри пакета**, и
резолв обязан остаться относительно кода формирователя. Корневой `frontend/`
это позволяет (`\dirname(__DIR__, 4) . '/frontend'`), но цена — четыре уровня
вверх вместо двух: то же самое хрупкое место, что уже сломалось в JS-тулинге
(D2/D3). Формирователь и страж поставки друг друга не подстрахуют: страж читает
из исходника формирователя только **суффиксы** (`регулярка
'#\$templateDir \. \'(/[^\']+)\'#'`) и склеивает их со своей константой `TREE`.
Определение самого `$templateDir` он не видит **никогда** — значит неверный
базовый каталог зелёный страж не поймает. Ловит его только `HtmlFormatterTest`
и CLI.

---

## B. Что уезжает в composer-пакет

### B1. Числа «сейчас» — проверено экспериментом

`git archive --worktree-attributes --format=tar HEAD | tar -tf -`, записи под
деревом: **6** — две директории (`Template/`, `Template/dist/`) и **4 файла**:

```
src/Reporting/Template/dist/d3.min.js
src/Reporting/Template/dist/report.min.js
src/Reporting/Template/report.css
src/Reporting/Template/report.html
```

Отслеживается git-ом под деревом **28** файлов. Разницу делают **семь** строк
`export-ignore` в `.gitattributes` (`src/`, `tests/`, `scripts/`,
`package.json`, `package-lock.json`, `vite.config.js`, `dev.html`).

### B2. После переноса пакет раздувается 4 → 28 — проверено экспериментом

Семь строк `export-ignore` называют путь буквально. Путь исчез — строки стали
инертны **молча**: `git archive` не ругается на правило, которое ничего не
матчит.

```
git archive --worktree-attributes --format=tar HEAD | tar -tf - | grep '^frontend/' | grep -v '/$' | wc -l
→ 28
```

То есть потребитель начинает получать восемь vitest-файлов, девять исходников
JS, три скрипта сборки, `vite.config.js`, `dev.html`, `package.json` и
локфайл — 24 лишних файла.

### B3. Страж поставки падает ГРОМКО, но ДО измерения — проверено экспериментом

`governance/DistributedPackage/HtmlReportShipsOnlyWhatItReadsTest.php:34`
хранит `private const string TREE = 'src/Reporting/Template';` и передаёт её
как pathspec в `git archive`.

```
git -C <root> archive --worktree-attributes --format=tar HEAD src/Reporting/Template failed, so nothing here was checked.
Failed asserting that 128 is identical to 0.
```

Это отказ инструмента, а не сообщение о регрессии поставки. Пока `TREE` не
поправлена, **у раздувания пакета оракула нет**.

Вторая половина проверена отдельно: правлю в копии только `TREE` на
`'frontend'` — страж краснеет уже по существу:

```
The dist package carries a file under frontend that the formatter never reads. A consumer cannot use it and cannot tell it arrived; add an export-ignore row beside the others:
frontend/dev.html
frontend/package-lock.json
… (24 строки)
```

Итого: страж работоспособен после правки `TREE`, но между переносом и правкой
он не измеряет ничего.

### B4. Негации в `.gitignore` становятся инертны — проверено экспериментом

`.gitignore:61` — сплошное `*.json`. Строки 99-100 пробивают его точечно:

```
!/src/Reporting/Template/package.json
!/src/Reporting/Template/package-lock.json
```

После переноса в копии:

```
git check-ignore -v --no-index frontend/package.json
→ .gitignore:61:*.json	frontend/package.json
git check-ignore -v --no-index frontend/package-lock.json
→ .gitignore:61:*.json	frontend/package-lock.json
git check-ignore -v --no-index frontend/newthing.json
→ .gitignore:61:*.json	frontend/newthing.json
```

(до переноса тот же вопрос про `src/Reporting/Template/package.json` отвечал
`.gitignore:99:!/src/Reporting/Template/package.json`)

Уже отслеживаемые файлы остаются отслеживаемыми, поэтому `git status` после
`git mv` **чист** — ровно та тишина, которую комментарий в самом `.gitignore`
(строки 87-93) описывает про предыдущий такой перенос: «правило стало инертным,
и ничто об этом не сказало; двадцать fixture-файлов уже были отслеживаемы, так
что продолжили работать, и молча проглотился бы только *новый* файл».

Последствие: любой новый `.json` под новым каталогом проглатывается молча — и
сканирование C2 (`git ls-files --cached --others --exclude-standard`) его тоже
не увидит.

---

## C. Генераторы архитектуры и governance

### C0. Прямой ответ на вопрос «попадает ли каталог в манифест» — проверено по артефактам

**В манифесте `docs/internal/modular-architecture-manifest.json` каталога нет
вообще.** Обход всего JSON даёт ровно одно попадание слова «Template»:
`/declarations/Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition/path`
— другой предмет (слои архитектурной политики), к HTML-отчёту отношения не
имеет. Причина проста: манифест описывает production-декларации, а PHP-файлов
под каталогом ноль.

**В генерируемых артефактах — один файл и 10 строк.** Из 28 отслеживаемых
файлов под деревом в каком-либо артефакте под
`docs/internal/generated/` упоминаются **10**: `package.json`,
восемь `tests/*.test.js`, `vite.config.js` — все в
`test-ownership.tsv:162-171`. Остальные **18** (`report.html`, `report.css`,
`dist/d3.min.js`, `dist/report.min.js`, `dev.html`, девять `src/*.js`, три
`scripts/*.mjs`, `package-lock.json`) не числятся **ни в одной** описи — ни
production, ни test. Поэтому production-генератор при переносе молчит, а
падает только test-инвентарь (C1).

Восемнадцать беспризорных файлов — это как раз то, для чего у переноса нет
машинного оракула владения.

### C1. `composer architecture:check` / `selfcheck` / `check:artifacts` — проверено экспериментом

`php scripts/generate-modular-architecture.php --check`, exit **1**, дословно:

```
Generator failed with exit 1: … scripts/generate-modular-architecture-test-inventory.php --output-directory=…
A test artifact's owner is a manifest owner, and these are not:
  Reporting/HtmlTemplate is allowed here for 10 row(s) and the tree now has 0 (the JS bundle tests and configs under src/Reporting/Template/. Their target root is not a manifest owner either, and the relocation they assert is named to no package — one of the rows 04-packages.md hands to the owner.)
Either file the artifact under the owner that owns it, or name the exception in NON_MANIFEST_TEST_OWNERS with the reason and the row count.
```

Источник — объявление с **жёстко заданным числом строк**:
`scripts/generate-modular-architecture-test-inventory.php:361-367`,
`'Reporting/HtmlTemplate' => ['rows' => 10, …]`.

Эти 10 строк живут в `docs/internal/generated/modular-architecture/test-ownership.tsv:162-171`:
`package.json`, восемь `tests/*.test.js`, `vite.config.js`; владелец
`Reporting/HtmlTemplate`, целевой путь `tests/Reporting/HtmlTemplate/Tests/…`,
диспозиция `"Move atomically with the named subject owner."`
**Скриптов `scripts/*.mjs` в этих 10 строках нет** — предписанный TSV-ом перенос
раскалывает npm-проект.

Один отказ гасит сразу: `composer architecture:check`, `composer selfcheck`
(его первая половина), `composer check:artifacts` и governance-тест
`ModularArchitectureGovernanceIntegrationTest::itChecksEveryGeneratedProjectionWithoutWriting`.

### C2. Литерал области сканирования — молчаливый сам по себе, громкий из-за C1

`scripts/generate-modular-architecture-test-inventory.php:424-431`, пathspec для
`git ls-files`, три литерала:

```
'src/Reporting/Template/tests', 'src/Reporting/Template/package.json', 'src/Reporting/Template/vite.config.js',
```

`git ls-files` по исчезнувшему пути возвращает пусто и не ругается — сам литерал
отказать не умеет (это тот самый «fails **silently**» из AGENTS.md). Громким его
делает только счётчик `rows => 10` из C1. **Если переносить и править `rows`, не
поправив pathspec, десять файлов уйдут из инвентаря молча.**

### C3. Изолированный проект мувера — проверено экспериментом

`scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php:389`
— `createIsolatedProject()` делает `git add -- … 'src/Reporting/Template'`.
Четыре пробы падают одинаково:

```
fatal: спецификатор пути «src/Reporting/Template» не соответствует ни одному файлу
Failed asserting that 128 is identical to 0.
```

(`itFailsWhenAPhpunitTestClassHasNoConfiguredSuite`,
`itFailsWhenACurrentSuiteLiteralHasNoDeclaredDirectory`,
`itFailsWhenATestClassNamesTwoLevels`,
`itFailsWhenARowUnderTestsPublishesANonManifestOwner`)

### C4. Реестр вручную прописанных кодов правил — проверено экспериментом

`governance/RuleDeclaration/RuleIdentifierLiteralGuardTest.php:228`,
`EXISTENCE_CHECKED_FILES` содержит `'src/Reporting/Template/dev.html'`. Даёт три
сигнала за один прогон:

```
RuntimeException: Could not read <root>/src/Reporting/Template/dev.html.
```
```
src/Reporting/Template/dev.html is existence-checked but no longer exists.
Failed asserting that two arrays are identical.
```
плюс PHP-warning `file_get_contents(...): Failed to open stream` (при
`failOnWarning="true"` это тоже красное).

### C5. Перепись переименований — проверено экспериментом, но молчаливая половина остаётся

`composer enumeration:renames:check` в основном дереве — exit **0**
(`up to date (58 channel, 54 producer, 82 metric-key rows, 113 executed)`).
В копии после переноса — exit **1**:

```
… /finding-gate/enumeration-renames.tsv is stale: 76 of 351 line(s) differ from a fresh measurement. Run `composer enumeration:renames` to refresh it.
  line 15:
    committed:    code-smell.boolean-argument	channel	?		4	37	…
    regenerated:  code-smell.boolean-argument	channel	?		2	37	…
```

Причина — `scripts/generate-rename-enumeration.php:45-50`: поверхность `src`
ходит по корню `src` c `excludeDirs ['node_modules','dist']` и одним
`excludeFiles`-литералом `'src/Reporting/Template/package-lock.json'`. То есть
`dev.html`, `report.html`, `report.css`, `src/*.js`, `tests/*.js`,
`scripts/*.mjs` сегодня **считаются в колонку `src`**. После переноса
`frontend/` не входит ни в одну поверхность.

Молчаливая половина ровно та, о которой предупреждает AGENTS.md: рефреш TSV
сделает прогон зелёным, а 76 строк прочитаются как «эти имена каналов пропали
из `src`» — падение в колонке, которое никто не перевыводит. Плюс
`excludeFiles`-литерал с локфайлом станет инертным.

---

## D. Сборка и тесты фронта

### D1. `composer test:js` и `composer build:js` — проверено экспериментом

`composer.json`: `"test:js": "cd src/Reporting/Template && npm test"`,
`"build:js": "cd src/Reporting/Template && npm run build"`. Оба exit **1**:

```
sh: line 0: cd: src/Reporting/Template: No such file or directory
Script cd src/Reporting/Template && npm test handling the test:js event returned with error code 1
```

`@test:js` входит в `check:code` (`['@cs-check','@phpstan','@test:aggregate','@dangling-names','@test:js','@test:cross-tool']`),
поэтому `composer check` умирает здесь.

Эти две строки в governance **не запинены**: `assertFreshnessScriptGraph`
проверяет `test`, `test:aggregate`, `selfcheck`, `selfcheck:analysis`,
`check:code[2]`, `check:artifacts`, `test:cross-tool`, `check:self` — `test:js`
и `build:js` среди них нет. Значит правка `composer.json` под новый путь
governance-тест **не** покраснит.

### D2. Жёсткая глубина 4 в JS-тулинге, чтение — проверено экспериментом

`src/Reporting/Template/scripts/metric-key-catalog.mjs:17`:
`const REPO_ROOT = resolve(__dirname, '..', '..', '..', '..');`

`npm test` внутри перенесённого `frontend/` — exit **1**, 7 из 8 файлов зелёные,
падает один:

```
FAIL tests/metric-key-catalog.test.js > metric-key catalog > every family-shaped string literal in src/*.js is a real MetricName catalog key
Error: ENOENT: no such file or directory, open '<scratchpad>/src/Analysis/Evidence/Measurement/Contract/MetricName.php'
 ❯ loadCatalog scripts/metric-key-catalog.mjs:48:40
```

Путь в ошибке — **на два уровня выше корня репозитория**: ровно тот класс
«устаревшая глубина резолвится в каталог над репозиторием», о котором
предупреждает AGENTS.md. Здесь он громкий только потому, что это чтение.

### D3. Та же глубина, но запись — проверено экспериментом (умирает раньше)

`src/Reporting/Template/scripts/collect-metric-keys.mjs:12-16` пишет
`resolve(__dirname,'..','..','..','..','finding-gate/enumeration-js-metric-keys.tsv')`.
Цель записи после переноса уезжает за пределы репозитория. На практике скрипт
падает раньше — на чтении из D2:

```
Error: ENOENT: no such file or directory, open '<scratchpad>/src/Analysis/Evidence/Measurement/Contract/MetricName.php'
    at loadCatalog (…/frontend/scripts/metric-key-catalog.mjs:48:28)
```

Проверено, что ничего за пределы копии записано не было и
`finding-gate/enumeration-js-metric-keys.tsv` в копии остался нетронут
(`git status finding-gate/` чист).

**Оба резолва зависят от назначения.** Корневой `frontend/` ломает их обоих;
любое размещение ровно на три уровня ниже корня (как сейчас) — нет.
`vite.config.js` и `scripts/bundle-d3.js` считают пути относительно самого
Template и переезд переживают.

### D4. Продуктовые PHPUnit-тесты формирователя — проверено экспериментом

Полный `phpunit --exclude-group=benchmark` в копии: **9156 тестов, 15 errors,
7 failures, 1 warning, exit 2**. Атрибуция прямая, а не по разнице с прогоном
«до»: каждое из 22 падений называет в собственном сообщении либо
`src/Reporting/Template`, либо владельца `Reporting/HtmlTemplate`.

14 из 15 ошибок — один и тот же `RuntimeException` из A1:

- `Tests\Reporting\Unit\Formatter\ArchitectureViolationSmokeTest::itRendersArchitectureViolationsViaHtmlFormatter`
- `Tests\Reporting\Unit\Formatter\Html\HtmlFormatterTest` — 8 методов
  (`itProducesValidHtml`, `itEmbedsCssInline`, `itEmbedsJsInline`,
  `itEmbedsJsonData`, `itUsesJsonHexTagEncoding`, `itEncodesScopedReportingFlag`,
  `itFormatsWithNullMetrics`, `itEmbedsHintsData`)
- `Tests\Reporting\Integration\CoverageProjectionFormatterTest::itProjectsCoverageInEveryNativeFormat` — 5 датасетов `html-*`

15-я ошибка и одна из failure — C4; ещё четыре failure — C3; одна — B3; одна — C1.

---

## E. CI — выведено из кода, не исполнялось

`.github/workflows/qmx.yml`:

- строка 139: `cache-dependency-path: 'src/Reporting/Template/package-lock.json'`
  (`actions/setup-node@v4`);
- строка 142: `run: npm ci --prefix src/Reporting/Template`.

Оба — литералы пути. `npm ci --prefix` по несуществующему каталогу упадёт
громко; `cache-dependency-path`, не разрешившийся ни в один файл, у
`setup-node` — тоже отказ шага. Проверить прогоном не мог.

`Dockerfile` пути не называет (`COPY . .`). `.dockerignore`: `**/node_modules/`
и `**/tests/` накрывают и старое, и новое размещение одинаково, `dist/`
корневой и `frontend/dist` не трогает — четыре ассета в образ попадают как и
раньше. Отдельной поломки Docker нет, но формирователь чинить всё равно надо.

---

## F. Что становится устаревшим молча (документация)

Ни один машинный контроль это не ловит — и это проверено, а не предположено:
набор `Governance` после переноса прогнал **779 тестов**, покраснели только
четыре названные выше (C1, C3 в виде `RuleIdentifierLiteralGuardTest`, C4, B3),
а `src/Reporting/README.md:805` всё это время оставался устаревшим и зелёным.
То есть `governance/DocumentationCensus` структуру README против дерева не
сверяет. В `website/` упоминаний **ноль**, так что `composer docs:check` не при
чём.

- `AGENTS.md:611` и `AGENTS.md:653`
- `src/Reporting/README.md:805` (`cd src/Reporting/Template`)
- `finding-gate/README.md:52` — строка таблицы «артефакт → производитель →
  потребитель» для `enumeration-js-metric-keys.tsv`, обе ячейки называют путь
- `docs/adr/0012-hybrid-architectural-direction.md:70,100,107,109` — исторический
  ADR, «HTML Report уже почти вертикальный по адресу `src/Reporting/Template/`»
- `docs/internal/plans/closure-package-retirement/01-removal.md:216`,
  `docs/internal/plans/health-recalibration/06-display.md:53` и
  `…/measurement/04-consumers.md` (много строк)
- `CHANGELOG.md:1143` — историческая запись, трогать не надо

---

## G. Что НЕ ломается (проверено, чтобы план не платил за это зря)

Все — эксперимент в копии после переноса:

| Проверка                                                                                            | Итог                                |
| --------------------------------------------------------------------------------------------------- | ----------------------------------- |
| `phpstan analyse` (кэш снесён)                                                                      | exit 0, `[OK] No errors`            |
| `php-cs-fixer fix --dry-run --diff`                                                                 | exit 0, `"files":[]`                |
| `composer selfcheck:analysis` (`bin/qmx check src/ --baseline=qmx-baseline.json --fail-on=warning`) | exit 0, `No violations found.`      |
| `composer suppression-snapshot:check`                                                               | exit 0 (225 + 19 строк, up to date) |
| `composer enumeration:runtime-channels:check`                                                       | exit 0                              |
| `composer enumeration:directives:check`                                                             | exit 0 (44 сайта)                   |
| `composer input-doors:grid:check`                                                                   | exit 0 (241 + 17 строк)             |
| `composer promise-effect:p1-set:check`                                                              | exit 0                              |
| `composer promise-effect:grid:check`                                                                | exit 0 (7218 ячеек)                 |
| `composer gate:self-test`                                                                           | exit 0                              |
| `composer dangling-names`                                                                           | exit 0                              |
| `composer check-leaks`                                                                              | exit 0                              |
| `composer directives:audit`                                                                         | exit 0 (inert 0, unmeasured 0)      |

Выведено из кода:

- **`composer.json` править не надо.** Под `src/Reporting/Template/` **ноль**
  PHP-файлов (проверено `find`), поэтому PSR-4 `Qualimetrix\: src/`, classmap и
  `composer dump-autoload --optimize --classmap-authoritative` (то, что делает
  Dockerfile) переносом не затрагиваются. То же для `phpstan.neon paths`,
  финдера `.php-cs-fixer.dist.php` и `<source><include>` в `phpunit.xml.dist`.
- **`phpunit.xml.dist` не называет каталог** — ни в одном `<testsuite>` нет
  директории под `src/`, так что ловушки «пустой каталог → exit 2» здесь нет.
- **`qmx.yaml` каталог не упоминает**; `node_modules` — встроенное исключение
  обнаружения (`RunConfigurationResolver::BUILT_IN_EXCLUDES = ['vendor','node_modules','.git']`),
  так что `bin/qmx check src/` его и сейчас не ходит.
- **`.githooks/pre-commit`** фильтрует `^(src|tests|governance|scripts|tools)/`
  и `\.php$` — 0 PHP-файлов, эффекта нет.
- **`governance/TestSuiteHygiene/…::ROOTS = ['tests','governance','scripts','tools']`**
  — `src` в списке нет, переносом не затрагивается.
- **`governance/FormatOptionKeys/FormatOptionKeyDeclarationTest.php:239`** —
  `if (str_contains($relativePath, '/Template/')) continue;` при обходе
  `src/Reporting`. Это пропуск, который **уже сегодня вакуумен** (пропускать
  нечего, PHP-файлов нет). После переноса станет инертным, но охрана от этого
  не изменится ни на йоту.

---

## H. Жёсткие счётчики над генерируемыми артефактами (вопрос 5)

**`governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php:105`,
`assertCount(28, $this->tsv('test-orphan-dispositions.tsv'))` — НЕ про этот
каталог.** Артефакт — `docs/internal/generated/modular-architecture/test-orphan-dispositions.tsv`,
29 строк файла = заголовок + 28 строк данных; `grep -c Template` по нему даёт
**0**. Совпадение с 28 отслеживаемыми файлами под Template — случайное. Тест
после переноса краснеет, но по другой причине (C1, соседний метод того же
класса).

Полный свип `assertCount(<число>` по `governance/` — 11 попаданий, ни одно не
про этот предмет:

| Адрес                                                                | Предмет                        |
| -------------------------------------------------------------------- | ------------------------------ |
| `HealthVocabulary/ComputedMetricDefaultsTest.php:22` — `6`           | computed-метрики               |
| `FindingVocabulary/SuppressionMechanismTest.php:35` — `7`            | enum-кейсы                     |
| `ModularOwnership/…IntegrationTest.php:105` — `28`                   | test-orphan-dispositions.tsv   |
| `ModularOwnership/…IntegrationTest.php:106` — `1`                    | test-system-support-owners.tsv |
| `ModularOwnership/ComputedMetricsInternalTopologyTest.php:62` — `52` | декларации ComputedMetrics     |
| остальные 6 (`assertCount(1, …)`)                                    | по одному нарушителю в стендах |

**Настоящий жёсткий счётчик про этот каталог — другой и лежит не в governance:**
`scripts/generate-modular-architecture-test-inventory.php:363`, `'rows' => 10`
внутри `NON_MANIFEST_TEST_OWNERS['Reporting/HtmlTemplate']` (см. C1). Свип по
`assertCount` его не видит — это не assert, а элемент конфигурационной
константы.

---

## I. Остался ли `src/` чистым PSR-4-корнем (вопрос 6)

Сегодня **не чист**: внутри PSR-4-корня живёт npm-проект — 1342 файла на диске
(из них 27 МБ `node_modules`), 28 отслеживаемых. Но PHP-файлов в нём **ноль**,
поэтому на корректность composer/PHPStan/cs-fixer это не влияет. (Влияет ли на
цену обхода — **не измерял**: времени с `node_modules` и без я не снимал.)
После переноса `src/` становится чистым, и **ничего в
`composer.json`, `phpstan.neon` и финдере cs-fixer по этой причине менять не
требуется** (подтверждено зелёными PHPStan и cs-check в копии после переноса).

Обратная сторона: `src/` перестаёт быть местом, где `git ls-files src` находит
фронт, — а именно на этом построены поверхность `src` в C5 и pathspec в C2.

---

## Сводка

| #   | Поломка                                                                                 | Чем доказано                                                                        | Громко / молча                     |
| --- | --------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- | ---------------------------------- |
| A1  | `--format=html` не собирается, exit 1                                                   | эксперимент                                                                         | громко                             |
| A2  | Совет в тексте отказа называет старый путь                                              | эксперимент                                                                         | молча (только человеку)            |
| A3  | Резолв у потребителя обязан остаться внутри `vendor/`                                   | код + `git archive`                                                                 | — (требование)                     |
| B2  | Пакет 4 → 28 файлов, 7 строк `export-ignore` инертны                                    | эксперимент                                                                         | **молча**                          |
| B3  | Страж поставки падает до измерения; после правки `TREE` краснеет на 24 файлах           | эксперимент (обе половины)                                                          | громко, но не про предмет          |
| B4  | Две негации `.gitignore` инертны, новый `.json` под каталогом проглатывается            | эксперимент (`git check-ignore -v`)                                                 | **молча**                          |
| C0  | Каталога нет в манифесте; 10 из 28 файлов в `test-ownership.tsv`, 18 — ни в одной описи | артефакты                                                                           | — (факт для плана)                 |
| C1  | `architecture:check` / `selfcheck` / `check:artifacts`: `rows => 10` против 0           | эксперимент                                                                         | громко                             |
| C2  | Три литерала в pathspec `git ls-files`                                                  | код                                                                                 | **молча** (громко только из-за C1) |
| C3  | 4 пробы `ModularArchitectureGeneratorRefusalTest`                                       | эксперимент                                                                         | громко                             |
| C4  | `EXISTENCE_CHECKED_FILES` → error + failure + warning                                   | эксперимент                                                                         | громко                             |
| C5  | `enumeration-renames.tsv`: 76 из 351 строк расходятся                                   | эксперимент (зелено в main, красно в копии)                                         | громко; **молча** после рефреша    |
| D1  | `composer test:js` и `build:js`, они же `check:code`                                    | эксперимент                                                                         | громко                             |
| D2  | `metric-key-catalog.mjs` REPO_ROOT глубины 4 → ENOENT над корнем                        | эксперимент                                                                         | громко                             |
| D3  | `collect-metric-keys.mjs` пишет за пределы репозитория (умирает раньше на D2)           | эксперимент                                                                         | было бы молча                      |
| D4  | 15 errors + 7 failures в полном PHPUnit (exit 2)                                        | эксперимент                                                                         | громко                             |
| E1  | CI: `cache-dependency-path` и `npm ci --prefix`                                         | код                                                                                 | громко (не исполнялось)            |
| F   | 7 документов устаревают                                                                 | код; то, что никакой контроль этого не ловит, — эксперимент (779 governance-тестов) | **молча**                          |

**17 поломок** (A1, A2, A3, B2, B3, B4, C1, C2, C3, C4, C5, D1, D2, D3, D4, E1, F).
**Проверено экспериментом — 13:** A1, A2, B2, B3, B4, C1, C3, C4, C5, D1, D2,
D3, D4. **Выведено из кода/артефактов — 4:** A3, C2, E1, F.
Плюс C0 — не поломка, а факт владения, выведенный из артефактов.

---

## Чего я не покрыл

1. **CI-прогон.** `.github/workflows/qmx.yml` не исполнялся; поведение
   `setup-node` при неразрешившемся `cache-dependency-path` — вывод из
   документации действия, а не измерение.
2. **`composer docs:check`.** Упал в копии из-за того, что я исключил
   `website/.venv` из rsync (`Install it into a local virtualenv`), а не из-за
   переноса. Косвенно: упоминаний пути в `website/` — ноль.
3. **`composer gate`** (полный finding-gate против reference-ревизии) и
   `gate:controls` — не гонял, дорого. `gate:self-test` зелёный.
4. **`composer benchmark:check`, `health:bench`, `directives:controls`,
   `directives:narrow-control`, `input-doors`, `promise-effect` (полные, не
   `:check`)** — не гонял.
5. **Прямой прогон полного PHPUnit «до переноса» в копии не делался.** На
   атрибуцию это не влияет (каждое из 22 падений само называет предмет), но
   если план захочет число «сколько было зелёных до», его надо снять отдельно.
6. **Docker-образ не собирался** — вывод по `.dockerignore` и `Dockerfile`
   только текстовый.
7. **Другие назначения переноса.** Всё измерено для корневого `frontend/`.
   От назначения зависят B2, B4, D2 и D3. Два очевидных варианта:
   - **всё дерево под `tests/…`** — строка `/tests/ export-ignore` накроет его
     целиком, и в composer-пакет не попадут уже и четыре нужных ассета, то есть
     `--format=html` умрёт у потребителя (не измерено, вывод из `.gitattributes`);
   - **только 10 строк, как предписывает `test-ownership.tsv`** — npm-проект
     раскалывается: в предписании нет ни `scripts/*.mjs`, ни ассетов, а
     `vite.config.js` будет ссылаться на `resolve(__dirname,'src/main.js')` и
     `test.include: ['tests/**/*.test.js']`, которых рядом не окажется.
     Не измерено.
8. **Свип по ссылкам я сознательно не делал** — это работа второго агента.
   Перечисленные в F/E адреса найдены как побочный продукт вопросов «какой
   артефакт» и «какой контракт», а не как попытка полного перечисления.
