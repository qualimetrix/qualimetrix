# Security Rules

Security rules detect patterns that may introduce security vulnerabilities into your codebase. These rules focus on finding credentials, secrets, and other sensitive data that should never be hardcoded.

!!! note "Scope limitation"
    These security rules detect only **direct superglobal usage** patterns (`$_GET`, `$_POST`, etc.). They do NOT perform taint analysis or track data flow through variables. For deeper security analysis with taint tracking, consider dedicated tools like [PHPStan Security](https://github.com/phpstan/phpstan-security), [Psalm Taint Analysis](https://psalm.dev/docs/security_analysis/), or [SonarQube](https://www.sonarqube.org/).

---

## Hardcoded Credentials

**Rule ID:** `security.hardcoded-credentials`
**Severity:** Error

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

## SQL Injection

**Rule ID:** `security.sql-injection`
**Severity:** Error

<!-- llms:skip-begin -->
### What it measures

Detects use of superglobals (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`) in SQL contexts, where unsanitized user input can lead to SQL injection attacks.

**Detection patterns:**

- String concatenation with SQL keywords: `"SELECT * FROM users WHERE id = " . $_GET['id']`
- Arguments to unsafe query functions: `mysql_query($_GET['q'])`, `mysqli_query($conn, $_POST['sql'])`, `pg_query($_REQUEST['q'])`
- `sprintf()` with SQL template: `sprintf("SELECT * FROM users WHERE id = %s", $_GET['id'])`

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
// Bad: superglobal directly in SQL query
$result = mysqli_query($conn, "SELECT * FROM users WHERE id = " . $_GET['id']);

// Bad: superglobal as argument to query function
$result = pg_query("SELECT * FROM orders WHERE status = '" . $_POST['status'] . "'");

// Bad: sprintf with unsanitized input
$sql = sprintf("DELETE FROM sessions WHERE token = '%s'", $_COOKIE['session']);
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

Use **parameterized queries** (prepared statements) instead of string concatenation:

```php
// Good: PDO prepared statement
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_GET['id']]);

// Good: mysqli prepared statement
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_GET['id']);
$stmt->execute();
```

!!! warning
    SQL injection is one of the most dangerous and common web vulnerabilities. Never concatenate user input into SQL strings, even if you think the input is "safe."

---

<!-- llms:skip-end -->

## XSS

**Rule ID:** `security.xss`
**Severity:** Error

<!-- llms:skip-begin -->
### What it measures

Detects `echo` or `print` statements that output superglobals (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`) without proper sanitization, which can lead to Cross-Site Scripting (XSS) attacks.

A violation is **not** reported when the value is wrapped in a sanitization function:

- `htmlspecialchars()`
- `htmlentities()`
- `strip_tags()`
- `intval()`
- `(int)` or `(float)` casts

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
// Bad: unsanitized superglobal echoed directly
echo $_GET['name'];
print("Welcome, " . $_POST['username']);

// Good: sanitized output
echo htmlspecialchars($_GET['name'], ENT_QUOTES, 'UTF-8');
echo (int) $_GET['page'];
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

Always sanitize user input before outputting it in HTML:

```php
// Use htmlspecialchars with ENT_QUOTES and explicit encoding
echo htmlspecialchars($_GET['name'], ENT_QUOTES, 'UTF-8');

// For integer values, cast to int
echo (int) $_GET['id'];

// In templates, use your framework's auto-escaping (Twig, Blade, etc.)
```

!!! warning
    XSS allows attackers to inject malicious scripts into pages viewed by other users. Always escape output, even in "internal" admin panels.

---

<!-- llms:skip-end -->

## Command Injection

**Rule ID:** `security.command-injection`
**Severity:** Error

<!-- llms:skip-begin -->
### What it measures

