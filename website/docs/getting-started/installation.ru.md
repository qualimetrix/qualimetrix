# Установка

## Требования

- **PHP 8.4** или выше
- **Composer** (любая современная версия)

!!! tip "Подсказка"
    Не уверены, какая версия PHP у вас установлена? Выполните `php -v` в терминале.

---

## Composer (рекомендуется)

Установите AI Mess Detector как dev-зависимость в вашем проекте:

```bash
composer require --dev fractalizer/ai-mess-detector
```

После установки бинарный файл `aimd` доступен по пути:

```bash
vendor/bin/aimd
```

---

## PHAR

!!! note "Скоро"
    Автономный PHAR-архив запланирован для будущих релизов. Это позволит запускать AI Mess Detector без добавления его в зависимости проекта.

---

## Docker

Запускайте AI Mess Detector в контейнере без локальной установки PHP:

```bash
docker run --rm -v $(pwd):/app aimd check src/
```

Эта команда монтирует текущую директорию в контейнер и анализирует папку `src/`.

Вы можете передавать любые параметры после `analyze`:

```bash
# Вывод в формате JSON
docker run --rm -v $(pwd):/app aimd check src/ --format=json

# С базовой линией (baseline)
docker run --rm -v $(pwd):/app aimd check src/ --baseline=baseline.json
```

---

## Проверка установки

=== "Composer"

    ```bash
    vendor/bin/aimd --version
    ```

=== "Глобальная установка или bin-dir"

    ```bash
    bin/aimd --version
    ```

=== "Docker"

    ```bash
    docker run --rm aimd --version
    ```

Вы должны увидеть вывод вида:

```
AI Mess Detector x.x.x
```

---

## Что дальше?

Перейдите к руководству [Быстрый старт](quick-start.ru.md), чтобы запустить первый анализ и настроить интеграцию с вашим рабочим процессом.
