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

Автономный архив, который запускается без добавления Qualimetrix в зависимости проекта. Скачайте
`qmx.phar` из [последнего релиза](https://github.com/qualimetrix/qualimetrix/releases/latest),
сделайте его исполняемым и запустите:

```bash
chmod +x qmx.phar
./qmx.phar check src/
```

!!! warning "Сохраняйте суффикс `.phar`"
    Параллельный анализ копирует весь архив во временный каталог при каждом запуске, если файл, из
    которого он работает, называется не `*.phar`. Переименование в `qmx` стоит нескольких мегабайт
    копирования на каждый запуск.

Два отличия от установки через Composer:

- `hook:install` недоступен. Хук — это симлинк на shell-скрипт, а симлинк не может указывать внутрь
  архива; создайте `.git/hooks/pre-commit` вручную либо установите через Composer.
- Релиз, выпущенный до появления этой возможности, не несёт ассета `qmx.phar`. Соберите архив
  командой `composer phar`, она пишет `build/qmx.phar`.

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

=== "PHAR"

    ```bash
    ./qmx.phar --version
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
