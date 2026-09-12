# Stage 4 — three keys declare their closed set, and the set says it folds case

## What is wrong, and what is not

Three option keys own a statically known set of words and are declared
`RuleOptionShape::text()`:

| key                                                  | reader                       |
| ---------------------------------------------------- | ---------------------------- |
| `annotation.directive` → `unused-directive-severity` | `InlineDirectiveOptions:89`  |
| `architecture.unassigned-class` → `mode`             | `UnassignedClassOptions:183` |
| `architecture.layer-violation` → `severity`          | `LayerViolationOptions:208`  |

Nothing is wrong with the readers. Each folds case, each refuses an unknown word,
and each names the accepted set in the refusal. What is wrong is the DECLARATION:
`text()` says "the reading code accepts any string" while the reader accepts
three words. That is the promise-wider-than-effect class this whole programme
measures, and it is 8 rows of the round's remainder table.

## Why the existing `oneOf()` cannot be used as it stands

`RuleOptionWordSet::contains()` is case-SENSITIVE, deliberately: X19 made it so
after a case-insensitive set accepted `scope: APPLICATION` for a reader that
compared strictly, and the value then fell into `all` without a word. That reason
is sound and is not being undone.

But these three readers DO fold case, on purpose, pinned by tests
(`itParsesSeverityCaseInsensitively`, and `'Warning'` in the inline-directive
test). A case-sensitive declaration in front of a case-folding reader is the
mirror defect: narrower than its reader, refusing a value the product accepts.

The tree has already written down the answer. `RuleOptionWordSet`'s class
docblock says: *"where a reader does fold case, the set it declares has to say so
rather than be assumed."* The same docblock opens by claiming matching ignores
case, which its own code contradicts — it is stale, and it is repaired here.

## The cure

The word set carries whether it folds, and the declaration site says which it is.
Two named factories, so neither can be chosen by inattention:

```
RuleOptionShape::oneOf(string ...$words): self            // exact, unchanged
RuleOptionShape::oneOfIgnoringCase(string ...$words): self // folds, new
RuleOptionWordSet::of(...) / ::foldingCase(...)
```

`coupling.cbo`'s `scope` keeps `oneOf()`. The three keys above take
`oneOfIgnoringCase()`, each declaring exactly the words its own enum offers.
`computed_metrics.<name>.levels` takes neither, for the reason already recorded:
it separates "a real level word that does not report" from "not a level at all",
and one set of words would flatten two different refusals into one.

## The guard, so the pair cannot drift apart again

A declaration wider than its reader is a silent rollback and a narrower one
refuses a legal value; both are invisible until someone writes the value. So a
test pairs every `oneOf`/`oneOfIgnoringCase` declaration in the tree with the
reader that consumes it and asserts they agree — for each declared word as
written, for each declared word with its case flipped, and for one word outside
the set. It is a two-witness check: the declaration is read from the key set, the
verdict is taken from the reader's own behaviour, not from a second hand-written
list.

## What changes for the user

The refusal moves from the reader (`fromArray`) to the option-key seam
(`refuseUnknownKeys`), so an unknown word is refused earlier and the message and
its position change. That is user-visible: `composer gate -- --reference=…` will
show it, the promise-ledger rows for the three keys move from the reader's framing
to the seam's, and the round's report reads the difference rather than assuming it.

A consequence to check, not assume: once the seam refuses first, each reader's own
refusal branch is reachable only through a direct `fromArray()` call — tests,
hierarchical wrappers, `scripts/promise-effect/InProcess.php`. That is the same
question as `ClassCboOptions::parseScope()`'s fallback, which its own comment
already calls unreachable. One enumeration of `fromArray()` callers answers all
four places; "dead" is a property of the set of call sites, not of the code, so it
is settled by enumeration and not by reading.

## Definition of Done

- The three keys declare `oneOfIgnoringCase()` with exactly their reader's words.
- `RuleOptionWordSet`'s stale class docblock no longer contradicts its code.
- The declaration↔reader guard exists and fails when a word is removed from a
  declaration and when a reader's fold is removed — both proved by planting each
  breakage once.
- The four reachability questions above are answered by one enumeration of
  `fromArray()` callers, written into the stage's report; anything the enumeration
  shows to be genuinely unreachable is deleted, anything reachable keeps its
  branch and the comment claiming otherwise is corrected.
- The 8 DECLARATION rows of the remainder table stop being defects on the live
  grid, and the ledger rows that move framing are updated in the same commit.

## Files

`src/Analysis/Finding/Contract/Rule/RuleOptionShape.php`,
`src/Analysis/Finding/Contract/Rule/RuleOptionWordSet.php`, the three options
classes above, `src/Analysis/Evidence/Coupling/ClassCboOptions.php` (only if the
enumeration proves the fallback dead), their tests, the new guard test, the
promise ledger, and the regenerated grid. Shares no `src/` file with stages 2-3.
