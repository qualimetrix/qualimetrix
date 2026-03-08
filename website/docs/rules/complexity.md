# Complexity Rules

Complexity rules measure how tangled and branching your code is. The more branches, loops, and conditions a method has, the harder it is to understand, test, and change without introducing bugs.

Think of it like directions to someone's house: "go straight, then turn left" is easy. "Go straight, but if there is construction turn right, unless it is a Tuesday, in which case..." -- that is complex.

---

## Cyclomatic Complexity

**Rule ID:** `complexity.cyclomatic`

### What it measures

Cyclomatic Complexity (often abbreviated CCN) counts the number of **decision points** in a method. Every `if`, `elseif`, `while`, `for`, `foreach`, `case`, `catch`, `&&`, `||`, `??`, and `?:` adds 1 to the count. A method with no branches at all has a complexity of 1.

The number roughly tells you the minimum number of test cases you need to fully cover the method.

### Thresholds

**Method level** (enabled by default):

| Level | Threshold | Severity |
|-------|-----------|----------|
| Warning | >= 10 | Warning |
| Error | >= 20 | Error |

**Class level** (enabled by default) -- checks the maximum CCN among all methods in the class:

| Level | Threshold | Severity |
|-------|-----------|----------|
| Warning | >= 30 | Warning |
| Error | >= 50 | Error |

### Example

This method has a cyclomatic complexity of 5 (1 base + 4 decision points):

```php
function processOrder(Order $order): void
{
    if ($order->isPaid()) {               // +1
        if ($order->hasDiscount()) {      // +1
            $this->applyDiscount($order);
        }
        foreach ($order->getItems() as $item) {  // +1
            $this->ship($item);
        }
    } elseif ($order->isPending()) {      // +1
        $this->notify($order);
    }
}
```

### How to fix

- **Extract methods.** Move nested logic into well-named helper methods. Each method becomes simpler and easier to test.
- **Use early returns.** Instead of nesting `if` blocks, check for invalid conditions first and return early.
- **Replace conditionals with polymorphism.** If you have a long `switch` or chain of `if/elseif`, consider using the Strategy or State pattern.
- **Simplify boolean expressions.** Complex conditions like `if ($a && ($b || $c) && !$d)` can often be broken into named boolean variables or separate methods.

### Configuration

```bash
# Adjust method-level thresholds
bin/aimd analyze src/ --rule-opt="complexity.cyclomatic:method.warning=15"
bin/aimd analyze src/ --rule-opt="complexity.cyclomatic:method.error=25"

# Adjust class-level thresholds
bin/aimd analyze src/ --rule-opt="complexity.cyclomatic:class.max_warning=40"

# Disable class-level check
bin/aimd analyze src/ --rule-opt="complexity.cyclomatic:class.enabled=false"
```

---

## Cognitive Complexity

**Rule ID:** `complexity.cognitive`

### What it measures

Cognitive Complexity measures how hard the code is to **read and understand** by a human. Unlike cyclomatic complexity, which counts decision points mechanically, cognitive complexity considers how the code *feels* to the reader.

Key differences from cyclomatic complexity:

- **Nesting increases the penalty.** An `if` inside another `if` scores higher than two `if` blocks at the same level, because nested logic is harder to follow mentally.
- **Shorthand structures score less.** A `switch` with 10 cases adds only 1 point (it is a single mental structure), while 10 separate `if` statements add 10 points each plus nesting.
- **Breaks in linear flow add points.** `break`, `continue`, and `goto` all cost points because they disrupt the reading flow.

### Thresholds

**Method level** (enabled by default):

| Level | Threshold | Severity |
|-------|-----------|----------|
| Warning | >= 15 | Warning |
| Error | >= 30 | Error |

**Class level** (enabled by default) -- checks the maximum cognitive complexity among all methods:

| Level | Threshold | Severity |
|-------|-----------|----------|
| Warning | >= 30 | Warning |
| Error | >= 50 | Error |

### Example

```php
function calculate(array $items): float  // cognitive complexity: 9
{
    $total = 0;
    foreach ($items as $item) {                  // +1 (nesting 0)
        if ($item->isActive()) {                 // +2 (1 + nesting 1)
            if ($item->hasDiscount()) {          // +3 (1 + nesting 2)
                $total += $item->discountedPrice();
            } else {                             // +1
                $total += $item->price();
            }
        }
    }
    return $total;
}
```

Notice how nesting makes the penalty grow. The deeply nested `if ($item->hasDiscount())` costs 3 points, not just 1, because it sits inside two other structures.

### How to fix

