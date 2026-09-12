# User documents that lose a nested block — empirical runs

Method: fixtures under this scratch dir (`fixture/src/Sample.php` — one class with
one high-CCN method; `fixture2/src/{A,B,C}.php` — A depends on B and C, giving
nonzero CBO/instability). Ran `bin/qmx check <dir> --config=<doc>.yaml
--format=json --workers=0 --cache-dir=<unique dir>`, deleted the cache dir before
each run, read `violations` (not `findings`) from the JSON. Raw outputs kept in
this same directory (`out-doc*.json`, `err-doc*.txt`).

## doc1 — `complexity.ccn: {threshold: 3, class: {max_warning: 1, max_error: 2}}`
Callable-level `complexity.ccn` fired once ("Cyclomatic complexity is 8, exceeds
threshold of 3"). **Zero** class-level `complexity.ccn` findings, even though
`max_warning: 1` / `max_error: 2` is far below the class's actual average CCN —
the `class:` block was not read. Confirmed by contrast with doc-class-only runs
below.

## doc2 — `complexity.ccn: {enabled: false, class: {max_warning: 1, max_error: 2}}`
Zero `complexity.ccn` findings at all (rule fully off). The `class:` block is
never read, its values silently gone.

## doc5 — `coupling.cbo: {class: {warning: 1, error: 1}}` (no top-level shorthand)
Fixture2 (A→B, A→C). 3 findings fired:
```
coupling.cbo error Efferent coupling too high: depends on 2 classes (CBO: 2, threshold: 1)
coupling.cbo error Afferent coupling too high: 1 classes depend on this (CBO: 1, threshold: 1)
coupling.cbo error Afferent coupling too high: 1 classes depend on this (CBO: 1, threshold: 1)
```
This is the control: the `class:` block, alone, is read and enforced.

## doc6 — `coupling.cbo: {threshold: 50, class: {warning: 1, error: 1}}`
Same fixture, same `class:` block as doc5, plus a top-level `threshold: 50`.
**Zero** `coupling.cbo` findings. The nested `class: {warning: 1, error: 1}`
that alone produced 3 error-severity findings in doc5 produces none once the
top-level `threshold` shorthand is present beside it — the actual CBO values
(1-2) don't exceed the uniformly-applied 50, and the user's `warning: 1,
error: 1` is never consulted. This is the single most legible before/after
pair collected: identical `class:` block, only a sibling top-level key added,
3 error findings → 0 findings.

## doc7 — `coupling.instability: {class: {max_warning: 0.01, max_error: 0.01, min_afferent: 0}}`
Fixture2, class A (Ce=2, Ca=0, instability=1.0). 1 finding fired:
```
coupling.instability error Instability is 1.00 (Ca=0, Ce=2), exceeds threshold of 0.01
```

## doc8 — `coupling.instability: {threshold: 0.99, class: {max_warning: 0.01, max_error: 0.01, min_afferent: 0}}`
Same fixture, same `class:` block as doc7, plus top-level `threshold: 0.99`.
**Zero** `coupling.instability` findings — `min_afferent: 0` (needed for class A
to even be judged, since the default `min_afferent: 1` would otherwise exclude
it) is silently dropped along with `max_warning`/`max_error`, and the uniformly-
applied 0.99 ceiling isn't reached by instability=1.00... wait: 1.00 > 0.99, so
by rights this SHOULD still fire under the uniform-threshold semantics. It
didn't. This is worth flagging as a discrepancy to verify further — either
`min_afferent` reverting to its class default of 1 (excluding class A, which has
Ca=0) is what actually silenced it, which is consistent with "the whole nested
block, including keys the shorthand doesn't cover, is discarded," or there is a
second effect. I did not chase this further (out of scope: "don't fix,
enumerate"), but it is *additional* confirmation that non-threshold keys inside
the nested block (`min_afferent`) are lost exactly like `max_warning`/`max_error`
are.

## Not independently run: CognitiveComplexityOptions, NpathComplexityOptions
Read-only verified (see `branches.tsv`): byte-identical branch structure and
condition to `ComplexityOptions`, down to the docblock wording. High confidence
by code reading; not separately exercised through `bin/qmx check` — flagging
this so the gap in verification method is visible rather than implied.

## Method note — what running proves and what it doesn't
Running `bin/qmx check` on a real fixture proves the *externally observable*
finding output for one representative document per group (Complexity family via
`complexity.ccn`; Cbo/Instability family via both members). It does not exercise
every one of the 81 measurement-rig cells (those vary the specific magnitudes,
same-name vs cross-level pairs, and the classifier's expected-vs-actual verdict
machinery) — it only demonstrates, with a real binary and a real fixture, that
the mechanism the code-reading and the mechanism table describe is real and
externally visible, not a reading artifact.
