# Is `enabled: false` (M1) the same question as the shorthand (M2/M3), or a separate one?

## Facts for "separate question"

1. **Semantically different intent.** `enabled: false` plausibly means "I do
   not want this rule at all, so the rest is moot" — the user may not even
   have written a sibling block on purpose; it could be leftover from a
   previous edit. A `threshold:` shorthand, by contrast, is an active
   competing *configuration*, not a statement of "ignore everything else."
2. **The website already treats them differently.** `getting-started/
   configuration.md:185` documents the shorthand-discards-block behaviour
   (M2/M3) explicitly, by name, with an example and the word "silently." No
   page documents `enabled: false` discarding a sibling block or scalar (see
   `docs-today.md`). If this were one question, it would be odd for one half
   to be a described, tested, deliberate feature and the other half to be
   unmentioned anywhere.
3. **Only one of the five classes has a test for `enabled: false` + a nested
   block specifically** (`ComplexityOptionsTest::
   itStaysDisabledWhenHierarchicalLevelKeysArePresent`, see `candidates.md`).
   The other four classes' test suites only exercise `enabled: false` alone
   (no sibling) — i.e. the test authors did not treat "does enabled:false also
   eat a sibling block" as an obviously-covered case worth repeating five
   times, the way they *did* treat the shorthand-discard case (present, by
   name, in all 5).

## Facts for "same question" (one underlying defect)

1. **Identical code shape.** In all 5 classes, the `enabled: false` branch and
   the shorthand branch are both `return new self(...)` constructed **before**
   any nested `class:`/`callable:`/`namespace:` key is read — literally the
   same `fromArray()` method, the same early-return structure, one branch
   above the other (see `branches.tsv`, lines 35-39 vs. 44-56/57-79/53-70).
   Whatever mechanism causes M2/M3 (early construction preceding nested-key
   reads) causes M1 identically; a fix that reorders "build defaults" vs.
   "read nested keys" fixes both at once as a side effect, and a fix that
   only patches the shorthand branch's condition leaves M1 completely
   untouched. There is no code-level joint between them to cut cleanly.
2. **Same "layer cannot be recovered" property the promise-effect ledger is
   built around.** `RuleOptionsFactory::create()` calls each Options class's
   `fromArray()` exactly once, on the config file + CLI layers already
   deep-merged (`src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php`
   step 2-4). By the time `fromArray()` runs, "user wrote `enabled: false` in
   layer A and `class: {...}` in layer B" and "user wrote both in the same
   file" are indistinguishable — exactly the property the CboOptions test
   docblock calls out for the shorthand branch ("regardless of which
   configuration layer contributed which key, information fromArray() cannot
   recover"). M1 has that same blindness: a low-priority layer's `class:`
   block reaching a merged document where a higher-priority layer wrote
   `enabled: false` is discarded the same way, by the same mechanism.
3. **A flat (non-hierarchical) options class does NOT do this**, which is
   itself evidence about what makes M1/M2/M3 one family: `WmcOptions::
   fromArray()` (and every other flat class checked) reads `enabled` and
   `warning`/`error` from the *same* level in the *same* pass — `enabled:
   false` there does not discard a written `warning`/`error` value, it stores
   it (inert, but present — see the class's `enabled: false, warning: 10`
   round-trip). The defect (in both M1 and M2/M3 alike) only exists where a
   class has to choose, at construction time, which of several *nested*
   sub-objects to build before reading further keys — i.e. it is a property
   of "hierarchical options class construction order," not specifically of
   what `enabled: false` means. That property is shared by M1 and M2/M3
   equally and is absent from every flat class.
4. **The mechanism table's own fix-locality column treats M1 and M2 as
   siblings needing the same kind of repair**: M1's fix-locality note says
   "the 5 classes' `fromArray()` needs to read nested/sibling keys before or
   independent of the `enabled` branch (or the 5 need a shared base class)" —
   textually close to M2's "read the nested block regardless of the
   shorthand." Both point at the same construction-order defect.

## Reading

The two facts don't contradict: M1 and M2/M3 share one mechanism (construction
order in `fromArray()`), but only M2/M3 currently has a *documented,
tested-as-a-feature* semantics ("shorthand replaces, does not merge") to decide
among candidates (a)/(b)/(c) for. M1 has no such existing intentional
semantics on record — nobody wrote a docblock or website paragraph arguing
"`enabled: false` discarding a sibling `class:` block is deliberate" the way
they did for the shorthand. So the *code fix* is naturally joint (same
construction reorder fixes both), but the *semantics decision* for M1 may not
need the same three-way (a)/(b)/(c) deliberation the shorthand does — under
almost any reasonable reading, "the rule is off" should still leave configured-
but-inert values readable rather than replaced by class-adjacent defaults,
which is closer to option (b)'s spirit (nested block's values honored/stored)
than a contested design question. This is a reading, not a finding — the
decision itself is explicitly out of scope for this recon.
