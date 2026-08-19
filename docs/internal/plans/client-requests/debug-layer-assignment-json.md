# План: `debug:layer-assignment --format=json`

**Статус:** реализовано (2026-08-19)
**Область:** `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php`,
`src/Infrastructure/Console/LayerAssignmentResolver.php` (изменений не потребовалось —
контракт `resolve()`/`resolveIncludingGenerated()` уже нёс всё нужное),
`tests/Functional/Console/LayerAssignmentCommandTest.php`,
`website/docs/usage/cli-options.{md,ru.md}`

---

## 1. Предпосылки

`debug:layer-assignment` — инструмент разбора затенения слоёв (shadowing). Его основной
потребитель в продуктовой модели — AI-агент (машинный потребитель фидбека), который до
этого изменения должен был парсить человекочитаемый текст, чтобы понять «в какой слой
попал класс и какие слои его затеняют».

`LayerAssignmentCommand::renderReport()` выводил только текст (`Assigned to:`, `Would also
match:`, `Diagnostic hint:`); структурированный результат `resolve()` (список
`LayerAssignmentMatch{layerName, criteria}` + `hasLayers`) жил в `LayerAssignmentResolver` и
на выход не сериализовался.

## 2. Решение (как реализовано)

1. Добавлена опция `--format` (`VALUE_REQUIRED`, дефолт `text`) без короткого алиаса —
   `-c` уже занято под `--config`, а частота использования `--format` на debug-команде не
   оправдывает второй short flag по правилу CLI_CONVENTIONS «≤6 коротких флагов на всё
   приложение».
2. `execute()` сначала проверяет `--format` на принадлежность `{text, json}` — неизвестное
   значение завершает команду с `Command::INVALID` (2) ДО разбора FQN и запуска
   Discovery/Collection, ничего не считая впустую.
3. `renderReport()` (текст) и новый `renderJson()` — два проектора над одним и тем же
   результатом `resolve()`/`resolveIncludingGenerated()`; логика разрешения не
   продублирована. Текстовый вывод не изменился ни на байт (регрессия покрыта тестом
   `defaultFormat_isText_omittingFormatOptionMatchesExplicitText` и тем, что все
   предсуществовавшие текстовые тесты остались зелёными без правок).
4. Ошибочные исходы вынесены в общий `reportError(format, message, exitCode)`:
   - `text` — прежняя строка `<error>...</error>` (побайтово идентична дореализационной).
   - `json` — конверт `{"error": "...", "exit_code": N}` **в stdout**; отдельного канала
     диагностики не потребовалось, так как команда больше ничего в json-режиме не печатает.
   - Используется для двух категорий ошибок: невалидный FQN (`Command::INVALID`, 2) и
     ошибка конфигурации/подготовки (`Command::FAILURE`, 1, все три существующих catch-ветки).
5. Проверка существования класса **не введена** — как и предполагалось, это отдельное
   решение вне скоупа; `assigned` просто `null` при пустых matches.

## 3. Зафиксированная JSON-схема

```json
{
  "fqn": "App\\Service\\Foo",
  "assigned": { "layer": "any-foo", "criteria": ["pattern \"App\\**\\Foo\""] },
  "shadowed": [
    { "layer": "service", "criteria": ["pattern \"App\\Service\\**\""] }
  ],
  "hasLayers": true
}
```

- `fqn: string` — нормализованный FQN (без ведущего `\`), тот же, что в заголовке `Class:`
  текстового отчёта.
- `assigned: {layer: string, criteria: non-empty-list<string>} | null` — первый элемент
  `matches`; `null`, если `matches === []`.
- `shadowed: list<{layer: string, criteria: non-empty-list<string>}>` — остаток `matches`
  (`array_slice($matches, 1)`) в порядке объявления слоёв; `[]`, если совпадение
  единственное или отсутствует.
- `hasLayers: bool` — как в контракте `LayerAssignment`; различает «слои не объявлены»
  (`false`) и «слои объявлены, но класс ничему не сопоставился» (`true` + `assigned: null`).

Ошибочный конверт:

```json
{ "error": "Configuration error: ...", "exit_code": 1 }
```

`exit_code` дублирует реальный код возврата процесса (2 для `Command::INVALID`, 1 для
`Command::FAILURE`) — не привязывался к отдельной внутренней таксономии ошибок, чтобы не
плодить вторую классификацию рядом с уже существующей.

## 4. Тесты

`tests/Functional/Console/LayerAssignmentCommandTest.php`, 8 новых кейсов (проверено, что
все 8 падают на дореализационном коде — `git stash` командного файла + прогон):

- `jsonFormat_overlappingLayers_returnsFixedSchemaWithShadowedEntries` — точная фиксация
  схемы (`assertSame` на весь декодированный массив).
- `jsonFormat_coversEveryFactTheTextReportPrints` — структурный parity: один и тот же
  конфиг гоняется через text и json, и оба сверяются с независимо известными фактами
  конфигурации (имена слоёв/паттерны), а не друг с другом через парсинг текста.
- `jsonFormat_noMatch_assignedIsNullAndShadowedIsEmpty`
- `jsonFormat_noLayersDeclared_reportsHasLayersFalse`
- `defaultFormat_isText_omittingFormatOptionMatchesExplicitText` — дефолт-формат
  побайтово равен явному `--format=text`.
- `unknownFormat_exitsInvalidWithoutRunningResolution`
- `jsonFormat_invalidFqn_returnsErrorEnvelope`
- `jsonFormat_configError_returnsErrorEnvelopeWithFailureExitCode`

## 5. Расхождения с исходным планом

План был написан 2026-08-16, реализация — 2026-08-19, после того как в main влилась
«подложка идентичности канала» (PR #14). Проверка по коду не обнаружила расхождений,
убивающих посылку: `ArchitecturePolicy::inspect` → `LayerAssignment(matches, hasLayers)` и
`LayerAssignmentMatch(layerName, criteria)` не менялись этим PR, `LayerAssignmentResolver`
и `LayerAssignmentCommand` тоже вне его области. Единственная правка по факту —
формулировка exit-кода в докблоке класса (`Command::INVALID` = 2, подтверждено по
`vendor/symfony/console/Command/Command.php`), которая и так совпадала с планом.
