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

- HTML-отчёты помечают анализируемый проект как `qualimetrix/qualimetrix`, если не указать иное.
  Метка берётся из имени корневого пакета, а внутри архива это собственный пакет Qualimetrix.
  Передайте `--format-opt=project-name=your/project`, чтобы задать её.
- Релизы, выпущенные до появления этой возможности, не несут ассета `qmx.phar`; первый, который его
  несёт, — тот, в чьей записи changelog эта возможность объявлена.

!!! note "Сборка своими руками"
    Из клона этого репозитория `composer phar` пишет `build/qmx.phar`. Команда скачивает свой
    инструмент сборки с GitHub, поэтому ей нужен [GitHub CLI](https://cli.github.com/) в `PATH`.

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
