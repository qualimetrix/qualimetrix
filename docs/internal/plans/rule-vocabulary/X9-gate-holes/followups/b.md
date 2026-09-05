## Х9 (2026-09-05) — пакет B

### П1 этапа 04 (перенос baseline) закрыт измерением Х9

`OccurrenceKey::semantic()` (`src/Analysis/Finding/Contract/OccurrenceKey.php:39`)
хеширует `{kind, evidence}`. Восемь вызовов в дереве:
`CircularDependencyRule.php:108`, `IdenticalSubExpressionRule.php:103`,
`CodeDuplicationRule.php:138`, `HardcodedCredentialsRule.php:106`,
`SensitiveParameterRule.php:106`, `LayerViolationFinding.php:92` — в этих
шести `kind` буквально `self::NAME`/`$this->ruleName`, та же строка, что
регистрирует код канала; `CodeSmellFinding.php:57` и
`SecurityPatternFinding.php:55` — в этих двух `kind` отдельная константа
(`SMELL_TYPE`, `$patternType`), от кода канала не зависящая.

- **Что это закрывает:** условие посылки 04-файла («если `kind` — код
  канала») подтверждено в 6 семействах из 8. Предписанное там же лечение
  («механический перенос обязан пересчитывать хеш») опровергнуто:
  `BaselineEntry::toArray()` (`src/Analysis/Policy/Baseline/BaselineEntry.php`)
  не сериализует сырые `evidence` ни для одного семейства — запись несёт
  только `channel`, уже посчитанный `occurrence` и `count`/`magnitudes`.
  Пересчитать хеш из содержимого baseline нельзя в принципе, для 6 из 8
  так же, как для 2 из 8.
- **Главное следствие:** развилка переноса стоит не на «пересчитывать или
  нет», а на «заморозить `kind` как внутренний дискриминатор, отвязанный от
  кода канала» (дешевле, перенос — подмена поля `channel` по карте) против
  «переносить прогоном с сопоставлением находок» (дороже, требует дерева
  потребителя). Рекомендация и цена обеих сторон — в переписанном разделе
  П1/П2 `docs/internal/plans/rule-vocabulary/X8-one-string-two-jobs/04-final-naming-step.md`.
- Реализация переноса (П2) и самой заморозки — не в Х9, входит в объём
  следующего захода, исполняющего Э4.
