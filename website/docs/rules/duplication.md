# Duplication Rules

Code duplication is one of the most common sources of technical debt. When the same logic exists in multiple places, every bug fix or feature change must be applied to all copies -- and inevitably, some copies get missed. These rules detect structurally identical code blocks across your codebase using token-stream analysis.

---

## Code Duplication

**Rule ID:** `duplication.clone`

<!-- llms:skip-begin -->
### What it measures

Detects duplicated code blocks across files using token-stream hashing. The algorithm works in three steps:

1. **Tokenize** -- PHP code is parsed into a token stream.
2. **Normalize** -- tokens are transformed to ignore cosmetic differences: variables become `$_`, string literals become `'_'`, numbers become `0`, whitespace and comments are stripped. Function, method, and class names are preserved, so only **structurally identical** code with different variable names is flagged.
3. **Detect** -- duplicate sequences are found using a rolling hash (Rabin-Karp algorithm). Blocks shorter than the minimum thresholds are ignored.

Think of it like comparing recipes: if two recipes have the exact same steps in the same order but use different ingredient names, they are duplicates.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Thresholds

| Value                  | Severity | Meaning                                                    |
| ---------------------- | -------- | ---------------------------------------------------------- |
| < 50 duplicated lines  | Warning  | Noticeable duplication, consider extracting shared logic   |
| >= 50 duplicated lines | Error    | Significant duplication, refactoring is strongly recommend |

Minimum block size (configurable):

| Option       | Default | Meaning                                                 |
| ------------ | ------- | ------------------------------------------------------- |
| `min_lines`  | 5       | Minimum number of lines a copy must span to be reported |
| `min_tokens` | 70      | Minimum number of tokens for a block to be flagged      |

Each copy's finding reports the lines **that copy** spans, and its severity follows from that number. A copy is reported only when it spans at least `min_lines` lines itself; a shorter copy of the same block reports nothing of its own, but the reported copies still name it. Comments and blank lines are not tokens, so the copies of one block can span different numbers of lines: a comment added inside one copy changes that copy's value, and can make that copy — and no other — cross `min_lines` in either direction.
<!-- llms:skip-end -->

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

The implementation is owned by the `Analysis.Evidence.Duplication` capability,
which keeps detection, its per-run result and the rule together. Run
orchestration triggers the capability through a narrow reset/inspect contract;
this ownership change does not alter the rule id, options, algorithm or output.

!!! info "Deviation from original spec"
    A duplicate block is identified by the full SHA-256 of its complete
    normalized matched token sequence plus token count, and each copy of it by
    that digest, the project-relative path of the file holding the copy, and
    the copy's place among the block's copies in that file, counted in line
    order. The project is the finding subject. No line number enters the
    identity, so lines added or removed outside the matched tokens re-key
    nothing while the detector finds the same block. The block is the longest
    token run all of its copies agree on, and the match takes in whatever
    context the copies share around the copied code, so a block is defined by
    all of its copies at once. A copy that agrees with only part of the block,
    an edit inside one copy, or code inserted between a copy and that shared
    context — a new method above a copied one, for example — changes the
    blocks the detector finds: each copy of a new block is a new finding, in
    files the change never touched too, and a new fingerprint to GitLab Code
    Quality and SARIF consumers, which tell findings apart by it; the baseline
    entries of a block that is gone go stale. A copy moved to another file,
    or every copy in a renamed file, is a new copy and leaves a stale baseline
    entry behind. A copy pasted above another copy of the same block in the
    same file takes the lower place, so the copy it displaced is the one
    reported as new.

!!! warning "Inline `@qmx-ignore` cannot suppress this channel"
    Every copy of a block is the same project-level debt, so no inline
    directive is allowed to silence it: `@qmx-ignore` binds to the declaration
    it decorates, which the project never is, and `@qmx-ignore-file` /
    `@qmx-ignore-next-line` would silence the one copy they are written beside
    while every other copy still reports the block — and a copy pasted
    together with such a directive would pass a baseline unseen. All three
    forms are refused (`annotation.unresolved-directive`) wherever they are
    written. Disable the rule instead — `disabled_rules: [duplication.clone]`
    in the configuration, or `--disable-rule=duplication.clone` — or accept
    the block, all of its copies, into the baseline. `suppress_paths` silences
    only the copies inside its paths: the block's other copies are still
    reported, so silencing a block means listing every file it has a copy in.

