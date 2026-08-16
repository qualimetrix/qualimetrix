# План: `debug:layer-assignment --format=json`

**Статус:** предложение, перед ревью
**Дата:** 2026-08-16
**Область:** `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php`, `src/Infrastructure/Console/LayerAssignmentResolver.php`

---

## 1. Предпосылки

`debug:layer-assignment` — инструмент разбора затенения слоёв (shadowing). Его основной
потребитель в продуктовой модели — AI-агент (машинный потребитель фидбека), который сейчас
должен парсить человеческий текст, чтобы понять «в какой слой попал класс и какие слои его
затеняют».

Проверено по коду: `LayerAssignmentCommand::renderReport()` (`LayerAssignmentCommand.php:215-275`)
выводит только человекочитаемый текст (`Assigned to:`, `Would also match:`,
`Diagnostic hint:`); структурированный результат `resolve()`/`resolveIncludingGenerated()`
живёт в `LayerAssignmentResolver` и на выход не сериализуется.

## 2. Аргументы

**Зачем.** Принцип «one analysis model, many projections» и критерий «machine contract fits
the context an agent actually has»: агент не должен реконструировать факт из форматирования.
JSON-вывод даёт стабильный машинный контракт и убирает regexp-парсинг текста.

**Цена.** Единственная реальная работа — схема JSON и поддержание parity с текстом
(текст и JSON обязаны быть проекциями одного и того же результата разрешения).

## 3. Решение

1. Добавить `--format=json` (значение по умолчанию — `text`, вывод не меняется).
2. JSON-вывод сериализует **только то, что уже есть в контракте** `ArchitecturePolicy::inspect`
   → `LayerAssignment(matches, hasLayers)`: список матчей (слой + критерии, в порядке
   объявления) и `hasLayers`. Исхода «класс не найден» **нет**: любой синтаксически корректный
   FQN классифицируется, существование не проверяется (`ArchitecturePolicy.php:84`). Реальные
   исходы: назначенный слой + затеняющие слои; «(no layer)» при пустых matches; «слои не
   объявлены» при `hasLayers=false`; config-ошибка.
3. `renderReport()` разводится на два проектора (text / json) над **одним** результатом
   `resolve()`; логика разрешения не дублируется. Parity проверяется структурно (см. §4), а не
   парсингом текста.
4. **Схема и ошибочные исходы фиксируются до реализации:** поля (`fqn`, `assigned{layer,
   criteria}`, `shadowed[]`, `hasLayers`), типы, nullability (`assigned=null` при пустых
   matches), error-envelope `{error, exit_code}` для config-ошибки; JSON — в stdout, диагностика
   — в stderr; неизвестный `--format` → exit 2 (INVALID). Проверка существования класса в схему
   не вводится (сменила бы семантику команды — отдельное решение, вне скоупа).
5. Свериться с `docs/internal/CLI_CONVENTIONS.md` на форму `--format` для debug-команд (у
   `check` конвенция есть; для debug-семейства подтвердить/зафиксировать).

## 4. Последствия

- Код: `LayerAssignmentCommand` (опция + ветвление рендера + JSON-представление ошибок),
  возможно тонкая сериализация `LayerAssignmentMatch` в JSON.
- Тесты: functional — `--format=json` возвращает ожидаемую схему; тест фиксирует конкретный
  список полей JSON и что он покрывает всё, что печатает text (оба проектора из одного
  `resolve()`) — без парсинга текстового вывода. Отдельно: ошибочные исходы в JSON.
- Доки: `website/docs/usage/cli-options.{md,ru.md}` (новая опция), при необходимости строка
  в справке команды.
