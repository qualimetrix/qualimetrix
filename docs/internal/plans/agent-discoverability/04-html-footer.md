# Stage 04 — The HTML report footer

## What the first version of this stage got wrong

It opened with a package to teach the gate about the inlined JavaScript bundle,
on the premise that rebuilding the bundle makes `format:html` differ in every
corpus case and floods the declaration with minified blobs.

**That problem was already solved before this branch existed.** `Gate.php`
reduces `format:html` to the `report-data` payload through `ReportPayload::of()`,
landed in `77ea0b80`, and that class's own docblock records the same incident —
the same 31.8 KB of minified JavaScript — that this plan rediscovered and built a
package around. A normalization row for the bundle would match nothing and be
reported as stale.

So there is no gate-normalization package. What the gate *does* see from this
stage is the pair of new keys in `report-data`, because that payload is compared
exactly.

## Packages, in sequence

### Carry the addresses into the report's data

- Files: `src/Reporting/Formatter/Html/HtmlTreeBuilder.php`, manifest rows, the
  regenerated architecture artifacts.
- The footer is written by JavaScript, which cannot read a PHP constant. The
  addresses reach it the way the version already does — through the project
  metadata the builder assembles. Without this the JS would hardcode them, which
  the overview forbids.
- This is the package the gate will notice: two new keys inside `report-data`.

### The footer itself

- Files: `html-report/src/main.js`, the rebuilt `html-report/dist/report.min.js`.
- The footer reads `Generated {date} | Qualimetrix {version}` today and gains the
  two addresses as links, read from the metadata the previous package supplied.
- `HtmlFormatter` inlines the **built** bundle, so `composer build:js` belongs to
  this package. Editing `main.js` alone ships nothing and every PHP test still
  passes.

**The definition of done must be able to fail.** "Generate a report and grep it
for the addresses" cannot: the footer element in `report.html` is empty, the
addresses are in the inlined bundle and in `report-data` regardless of whether
the footer renders, so the grep succeeds even when the footer is broken. The
assertion is therefore on the **footer element's rendered text** — a JS test that
mounts the footer and reads its content. No such test exists today, so this stage
writes the first one, and the first assertion over that element at all.

### The generated inventories and the declaration

- Files: whatever `composer architecture:check` reports stale after the new test
  file, `finding-gate/declared-delta.tsv` and its diffs, `CHANGELOG.md`.
- Adding a test file changes the test inventories, which are byte-compared;
  regenerate rather than predict. `DistributedPackage/HtmlReportShipsOnlyWhatItReadsTest`
  carries a re-derived count of the files under `html-report/` (30, measured
  by `git ls-files html-report | wc -l` after this stage landed) and its
  docblock says to re-derive it, so a new test file there moves it. That
  coupling is expressed as a number, which is why a sweep by path and name
  cannot find it.
- The gate declaration covers whatever the gate reports, not a forecast.

## Test plan

The footer element's rendered text, which is a new guard over a surface that has
none. Plus `composer test:js`, and the gate and generated artifacts as above.
