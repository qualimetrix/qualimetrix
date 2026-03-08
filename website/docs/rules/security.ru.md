# Правила безопасности (Security)

Правила безопасности обнаруживают паттерны, которые могут привести к уязвимостям в вашем коде. Эти правила направлены на поиск учетных данных, секретов и другой конфиденциальной информации, которая не должна быть захардкожена в исходном коде.

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

## Конфигурация

```yaml
# aimd.yaml
rules:
  security.hardcoded-credentials:
    enabled: true  # или false для отключения
```

Также можно отключить через CLI:

```bash
# Отключить конкретное правило
bin/aimd check src/ --disable-rule=security.hardcoded-credentials

# Отключить все правила безопасности (сопоставление по префиксу)
bin/aimd check src/ --disable-rule=security
```
