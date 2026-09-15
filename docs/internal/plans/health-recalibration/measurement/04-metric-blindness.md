# What the coupling metric cannot see, and what that bounds

Found by the independent ranking author while reading the anchors' sources, not
by any measurement of ours. Counted with grep over the analysed trees.

| project     | `coupling.cbo.avg` | what the metric does not count                                           |
| ----------- | ------------------ | ------------------------------------------------------------------------ |
| codeigniter | 1.69               | 76 `get_instance()` calls — a service locator                            |
| wordpress   | 4.02               | 797 `global $` declarations, 210 of them `$wpdb`; 1716 `apply_filters()` |

## The point

CBO, Ce and Ce-packages count references to **types**. Procedural code couples
through a global registry, a service locator, and string-keyed hook dispatch —
mechanisms that leave no type reference behind. So a low CBO on CodeIgniter is
not a measurement error: the metric faithfully reports that there are few
type-to-type edges, and it is silent about the dependencies that actually hold
the system together.

This is a limitation of the metric, not a defect in it. `PRODUCT_VISION.md`
principle 7 requires base metrics to implement their published algorithm, and
CBO's published algorithm is about class references.

## What it bounds

Three things follow for this work.

1. **The monotonicity fix does not make the anchors' coupling scores right.**
   Restoring the global namespace to the aggregate repairs a whole that
   outscored its parts. It does not teach an object-coupling metric to see
   `global $wpdb`. Procedural coupling will still read as lighter than it is.
2. **Calibration must not be used to compensate.** Lowering a threshold until
   CodeIgniter looks appropriately coupled would distort every project that
   couples through types, to correct a project the metric cannot see. The
   honest move is to say what the number covers, which is C3's subject.
3. **The ADR has to state the limitation.** A reader who sees a legacy PHP
   application score well on coupling deserves to know that the dimension
   measures type coupling and that this codebase does not couple that way.

## What it does not license

It is not an argument for a new "procedural coupling" metric in this work, and
it is not an argument that health.coupling is meaningless. It is the reason the
recalibration's promise is bounded: the scale can be made to say something true
about how much of the subject it measured, and cannot be made to measure what
the metric was never about.
