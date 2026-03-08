# Size Rules

Size rules catch classes and namespaces that have grown too large. Large classes are harder to understand, test, and maintain. These rules set upper bounds on how big your code units should be.

---

## Method Count

**Rule ID:** `size.method-count`

### What it measures

Counts the number of methods in a class. A class with too many methods is likely doing too much and should be split into smaller, more focused classes.

### Thresholds

| Value   | Severity | Meaning                                    |
|---------|----------|--------------------------------------------|
| 1--19   | OK       | Reasonable class size                      |
| 20--29  | Warning  | Class is getting large, consider splitting |
| 30+     | Error    | Class is too large, should be refactored   |

### Configuration

| Option    | Default | Description                        |
|-----------|---------|------------------------------------|
| `enabled` | `true`  | Enable or disable this rule        |
| `warning` | `20`    | Method count that triggers warning |
| `error`   | `30`    | Method count that triggers error   |

```yaml
# aimd.yaml
rules:
  size.method-count:
    warning: 20
    error: 30
```

```bash
bin/aimd analyze src/ --rule-opt="size.method-count:warning=25"
bin/aimd analyze src/ --rule-opt="size.method-count:error=40"
```

### Example

```php
class OrderService
{
    public function createOrder() { /* ... */ }
    public function updateOrder() { /* ... */ }
    public function deleteOrder() { /* ... */ }
    public function findOrder() { /* ... */ }
    public function listOrders() { /* ... */ }
    public function validateOrder() { /* ... */ }
    public function calculateTotal() { /* ... */ }
    public function applyDiscount() { /* ... */ }
    public function sendConfirmation() { /* ... */ }
    public function generateInvoice() { /* ... */ }
    public function processPayment() { /* ... */ }
    public function refundOrder() { /* ... */ }
    public function shipOrder() { /* ... */ }
    public function trackShipment() { /* ... */ }
    public function notifyCustomer() { /* ... */ }
    public function exportToCSV() { /* ... */ }
    public function importFromCSV() { /* ... */ }
    public function archiveOrder() { /* ... */ }
    public function restoreOrder() { /* ... */ }
    public function auditOrder() { /* ... */ }
    // ...and more -- this class does everything!
}
```

### How to fix

1. **Identify method groups.** Look for clusters of methods that work together (e.g., all payment-related methods, all notification methods).

2. **Extract service classes.** Move each group into its own class:

    ```php
    class OrderService       { /* create, update, delete, find */ }
    class OrderPricing       { /* calculateTotal, applyDiscount */ }
    class PaymentProcessor   { /* processPayment, refundOrder */ }
    class OrderNotifier      { /* sendConfirmation, notifyCustomer */ }
    class OrderExporter      { /* exportToCSV, importFromCSV */ }
    ```

3. **Use composition.** If the original class needs to orchestrate these operations, inject the new services as dependencies.

---

## Class Count

**Rule ID:** `size.class-count`

### What it measures

Counts the number of classes in a namespace (package). This is measured at the namespace level, not the class level. A namespace with too many classes is hard to navigate and likely has too broad a scope.

### Thresholds

| Value   | Severity | Meaning                                         |
|---------|----------|--------------------------------------------------|
| 1--14   | OK       | Focused namespace                                |
| 15--24  | Warning  | Namespace is getting crowded                     |
| 25+     | Error    | Namespace should be split into sub-namespaces    |

### Configuration

| Option    | Default | Description                       |
|-----------|---------|-----------------------------------|
| `enabled` | `true`  | Enable or disable this rule       |
| `warning` | `15`    | Class count that triggers warning |
| `error`   | `25`    | Class count that triggers error   |

```yaml
# aimd.yaml
rules:
  size.class-count:
    warning: 15
    error: 25
```

```bash
bin/aimd analyze src/ --rule-opt="size.class-count:warning=20"
bin/aimd analyze src/ --rule-opt="size.class-count:error=30"
```

### Example

```
App\Service\                  # 28 classes -- too many!
├── UserService.php
├── OrderService.php
├── PaymentService.php
├── NotificationService.php
├── ReportService.php
├── ... (23 more files)
```

### How to fix

1. **Group related classes into sub-namespaces:**

    ```
    App\Service\User\           # 5 classes
    App\Service\Order\          # 6 classes
    App\Service\Payment\        # 4 classes
    App\Service\Notification\   # 3 classes
    ```

2. **Follow the Single Responsibility Principle** at the namespace level -- each namespace should represent one cohesive concept.

---

## Property Count

**Rule ID:** `size.property-count`

### What it measures

Counts the number of properties (fields) in a class. A class with many properties often has too many responsibilities or is storing too much state.

!!! note
    This rule uses a strict comparison (`>` instead of `>=`). A class with exactly 15 properties will **not** trigger a warning; it needs 16 or more.

### Thresholds

| Value   | Severity | Meaning                                           |
|---------|----------|---------------------------------------------------|
| 1--15   | OK       | Reasonable number of properties                   |
| 16--20  | Warning  | Too many properties, consider extracting objects  |
| 21+     | Error    | Far too many properties, refactor needed          |

### Configuration

| Option                | Default | Description                                                                |
|-----------------------|---------|----------------------------------------------------------------------------|
| `enabled`             | `true`  | Enable or disable this rule                                                |
| `warning`             | `15`    | Property count above this triggers warning                                 |
| `error`               | `20`    | Property count above this triggers error                                   |
| `excludeReadonly`     | `true`  | Skip `readonly` classes (DTOs, value objects)                              |
| `excludePromotedOnly` | `true` | Skip classes where all properties are promoted constructor parameters      |

```yaml
# aimd.yaml
rules:
  size.property-count:
    warning: 15
    error: 20
    exclude_readonly: true
    exclude_promoted_only: true
```

```bash
bin/aimd analyze src/ --rule-opt="size.property-count:warning=18"
bin/aimd analyze src/ --rule-opt="size.property-count:error=25"
```

### Example

```php
class ReportGenerator
{
    private string $title;
    private string $subtitle;
    private string $author;
    private \DateTimeInterface $createdAt;
    private string $format;
    private string $orientation;
    private float $marginTop;
    private float $marginBottom;
    private float $marginLeft;
    private float $marginRight;
    private string $headerText;
    private string $footerText;
    private string $fontFamily;
    private int $fontSize;
    private string $colorScheme;
    private bool $includeCharts;
    private bool $includeTables;
    private string $outputPath;
    // 18 properties -- this class knows too much!
}
```

### How to fix

1. **Extract value objects.** Group related properties into small, focused objects:

    ```php
    class PageMargins
    {
        public function __construct(
            public readonly float $top,
            public readonly float $bottom,
            public readonly float $left,
            public readonly float $right,
        ) {}
    }

    class ReportStyle
    {
        public function __construct(
            public readonly string $fontFamily,
            public readonly int $fontSize,
            public readonly string $colorScheme,
        ) {}
    }

    class ReportGenerator
    {
        public function __construct(
            private ReportMetadata $metadata,
            private PageMargins $margins,
            private ReportStyle $style,
            private ReportOptions $options,
        ) {}
    }
    ```

2. **Look for property clusters** -- properties that are always used together are candidates for extraction.

!!! tip
    Classes with only promoted constructor properties (common in DTOs) are excluded by default via `excludePromotedOnly`. This avoids false positives on classes like `CreateUserRequest(string $name, string $email, ...)`.
