# Правила безопасности (Security)

Правила безопасности обнаруживают паттерны, которые могут привести к уязвимостям в вашем коде. Эти правила направлены на поиск учетных данных, секретов и другой конфиденциальной информации, которая не должна быть захардкожена в исходном коде.

!!! note "Ограничения области применения"
    Эти правила безопасности обнаруживают только паттерны **прямого использования суперглобальных переменных** (`$_GET`, `$_POST` и т.д.). Они НЕ выполняют taint-анализ и не отслеживают поток данных через переменные. Для более глубокого анализа безопасности с отслеживанием потоков данных рекомендуем специализированные инструменты: [PHPStan Security](https://github.com/phpstan/phpstan-security), [Psalm Taint Analysis](https://psalm.dev/docs/security_analysis/) или [SonarQube](https://www.sonarqube.org/).

---

## Захардкоженные учетные данные (Hardcoded Credentials)

**Идентификатор правила:** `security.hardcoded-credentials`
**Серьезность:** Error

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает захардкоженные учетные данные в PHP-коде -- строковые литералы, присвоенные переменным, свойствам, константам, ключам массивов и параметрам с именами, связанными с учетными данными.

**Паттерны обнаружения:**

- Присвоение переменной: `$password = 'secret';`
- Элемент массива: `['api_key' => 'abc123']`
- Константа класса: `const DB_PASSWORD = 'root';`
- Вызов `define()`: `define('API_KEY', '...');`
- Значение по умолчанию свойства: `private string $token = 'x';`
- Значение по умолчанию параметра: `function f($pwd = 'root')`

**Сопоставление чувствительных имен:**

- Суффиксные слова (совпадение в любом месте): `password`, `passwd`, `pwd`, `secret`, `credential(s)`
- Составные с "key" (только с квалификатором): `apiKey`, `secretKey`, `privateKey`, `encryptionKey`, `signingKey`, `authKey`, `accessKey`
- Составные с "token" (только с квалификатором): `authToken`, `accessToken`, `bearerToken`, `apiToken`, `refreshToken`

Имена вроде `$passwordHash`, `$tokenStorage`, `$cacheKey`, `OPTION_PASSWORD` исключаются (неучетный контекст).

**Фильтрация значений:** пустые строки, строки короче 4 символов и строки из одинаковых символов (`***`, `xxx`) пропускаются.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
class DatabaseConfig
{
    // Плохо: учетные данные захардкожены напрямую
    private const DB_PASSWORD = 'super_secret_123';
    private string $apiKey = 'sk-live-abc123def456';

    public function connect(string $password = 'root'): void
    {
        $apiToken = 'ghp_xxxxxxxxxxxxxxxxxxxx';
        // ...
    }
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

1. **Используйте переменные окружения:**

    ```php
    $password = $_ENV['DB_PASSWORD'];
    // или
    $password = getenv('DB_PASSWORD');
    ```

2. **Используйте менеджер секретов** (Vault, AWS Secrets Manager и т.д.)

3. **Используйте конфигурацию фреймворка:**

    ```php
    // Symfony
    $password = $this->getParameter('database_password');

    // Laravel
    $password = config('database.password');
    ```

!!! warning "Внимание"
    Захардкоженные учетные данные в исходном коде -- это серьезный риск безопасности. Они могут утечь через систему контроля версий, логи, сообщения об ошибках или скомпилированные артефакты.

---

<!-- llms:skip-end -->

## SQL-инъекция (SQL Injection)

**Идентификатор правила:** `security.sql-injection`
**Серьезность:** Error

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает использование суперглобальных переменных (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`) в SQL-контексте, где несанитизированный пользовательский ввод может привести к SQL-инъекции.

**Обнаруживаемые паттерны:**

- Конкатенация строки с SQL-ключевыми словами: `"SELECT * FROM users WHERE id = " . $_GET['id']`
- Аргумент небезопасной функции запроса: `mysql_query($_GET['q'])`, `mysqli_query($conn, $_POST['sql'])`, `pg_query($_REQUEST['q'])`
- `sprintf()` с SQL-шаблоном: `sprintf("SELECT * FROM users WHERE id = %s", $_GET['id'])`

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: суперглобальная переменная напрямую в SQL-запросе
$result = mysqli_query($conn, "SELECT * FROM users WHERE id = " . $_GET['id']);

// Плохо: суперглобальная переменная как аргумент функции запроса
$result = pg_query("SELECT * FROM orders WHERE status = '" . $_POST['status'] . "'");

// Плохо: sprintf с несанитизированным вводом
$sql = sprintf("DELETE FROM sessions WHERE token = '%s'", $_COOKIE['session']);
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

Используйте **параметризованные запросы** (prepared statements) вместо конкатенации строк:

```php
// Хорошо: подготовленный запрос PDO
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_GET['id']]);

// Хорошо: подготовленный запрос mysqli
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_GET['id']);
$stmt->execute();
```

!!! warning "Внимание"
    SQL-инъекция -- одна из самых опасных и распространённых уязвимостей веб-приложений. Никогда не подставляйте пользовательский ввод в SQL-строки конкатенацией, даже если считаете его "безопасным".

---

<!-- llms:skip-end -->

## XSS (Cross-Site Scripting)

**Идентификатор правила:** `security.xss`
**Серьезность:** Error

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает операторы `echo`/`print`, выводящие суперглобальные переменные (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`) без должной санитизации, что может привести к межсайтовому скриптингу (XSS).