- **Reduce nesting depth.** This is the single most effective fix. Use early returns to "flatten" the code.
- **Extract deeply nested blocks** into separate methods with descriptive names.
- **Avoid `else` after `return`.** If the `if` branch returns, you do not need `else`.
- **Replace loops with collection methods** (e.g., `array_filter`, `array_map`) when appropriate.

### Configuration

```bash
bin/aimd analyze src/ --rule-opt="complexity.cognitive:method.warning=20"
bin/aimd analyze src/ --rule-opt="complexity.cognitive:method.error=40"
```

---

## NPath Complexity

**Rule ID:** `complexity.npath`

### What it measures

NPath Complexity counts the total number of **unique execution paths** through a method. While cyclomatic complexity adds 1 for each decision point, NPath *multiplies* across branches.

Think of it this way: if a method has 3 independent `if` statements, each can be true or false. That gives 2 x 2 x 2 = 8 possible paths. NPath would be 8, while cyclomatic complexity would be 4.

This makes NPath grow very fast. It reflects the true testing burden: to fully test all paths, you would need one test case per unique path.

### Thresholds

**Method level** (enabled by default):

| Level | Threshold | Severity |
|-------|-----------|----------|
| Warning | >= 200 | Warning |
| Error | >= 1000 | Error |

**Class level** (disabled by default) -- checks the maximum NPath among methods.

### Example

```php
function validate(Request $request): bool
{
    if ($request->hasName()) { /* ... */ }       // 2 paths
    if ($request->hasEmail()) { /* ... */ }      // x 2 = 4 paths
    if ($request->hasPhone()) { /* ... */ }      // x 2 = 8 paths
    if ($request->hasAddress()) { /* ... */ }    // x 2 = 16 paths
    if ($request->hasCity()) { /* ... */ }       // x 2 = 32 paths
    if ($request->hasCountry()) { /* ... */ }    // x 2 = 64 paths
    if ($request->hasZip()) { /* ... */ }        // x 2 = 128 paths
    if ($request->hasState()) { /* ... */ }      // x 2 = 256 paths -> WARNING
    return true;
}
```

Just 8 independent `if` statements already produce 256 paths.

### How to fix

- **Extract groups of related checks** into separate methods. Splitting validation into `validateContactInfo()` and `validateAddress()` cuts the path count dramatically.
- **Reduce independent branches.** Combine related conditions or use data-driven validation (e.g., loop over a list of required fields).
- **Avoid deeply nested conditions** -- they multiply NPath even faster than sequential ones.

### Configuration

```bash
bin/aimd analyze src/ --rule-opt="complexity.npath:method.warning=300"
bin/aimd analyze src/ --rule-opt="complexity.npath:method.error=2000"

# Enable class-level check
bin/aimd analyze src/ --rule-opt="complexity.npath:class.enabled=true"
```

---

## WMC -- Weighted Methods per Class

**Rule ID:** `complexity.wmc`

### What it measures

WMC (Weighted Methods per Class) is the **sum of cyclomatic complexity of all methods** in a class. It tells you the overall complexity burden of the entire class.

A class with 20 simple getter/setter methods (each with complexity 1) has WMC = 20. A class with 5 methods where each has complexity 10 also has WMC = 50. Both are "heavy" in different ways: the first has too many methods, the second has too-complex methods.

### Thresholds

| Level | Threshold | Severity |
|-------|-----------|----------|
| Warning | > 50 | Warning |
| Error | > 80 | Error |

### Example

```php
class OrderProcessor
{
    public function process(): void { /* CCN = 8 */ }
    public function validate(): void { /* CCN = 12 */ }
    public function calculateTax(): void { /* CCN = 6 */ }
    public function applyDiscounts(): void { /* CCN = 10 */ }
    public function generateInvoice(): void { /* CCN = 7 */ }
    public function sendNotification(): void { /* CCN = 5 */ }
    public function logResult(): void { /* CCN = 3 */ }
    // WMC = 8 + 12 + 6 + 10 + 7 + 5 + 3 = 51 -> WARNING
}
```

### How to fix

- **Split the class** into smaller, focused classes. If WMC is high because of many methods, the class likely has too many responsibilities.
- **Simplify individual methods.** If WMC is high because a few methods are very complex, refactor those methods first.
- **Consider the Single Responsibility Principle.** A class should have only one reason to change.

### Configuration

```bash
bin/aimd analyze src/ --rule-opt="complexity.wmc:warning=60"
bin/aimd analyze src/ --rule-opt="complexity.wmc:error=100"

# Exclude data classes (DTOs, entities) from the check
bin/aimd analyze src/ --rule-opt="complexity.wmc:exclude_data_classes=true"
```
