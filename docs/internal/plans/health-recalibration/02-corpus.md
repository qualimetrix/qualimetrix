# P1 — the corpus

Two changes that both move the numbers, in two commits with a measurement
between them.

## P1a — versions

All fourteen packages have newer releases, four crossing a major
(`measurement/00-corpus-outdated.txt`). Review (x-10) is right that "a newer
release exists" is not "users run it", so the rule is stated rather than
reflexive:

**Take the latest release of each package that is itself a supported stable
line.** Where a major has just landed and its predecessor is still the line most
projects are on, the corpus keeps the predecessor and the choice is written down
per package in the stage report. The corpus exists to represent the code the
product is pointed at, not the code that was published most recently.

The Symfony components are currently split across 7.4 and 8.0 — that split was
nobody's decision and is resolved to one line whichever way the rule falls.

Bump by editing `benchmarks/composer.json` and running `composer update` inside
`benchmarks/`. The root `composer.lock` must not move; check it afterwards.

Re-measure, re-baseline, commit. The resulting diff is what the corpus drift
cost, isolated from anything the model change does.

A bumped package may stop parsing cleanly, or may move a path the collector
points at (`src/`, `lib/`). Both are findings recorded in the stage report, and
the path follows in the same commit.

## P1b — anchors

| id          | package                     | path           | why                                           |
| ----------- | --------------------------- | -------------- | --------------------------------------------- |
| codeigniter | `codeigniter/framework`     | `system/`      | procedural PHP5-era; the monotonicity witness |
| wordpress   | `johnpbloch/wordpress-core` | `wp-includes/` | large procedural legacy; the low anchor       |

Both measured `coverage.complete = true` under PHP 8.4
(`measurement/00-anchor-probe.md`). `--ignore-platform-reqs` is acceptable: the
code is read, never executed.

**Installation detail that will otherwise fail the stage.**
`johnpbloch/wordpress-core` pulls `johnpbloch/wordpress-core-installer`, a
composer plugin. A non-interactive `composer install` refuses to run it without
an explicit `allow-plugins` entry, and if the plugin *does* run it relocates the
package out of `vendor/johnpbloch/wordpress-core`, breaking the path above. The
probe used `"allow-plugins": false`; `benchmarks/composer.json` has no such key
today and needs one.

phpMyAdmin was probed; its sources are not under `src/` and the probe refused the
path. Not in this stage.

**Named hazard.** The anchors are added *because* the current model scores them
wrongly. Their first baseline records those wrong numbers deliberately — it is
the "before" of C6, not an endorsement. Expect both entries to move
substantially in P3 and P4.

## Definition of Done

- `benchmarks/composer.json` names sixteen packages; a clean `composer install`
  in `benchmarks/` succeeds non-interactively.
- `composer benchmark:check` covers seventeen projects, every one reporting
  `coverage.complete = true`, exit 0 against freshly written baselines.
- Both anchors resolve to the paths named above after installation.
- The root `composer.lock` unchanged.
- `measurement/01-after-corpus-scores.txt` holds the post-bump, pre-model table
  at project, namespace and class level. This is the isolation of the corpus
  variable and the before-picture for P3 and P4.
- Two commits: versions, then anchors.

## Files

`benchmarks/composer.json`, `benchmarks/composer.lock`, `benchmarks/README.md`,
`scripts/collect-benchmark-data.php` (project list only),
`docs/internal/benchmark-baselines.json`, `measurement/01-*`.

Shares `collect-benchmark-data.php` with P0 — sequential.
