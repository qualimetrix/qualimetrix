# Code Smell Rules

Code smells are patterns that suggest something might be wrong with your code. They are not necessarily bugs, but they indicate areas where the code could be improved. These rules detect common bad practices that should usually be avoided.

All code smell rules can be individually enabled or disabled.

---

## Boolean Arguments

**Rule ID:** `code-smell.boolean-argument`
**Severity:** Warning

### What it measures

Detects methods that accept `bool` parameters. A boolean argument usually means the method does two different things depending on the flag, which violates the Single Responsibility Principle.

### Example

```php
// Bad: what does `true` mean here? Hard to read at the call site.
$user->save(true);

// The method signature reveals the problem:
public function save(bool $sendNotification): void
{
    // ...saves the user...
    if ($sendNotification) {
        // ...sends notification...
    }
}
```

### How to fix

1. **Split into two methods** with descriptive names:

    ```php
    public function save(): void { /* ... */ }
    public function saveAndNotify(): void { /* ... */ }
    ```

2. **Use an enum** if there are more than two options:

    ```php
    enum SaveMode {
        case Silent;
        case WithNotification;
        case WithAuditLog;
    }

    public function save(SaveMode $mode = SaveMode::Silent): void { /* ... */ }
    ```

---

## count() in Loop

**Rule ID:** `code-smell.count-in-loop`
**Severity:** Warning

### What it measures

Detects calls to `count()` (or `sizeof()`) inside loop conditions. When `count()` is in the loop condition, it gets recalculated on every iteration, which is wasteful.

### Example

```php
// Bad: count() runs on every iteration
for ($i = 0; $i < count($items); $i++) {
    processItem($items[$i]);
}
```

### How to fix

Store the count in a variable before the loop:

```php
// Good: count() runs once
$itemCount = count($items);
for ($i = 0; $i < $itemCount; $i++) {
    processItem($items[$i]);
}

// Even better: use foreach when possible
foreach ($items as $item) {
    processItem($item);
}
```

---

## Debug Code

**Rule ID:** `code-smell.debug-code`
**Severity:** Error

### What it measures

Detects debugging functions left in production code: `var_dump()`, `print_r()`, `debug_print_backtrace()`, `debug_zval_dump()`, and similar.

### Example

```php
public function processPayment(Order $order): void
{
    var_dump($order);          // forgotten debug output
    print_r($order->items);   // forgotten debug output

    // actual logic...
}
```

### How to fix

Remove all debug statements before committing. If you need to inspect data:

- Use a proper logger (`$this->logger->debug(...)`)
- Use a debugger (Xdebug)
- Use a profiling tool

!!! warning
    Debug output can leak sensitive information (passwords, tokens, personal data) to end users. This is why it is reported as **Error**, not Warning.

---

## Empty Catch

**Rule ID:** `code-smell.empty-catch`
**Severity:** Error

### What it measures

Detects `catch` blocks that are completely empty -- they catch an exception and do absolutely nothing with it. This silently swallows errors, making bugs extremely hard to diagnose.

### Example

```php
// Bad: exception is silently ignored
try {
    $this->sendEmail($user);
} catch (\Exception $e) {
    // nothing here -- if sending fails, nobody will know
}
```

### How to fix

1. **Log the exception:**

    ```php
    try {
        $this->sendEmail($user);
    } catch (\Exception $e) {
        $this->logger->error('Failed to send email', ['exception' => $e]);
    }
    ```

2. **Rethrow as a domain exception:**

    ```php
    try {
        $this->sendEmail($user);
    } catch (\Exception $e) {
        throw new NotificationFailedException('Email sending failed', previous: $e);
    }
    ```

3. **Handle the error explicitly** if it is expected and recoverable.

---

## Error Suppression

**Rule ID:** `code-smell.error-suppression`
**Severity:** Warning

### What it measures

Detects use of the `@` error suppression operator. The `@` operator hides PHP errors and warnings, making it harder to find and fix problems.

### Example

```php
// Bad: if the file doesn't exist, you won't know why things fail later
$data = @file_get_contents('/path/to/file');

// Bad: suppressing warnings from a function
$result = @json_decode($input);
```

### How to fix

Handle errors explicitly:

```php
// Good: check before calling
if (!file_exists($path)) {
    throw new FileNotFoundException($path);
}
$data = file_get_contents($path);

// Good: use error handling functions
$result = json_decode($input, flags: JSON_THROW_ON_ERROR);
```

---

## eval()

