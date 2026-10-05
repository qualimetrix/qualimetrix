# Правила baseline

Правила baseline проверяют файл принятия после сравнения.

## Неиспользуемая запись

**Rule ID:** `baseline.unused-entry`
**Серьёзность:** Warning
**Уровень:** Project
**Оценка исправления:** 5 минут

<!-- llms:skip-begin -->
### Что проверяет

Полное сравнимое отсутствие означает stale; неприменимая запись — inert.
Unknown, outside и unmeasured не доказывают исправление. Каждая такая запись даёт
проектное предупреждение после ceiling. Это не судимая метрика, порогов warning/error нет.

### Как исправить

Проверь запись и охват через baseline:explain или baseline:cleanup. Удаляй только
проверенные selectors через --remove; исправляй malformed и намеренно мигрируй
устаревшие каналы. Не перегенерируй файл только ради исчезновения предупреждения.

<!-- llms:skip-end -->

### Конфигурация

```yaml
rules:
  baseline.unused-entry:
    enabled: false
```

```bash
bin/qmx check src/ --baseline=baseline.json --disable-rule=baseline.unused-entry
```

Аудит нельзя принять в baseline или --accept-new. Отключённый/невыбранный аудит
сохраняет только счётчики stderr. Подавления путей/пространств имён и Git не скрывают
выбранные проектные предупреждения. Изолированное предупреждение даёт 0 по умолчанию/
error/none и 1 с --fail-on=warning; неполный анализ — 4. Форматы находок публикуют аудит;
metrics, health и suppressed не публикуют его.
