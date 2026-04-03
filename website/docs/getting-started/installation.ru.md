# Установка

## Требования

- **PHP 8.4** или выше
- **Composer** (любая современная версия)

!!! tip "Подсказка"
    Не уверены, какая версия PHP у вас установлена? Выполните `php -v` в терминале.

---

## Composer (рекомендуется)

Установите Qualimetrix как dev-зависимость в вашем проекте:

```bash
composer require --dev qualimetrix/qualimetrix
```

После установки бинарный файл `qmx` доступен по пути:

```bash
vendor/bin/qmx
```

---

## PHAR

!!! note "Скоро"
    Автономный PHAR-архив запланирован для будущих релизов. Это позволит запускать Qualimetrix без добавления его в зависимости проекта.

---

## Docker

Запускайте Qualimetrix в контейнере без локальной установки PHP:

```bash
docker run --rm -v $(pwd):/app qmx check src/
```

Эта команда монтирует текущую директорию в контейнер и анализирует папку `src/`.

Вы можете передавать любые параметры после `check`:

```bash
# Вывод в формате JSON
docker run --rm -v $(pwd):/app qmx check src/ --format=json

# С базовой линией (baseline)
docker run --rm -v $(pwd):/app qmx check src/ --baseline=baseline.json
```

---

## Проверка установки

=== "Composer"

    ```bash
    vendor/bin/qmx --version
    ```

=== "Глобальная установка или bin-dir"

    ```bash
    bin/qmx --version
    ```

=== "Docker"

    ```bash
    docker run --rm qmx --version
    ```

Вы должны увидеть вывод вида:

```
Qualimetrix x.x.x
```

---

## Что дальше?

Перейдите к руководству [Быстрый старт](quick-start.ru.md), чтобы запустить первый анализ и настроить интеграцию с вашим рабочим процессом.
