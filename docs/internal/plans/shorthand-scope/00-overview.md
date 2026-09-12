# A shorthand beside the block it is shorthand for — the question, and where its answer has to live

**Status:** the semantics are decided; the round is NOT planned. A first plan was
written, reviewed by two reviewers before any code existed, and withdrawn on
seven HIGH findings. This file records what survived that review, so the next
round starts from the question as it actually is rather than from the shape it
looks like.

**Base:** `main` at `409fdf39`.

## What was stuck, and what unstuck it

Axis B of the promise-effect stand carries 81 defects, 61 of them one
copy-pasted shape in five sibling options classes: a bare `threshold` at a
hierarchical rule's top level makes `fromArray()` return before it reads the
`callable:` / `class:` / `namespace:` block written beside it. X17 deferred it
because "no owner holds a mandate over pair semantics", and four rounds read that
governance sentence as a technical blocker. It is not one.

**The answer was already in the ledger, written by the round that measured it and
used by nobody.** The note on `pair complexity.ccn class: threshold same-source`:

> What it is NOT is `compose`: the carrier positively denies that both apply. It
> is not `refuse` either — the carrier insists the drop is silent. **The
> coexistence column has no value for «the shorthand replaces, silently»**.

Measured: `coexistence` carries exactly `compose` (380 rows), `refuse` (85) and
empty (108). The website states the discard precisely, with a workaround, down to
the word "silently" — so the product keeps a documented promise, and what
diverges is the MEASURING VOCABULARY. The row says `compose` because there was
nothing else to write, and the grid bills the difference to the product.

That splits one question into two, and conflating them is what kept it closed:

1. Can the ledger say "replaces"?
2. Should a written key ever be dropped in silence?

## What is decided

**Silence goes.** Not on taste — on the programme's own thesis (a recognised key
does what its name promises), on the website's own phrasing flagging the silence,
and on measurement: an identical `class:` block reports three errors alone and
nothing at all with a shorthand beside it (`measurement/user-documents.md`,
doc5/doc6). A written block that changes nothing is not configuration.

**Composition, not refusal.** Refusing punishes a document nobody wrote: a preset
writes the shorthand, a `qmx.yaml` writes the block, and the contradiction exists
only after the merge.

**`threshold` beside `warning`/`error` at the SAME level stays refused.** Two
spellings of one value in one slot is a contradiction; a shorthand and a block
addressing different slots is not.

## What the review destroyed, and why it matters more than what it confirmed

The withdrawn plan put the cure in `fromArray()` and derived its rule from
ADR 0058 by analogy. Both reviewers independently showed the analogy does not
transfer, and the measurements they brought are the reason this file exists.

**The cure cannot live in `fromArray()`, because by then the layers are gone.**
`RuleOptionsFactory::deepMerge()` unfolds and merges both layers into one array,
and the single `fromArray()` call cannot tell which key came from where. So a
rule phrased as "the more specific key wins" silently becomes "the more specific
key wins REGARDLESS OF LAYER" — and that reverses the priority the product
already promises. Measured: a preset writing
`complexity.npath: {class: {enabled: true, max_warning: 2, max_error: 3}}` under a
CLI writing `threshold=50` gives the CLI the whole rule today; the withdrawn plan
would have left the preset's block standing at 2/3 against a higher layer that
explicitly asked for 50.

**ADR 0058 is about a different axis.** It settled the integrity of ONE layer's
value across layers — key by key. Level-vs-key granularity is a second question,
and "the same sentence one level down" is a slogan, not a derivation.

**The M1/M2 split was wrong, and it was wrong the way this programme keeps
finding.** The withdrawn plan argued that `enabled: false` beside a block is the
author's instruction rather than the product's silence, because the outcome is
identical either way. That was generalised from ONE observation. Measured
properly: `class: {enabled: true, warning: 1, error: 1}` under a top-level
`enabled: false` moves three findings to none, and the block is NOT inert at the
recognition seam either — an unknown key inside it still exits 3. So the block is
read for validation and discarded for effect, which is a state nothing defends.

**The ledger arithmetic was wrong too.** 7 of the 18 shorthand-vs-block rows
promise `refuse`, so "axis B falls by 61" was unreachable as written.

## What the next round has to settle first

Not implementation — these, in this order:

1. **Specificity against layer priority, both orientations, named explicitly.**
   Higher-layer shorthand over lower-layer block, and lower-layer shorthand under
   higher-layer block. This is a breaking decision in its own right and cannot be
   inherited from ADR 0058.
2. **Therefore: the cure belongs at the merge seams**, where provenance still
   exists — the same place ADR 0058 put its own. What `fromArray()` then receives
   is a document whose meaning is already unambiguous.
3. **Granularity, once.** Does a block that names one half of a band take the
   shorthand's value for the other half, or the rule's default? The withdrawn
   plan answered both ways in two files.
4. **The shapes nothing classifies yet:** an empty `class: {}`, a `class: ~`, and
   a `class: {enabled: ~}` — `isset()` folds the second into absence today.
5. **`enabled: false` beside a block** as its own decision, now that it is known
   not to be inert.

## What is already measured, and should not be re-derived

`measurement/` — the branch enumeration by file and line; eight live `bin/qmx`
observations of documents a user could reasonably write, including the two pairs
that pin the loss; the three candidate semantics with what each breaks (6 tests,
3 site pages, 0 presets, 0 gate corpus cases); what the website says today; and
the `enabled: false` question with the facts on both sides.

The two review files that withdrew the plan are worth reading before writing the
next one: seven HIGH findings, four of them carrying measurements the plan did
not have.
