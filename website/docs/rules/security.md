# Security Rules

Security rules detect patterns that may introduce security vulnerabilities into your codebase. These rules focus on finding credentials, secrets, and other sensitive data that should never be hardcoded.

---

## Hardcoded Credentials

**Rule ID:** `security.hardcoded-credentials`
**Severity:** Error

### What it measures

Detects hardcoded credentials in PHP code -- string literal values assigned to variables, properties, constants, array keys, and parameters with credential-related names.

**Detection patterns:**

- Variable assignment: `$password = 'secret';`
- Array item: `['api_key' => 'abc123']`
- Class constant: `const DB_PASSWORD = 'root';`
- `define()` call: `define('API_KEY', '...');`
- Property default: `private string $token = 'x';`
- Parameter default: `function f($pwd = 'root')`

**Sensitive name matching:**

- Suffix words (match anywhere): `password`, `passwd`, `pwd`, `secret`, `credential(s)`
- Compound "key" (only with qualifier): `apiKey`, `secretKey`, `privateKey`, `encryptionKey`, `signingKey`, `authKey`, `accessKey`
- Compound "token" (only with qualifier): `authToken`, `accessToken`, `bearerToken`, `apiToken`, `refreshToken`

Names like `$passwordHash`, `$tokenStorage`, `$cacheKey`, `OPTION_PASSWORD` are excluded (non-credential context).

**Value filtering:** empty strings, strings shorter than 4 characters, and strings of identical characters (`***`, `xxx`) are skipped.

### Example

```php
class DatabaseConfig
{
    // Bad: credentials hardcoded directly
    private const DB_PASSWORD = 'super_secret_123';
    private string $apiKey = 'sk-live-abc123def456';

    public function connect(string $password = 'root'): void
    {
        $token = 'ghp_xxxxxxxxxxxxxxxxxxxx';
        // ...
    }
}
```

### How to fix

1. **Use environment variables:**

    ```php
    $password = $_ENV['DB_PASSWORD'];
    // or
    $password = getenv('DB_PASSWORD');
    ```

2. **Use a secrets manager** (Vault, AWS Secrets Manager, etc.)

3. **Use framework configuration:**

    ```php
    // Symfony
    $password = $this->getParameter('database_password');

    // Laravel
    $password = config('database.password');
    ```

!!! warning
    Hardcoded credentials in source code are a serious security risk. They can be leaked through version control, logs, error messages, or compiled artifacts.

---

## Configuration

```yaml
# aimd.yaml
rules:
  security.hardcoded-credentials:
    enabled: true  # or false to disable
```

You can also disable via the CLI:

```bash
# Disable this rule
bin/aimd check src/ --disable-rule=security.hardcoded-credentials

# Disable all security rules (prefix matching)
bin/aimd check src/ --disable-rule=security
```