!!! info "Constant and property arrays are always excluded"
    A duplicate block that lies **entirely** inside a `const` declaration or a static/instance property's array-literal initializer is never reported. Rows of a lookup table (e.g. `'key' => ['a' => ..., 'b' => ...]` repeated with different values) normalize to identical token sequences, but "extract a shared method" is not actionable advice for a data table — repeating the same field shape across rows is the normal, correct form of that table. A block spanning both a data declaration and executable code (or lying entirely in a method body) is still reported. This suppression is unconditional and cannot be turned off.

!!! info "Every copy of a block is a finding of its own"
    Each copy of a duplicated block that spans at least `min_lines` lines is reported by a finding located on that copy, however many copies there are — there is no upper limit on the number of copies. The message states the number of occurrences and names up to ten other copies, followed by `and N more` when there are more; the finding's related locations are the copies its message names. Each copy has an identity of its own, so a new copy that agrees with the whole of an accepted block is a new finding — reported on the new copy alone, at its own severity, while the copies the baseline accepted stay accepted — and `--report=git:*` reports it in the file it was pasted into. A block of N copies is N findings, each carrying the rule's remediation time. When copies agree over different lengths, each longest agreeing set is reported: two copies that match for 40 lines and a third that matches them only for the first 10 give a finding on each of the two 40-line copies and one on each of the three copies over 10 lines. That is also why a copy agreeing with only part of an accepted block is not new on its own: the shorter block it forms is new on every copy.

!!! info "Copies within one file"
    Two occurrences in the same file that share a line are one repetitive structure matching itself at a shifted position, not two copies, and only the first is kept. Occurrences that merely touch — one ends on the line before the other starts — are two copies and are reported.

!!! tip "IDE integration"
    When using SARIF output (`--format=sarif`), duplicate copies are linked via `relatedLocations`. This means duplicate copies appear as **clickable cross-references** in VS Code (SARIF Viewer extension) and JetBrains IDEs, making it easy to navigate from each copy to the others it names.

<!-- llms:skip-end -->

### Content preview hints

Duplication findings include a content preview of the duplicated block. This helps you quickly identify which code is duplicated without navigating to the file:

```
Duplicated code block (16 lines, 2 occurrences): "$total = 0.0; foreach ($order->getItems() as $item) { $price =..." — also at src/Service/InvoiceService.php:15-30
```

The preview is the source text of the first copy: up to three of its first ten lines, skipping blank and brace-only lines, with whitespace collapsed and cut to about 80 characters.

### Configuration

```yaml
# qmx.yaml
rules:
  duplication.clone:
    enabled: true
    min_lines: 5
    min_tokens: 70
    warning: 5    # duplicated lines
    error: 50
```

```bash
# Increase minimum token threshold to reduce noise
bin/qmx check src/ --rule-opt="duplication.clone:min_tokens=100"

# Increase minimum line count
bin/qmx check src/ --rule-opt="duplication.clone:min_lines=10"

bin/qmx check src/ --rule-opt="duplication.clone:warning=10"
bin/qmx check src/ --rule-opt="duplication.clone:error=60"
```

For a simple pass/fail threshold instead of separate warning/error levels
(`threshold` cannot be combined with `warning` or `error` — mixing them is a
configuration error and the run stops with exit code 3):

```yaml
rules:
  duplication.clone:
    threshold: 50   # warning=50, error=50 → all violations are errors
```

```bash
bin/qmx check src/ --rule-opt="duplication.clone:threshold=50"
```

You can also disable the rule entirely:

```bash
bin/qmx check src/ --disable-rule=duplication.clone
```

!!! note "Memory usage"
    Duplication detection uses the Rabin-Karp rolling hash algorithm, which requires storing normalized tokens for all files with matching hashes in memory simultaneously. On large codebases (500+ files), this can consume significant memory. Disabling the rule — `--disable-rule=duplication.clone`, or `enabled: false` under `duplication.clone` in the configuration — skips the detection phase entirely and frees the memory.
