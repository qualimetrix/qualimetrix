# T06: Duplication Content Hints

**Proposal:** #8 | **Priority:** Batch 3 (output enrichment) | **Effort:** ~3h | **Dependencies:** none

## Motivation

Current duplication message: "Duplicated code block (33 lines, 2 occurrences) — also at src/Foo.php:10-42".
Developer must open the file to understand whether it's genuine algorithmic duplication or coincidental
structural similarity (array constants, boilerplate). A short content hint saves one round-trip.

## Design

Add a ~80 character content hint extracted from the first lines of the duplicated block.

### Content hint extraction

During duplication detection (Pass 2, when tokens are re-tokenized and source is in memory):
1. Extract first 2-3 non-empty, non-brace-only lines from the token stream (skip `{`, `}`, blank lines)
2. Normalize whitespace (collapse multiple spaces, trim)
3. Truncate to ~80 chars with `...` suffix
4. Store as `$hint` field (nullable string) in `DuplicateBlock`

**Source availability:** In Pass 2, `DuplicationDetector` re-reads files and has token streams.
The hint can be extracted from tokens without additional I/O. The `$hint` field in
`DuplicateBlock` (Core) is a plain string data carrier — generation logic stays in Analysis.

### Message format (before → after)

**Before:**
```
Duplicated code block (33 lines, 2 occurrences) — also at src/Foo.php:10-42
```

**After:**
```
Duplicated code block (33 lines, 2 occurrences): "return [ 'complexity' => ['warning' => 15, ..." — also at src/Foo.php:10-42
```

## Files to modify

| File                                               | Change                                |
| -------------------------------------------------- | ------------------------------------- |
| `src/Core/Duplication/DuplicateBlock.php`          | Add `$hint` field (nullable string)   |
| `src/Analysis/Duplication/DuplicationDetector.php` | Extract hint during block creation    |
| `src/Rules/Duplication/CodeDuplicationRule.php`    | Include hint in violation message     |
| Tests for duplication                              | Add test cases with hint verification |

## Acceptance criteria

- [ ] Duplication violations include content hint in message
- [ ] Hint is max ~80 characters, truncated with `...` if longer
- [ ] Hint is extracted from first meaningful lines (skip blank lines)
- [ ] Hint is properly escaped (no unbalanced quotes breaking output)
- [ ] JSON output includes hint in violation message
- [ ] PHPStan passes, tests pass

## Edge cases

- Duplicated block starts with blank lines → skip to first non-blank
- Duplicated block is a single long line → truncate at 80 chars
- Content contains special characters (quotes, backslashes) → escape for readability
- Binary-like content (unlikely in PHP) → fallback to "no preview available"
- Very short block (5 lines, min threshold) → hint from first 2 lines is sufficient
- Hint for array constant blocks → shows `return ['key' => ...` making it clear it's declarative data
