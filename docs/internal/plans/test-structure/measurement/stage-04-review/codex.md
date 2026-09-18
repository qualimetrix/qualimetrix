# Stage 04 plan review — codex (external CLI reviewer)

Материал: `04-subject-layout.md`, `04-packages.md`, `06-governance-subject-groups.md`,
`00-overview.md`, шесть файлов `measurement/stage-04/`. Ревью плана, код не исполнен.
Вердикт codex: план требует переработки до исполнения — раскладка и базовая
арифметика когерентны, но P5 невыполним на реальном корпусе как описан, P6
гарантированно ломает два guard'а генератора, и контракты multi-owner
`#[CoversClass]`, положительный контракт `targetPath()` и монотонность
`LEGACY_UNMOVED` недоопределены.

### codex-01

- **reviewer**: codex
- **severity**: HIGH
- **kind**: contract
- **domain**: tests
- **title**: P6 не включает обязательные изменения генератора при переносе `FindingFactory`
- **mechanism**: P6 переносит `tests/Analysis/Finding/Support/FindingFactory.php` в
  `tests/Analysis/Policy/Baseline/Support/`, но не называет генератор в своём
  файловом наборе. Старый путь зашит в `P6_A_FINDING_TEST_PATHS`
  (`scripts/generate-modular-architecture-test-inventory.php:202`), которую
  проверяет fail-closed `assertPathLiteralsResolve()`. Отдельно `P6_C_BASELINE_PATHS_SHA256`
  (строка 17) — это sha256 от списка путей Baseline-теста, вычисляемого
  `p6CBaselinePaths()` и сверяемого на строке 358; перенос файла в Baseline
  меняет этот список и, следовательно, хэш.
- **trigger**: точное исполнение P6 как написано — файл переносится, генератор не редактируется
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/04-packages.md:212-226`;
  `scripts/generate-modular-architecture-test-inventory.php:17,194-202,358`
- **evidence**: подтверждено прямым чтением генератора фасилитатором —
  `P6_A_FINDING_TEST_PATHS` (строка 194) содержит
  `'tests/Analysis/Finding/Support/FindingFactory.php'` (строка 202);
  `p6CBaselinePaths()` пересчитывается и сверяется с зафиксированным digest на
  строке 358 (`fail('P6-C Baseline test artifact set differs...')`).
- **verification**: confirmed
- **verification_note**: оба отказа следуют из порядка проверок генератора без изменения дерева; фасилитатор перепроверил обе строки в исходнике.
- **fix_direction**: включить генератор (обе константы) в файловый набор P6, определить судьбу строки Finding-closure и зафиксировать заново Baseline digest; после этого P5 и P6 делят один файл (`phpunit.xml.dist`/генератор) и не могут декларироваться как независимо параллельные.

### codex-02

- **reviewer**: codex
- **severity**: HIGH
- **kind**: contract
- **domain**: tests
- **title**: Потолок исключений P5 (8) на порядок меньше фактического корпуса без `#[CoversClass]` (84)
- **mechanism**: P5 — контроль над всем продуктовым тестовым деревом, а не только над 114 перемещаемыми файлами. Потолок 8 выведен только из undecidable-строк `relocation-map.csv`, а не из полного корпуса `tests/`.
- **trigger**: первый запуск P5 после реализации над заявленным полным деревом
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/04-subject-layout.md:127-131`;
  `docs/internal/plans/test-structure/04-packages.md:188-191`;
  `measurement/stage-04/prediction.md:98-107`
- **evidence**: по утверждению codex — read-only census по строкам `kind=phpunit-test-class` в сгенерированном `test-ownership.tsv` даёт 616 всего / 84 без `CoversClass`.
- **verification**: confirmed (со стороны codex; фасилитатор не переисполнял census независимо — команда воспроизводима, но не перезапущена в рамках этого ревью)
- **verification_note**: число 8 в плане явно привязано к 114-строчной карте (`prediction.md` подтверждает это), а не к полному дереву — план сам не утверждает иного источника, так что контрактный разрыв подтверждается текстом плана независимо от точного значения 84.
- **fix_direction**: сначала перечислить полный корпус P5, отдельно классифицировать все случаи без `#[CoversClass]`, затем выбрать полный exception list или более узко сформулированный инвариант.

