# Maintainability Rules

The Maintainability Index combines several metrics into a single score that indicates how easy the code is to maintain. Higher scores are better -- the opposite of most other rules.

---

## Maintainability Index

**Rule ID:** `maintainability.index`

### What it measures

The Maintainability Index (MI) is a composite score that combines three factors:

- **Halstead Volume** -- how much "information" is in the code (based on the number of operators and operands)
- **Cyclomatic Complexity** -- how many independent paths through the code exist
- **Lines of Code** -- the physical size of the method

These three factors are combined into a single number. The original formula produces values on a 0--171 scale, though in practice most code falls between 0 and 100.

**How to read the score:**

| Score    | Meaning                                                 |
| -------- | ------------------------------------------------------- |
| 85--100+ | Excellent -- easy to understand and modify              |
| 65--84   | Good -- reasonable maintainability                      |
| 40--64   | Moderate -- could benefit from simplification           |
| 20--39   | Poor -- difficult to maintain, refactoring recommended  |
| Below 20 | Critical -- very hard to maintain, refactoring required |

!!! warning "Inverted thresholds"
    Unlike most rules where higher values are worse, here **lower values are worse**. The rule triggers when the score drops **below** the threshold.

### Thresholds

| Score    | Severity | Meaning                          |
| -------- | -------- | -------------------------------- |
| 40+      | OK       | Maintainable code                |
| 20--39   | Warning  | Maintainability is deteriorating |
| Below 20 | Error    | Code is very hard to maintain    |

### Example

A method with low maintainability (MI around 15):

```php
public function processOrder(array $items, array $discounts, ?Customer $customer): array
{
    $result = [];
    $total = 0;
    $taxRate = 0.0;

    if ($customer !== null) {
        if ($customer->isPremium()) {
            $taxRate = $customer->getRegion() === 'EU' ? 0.20 : 0.15;
            if ($customer->hasLoyaltyCard()) {
                $taxRate *= 0.95;
            }
        } else {
            $taxRate = match ($customer->getRegion()) {
                'EU' => 0.21,
                'US' => 0.08,
                'UK' => 0.20,
                default => 0.10,
            };
        }
    }

    foreach ($items as $item) {
        $price = $item['price'] * $item['quantity'];
        foreach ($discounts as $discount) {
            if ($discount['type'] === 'percentage') {
                if (in_array($item['category'], $discount['categories'], true)) {
                    $price *= (1 - $discount['value'] / 100);
                }
            } elseif ($discount['type'] === 'fixed') {
                if ($price > $discount['min_amount']) {
                    $price -= $discount['value'];
                }
            } elseif ($discount['type'] === 'bogo') {
                if ($item['quantity'] >= 2) {
                    $freeItems = intdiv($item['quantity'], 2);
                    $price -= $freeItems * $item['price'];
                }
            }
        }
        $tax = $price * $taxRate;
        $result[] = [
            'item' => $item['name'],
            'subtotal' => $price,
            'tax' => $tax,
            'total' => $price + $tax,
        ];
        $total += $price + $tax;
    }

    return ['items' => $result, 'total' => $total];
}
```

This method has high complexity, many lines, and many operators/operands, all of which drive the MI score down.

### How to fix

1. **Extract helper methods.** Break the long method into smaller, named pieces:

    ```php
    public function processOrder(array $items, array $discounts, ?Customer $customer): array
    {
        $taxRate = $this->calculateTaxRate($customer);
        $result = [];
        $total = 0;

        foreach ($items as $item) {
            $lineItem = $this->processLineItem($item, $discounts, $taxRate);
            $result[] = $lineItem;
            $total += $lineItem['total'];
        }

        return ['items' => $result, 'total' => $total];
    }
    ```

2. **Reduce branching.** Replace nested `if/else` chains with early returns, strategy pattern, or polymorphism.

3. **Use value objects.** Replace arrays with typed objects to reduce the number of raw operations.

!!! tip
    The `minLoc` option (default: 10) filters out trivially small methods. Simple getters and setters would produce extreme MI scores that are meaningless. Adjust this if you get too many false positives on small methods.

### Implementation notes

The Maintainability Index uses the **Oman-Hagemeister formula**:

```
MI = 171 - 5.2 x ln(V) - 0.23 x CCN - 16.2 x ln(LOC)
```

Where:

- **V** = Halstead Volume (a measure of information content based on operators and operands)
- **CCN** = Cyclomatic Complexity ([CCN2+ variant](complexity.md#implementation-notes))
- **LOC** = Logical Lines of Code (LLOC -- statement count, not physical line count)

The raw MI value (0-171 scale) is normalized to a **0-100 scale**: `max(0, MI x 100 / 171)`.

**Scope:** MI is calculated per method, then aggregated to class/namespace/project level using average and minimum values.

!!! note "LOC input"
    AIMD uses LLOC (logical lines -- the number of statements) for the MI formula, which aligns with the original Oman-Hagemeister paper. Some tools use physical LOC (including blank lines and comments) or ELOC (executable lines), which produces different results. LLOC gives the most stable and meaningful values because it is not affected by formatting or comment density.

### Configuration

| Option         | Default | Description                                  |
| -------------- | ------- | -------------------------------------------- |
| `enabled`      | `true`  | Enable or disable this rule                  |
| `warning`      | `40.0`  | Score below this triggers a warning          |
| `error`        | `20.0`  | Score below this triggers an error           |
| `excludeTests` | `true`  | Skip test files                              |
| `minLoc`       | `10`    | Skip methods with fewer lines (avoids noise) |

```yaml
# aimd.yaml
rules:
  maintainability.index:
    warning: 40
    error: 20
    exclude_tests: true
    min_loc: 10
```

```bash
bin/aimd check src/ --rule-opt="maintainability.index:warning=35"
bin/aimd check src/ --rule-opt="maintainability.index:min_loc=15"
```
