# Code Smell Rules

Code smells are patterns that suggest something might be wrong with your code. They are not necessarily bugs, but they indicate areas where the code could be improved. These rules detect common bad practices that should usually be avoided.

All code smell rules can be individually enabled or disabled.

---

## Boolean Arguments

**Rule ID:** `code-smell.boolean-argument`
**Severity:** Warning

<!-- llms:skip-begin -->
### What it measures

Detects methods that accept `bool` parameters. A boolean argument usually means the method does two different things depending on the flag, which violates the Single Responsibility Principle.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

## count() in Loop

**Rule ID:** `code-smell.count-in-loop`
**Severity:** Warning

<!-- llms:skip-begin -->
### What it measures

Detects calls to `count()` (or `sizeof()`) inside loop conditions. When `count()` is in the loop condition, it gets recalculated on every iteration, which is wasteful.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
// Bad: count() runs on every iteration
for ($i = 0; $i < count($items); $i++) {
    processItem($items[$i]);
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

## Debug Code

**Rule ID:** `code-smell.debug-code`
**Severity:** Error

<!-- llms:skip-begin -->
### What it measures

Detects debugging functions left in production code: `var_dump()`, `print_r()`, `debug_print_backtrace()`, `debug_zval_dump()`, and similar.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
public function processPayment(Order $order): void
{
    var_dump($order);          // forgotten debug output
    print_r($order->items);   // forgotten debug output

    // actual logic...
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

Remove all debug statements before committing. If you need to inspect data:

- Use a proper logger (`$this->logger->debug(...)`)
- Use a debugger (Xdebug)
- Use a profiling tool

!!! warning
    Debug output can leak sensitive information (passwords, tokens, personal data) to end users. This is why it is reported as **Error**, not Warning.

---

<!-- llms:skip-end -->

## Empty Catch

**Rule ID:** `code-smell.empty-catch`
**Severity:** Error

<!-- llms:skip-begin -->
### What it measures

Detects `catch` blocks that are completely empty -- they catch an exception and do absolutely nothing with it. This silently swallows errors, making bugs extremely hard to diagnose.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
// Bad: exception is silently ignored
try {
    $this->sendEmail($user);
} catch (\Exception $e) {
    // nothing here -- if sending fails, nobody will know
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

## Error Suppression

**Rule ID:** `code-smell.error-suppression`
**Severity:** Warning

<!-- llms:skip-begin -->
### What it measures

Detects use of the `@` error suppression operator. The `@` operator hides PHP errors and warnings, making it harder to find and fix problems.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
// Bad: if the file doesn't exist, you won't know why things fail later
$data = @file_get_contents('/path/to/file');

// Bad: suppressing warnings from a function
$result = @json_decode($input);
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

## eval()

**Rule ID:** `code-smell.eval`
**Severity:** Error

<!-- llms:skip-begin -->
### What it measures

Detects use of `eval()`, which executes arbitrary PHP code from a string. This is a serious security risk: if user input reaches `eval()`, an attacker can run any code on your server.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
// Bad: security vulnerability
$formula = $_GET['formula'];
$result = eval("return $formula;");

// Bad: even with "safe" input, eval is hard to debug and maintain
eval('$config = ' . var_export($data, true) . ';');
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

- **Use closures or callable objects** instead of generating code as strings.
- **Use the Strategy pattern** instead of dynamic code execution.
- **Use `json_decode()`** for data parsing, not `eval()`.
- **Use template engines** for generating dynamic output.

---

<!-- llms:skip-end -->

## exit() / die()

**Rule ID:** `code-smell.exit`
**Severity:** Warning

<!-- llms:skip-begin -->
### What it measures

Detects use of `exit()` and `die()`. These functions terminate the entire PHP process immediately, which:

- Prevents proper error handling
- Makes the code untestable (PHPUnit cannot catch `exit`)
- Bypasses shutdown handlers and destructors

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

## goto

**Rule ID:** `code-smell.goto`
**Severity:** Error

<!-- llms:skip-begin -->
### What it measures

Detects use of `goto` statements. `goto` makes control flow unpredictable -- the reader has to search for the target label, which can be anywhere in the function. This makes the code very hard to follow and debug.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

## Superglobals

**Rule ID:** `code-smell.superglobals`
**Severity:** Warning

<!-- llms:skip-begin -->
### What it measures

Detects direct access to PHP superglobal variables: `$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER`, `$_SESSION`, `$_COOKIE`, `$_FILES`, `$_ENV`.

Direct superglobal access creates hidden dependencies on the global state, making code hard to test and unpredictable.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
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

<!-- llms:skip-end -->

## Constructor Over-injection

**Rule ID:** `code-smell.constructor-overinjection`

<!-- llms:skip-begin -->
### What it measures

Detects constructors with too many dependencies injected. A long constructor parameter list in DI-heavy codebases is a direct signal of Single Responsibility Principle violation -- the class has too many collaborators.

<!-- llms:skip-end -->

### Thresholds

| Value | Severity | Meaning                                   |
| ----- | -------- | ----------------------------------------- |
| 8     | Warning  | Too many dependencies, consider splitting |
| 12+   | Error    | Class clearly violates SRP                |

<!-- llms:skip-begin -->
### Example

```php
// Bad: constructor depends on too many services
class OrderProcessor
{
    public function __construct(
        private UserRepository $users,
        private ProductRepository $products,
        private InventoryService $inventory,
        private PricingService $pricing,
        private TaxCalculator $tax,
        private ShippingService $shipping,
        private NotificationService $notifications,
        private AuditLogger $audit,
        private MetricsCollector $metrics,
    ) {}
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

1. **Split the class** into smaller, focused services (e.g., `OrderValidator`, `OrderFulfiller`, `OrderNotifier`).
2. **Use a Facade or Mediator** to group related dependencies behind a single interface.
3. **Introduce a Command Bus** -- instead of injecting handlers directly, dispatch commands.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  code-smell.constructor-overinjection:
    warning: 8
    error: 12
```

```bash
bin/qmx check src/ --rule-opt="code-smell.constructor-overinjection:warning=6"
```

---

## Long Parameter List

**Rule ID:** `code-smell.long-parameter-list`

<!-- llms:skip-begin -->
### What it measures

Detects methods and functions with too many parameters. A long parameter list makes the method hard to call correctly, hard to test, and often indicates the method is doing too many things. Consider using a parameter object or splitting the method.

<!-- llms:skip-end -->

### Thresholds

**Standard thresholds** (methods and functions):

| Value | Severity | Meaning                              |
| ----- | -------- | ------------------------------------ |
| 4     | Warning  | Consider grouping parameters         |
| 6+    | Error    | Too many parameters, refactor needed |

**VO constructor thresholds** (readonly class, all promoted, empty body):

| Value | Severity | Meaning                                 |
| ----- | -------- | --------------------------------------- |
| 8     | Warning  | Consider splitting the value object     |
| 12+   | Error    | Too many fields, split the value object |

<!-- llms:skip-begin -->

#### Value Object constructor exemption

Readonly classes with constructor parameter promotion (PHP 8.2+) are treated as Value Objects.
A `readonly class` with 6 promoted properties and no body logic is valid design — it's a typed
data container, not a sign of poor decomposition. These constructors use separate, higher thresholds.

**VO detection criteria** (all must be true):

1. Class has the `readonly` modifier
2. All constructor parameters are promoted properties (have visibility modifier)
3. Constructor body is empty (no statements)

When **any** condition is not met, standard thresholds apply.

### Example

```php
// Bad: too many parameters, hard to remember the order
public function createUser(
    string $name,
    string $email,
    string $phone,
    string $address,
    string $city,
    string $country,
    string $zipCode,
): User {
    // ...
}
```

```php
// OK: readonly VO with promoted properties uses higher thresholds
final readonly class CreateUserRequest
{
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public string $address,
        public string $city,
        public string $country,
        public string $zipCode,  // 7 params — below vo-warning=8
    ) {}
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

1. **Use a parameter object (DTO):**

    ```php
    final readonly class CreateUserRequest
    {
        public function __construct(
            public string $name,
            public string $email,
            public string $phone,
            public Address $address,
        ) {}
    }

    public function createUser(CreateUserRequest $request): User { /* ... */ }
    ```

2. **Split the method** if parameters belong to different responsibilities.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  code-smell.long-parameter-list:
    warning: 4        # standard methods/functions
    error: 6
    vo-warning: 8     # readonly VO constructors
    vo-error: 12
```

```bash
bin/qmx check src/ --rule-opt="code-smell.long-parameter-list:warning=5"
bin/qmx check src/ --rule-opt="code-smell.long-parameter-list:error=8"
bin/qmx check src/ --rule-opt="code-smell.long-parameter-list:vo-warning=10"
bin/qmx check src/ --rule-opt="code-smell.long-parameter-list:vo-error=15"
```

---

## Identical Sub-expression

**Rule ID:** `code-smell.identical-subexpression`
**Severity:** Warning

<!-- llms:skip-begin -->
### What it measures

Detects identical sub-expressions that indicate copy-paste errors or logic bugs. The rule catches four patterns:

1. **Identical operands in binary operations** -- the same expression on both sides of an operator (e.g., `$a === $a`, `$a - $a`, `$a && $a`).
2. **Duplicate conditions in if/elseif chains** -- the same condition checked more than once, meaning the second branch is dead code.
3. **Identical ternary branches** -- a ternary where the "true" and "false" branches are the same, making the condition pointless.
4. **Duplicate match arm conditions** -- repeated conditions in a `match` expression, where only the first arm will ever execute.

Operators with legitimate identical-operand use cases are not flagged: `+`, `*`, `.`, `&`, `|`, `<<`, `>>`.

Expressions with side effects (function calls, method calls, etc.) are excluded since consecutive calls may return different results.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
class OrderService
{
    public function validate(Order $order): bool
    {
        // Bad: identical operands -- always true, likely a typo
        if ($order->total === $order->total) {
            // ...
        }

        // Bad: subtracting a value from itself -- always 0
        $diff = $order->price - $order->price;

        // Bad: duplicate condition -- second branch is dead code
        if ($order->isPaid()) {
            return true;
        } elseif ($order->isPaid()) {
            return false;
        }

        // Bad: identical ternary branches -- condition is pointless
        $status = $order->isActive() ? 'pending' : 'pending';

        // Bad: duplicate match arm condition
        return match ($order->type) {
            'retail' => $this->handleRetail($order),
            'wholesale' => $this->handleWholesale($order),
            'retail' => $this->handleSpecial($order),  // never reached
        };
    }
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

These are almost always bugs -- inspect each occurrence and fix the intended logic:

1. **Identical operands:** One side is usually a typo. Replace it with the correct variable:

    ```php
    // Was: $order->total === $order->total (always true)
    // Fix: compare with the expected value
    if ($order->total === $order->expectedTotal) {
        // ...
    }
    ```

2. **Duplicate conditions:** Remove the duplicate branch or fix the condition:

    ```php
    if ($order->isPaid()) {
        return true;
    } elseif ($order->isRefunded()) {
        return false;
    }
    ```

3. **Identical ternary branches:** Either the condition is unnecessary, or one branch has a wrong value:

    ```php
    $status = $order->isActive() ? 'active' : 'pending';
    ```

4. **Duplicate match arms:** Remove the duplicate or fix the condition value.

---

<!-- llms:skip-end -->

## Unused Private Members

**Rule ID:** `code-smell.unused-private`
**Severity:** Warning

<!-- llms:skip-begin -->
### What it measures

Detects private methods, properties, and constants that are declared but never referenced within the class. Unused private members are dead code -- they add noise, increase cognitive load, and may indicate incomplete refactoring.

The rule is smart about edge cases:

- **Magic method awareness:** classes with `__call`/`__callStatic` skip method checks; classes with `__get`/`__set` skip property checks
- **Constructor promotion:** promoted properties are tracked correctly
- **Anonymous classes:** private members in anonymous classes are isolated and don't leak to the parent class
- **Excluded types:** interfaces, traits, and enums are not analyzed
- **Access patterns:** recognizes `$this->method()`, `self::method()`, `static::method()`, property access, and constant access
- **Trait resolution:** calls to methods defined in traits used by the same class (in the same file) are recognized, reducing false positives

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
class OrderService
{
    private string $unusedField = '';  // never read or written

    private const LEGACY_LIMIT = 100;  // never referenced

    public function process(Order $order): void
    {
        // ... uses $order but never touches $unusedField or LEGACY_LIMIT
    }

    private function oldHelper(): void  // never called
    {
        // leftover from a previous implementation
    }
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

- **Remove** the unused member if it is truly dead code.
- **Change visibility** to `protected` or `public` if the member is used by subclasses or external code.
- If the member is intentionally kept for future use, suppress the warning with `@qmx-ignore code-smell.unused-private`.

---

<!-- llms:skip-end -->

## Unreachable Code

**Rule ID:** `code-smell.unreachable-code`

<!-- llms:skip-begin -->
### What it measures

Detects code that can never be executed because it appears after a terminal statement (`return`, `throw`, `exit`/`die`, `continue`, `break`, `goto`). Dead code adds noise, confuses readers, and may indicate a logic error.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
public function process(Order $order): string
{
    if ($order->isPaid()) {
        return 'processed';
    }

    return 'pending';

    // Bad: this code can never run
    $this->logger->info('Processing complete');
    $this->notify($order);
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

Remove the unreachable code. If the code was supposed to run, fix the control flow:

```php
public function process(Order $order): string
{
    if ($order->isPaid()) {
        $this->logger->info('Processing complete');
        $this->notify($order);
        return 'processed';
    }

    return 'pending';
}
```

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  code-smell.unreachable-code:
    warning: 1
    error: 1
```

```bash
bin/qmx check src/ --rule-opt="code-smell.unreachable-code:warning=1"
bin/qmx check src/ --rule-opt="code-smell.unreachable-code:error=1"
```

---

## Configuration

All code smell rules share the same simple configuration -- just enable or disable:

```yaml
# qmx.yaml
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
  code-smell.constructor-overinjection:
    warning: 8
    error: 12
  code-smell.count-in-loop:
    enabled: true
  code-smell.error-suppression:
    enabled: true
  code-smell.long-parameter-list:
    warning: 4
    error: 6
  code-smell.unreachable-code:
    warning: 1
    error: 1
  code-smell.unused-private:
    enabled: true
  code-smell.identical-subexpression:
    enabled: true
```

You can also disable individual rules via the `--disable-rule` CLI option:

```bash
# Disable a specific rule
bin/qmx check src/ --disable-rule=code-smell.exit

# Disable all code smell rules at once (prefix matching)
bin/qmx check src/ --disable-rule=code-smell
```