### codex-03

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: architecture
- **title**: Трёхчастный контроль P5 не определяет семантику multi-owner `#[CoversClass]`
- **mechanism**: контракт P5 говорит про namespace единственного `#[CoversClass]`, но минимум три перемещаемых теста покрывают классы двух разных manifest owners одновременно (карта помечает их `CoversClass(multi)+decision`). Неясно, что проверяет "remainder = namespace CoversClass минус owner", когда `CoversClass` не один.
- **trigger**: P5 проверяет один из размеченных multi-owner файлов (`RulesCommandWiringTest`, `MaxExpandedLayersFromYamlTest`, аналогичные)
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/04-subject-layout.md:116-125`;
  `measurement/stage-04/relocation-map.csv` (строки с `note` вида `DECIDED:` и witness `CoversClass(multi)+decision` — 3 из 11 decided-строк)
- **evidence**: подтверждено чтением `relocation-map.csv` фасилитатором — 11 строк несут `DECIDED:` (`grep -c "DECIDED:" ... → 11`), план сам говорит о "3 covering two owners" (`04-subject-layout.md:63-64`).
- **verification**: confirmed
- **verification_note**: план прямо признаёт существование multi-owner строк, но P5 (`04-packages.md:182-207`) формулирует инвариант только через единственный `CoversClass`.
- **fix_direction**: определить проверяемую семантику multi-owner случая — явный primary subject в файле или отдельный adjudication-список, и явно указать, какой из владельцев обязан совпасть с владельцем пути.

### codex-04

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: DoD P0 не доказывает новый контракт `targetPath()` на положительных примерах
- **mechanism**: P0 проверяется на дереве, где все 114 путей всё ещё обслуживаются точной таблицей `LEGACY_UNMOVED`, и planted-breakage в DoD P0 — только отрицательные пробы (неизвестный owner). Нет позитивной пробы, что переписанный `targetPath()` верно строит remainder для вложенного пути, для Infrastructure-owner и для `Core.Neutral`, хотя именно эти три формы `generator-probe.md` уже показал ломающимися сегодня.
- **trigger**: P0 реализует allow-list правильно, но ошибается в общем parser-поведении; все три заявленных planted breakage при этом остаются зелёными, как и предсказано
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/04-packages.md:27-46,58-70`;
  `measurement/stage-04/generator-probe.md:9-15`
- **evidence**: `generator-probe.md` таблица — 5 из 6 послемувных путей классифицируются неверно сегодня, 4 молча; ни один из этих трёх случаев (nested remainder / Infrastructure owner / Core.Neutral) не назван в списке из трёх planted breakages P0 (`04-packages.md:66-71`).
- **verification**: confirmed
- **verification_note**: то, что генератор должен переписываться до/вместе с первым move — подтверждено пробой; но сам P0 DoD не покрывает положительный контракт, который это обосновывает.
- **fix_direction**: добавить positive probes для всех трёх форм (nested remainder, Infrastructure owner, Core.Neutral) в DoD P0, либо явно смержить изменение контракта с первым move-пакетом так, чтобы реальные post-move пути проверялись сразу.

### codex-05