**Rule ID:** `code-smell.eval`
**Severity:** Error

### What it measures

Detects use of `eval()`, which executes arbitrary PHP code from a string. This is a serious security risk: if user input reaches `eval()`, an attacker can run any code on your server.

### Example

```php
// Bad: security vulnerability
$formula = $_GET['formula'];
$result = eval("return $formula;");

// Bad: even with "safe" input, eval is hard to debug and maintain
eval('$config = ' . var_export($data, true) . ';');
```

### How to fix

- **Use closures or callable objects** instead of generating code as strings.
- **Use the Strategy pattern** instead of dynamic code execution.
- **Use `json_decode()`** for data parsing, not `eval()`.
- **Use template engines** for generating dynamic output.

---

## exit() / die()

**Rule ID:** `code-smell.exit`
**Severity:** Warning

### What it measures

Detects use of `exit()` and `die()`. These functions terminate the entire PHP process immediately, which:

- Prevents proper error handling
- Makes the code untestable (PHPUnit cannot catch `exit`)
- Bypasses shutdown handlers and destructors

### Example

```php
// Bad: untestable, no error handling
if (!$user->isAdmin()) {
    die('Access denied');
}

// Bad: prevents proper response handling
if ($error) {
    exit(1);
}
```

### How to fix

Throw exceptions instead:

```php
// Good: can be caught, tested, and handled
if (!$user->isAdmin()) {
    throw new AccessDeniedException('Access denied');
}
```

!!! note
    `exit()` is acceptable in CLI entry points (e.g., `bin/console`) where it sets the process exit code. This rule is mainly about using `exit`/`die` inside application logic.

---

## goto

**Rule ID:** `code-smell.goto`
**Severity:** Error

### What it measures

Detects use of `goto` statements. `goto` makes control flow unpredictable -- the reader has to search for the target label, which can be anywhere in the function. This makes the code very hard to follow and debug.

### Example

```php
// Bad: spaghetti control flow
function process(array $items): void
{
    foreach ($items as $item) {
        if ($item->isInvalid()) {
            goto cleanup;
        }
        // process item...
    }

    cleanup:
    // cleanup code
}
```

### How to fix

Use standard control flow structures:

```php
// Good: clear control flow
function process(array $items): void
{
    foreach ($items as $item) {
        if ($item->isInvalid()) {
            $this->cleanup();
            return;
        }
        // process item...
    }
}
```

Use loops, functions, early returns, or exceptions -- they all express intent more clearly than `goto`.

---

## Superglobals

**Rule ID:** `code-smell.superglobals`
**Severity:** Warning

### What it measures

Detects direct access to PHP superglobal variables: `$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER`, `$_SESSION`, `$_COOKIE`, `$_FILES`, `$_ENV`.

Direct superglobal access creates hidden dependencies on the global state, making code hard to test and unpredictable.

### Example

```php
// Bad: tightly coupled to global state
class UserController
{
    public function register(): void
    {
        $name = $_POST['name'];
        $email = $_POST['email'];
        $ip = $_SERVER['REMOTE_ADDR'];
        // ...
    }
}
```

### How to fix

Use dependency injection with request objects:

```php
// Good: dependencies are explicit and testable
class UserController
{
    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $name = $body['name'];
        $email = $body['email'];
        $ip = $request->getServerParams()['REMOTE_ADDR'];
        // ...
    }
}
```

Common request abstractions:

- **PSR-7:** `ServerRequestInterface` (framework-agnostic)
- **Symfony:** `Request` from HttpFoundation
- **Laravel:** `Illuminate\Http\Request`

---

## Configuration

All code smell rules share the same simple configuration -- just enable or disable:

```yaml
# .aimd.yaml
rules:
  code-smell.boolean-argument:
    enabled: true
  code-smell.debug-code:
    enabled: true
  code-smell.empty-catch:
    enabled: true
  code-smell.eval:
    enabled: false    # disable if you have legitimate eval usage
  code-smell.exit:
    enabled: true
  code-smell.goto:
    enabled: true
  code-smell.superglobals:
    enabled: true
  code-smell.count-in-loop:
    enabled: true
  code-smell.error-suppression:
    enabled: true
```

You can also disable individual rules via the `--disable-rule` CLI option:

```bash
# Disable a specific rule
bin/aimd analyze src/ --disable-rule=code-smell.exit

# Disable all code smell rules at once (prefix matching)
bin/aimd analyze src/ --disable-rule=code-smell
```
