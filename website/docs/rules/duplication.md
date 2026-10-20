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

| Value                    | Severity | Meaning                                                      |
| ------------------------ | -------- | ------------------------------------------------------------ |
| < 50 covered code lines  | Warning  | Noticeable duplication; consider extracting shared logic     |
| >= 50 covered code lines | Error    | Significant duplication; refactoring is strongly recommended |

Positive minimum options:

| Option       | Default | Meaning                                                        |
| ------------ | ------- | -------------------------------------------------------------- |
| `min_lines`  | 5       | Minimum covered code lines needed to admit a matching block    |
| `min_tokens` | 70      | Minimum normalized-token count needed to admit a block         |
| `error`      | 50      | Per-copy Error boundary; below it, an admitted copy is Warning |

The detector admits a matching block using its greatest covered-code-line
count and token count. It then reports each admitted copy with that copy's own
covered `codeLines`; comments, blank lines, and line breaks do not add covered
code. Severity is Warning below `error` and Error at or above it. `warning`,
`threshold`, and local `@qmx-threshold` overrides are not supported.
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
orchestration invokes it through Run's FileSet inspection participant contract.
The rule declares only the File channel and has no public Duplication contract.

!!! info "Deviation from original spec"
    The complete normalized token sequence and token count identify a block.
    Each copy's finding subject and `symbolPath` identify its project-relative
    file; the occurrence uses the block digest and that copy's order in the file.
    No line number enters the identity. A moved/renamed file, a changed block,
    or a changed copy order can change identity, so GitLab Code Quality and SARIF
    fingerprints and baseline entries may need a one-time rebase. The baseline
    schema does not change for this identity migration. Lines outside the
    matched tokens do not by themselves
    change identity when the detector still finds the same block.

    A candidate is divided into connected balanced token segments. Reportable
    segments are retained; if no segment meets the admission minima, the whole
    match is the fallback. Segments with the same complete normalized sequence
    merge into one block before the second coverage pass, with distinct
    file/offset positions and one per-file ordinal sequence. Review affected
    baseline keys and accept newly distinct copy identities after a complete run.
    Connected file-pair evidence is retained while
    containment witnesses that add no reportable evidence are removed; this is
    not a blanket removal of every contained copy. Some nested matching
    multiplicity is known behavior and is not claimed fixed here.

    PHP keyword identifiers are normalized case-insensitively; ordinary
    identifiers and the existing non-HTML hash vocabulary are unchanged.
    `T_INLINE_HTML` content collapses ASCII whitespace and uses an `xxh128`
    digest. Token source rows count CR, LF and CRLF correctly, with CRLF as one
    line break. A trailing line break in inline HTML does not cover an empty
    following row. Each preview hint comes from that copy's byte range, starts at
    its first substantive fragment, and is limited to 80 Unicode codepoints;
    invalid UTF-8 uses a byte-safe fallback.

!!! warning "Inline `@qmx-ignore` cannot suppress this channel"
    `duplication.clone` is a File-level channel and is not addressable by
    `@qmx-ignore`, `@qmx-ignore-file` or `@qmx-ignore-next-line`; explicit
    selectors are refused. A file subject has no namespace, so global or
    per-rule `suppress_namespaces` selectors do not suppress these findings.
    `suppress_paths` can suppress copies in the named files. Disable the rule
    with `disabled_rules: [duplication.clone]` or
    `--disable-rule=duplication.clone`, or accept findings into the baseline.

!!! info "Constant and property arrays are always excluded"
    A group is excluded only when **every copy** lies entirely inside a `const` declaration or a static/instance property's array-literal initializer. A group matching data with executable code remains reportable. Rows of a lookup table (e.g. `'key' => ['a' => ..., 'b' => ...]` repeated with different values) normalize to identical token sequences, but "extract a shared method" is not actionable advice for a data table — repeating the same field shape across rows is the normal, correct form of that table. A block spanning both a data declaration and executable code (or lying entirely in a method body) is still reported. This suppression is unconditional and cannot be turned off.

!!! info "Every admitted copy has its own finding"
    Every admitted copy is reported with its own subject, covered-code-line
    value and severity. The message names up to ten other copies and counts the
    remainder; related locations correspond to the copies it names. A block's
    matching group may change when the shared token sequence or its copies
    change, so an edit can re-key findings in untouched files. Some nested
    matching multiplicity is an acknowledged behavior. This description does
    not claim that every apparent containment duplicate is removed.

!!! info "Copies within one file"
    Overlapping token intervals in one file are one repetitive structure matching itself at a shifted position, so only the first is kept. Adjacent, non-overlapping token intervals are distinct copies and are reported, even when they share a physical line.

!!! tip "IDE integration"
    When using SARIF output (`--format=sarif`), duplicate copies are linked via `relatedLocations`. This means duplicate copies appear as **clickable cross-references** in VS Code (SARIF Viewer extension) and JetBrains IDEs, making it easy to navigate from each copy to the others it names.

<!-- llms:skip-end -->

### Content preview hints

Duplication findings include a content preview of the duplicated block. This helps you quickly identify which code is duplicated without navigating to the file:

```
Duplicated code block (16 code lines, 2 occurrences): "$total = 0.0; foreach ($order->getItems() as $item) { $price =..." — also at src/Service/InvoiceService.php:15-30
```

Each preview is extracted from that finding's own copy byte range. It joins up to three substantive excerpts from the first ten lines, skipping blank and brace-only lines, and collapses whitespace. The hint is limited to 80 Unicode codepoints, with a byte-safe fallback for invalid UTF-8.

### Configuration

```yaml
# qmx.yaml
rules:
  duplication.clone:
    enabled: true
    min_lines: 5
    min_tokens: 70
    error: 50
```

All three numeric options must be positive integers. `min_lines` and
`min_tokens` admit a block; `error` is the per-copy severity boundary.

```bash
# Increase the minimum covered code lines needed to admit a block
bin/qmx check src/ --rule-opt="duplication.clone:min_lines=10"

# Increase the minimum token count
bin/qmx check src/ --rule-opt="duplication.clone:min_tokens=100"

# Set the per-copy Error boundary
bin/qmx check src/ --rule-opt="duplication.clone:error=60"
```

`warning` and `threshold` are retired duplication options and are refused with
configuration exit 3. `@qmx-threshold duplication.clone` is refused with
`annotation.unsupported-threshold` because this rule does not support local
threshold overrides.

You can also disable the rule entirely:

```bash
bin/qmx check src/ --disable-rule=duplication.clone
```

!!! note "Memory and incomplete inspection"
    If the detector exhausts PHP memory, it emits a short stderr hint with the failure site and the current `memory_limit`, recommends `--memory-limit` or `memory_limit` in `qmx.yaml`, and exits with code 4. A fatal OOM can interrupt report delivery, so valid or complete JSON is not promised. A late false read also clears partial Duplication output and makes the run incomplete with exit 4; an empty result from that run does not mean there are no copies.

## Valid window and explicit disabling

`min_lines`, `min_tokens`, and `error` are positive integers. To skip detection, disable `duplication.clone` through configuration or selection. The detector consumes the prepared immutable options snapshot and does not fall back to raw configuration.
