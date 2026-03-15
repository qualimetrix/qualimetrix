# Правила безопасности (Security)

Правила безопасности обнаруживают паттерны, которые могут привести к уязвимостям в вашем коде. Эти правила направлены на поиск учетных данных, секретов и другой конфиденциальной информации, которая не должна быть захардкожена в исходном коде.

!!! note "Ограничения области применения"
    Эти правила безопасности обнаруживают только паттерны **прямого использования суперглобальных переменных** (`$_GET`, `$_POST` и т.д.). Они НЕ выполняют taint-анализ и не отслеживают поток данных через переменные. Для более глубокого анализа безопасности с отслеживанием потоков данных рекомендуем специализированные инструменты: [PHPStan Security](https://github.com/phpstan/phpstan-security), [Psalm Taint Analysis](https://psalm.dev/docs/security_analysis/) или [SonarQube](https://www.sonarqube.org/).

---

## Захардкоженные учетные данные (Hardcoded Credentials)

**Идентификатор правила:** `security.hardcoded-credentials`
**Серьезность:** Error

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

### Пример

```php
class DatabaseConfig
{
    // Плохо: учетные данные захардкожены напрямую
    private const DB_PASSWORD = 'super_secret_123';
    private string $apiKey = 'sk-live-abc123def456';

    public function connect(string $password = 'root'): void
    {
        $token = 'ghp_xxxxxxxxxxxxxxxxxxxx';
        // ...
    }
}
```

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

## SQL-инъекция (SQL Injection)

**Идентификатор правила:** `security.sql-injection`
**Серьезность:** Error

### Что измеряет

Обнаруживает потенциальные уязвимости SQL-инъекции -- использование суперглобальных переменных (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`) при построении SQL-запросов через конкатенацию, интерполяцию или прямую передачу в аргументы SQL-функций.

### Пример

```php
// Плохо: суперглобальная переменная напрямую в SQL-запросе
$query = "SELECT * FROM users WHERE id = " . $_GET['id'];
$result = mysqli_query($conn, "SELECT * FROM users WHERE name = '$_POST[name]'");
```

### Как исправить

1. **Используйте параметризованные запросы:**

    ```php
    // PDO
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $_GET['id']]);

    // mysqli
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->bind_param('i', $_GET['id']);
    $stmt->execute();
    ```

2. **Используйте ORM** (Doctrine, Eloquent), которые автоматически экранируют параметры.

!!! warning "Внимание"
    SQL-инъекция -- одна из самых опасных уязвимостей. Она позволяет злоумышленнику читать, модифицировать или удалять данные в базе, а в некоторых случаях -- получить контроль над сервером.

---

## XSS (Cross-Site Scripting)

**Идентификатор правила:** `security.xss`
**Серьезность:** Error

### Что измеряет

Обнаруживает потенциальные уязвимости межсайтового скриптинга (XSS) -- вывод суперглобальных переменных (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`) через `echo`/`print` без санитизации (`htmlspecialchars`, `htmlentities`, `strip_tags`, `intval`, приведение к `int`/`float`).

### Пример

```php
// Плохо: вывод пользовательского ввода без экранирования
echo $_GET['name'];
print("Привет, " . $_POST['username']);
```

### Как исправить

1. **Экранируйте вывод:**

    ```php
    echo htmlspecialchars($_GET['name'], ENT_QUOTES, 'UTF-8');
    ```

2. **Используйте шаблонизатор** (Twig, Blade), который экранирует вывод автоматически.

!!! warning "Внимание"
    XSS позволяет злоумышленнику внедрить произвольный JavaScript в страницу, что может привести к краже сессий, перенаправлению пользователей и другим атакам.

---

## Внедрение команд (Command Injection)

**Идентификатор правила:** `security.command-injection`
**Серьезность:** Error

### Что измеряет

Обнаруживает потенциальные уязвимости внедрения команд -- использование суперглобальных переменных в качестве аргументов функций выполнения команд (`exec`, `system`, `passthru`, `shell_exec`, `proc_open`, `popen`) без санитизации (`escapeshellarg`, `escapeshellcmd`).

### Пример

```php
// Плохо: суперглобальная переменная напрямую в shell-команде
exec("ping " . $_GET['host']);
system("ls " . $_POST['dir']);
```

### Как исправить

1. **Экранируйте аргументы:**

    ```php
    exec("ping " . escapeshellarg($_GET['host']));
    ```

2. **Используйте Process-компоненты** (Symfony Process), которые передают аргументы безопасно.

3. **Валидируйте ввод** с помощью белого списка допустимых значений.

!!! warning "Внимание"
    Внедрение команд позволяет злоумышленнику выполнять произвольные команды на сервере, что может привести к полной компрометации системы.

---

## Чувствительные параметры (Sensitive Parameter)

**Идентификатор правила:** `security.sensitive-parameter`
**Серьезность:** Warning

### Что измеряет

Обнаруживает параметры с чувствительными именами (password, secret, apiKey и т.д.), у которых отсутствует атрибут `#[\SensitiveParameter]`. Этот атрибут (доступен с PHP 8.2) предотвращает утечку значений параметров в stack traces, что особенно важно для учетных данных.

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

## Конфигурация

```yaml
# aimd.yaml
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
bin/aimd check src/ --disable-rule=security.hardcoded-credentials

# Отключить все правила безопасности (сопоставление по префиксу)
bin/aimd check src/ --disable-rule=security
```