Detects superglobals (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`) passed as arguments to shell execution functions, which can lead to command injection attacks.

**Detected functions:** `exec()`, `system()`, `passthru()`, `shell_exec()`, `proc_open()`, `popen()`

A violation is **not** reported when the value is wrapped in:

- `escapeshellarg()`
- `escapeshellcmd()`

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
// Bad: superglobal passed directly to shell function
exec("convert " . $_GET['filename'] . " output.png");
system("ping " . $_POST['host']);
$output = shell_exec("grep " . $_REQUEST['pattern'] . " /var/log/app.log");

// Good: properly escaped
exec("convert " . escapeshellarg($_GET['filename']) . " output.png");
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

1. **Use `escapeshellarg()`** to escape individual arguments:

    ```php
    exec("convert " . escapeshellarg($_GET['filename']) . " output.png");
    ```

2. **Avoid shell commands entirely** when possible. Use PHP built-in functions instead:

    ```php
    // Instead of: exec("ls " . escapeshellarg($dir))
    $files = scandir($dir);

    // Instead of: exec("ping " . escapeshellarg($host))
    // Use sockets or a library
    ```

!!! warning
    Command injection allows attackers to execute arbitrary commands on your server. Even with escaping, prefer PHP-native alternatives to shell commands when they exist.

---

<!-- llms:skip-end -->

## Sensitive Parameter

**Rule ID:** `security.sensitive-parameter`
**Severity:** Warning

<!-- llms:skip-begin -->
### What it measures

Detects function and method parameters with sensitive names that are missing the `#[\SensitiveParameter]` attribute (available since PHP 8.2). Without this attribute, sensitive values like passwords and tokens will appear in plain text in stack traces, error logs, and exception reports.

**Sensitive parameter names** include: `password`, `passwd`, `pwd`, `secret`, `token`, `apiKey`, `privateKey`, `credential`, and similar patterns.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
// Bad: sensitive parameter without the attribute
class AuthService
{
    public function login(string $username, string $password): bool
    {
        // If an exception is thrown here, $password appears in the stack trace
        return $this->verify($username, $password);
    }
}

// Good: attribute prevents value from appearing in stack traces
class AuthService
{
    public function login(
        string $username,
        #[\SensitiveParameter] string $password,
    ): bool {
        return $this->verify($username, $password);
    }
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

Add the `#[\SensitiveParameter]` attribute to parameters that handle sensitive data:

```php
function authenticate(
    string $username,
    #[\SensitiveParameter] string $password,
): bool {
    // ...
}

function callApi(
    string $url,
    #[\SensitiveParameter] string $apiToken,
): Response {
    // ...
}
```

!!! tip
    The `#[\SensitiveParameter]` attribute was introduced in PHP 8.2. It replaces the value with `SensitiveParameterValue` in stack traces, preventing accidental exposure in logs and error reports.

---

<!-- llms:skip-end -->

## Detection scope

The security rules (`sql-injection`, `xss`, `command-injection`) use **pattern-based detection** -- they look for superglobal variables (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`) used directly in dangerous contexts. This approach is fast and produces zero false positives for direct patterns, but has inherent limitations.

### What IS detected

Direct superglobal-to-sink patterns, including through concatenation and string interpolation:

```php
// All detected:
echo $_GET['name'];                                          // direct
echo "Hello " . $_POST['user'];                              // concatenation
echo "Welcome {$_GET['name']}";                              // interpolation
mysqli_query($conn, "SELECT * FROM t WHERE id=" . $_GET['id']); // SQL function arg
exec("ping " . $_GET['host']);                                // command function arg
```

### What is NOT detected

These rules do **not** perform taint analysis -- they cannot track data flow through variables, function returns, or object properties:

```php
// NOT detected -- value assigned to intermediate variable:
$name = $_GET['name'];
echo $name;

// NOT detected -- value passed through a function:
function getName() { return $_GET['name']; }
echo getName();

// NOT detected -- value stored in an object:
$request->name = $_POST['name'];
echo $request->name;

// NOT detected -- indirect SQL injection:
$id = $_GET['id'];
$query = "SELECT * FROM users WHERE id = " . $id;
```

### Recommendations

For comprehensive security analysis with full taint tracking, use dedicated tools alongside AIMD:

- **[PHPStan Security Advisories](https://github.com/phpstan/phpstan-security)** -- security-focused PHPStan extension
- **[Psalm Taint Analysis](https://psalm.dev/docs/security_analysis/)** -- tracks tainted data through assignments, function calls, and returns
- **[SonarQube](https://www.sonarqube.org/)** -- commercial tool with deep data-flow analysis
- **[Snyk Code](https://snyk.io/product/snyk-code/)** -- AI-powered security scanning with taint tracking

AIMD security rules are best used as a **first line of defense** to catch the most obvious patterns. They complement but do not replace dedicated security analysis tools.

---

## Configuration

```yaml
# aimd.yaml
rules:
  security.hardcoded-credentials:
    enabled: true  # or false to disable
  security.sql-injection:
    enabled: true
  security.xss:
    enabled: true
  security.command-injection:
    enabled: true
  security.sensitive-parameter:
    enabled: true
```

You can also disable via the CLI:

```bash
# Disable a specific rule
bin/aimd check src/ --disable-rule=security.hardcoded-credentials

# Disable all security rules (prefix matching)
bin/aimd check src/ --disable-rule=security
```