Нарушение **не** сообщается, если значение обёрнуто в функцию санитизации:

- `htmlspecialchars()`
- `htmlentities()`
- `strip_tags()`
- `intval()`
- приведение типов `(int)` или `(float)`

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: несанитизированная суперглобальная переменная выводится напрямую
echo $_GET['name'];
print("Привет, " . $_POST['username']);

// Хорошо: санитизированный вывод
echo htmlspecialchars($_GET['name'], ENT_QUOTES, 'UTF-8');
echo (int) $_GET['page'];
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

Всегда санитизируйте пользовательский ввод перед выводом в HTML:

```php
// Используйте htmlspecialchars с ENT_QUOTES и явной кодировкой
echo htmlspecialchars($_GET['name'], ENT_QUOTES, 'UTF-8');

// Для целочисленных значений приводите к int
echo (int) $_GET['id'];

// В шаблонах используйте автоэкранирование вашего фреймворка (Twig, Blade и т.д.)
```

!!! warning "Внимание"
    XSS позволяет злоумышленнику внедрить произвольный скрипт в страницу, которую видят другие пользователи. Всегда экранируйте вывод, даже во "внутренних" админ-панелях.

---

<!-- llms:skip-end -->

## Внедрение команд (Command Injection)

**Идентификатор правила:** `security.command-injection`
**Серьезность:** Error

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает суперглобальные переменные (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`), переданные аргументами функциям выполнения shell-команд, что может привести к внедрению команд.

**Обнаруживаемые функции:** `exec()`, `system()`, `passthru()`, `shell_exec()`, `proc_open()`, `popen()`

Нарушение **не** сообщается, если значение обёрнуто в:

- `escapeshellarg()`
- `escapeshellcmd()`

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: суперглобальная переменная напрямую передаётся shell-функции
exec("convert " . $_GET['filename'] . " output.png");
system("ping " . $_POST['host']);
$output = shell_exec("grep " . $_REQUEST['pattern'] . " /var/log/app.log");

// Хорошо: значение правильно экранировано
exec("convert " . escapeshellarg($_GET['filename']) . " output.png");
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

1. **Используйте `escapeshellarg()`** для экранирования отдельных аргументов:

    ```php
    exec("convert " . escapeshellarg($_GET['filename']) . " output.png");
    ```

2. **По возможности избегайте shell-команд вовсе.** Используйте встроенные функции PHP:

    ```php
    // Вместо: exec("ls " . escapeshellarg($dir))
    $files = scandir($dir);

    // Вместо: exec("ping " . escapeshellarg($host))
    // Используйте сокеты или библиотеку
    ```

!!! warning "Внимание"
    Внедрение команд позволяет злоумышленнику выполнять произвольные команды на вашем сервере. Даже с экранированием предпочитайте нативные для PHP альтернативы shell-командам, когда они существуют.

---

<!-- llms:skip-end -->

## Чувствительные параметры (Sensitive Parameter)

**Идентификатор правила:** `security.sensitive-parameter`
**Серьезность:** Warning

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает параметры функций и методов с чувствительными именами, у которых отсутствует атрибут `#[\SensitiveParameter]` (доступен с PHP 8.2). Без этого атрибута чувствительные значения вроде паролей и токенов появляются в открытом виде в stack traces, логах ошибок и отчётах об исключениях.

Сопоставление чувствительных параметров использует ту же политику, что и поиск захардкоженных
учетных данных: самостоятельные слова `password`, `passwd`, `pwd`, `secret`,
`credential` и `credentials` совпадают, а `key` и `token` требуют квалифицирующего префикса,
например `api`, `auth`, `access`, `private` или `refresh`. Контексты
`passwordHash`, `tokenStorage`, `cacheKey` и `OPTION_PASSWORD` исключаются.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Пример

```php
// Плохо: чувствительный параметр без атрибута
function authenticate(string $password): bool
{
    // Если здесь возникнет исключение, $password будет виден в stack trace
    // ...
}

// Хорошо: атрибут #[\SensitiveParameter] скрывает значение в stack traces
function authenticate(#[\SensitiveParameter] string $password): bool
{
    // ...
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

Добавьте атрибут `#[\SensitiveParameter]` к параметрам с чувствительными именами:

```php
function connect(
    #[\SensitiveParameter] string $password,
    #[\SensitiveParameter] string $apiKey,
): void {
    // ...
}
```

!!! note "Примечание"
    Атрибут `#[\SensitiveParameter]` доступен начиная с PHP 8.2. Он заменяет значение параметра на `SensitiveParameterValue` в stack traces, предотвращая случайную утечку секретов в логи и отчеты об ошибках.

---

<!-- llms:skip-end -->

## Область обнаружения

Правила безопасности (`sql-injection`, `xss`, `command-injection`) используют **паттерн-ориентированное обнаружение** -- они ищут суперглобальные переменные (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`), используемые непосредственно в опасных контекстах. Этот подход быстр и дает ноль ложных срабатываний для прямых паттернов, но имеет свои ограничения.

### Что обнаруживается

Прямые паттерны "суперглобальная переменная в приемнике", включая конкатенацию и интерполяцию строк:

```php
// Все обнаруживается:
echo $_GET['name'];                                          // прямой вывод
echo "Hello " . $_POST['user'];                              // конкатенация
echo "Welcome {$_GET['name']}";                              // интерполяция
mysqli_query($conn, "SELECT * FROM t WHERE id=" . $_GET['id']); // аргумент SQL-функции
exec("ping " . $_GET['host']);                                // аргумент shell-функции
```

### Что НЕ обнаруживается

Эти правила **не** выполняют taint-анализ -- они не могут отслеживать поток данных через переменные, возвращаемые значения функций или свойства объектов:

```php
// НЕ обнаруживается -- значение присвоено промежуточной переменной:
$name = $_GET['name'];
echo $name;

// НЕ обнаруживается -- значение передано через функцию:
function getName() { return $_GET['name']; }
echo getName();

// НЕ обнаруживается -- значение сохранено в объекте:
$request->name = $_POST['name'];
echo $request->name;

// НЕ обнаруживается -- непрямая SQL-инъекция:
$id = $_GET['id'];
$query = "SELECT * FROM users WHERE id = " . $id;
```

### Рекомендации

Для полноценного анализа безопасности с отслеживанием потоков данных используйте специализированные инструменты совместно с Qualimetrix:

- **[PHPStan Security Advisories](https://github.com/phpstan/phpstan-security)** -- расширение PHPStan для проверок безопасности
- **[Psalm Taint Analysis](https://psalm.dev/docs/security_analysis/)** -- отслеживает «загрязненные» данные через присвоения, вызовы функций и возвращаемые значения
- **[SonarQube](https://www.sonarqube.org/)** -- коммерческий инструмент с глубоким анализом потоков данных
- **[Snyk Code](https://snyk.io/product/snyk-code/)** -- сканирование безопасности на основе AI с отслеживанием потоков данных

Правила безопасности Qualimetrix лучше всего использовать как **первую линию обороны** для обнаружения наиболее очевидных паттернов. Они дополняют, но не заменяют специализированные инструменты анализа безопасности.

---

## Конфигурация

```yaml
# qmx.yaml
rules:
  security.hardcoded-credentials:
    enabled: true  # или false для отключения
  security.sql-injection:
    enabled: true
  security.xss:
    enabled: true
  security.command-injection:
    enabled: true
  security.sensitive-parameter:
    enabled: true
```

Также можно отключить через CLI:

```bash
# Отключить конкретное правило
bin/qmx check src/ --disable-rule=security.hardcoded-credentials

# Отключить все правила безопасности (wildcard-сопоставление; захватывает только потомков, не сам "security")
bin/qmx check src/ --disable-rule=security.*
```
