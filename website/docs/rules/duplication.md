# Duplication Rules

Code duplication is one of the most common sources of technical debt. When the same logic exists in multiple places, every bug fix or feature change must be applied to all copies -- and inevitably, some copies get missed. These rules detect structurally identical code blocks across your codebase using token-stream analysis.

---

## Code Duplication

**Rule ID:** `duplication.code-duplication`

<!-- llms:skip-begin -->
### What it measures

Detects duplicated code blocks across files using token-stream hashing. The algorithm works in three steps:

1. **Tokenize** -- PHP code is parsed into a token stream.
2. **Normalize** -- tokens are transformed to ignore cosmetic differences: variables become `$_`, string literals become `'_'`, numbers become `0`, whitespace and comments are stripped. Function, method, and class names are preserved, so only **structurally identical** code with different variable names is flagged.
3. **Detect** -- duplicate sequences are found using a rolling hash (Rabin-Karp algorithm). Blocks shorter than the minimum thresholds are ignored.

Think of it like comparing recipes: if two recipes have the exact same steps in the same order but use different ingredient names, they are duplicates.

<!-- llms:skip-end -->

### Thresholds

| Value                  | Severity | Meaning                                                    |
| ---------------------- | -------- | ---------------------------------------------------------- |
| < 50 duplicated lines  | Warning  | Noticeable duplication, consider extracting shared logic   |
| >= 50 duplicated lines | Error    | Significant duplication, refactoring is strongly recommend |

Minimum block size (configurable):

| Option       | Default | Meaning                                            |
| ------------ | ------- | -------------------------------------------------- |
| `min_lines`  | 5       | Minimum number of lines for a block to be checked  |
| `min_tokens` | 70      | Minimum number of tokens for a block to be flagged |

<!-- llms:skip-begin -->
### Example

These two methods have identical structure but different variable names -- the rule flags them as duplicates:

```php
// In OrderService.php
public function calculateOrderTotal(Order $order): float
{
    $total = 0.0;
    foreach ($order->getItems() as $item) {
        $price = $item->getPrice();
        $quantity = $item->getQuantity();
        $subtotal = $price * $quantity;
        if ($item->hasDiscount()) {
            $subtotal *= (1 - $item->getDiscount());
        }
        $total += $subtotal;
    }
    return $total;
}

// In InvoiceService.php -- structurally identical
public function calculateInvoiceAmount(Invoice $invoice): float
{
    $amount = 0.0;
    foreach ($invoice->getLineItems() as $lineItem) {
        $rate = $lineItem->getPrice();
        $qty = $lineItem->getQuantity();
        $lineTotal = $rate * $qty;
        if ($lineItem->hasDiscount()) {
            $lineTotal *= (1 - $lineItem->getDiscount());
        }
        $amount += $lineTotal;
    }
    return $amount;
}
```

After normalization, both methods produce the same token sequence -- variables are replaced with `$_`, but method/class names like `getPrice`, `getQuantity`, `hasDiscount` are preserved.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

1. **Extract Method** -- move the shared logic into a common method or service:

    ```php
    final class PriceCalculator
    {
        /** @param iterable<PriceableItem> $items */
        public function calculateTotal(iterable $items): float
        {
            $total = 0.0;
            foreach ($items as $item) {
                $subtotal = $item->getPrice() * $item->getQuantity();
                if ($item->hasDiscount()) {
                    $subtotal *= (1 - $item->getDiscount());
                }
                $total += $subtotal;
            }
            return $total;
        }
    }
    ```

2. **Strategy pattern** -- when duplicated blocks differ in a few steps, extract the varying parts into strategy objects.

3. **Template Method** -- when the overall structure is the same but subclasses differ in specific steps, use an abstract base class with template methods.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Implementation notes

Qualimetrix uses the **Rabin-Karp rolling hash** algorithm for efficient detection. The normalization step is key to finding "near-duplicates" that differ only in variable names, string values, or numeric constants. This approach is similar to tools like PMD CPD and Simian.

Because function/method/class names are preserved during normalization, the detector will **not** flag two methods that call completely different APIs, even if their control flow structure is identical.

!!! tip "IDE integration"
    When using SARIF output (`--format=sarif`), duplicate copies are linked via `relatedLocations`. This means duplicate pairs appear as **clickable cross-references** in VS Code (SARIF Viewer extension) and JetBrains IDEs, making it easy to navigate between all copies of a duplicated block.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  duplication.code-duplication:
    enabled: true
    min_lines: 5
    min_tokens: 70
```

```bash
# Increase minimum token threshold to reduce noise
bin/qmx check src/ --rule-opt="duplication.code-duplication:min_tokens=100"

# Increase minimum line count
bin/qmx check src/ --rule-opt="duplication.code-duplication:min_lines=10"
```

You can also disable the rule entirely:

```bash
bin/qmx check src/ --disable-rule=duplication
```

!!! note "Memory usage"
    Duplication detection uses the Rabin-Karp rolling hash algorithm, which requires storing normalized tokens for all files with matching hashes in memory simultaneously. On large codebases (500+ files), this can consume significant memory. Disabling the rule with `--disable-rule=duplication` skips the detection phase entirely and frees the memory.