- **reviewer**: codex
- **severity**: MEDIUM
- **kind**: point
- **domain**: reliability
- **title**: Монотонность `LEGACY_UNMOVED` заявлена, но planted breakage её не проверяет
- **mechanism**: DoD P0 доказывает guard только пробой "добавить строку на отсутствующий файл" — это existence-check, не anti-growth-check. Если между P0 и P4 кто-то одновременно добавит новый файл в legacy bucket и соответствующую строку allowance, planted-тест из DoD этого не поймает: "может только терять записи" не имеет названного immutable источника исходных 114 ключей, относительно которого росла бы проверка.
- **trigger**: между P0 и P4 добавляется новый legacy-файл вместе с записью в `LEGACY_UNMOVED`
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/04-packages.md:52-70`
- **evidence**: три planted breakage P0 (строки 66-71) — все про несуществующий путь или про неверный owner; ни один не проверяет "существующий новый файл + согласованная строка allowance".
- **verification**: confirmed
- **verification_note**: не отклонён, так как дыра именно в parity-версии проверки ("файл+запись добавлены согласованно"), а не в отдельном existence-guard — это подтверждается текстом DoD P0.
- **fix_direction**: назвать неизменяемый источник исходных 114 ключей (например, зафиксированный на коммите P0 список) и проверять `LEGACY_UNMOVED` как подмножество этого источника; добавить в planted-набор случай "существующий синтетический legacy-файл + новая строка".

### codex-06

- **reviewer**: codex
- **severity**: LOW
- **kind**: pattern
- **domain**: tests
- **title**: P4 предписывает "переисчислить" два независимых от 114-строчной карты счётчика
- **mechanism**: P4 требует "re-derive" `assertCount(28,...)` над `test-orphan-dispositions.tsv` и `assertCount(1,...)` над `test-system-support-owners.tsv`. По утверждению codex оба числа строятся из источников (`P8_ORPHAN_DISPOSITIONS` и отдельный `TestSupport`-список), не пересекающихся со 114 перемещениями — то есть эти числа не должны меняться, а формулировка "пересчитать" рискует нормализовать постороннюю регрессию под видом ожидаемого обновления.
- **trigger**: исполнитель меняет 28/1 вслед за новым generated output без отдельного обоснования, почему изменились именно эти источники
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/04-packages.md:159-167`
- **evidence**: `addresses-witness-a.md:61` и `addresses-witness-b.md:36` (оба прочитаны фасилитатором) независимо оценивают оба счётчика как "unaffected"/"untouched" этим стейджем.
- **verification**: confirmed
- **verification_note**: оба свидетеля plan-измерений сходятся на "не затронуто" — план мог бы явно зафиксировать 28/1 как неизменные ожидаемые значения вместо нейтральной формулировки "re-derive".
- **fix_direction**: явно зафиксировать 28 и 1 как ожидаемые неизменные значения в DoD P4; трактовать любое отклонение как отдельный сигнал, требующий независимого объяснения, а не рутинную часть пересчёта.

### codex-07

- **reviewer**: codex
- **severity**: LOW
- **kind**: point
- **domain**: reliability
- **title**: Несведённое расхождение популяции между Witness B и остальными источниками (91/23 vs 88/26)
- **mechanism**: `addresses-witness-b.md` заявляет "91 rows currently sit under tests/Unit/, tests/Integration/, tests/Functional/; 23 rows already sit under [owner-prefixed paths]" — то есть разбиение 91/23. `prediction.md` и сама `relocation-map.csv` дают 88 legacy-bucket / 26 residue. `addresses.md`, сводящий обоих свидетелей, называет их расхождения по адресам регистрации, но не называет это расхождение в базовой популяции.
- **trigger**: план опирается на объединение двух свидетелей как на доказательство полноты enumерации адресов
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/measurement/stage-04/addresses-witness-b.md:6-13` vs `measurement/stage-04/prediction.md:86-88`
- **evidence**: witness-b.md, строки 8-12: "91 rows currently sit under `tests/Unit/`, `tests/Integration/`, `tests/Functional/`; 23 rows already sit under `tests/Analysis/…`, `tests/Infrastructure/…`, `tests/Reporting/…`". `prediction.md`, строка 87: "114 rows: 88 in the legacy buckets, 26 pre-existing residue." Обе цифры прочитаны фасилитатором напрямую из файлов.
- **verification**: confirmed
- **verification_note**: расхождение реально и не разрешено в `addresses.md`; итоговое разбиение по пакетам (P1=52, P2=51, P3=11 = 114) при этом корректно и им не затронуто.
- **fix_direction**: исправить формулировку популяции в Witness B и явно внести это расхождение в раздел сведения `addresses.md`, чтобы подтвердить, что ошибочная тройка/двадцать-три не повлияла на найденные адреса.

## Coverage

Прочитано и проверено фасилитатором и codex: все четыре файла плана (`04-subject-layout.md`,
`04-packages.md`, `06-governance-subject-groups.md`, `00-overview.md`); все шесть
файлов `measurement/stage-04/` (`relocation-map.csv`, `prediction.md`, `addresses.md`,
`addresses-witness-a.md`, `addresses-witness-b.md`, `generator-probe.md`); `CLAUDE.md`;
ADR 0016 и ADR 0022; `docs/internal/modular-architecture-manifest.json`; ключевые функции
`scripts/generate-modular-architecture-test-inventory.php` (`classifyOwner()`, `targetPath()`,
`dispositionFor()`, `testSuitePrefixTable()`, `assertPathLiteralsResolve()`,
`p6CBaselinePaths()`, константы `P6_A_FINDING_TEST_PATHS`, `P6_C_BASELINE_PATHS_SHA256`,
`RETIRED_PATH_ASSERTIONS`); `phpunit.xml.dist`.

Признано чистым: манифест содержит ровно 37 владельцев, `Core.Neutral` — единственный
нестандартный path mapping; CSV содержит 114 уникальных target paths с корректным
разбиением по пакетам P1=52/P2=51/P3=11; базовая арифметика suite-счётчиков сходится
(baseline 6987+419+203+660+179+748=9196; после P1/P2/P3 6705+383+152+1029+179+748=9196
для всех трёх пакетов, как и требует DoD P2/P3 "suite-neutral"); `validateInventory()`
уже отказывает при коллизии target path (снимает гипотетическую находку про два файла
с одинаковым target); вывод `generator-probe.md` о необходимости переписать генератор
до/вместе с первым move подтверждён чтением кода; порядок P0→P1→P2→P3→P4 обоснован
корректно относительно риска "P0 после move оставил бы дерево, самосогласованное с
неверным инвентарём" — альтернативная перестановка (move первым) не лучше по этому
критерию.

Не покрыто / не перепроверено независимо: точное число 616/84 в census без `#[CoversClass]`
(codex-02) — команда воспроизводима, но не перезапущена в рамках этого ревью;
полная сверка всех 11 DECIDED-строк на предмет других скрытых multi-owner эффектов,
кроме трёх, названных в codex-03; независимая проверка, действительно ли все 108
не-multi-owner строк карты классифицируются корректно новым path-parse контрактом
(генератор не исполнялся на реально перемещённом дереве ни codex, ни фасилитатором —
это прямо названо ограничением обоих witness-отчётов); PHPStan baseline на предмет
line-scoped игнорирований по перемещаемым файлам; содержимое YAML/JSON фикстур на
предмет путей-как-данных.

## Отклонённые находки

- codex-08 | Арифметика suite prediction не сходится | Опровергнуто: обе контрольные строки (baseline и после P1/P2/P3) дают ровно 9196.
- codex-09 | Несколько файлов могут молча получить одинаковый target | Опровергнуто: текущая карта уникальна по target, и `validateInventory()` отказывает при коллизии.
- codex-10 | Новый legacy-файл без изменения allowance пройдёт молча | Опровергнуто: такой файл будет отвергнут path-parser'ом как неизвестный; реальная дыра — только при согласованном добавлении файла и записи allowance одновременно, что отражено отдельно в codex-05.
- codex-11 | Точная карта не умеет переносить многосегментный remainder или `Core.Neutral` | Опровергнуто для этих 114 строк: `LEGACY_UNMOVED` хранит точный target напрямую; недоказанным остаётся только общий post-move контракт `targetPath()`, отражённый в codex-04.
