# P2 — the independent a-priori ranking

Written by an executor who has not seen any project-level score of the six
composite dimensions. What that executor was given, and one accidental
exposure, are recorded in the last section — read it before trusting anything
below.

This file states what the answer *should* look like, so that P4's coefficient
fit can be checked against an expectation formed without it.

## How to read this file

- Bands, not a ranking. `excellent / good / fair / poor / critical`. The claim
  is "these two are not in the same band", never "this one is two points above
  that one". Adjacent-band disagreement between this file and P4 is noise;
  two-band disagreement is a question P4 has to answer.
- Six tables of seventeen rows: complexity, cohesion, coupling, typing,
  maintainability, then overall. Every corpus project appears exactly once per
  table.
- No band cut-lines in score units appear here, deliberately. Naming numeric
  targets would anchor the calibration this file exists to audit.

### The external anchors used, stated before the bands

The bands are anchored on outside norms plus purpose and reading, with the
corpus spread used only as context. Banding by z-score across these seventeen
points was rejected: that is a miniature calibration on the same seventeen
points and would agree with P4 by construction.

- **Complexity** — average cyclomatic complexity per callable across a whole
  codebase. Common practice: below 2 is very simple, 2–3 ordinary, 3–4 busy,
  4–5.5 heavy, above 5.5 unusual for a library. Average cognitive complexity is
  read alongside; maximum CCN is read as a tail signal, not as the band.
- **Cohesion** — LCOM4 is the primary signal, because its scale is absolute:
  1.0 means the average class is one connected component, 2.0 means the average
  class splits cleanly in two. TCC average is secondary and is *not* comparable
  across projects in this corpus: it is dominated by how many tiny value
  objects, interfaces and exceptions a project has, not by how cohesive its
  real classes are. Per the Cohesion README, constructors are excluded from the
  graph and stateless constant methods are merged, so getter-heavy and
  metadata-heavy designs are already protected.
- **Coupling** — average CBO and average efferent coupling. The decisive caveat
  is that **both are blind to coupling that does not go through a type name**:
  service locators, superglobals and string-keyed hook registries are invisible
  to them. Two corpus members are built on exactly that, and their measured
  coupling is rejected below in favour of reading.
- **Typing** — the declared-type ratio. Docblock types are not language types;
  a codebase that types only in docblocks is honestly reported as untyped by a
  parser-based tool, and that is the correct reading for a score that claims to
  describe what the language enforces. Maximum DIT is read as a secondary
  signal.
- **Maintainability** — the classic 171-based Maintainability Index, clamped to
  0–100, whose own published reading is 85+ excellent, 65–84 good, 20–64
  moderate. **Every project in this corpus averages inside "good" or better.**
  So the average alone cannot separate them, and the fifth-percentile tail is
  used to subdivide: a project whose worst-5% methods are still comfortable is
  banded above one with the same average and a long bad tail.
- **Overall** — stated as a rule and applied mechanically, not averaged by
  feel: *the median of the five dimensions; one band lower when the worst
  dimension is more than two bands below that median.* No project in this
  corpus triggers the demotion clause, so overall is the plain median
  throughout. This is a deliberate choice that a single catastrophic dimension
  should not by itself define a codebase, and it is exactly the kind of choice
  P4 may legitimately disagree with — if P4's aggregation is minimum-like
  rather than median-like, expect systematic disagreement here and treat it as
  a finding about aggregation, not about the projects.

---

## Complexity

| project                 | band      | reason                                                                                                                                                                                                                                                                               |
| ----------------------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| flysystem               | excellent | Average CCN 1.38 and cognitive 0.42 — the lowest pair in the corpus; a thin adapter layer over per-backend drivers, with a maximum CCN of 8 meaning no single hard method exists anywhere.                                                                                           |
| php-parser              | excellent | Average CCN 1.64 despite two ~2,900-line generated parser tables; the 193 node classes in `Node/` carry two or three branch-free methods each, which is the honest shape of the subject.                                                                                             |
| phpunit                 | excellent | Average CCN 1.83 over 993 classes; the tail (max 292) is the assertion/constraint dispatch, not the norm.                                                                                                                                                                            |
| laravel-framework       | excellent | Average CCN 1.90 and cognitive 1.13 over 1,233 classes; the eloquent/fluent-builder style produces very many short delegating methods, which is genuinely low branch density even though `Query/Builder.php` runs to 5,000 lines.                                                    |
| doctrine-dbal           | excellent | Average CCN 1.97, cognitive 1.09 — a driver/platform abstraction where the per-platform SQL differences are expressed as separate classes rather than as branches.                                                                                                                   |
| monolog                 | good      | Average CCN 2.57, max 24 — handler-per-concern decomposition keeps every method small; nothing in the package is algorithmically hard.                                                                                                                                               |
| symfony-http-foundation | good      | Average CCN 2.58; HTTP parsing has irreducible branching (content negotiation, ranges, cookie parsing) and it sits where that predicts.                                                                                                                                              |
| qmx                     | good      | Average CCN 2.59 with a maximum of 30 — by far the lowest maximum in the corpus, which is a property of the codebase having been shaped against a complexity rule rather than of the subject being easy (see the qmx section).                                                       |
| doctrine-orm            | good      | Average CCN 2.73 over 410 classes; the hard parts are concentrated (`Query/Parser.php` 3,544 lines, `UnitOfWork.php` 3,510, max CCN 159) and the rest is mapping plumbing.                                                                                                           |
| symfony-console         | good      | Average CCN 2.91, cognitive 2.53 — argument/option parsing and terminal rendering are branch-heavy by nature, and the package stays just inside ordinary.                                                                                                                            |
| symfony-http-kernel     | fair      | Average CCN 3.31 and cognitive 2.95 in only 154 classes: the request lifecycle, controller resolution and fragment handling concentrate real branching in a small surface.                                                                                                           |
| symfony-routing         | fair      | Average CCN 3.75 with cognitive 4.04 *above* cyclomatic — a compiler-shaped package (route compilation, regex generation, dumped matchers) where nesting, not branch count, is the cost.                                                                                             |
| codeigniter             | fair      | Average CCN 3.95 and cognitive 3.86 in 139 classes; procedural-era methods such as the 1,999-line `DB_driver.php` and the 1,416-line `Loader.php` carry long conditional chains, but the average is held down by many trivial helpers.                                               |
| composer                | fair      | Average CCN 4.22, cognitive 5.22, max 192; this is an application, not a library — `Installer.php` (1,661 lines) and `ComposerRepository.php` (2,068) are dependency-resolution driver code and branch accordingly.                                                                  |
| guzzle                  | fair      | Average CCN 4.37 over only 62 classes, so a handful of files set the average: `Handler/CurlFactory.php` (3,152 lines) and `StreamHandler.php` (2,077) are option-matrix code translating request options into a C library's flags.                                                   |
| symfony-di              | poor      | Average CCN 4.91 and cognitive 5.78 — the worst pair among the maintained libraries; 59 compiler passes plus a 2,501-line `Dumper/PhpDumper.php` emitting PHP source. The subject is genuinely hard, but "hard subject" is a reason the score is low, not a reason it should not be. |
| wordpress               | critical  | Average CCN 5.47, cognitive 6.29, maximum 808 in a single callable, 3,309 global functions, average 430 lines per class — every complexity signal is the worst or near-worst in the corpus, and unlike symfony-di the tail is unbounded.                                             |

## Cohesion

| project                 | band      | reason                                                                                                                                                                                                                                                              |
| ----------------------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| symfony-http-kernel     | excellent | LCOM4 average 1.03 — the average class is a single connected component; small lifecycle collaborators with one job each.                                                                                                                                            |
| qmx                     | excellent | LCOM4 average 1.03 across 948 files averaging 105 lines; 433 files declare a final class and the capability layout forces one subject per class.                                                                                                                    |
| doctrine-orm            | excellent | LCOM4 average 1.07 despite several very large classes — the big ones (`UnitOfWork`, `ClassMetadata`) are internally interconnected around one state blob rather than being several classes in a trench coat.                                                        |
| guzzle                  | good      | LCOM4 average 0.61 — below 1.0 because a large share of the 62 classes are exception and value types with no instance-method graph at all; the number flatters the package and is read as "no cohesion problem visible", not as "best in corpus".                   |
| symfony-di              | good      | LCOM4 average 1.23; `Definition` and `ContainerBuilder` are big but each is organised around one piece of state.                                                                                                                                                    |
| symfony-routing         | good      | LCOM4 average 1.25; route, compiled route and matcher are separate types rather than one multi-purpose class.                                                                                                                                                       |
| flysystem               | good      | LCOM4 average 1.32 with the lowest class size in the corpus (47 lines); TCC 0.23 is low only because most of the 41 classes are interfaces and exceptions.                                                                                                          |
| symfony-http-foundation | good      | LCOM4 average 1.32 and TCC 0.43 — `Request` and `Response` are wide, but their methods genuinely share the same parsed state.                                                                                                                                       |
| monolog                 | good      | LCOM4 average 1.37; the handler/formatter/processor split is exactly the decomposition LCOM4 rewards.                                                                                                                                                               |
| symfony-console         | fair      | LCOM4 average 1.49 — the highest among the Symfony components here; `Application` and the table/progress renderers each carry two or three weakly-related method groups.                                                                                            |
| wordpress               | fair      | LCOM4 average 1.60 measured, but the measurement covers only the 589 classes and ignores the 3,309 global functions where most of the codebase lives; the band is what the classes deserve and is not a statement about the package. Also listed under "unsure".    |
| laravel-framework       | fair      | LCOM4 average 1.66 over 1,233 classes; the macroable/trait-composition style attaches method groups to a class that share no state with it, which is the textbook LCOM4 signal and here it is real rather than an artifact.                                         |
| php-parser              | fair      | LCOM4 average 1.77 and TCC 0.17, the corpus minimum — but 193 of 271 files are node data classes whose public methods (`getSubNodeNames`, `getType`) touch no properties in common; read as structural, so "fair" rather than the "poor" the raw TCC would suggest. |
| composer                | fair      | LCOM4 average 1.78; command classes bundle argument handling, output and orchestration (`ShowCommand.php` 1,572 lines, `ConfigCommand.php` 1,316) and split cleanly along those lines.                                                                              |
| phpunit                 | fair      | LCOM4 average 1.84 over 993 classes; the constraint and assertion hierarchies are mostly stateless method bags, which LCOM4 counts as disconnected by definition.                                                                                                   |
| doctrine-dbal           | fair      | LCOM4 average 1.88, the highest among the maintained libraries; platform and schema-manager classes are long lists of independent SQL-producing methods with almost no shared state — an inherent property of the design.                                           |
| codeigniter             | poor      | LCOM4 average 2.98 — the only project where the average class splits into three components, and the reading confirms it: `Loader.php`, `Input.php` and `DB_driver.php` are each several unrelated services sharing a file.                                          |

## Coupling

| project                 | band      | reason                                                                                                                                                                                                                                                                                                                           |
| ----------------------- | --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| flysystem               | excellent | Average CBO 4.33, efferent 2.40, max 21 — a genuinely small dependency footprint; the filesystem interface is the only wide type.                                                                                                                                                                                                |
| symfony-http-foundation | excellent | Average CBO 4.35, efferent 2.54, max 41 — deliberately dependency-free by charter, and the numbers agree.                                                                                                                                                                                                                        |
| symfony-routing         | good      | Average CBO 5.10, efferent 3.07, max 37; the matcher/compiler split keeps the fan-out contained.                                                                                                                                                                                                                                 |
| symfony-http-kernel     | good      | Average CBO 6.07 with the corpus's lowest maximum (32) — no god object at all, which is notable for a package whose job is to wire everything together.                                                                                                                                                                          |
| phpunit                 | good      | Average CBO 6.81, efferent 3.60 across 993 classes; the max of 163 is the test runner, which is one place, not a pattern.                                                                                                                                                                                                        |
| doctrine-dbal           | good      | Average CBO 7.11, efferent 3.85; driver implementations depend on a narrow interface set rather than on each other.                                                                                                                                                                                                              |
| doctrine-orm            | good      | Average CBO 7.64, efferent 4.55, max 115 — an ORM is inherently a hub-and-spoke design and this is the low end of what that costs.                                                                                                                                                                                               |
| monolog                 | good      | Average CBO 8.03 with efferent only 4.23, so roughly half of the coupling is incoming: many handlers depend on a few core types, which is the desired direction.                                                                                                                                                                 |
| symfony-console         | fair      | Average CBO 8.21, efferent 4.47 in only 149 classes — `Application`, helpers and the output stack reference each other densely for a package this small.                                                                                                                                                                         |
| guzzle                  | fair      | Average CBO 8.84 and efferent 5.80 over 62 classes; reading `Handler/CurlFactory.php` shows ~30 imports in a single class, and with a denominator this small that is the average, not an outlier.                                                                                                                                |
| symfony-di              | fair      | Average CBO 9.02, efferent 4.94, max 116; 59 compiler passes all reach into `ContainerBuilder` and `Definition`, which is a real bidirectional hub.                                                                                                                                                                              |
| qmx                     | fair      | Average CBO 9.54 and efferent 5.24 — the third-highest average in the corpus, and the capability-per-subject layout with explicit contract types is part of the reason: many small collaborators each naming their collaborators. Banding this honestly is the point of writing it here.                                         |
| php-parser              | fair      | Average CBO 9.93, the highest among the libraries, and max 174 — but the reading shows why: every visitor and pretty-printer must name every one of ~193 node types. Structural, so "fair" and not "poor".                                                                                                                       |
| laravel-framework       | fair      | Average CBO 6.87 looks good, but max 227 is the corpus maximum and sits on `Container`/`Application`; on top of that, facades and string-keyed container bindings route a large share of real dependencies through strings that a type-based metric cannot see, so the measured value understates.                               |
| composer                | poor      | Average CBO 12.85 and efferent 7.31, both the corpus maximum by a wide margin; `Installer.php` alone imports 66 types. This is application-shaped wiring rather than sloppiness, but a health dimension that calls it anything better is not measuring coupling.                                                                 |
| codeigniter             | poor      | Measured CBO 1.69 is the corpus *minimum* and is rejected: reading `system/` shows 76 uses of `get_instance()` plus `load_class()` singletons and a string-keyed `$this->load` service locator. Everything is coupled to everything through a locator that carries no type names, so the metric sees nothing. Band from reading. |
| wordpress               | critical  | Measured CBO 4.02 is likewise rejected: 797 `global $` statements, 210 of them `global $wpdb`, and 1,716 `apply_filters()` call sites in `wp-includes/`. Coupling here is global state and a string-keyed hook registry — the most tightly coupled codebase in the corpus, and the one where the coupling metric is most blind.  |

## Typing

| project                 | band      | reason                                                                                                                                                                                                                                                                                                                        |
| ----------------------- | --------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| qmx                     | excellent | 100.0% declared types; `readonly` appears in 531 of 948 files and `final class` in 433.                                                                                                                                                                                                                                       |
| symfony-routing         | excellent | 100.0% declared types.                                                                                                                                                                                                                                                                                                        |
| phpunit                 | excellent | 99.9% declared types across 993 classes and 251 functions — the largest fully-typed body in the corpus.                                                                                                                                                                                                                       |
| symfony-di              | excellent | 99.8% declared types, including the dumper's generated-code paths.                                                                                                                                                                                                                                                            |
| doctrine-dbal           | excellent | 99.7% declared types; every one of the 471 source files declares strict types.                                                                                                                                                                                                                                                |
| doctrine-orm            | excellent | 99.7% declared types; 471 of 471 files declare `strict_types=1`, verified by count.                                                                                                                                                                                                                                           |
| symfony-http-kernel     | excellent | 99.4% declared types.                                                                                                                                                                                                                                                                                                         |
| symfony-console         | excellent | 98.6% declared types.                                                                                                                                                                                                                                                                                                         |
| symfony-http-foundation | excellent | 98.1% declared types.                                                                                                                                                                                                                                                                                                         |
| monolog                 | excellent | 96.3% declared types, DIT max 2 — modern and flat.                                                                                                                                                                                                                                                                            |
| flysystem               | good      | 93.6% declared types; the residue is adapter callbacks handed to PHP stream functions.                                                                                                                                                                                                                                        |
| guzzle                  | good      | 92.5% declared types, and reading confirms `declare(strict_types=1)` at the top of the handler stack; the gap is the option arrays, which are untypable as written.                                                                                                                                                           |
| php-parser              | good      | 90.6% declared types with DIT max 4 — the node hierarchy is deep by design; the untyped remainder is the generated parser tables.                                                                                                                                                                                             |
| composer                | fair      | 77.5% declared types, though 307 of 309 source files declare `strict_types=1` — a codebase mid-migration that enforces strictness without having annotated every signature.                                                                                                                                                   |
| laravel-framework       | poor      | 21.0% declared types over 1,233 classes. Reading `Container.php` shows the pattern: properties and parameters are documented with `@var`/`@param` docblocks (105 of them in that file alone) and left untyped in the language, and only 2 of 1,696 source files declare strict types. Well-documented, but not type-enforced. |
| wordpress               | critical  | 9.9% declared types across 589 classes and 3,309 functions; pre-namespace procedural PHP with array-shaped everything.                                                                                                                                                                                                        |
| codeigniter             | critical  | 0.8% declared types — effectively none; the package predates scalar type declarations and was frozen before them.                                                                                                                                                                                                             |

## Maintainability

The published MI reading puts every project here in "good" or better, so the
average alone separates nothing. The bands below use the average together with
the fifth-percentile tail — how bad the worst twentieth of methods gets. The
line used, in raw MI units and not in score units: average above 85 reads as
excellent; average above 78 with a tail at or above roughly 50 reads as good;
either one failing reads as fair; both failing badly reads as poor. Three rows
sit within a point of that tail line and are marked as such.

| project                 | band      | reason                                                                                                                                                                                  |
| ----------------------- | --------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| flysystem               | excellent | MI average 87.3 and a fifth percentile of 69.8 — the only project whose *worst* methods are still in the published "good" range.                                                        |
| phpunit                 | excellent | MI average 85.9, just over the published excellent line, with a tail of 59.6 that stays moderate rather than poor.                                                                      |
| php-parser              | good      | MI average 84.6 with a strong tail (64.4); the generated parser files are long but are near-linear statement lists.                                                                     |
| laravel-framework       | good      | MI average 83.6 and the second-best tail in the corpus (62.4) — the fluent style produces very many short methods, which MI rewards directly.                                           |
| doctrine-dbal           | good      | MI average 83.3, tail 58.7; the largest classes are flat method lists rather than deep logic.                                                                                           |
| symfony-http-foundation | good      | MI average 81.5, tail 53.0.                                                                                                                                                             |
| symfony-console         | good      | MI average 80.6, tail 51.8; the tail is the table renderer and the input parser.                                                                                                        |
| qmx                     | good      | MI average 80.5, tail 55.7, largest file 670 lines — a consequence of the codebase being continuously refactored against its own size and complexity rules.                             |
| doctrine-orm            | good      | MI average 80.2, tail 49.6 — the tail is `Query/Parser.php` and `SqlWalker.php`, two files that concentrate nearly all of the hard code. Sits on the good/fair line.                    |
| symfony-http-kernel     | good      | MI average 80.2, tail 49.0 — the smallest average class in the corpus (75 lines) holds the average up while the lifecycle methods drag the tail. Sits on the good/fair line.            |
| monolog                 | good      | MI average 78.0 with the fourth-best tail (54.3); no method in the package is large.                                                                                                    |
| symfony-routing         | fair      | MI average 78.6 but a tail of 45.9 — route compilation and dumped-matcher generation produce a handful of very dense methods in a 53-class package.                                     |
| composer                | fair      | MI average 75.9, tail 45.3; command classes above 1,300 lines each put real weight in the tail.                                                                                         |
| guzzle                  | fair      | MI average 74.1, tail 48.0, and the second-largest average class in the corpus (290 lines) — `CurlFactory` and `StreamHandler` dominate a 62-class package. Sits on the fair/good line. |
| codeigniter             | fair      | MI average 73.5, tail 47.3; long procedural methods, but each statement is simple, which is exactly the case MI treats more kindly than a reader would.                                 |
| symfony-di              | fair      | MI average 76.7 with the worst tail of any maintained project (43.1) — `PhpDumper` has 69 methods emitting PHP source, and that is where the tail lives.                                |
| wordpress               | poor      | MI average 68.7 and tail 39.6, both the corpus worst; 9,228-line `functions.php`, 430-line average class, and the largest single callables in the corpus.                               |

Nothing lands in `critical`. The published MI floor (below 20) is not reachable
by code that ships and is maintained, even by the two legacy anchors. If P4
produces a critical maintainability score for anything in this corpus, that is a
scale problem, not a discovery.

## Overall

Median of the five dimensions, per the rule stated above. No project triggered
the demotion clause.

| project                 | band      | reason                                                                                                                                                                |
| ----------------------- | --------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| flysystem               | excellent | Median of excellent/good/excellent/good/excellent; a small, modern, single-purpose package with no weak dimension.                                                    |
| phpunit                 | excellent | Median of excellent/fair/good/excellent/excellent; the only cohesion softness is the stateless constraint hierarchy.                                                  |
| symfony-http-foundation | good      | Median of good/good/excellent/excellent/good — near the top of good, held there by ordinary complexity in HTTP parsing.                                               |
| symfony-http-kernel     | good      | Median of fair/excellent/good/excellent/good; concentrated complexity in a small, well-decomposed surface.                                                            |
| symfony-console         | good      | Median of good/fair/fair/excellent/good.                                                                                                                              |
| symfony-routing         | good      | Median of fair/good/good/excellent/fair; a compiler-shaped package with a thin bad tail.                                                                              |
| monolog                 | good      | Median of good/good/good/excellent/good — no dimension below good, none above excellent typing.                                                                       |
| doctrine-orm            | good      | Median of good/excellent/good/excellent/good; the hardest code is concentrated in two files rather than spread.                                                       |
| doctrine-dbal           | good      | Median of excellent/fair/good/excellent/good; the cohesion result is the platform-class design, not a defect.                                                         |
| php-parser              | good      | Median of excellent/fair/fair/good/good; both mid bands are consequences of having 193 node types, which reading confirms.                                            |
| qmx                     | good      | Median of good/excellent/fair/excellent/good; the coupling band is the honest cost of a contract-heavy modular layout.                                                |
| composer                | fair      | Median of fair/fair/poor/fair/fair — consistently mid across every dimension; an application with application-shaped coupling, not a library.                         |
| guzzle                  | fair      | Median of fair/good/fair/good/fair; a small package whose averages are set by two very large handler classes.                                                         |
| symfony-di              | fair      | Median of poor/good/fair/excellent/fair; the highest complexity of any maintained project in the corpus, on a genuinely hard subject.                                 |
| laravel-framework       | fair      | Median of excellent/fair/fair/poor/good; very low branch density and good MI, pulled down by language-level untypedness and string-keyed container coupling.          |
| codeigniter             | poor      | Median of fair/poor/poor/critical/fair; frozen pre-namespace framework whose coupling is a service locator and whose typing is absent.                                |
| wordpress               | critical  | Median of critical/fair/critical/critical/poor; the only project where three separate dimensions are at the floor, and the cohesion result is not trustworthy anyway. |

---

## Projects I am unsure about

These are placements I would not defend if challenged. They are listed rather
than quietly rounded to whatever makes P4 arithmetic work.

1. **wordpress cohesion (banded `fair`).** The class-cohesion metrics see 589
   classes and are blind to 3,309 global functions, so the band describes a
   minority of the codebase. A defensible instrument would either exclude it or
   report it as not applicable. I would not be surprised by anything from `good`
   to `critical` here, and I would not treat a disagreement at this cell as
   evidence about the formula.
2. **codeigniter and wordpress coupling.** I overrode the measured values from
   reading. The override is well-evidenced (76 locator calls; 797 globals; 1,716
   hook calls), but it is a claim about what coupling *means*, not a measurement.
   If the product deliberately scores only type-visible coupling, then P4 will
   place both of these high and will be internally consistent while being wrong
   about the world. That is a finding about the metric's scope, and it belongs in
   the write-up rather than being resolved by moving this band.
3. **composer coupling (banded `poor`).** Composer is a well-regarded codebase
   and its coupling is the highest in the corpus by a clear margin. I could not
   decide whether that is a defect or an expectation mismatch between "library"
   and "application", and I banded the measurement rather than the reputation.
   If the corpus is meant to represent libraries, composer may be the wrong
   member rather than a poor one.
4. **guzzle overall (banded `fair`).** Reputation says better; every raw metric
   says middling. With 62 classes the averages are set by two files, so this is
   partly a small-denominator effect that a percentile-based formula would treat
   differently from a mean-based one. I went with the metrics and flag the
   conflict.
5. **laravel-framework typing and overall.** 21% is the honest parser-visible
   number and I stand by the typing band. What I am unsure about is whether
   *overall* should be `fair`: the code is heavily docblock-typed and
   statically analysed in practice, so a user may reasonably feel a `fair`
   verdict misdescribes it. This is the clearest case in the corpus where the
   typing dimension's weight in the composite will be visible.
6. **symfony-di complexity (banded `poor`).** The subject (compiler passes, a
   source-emitting dumper) is genuinely hard, and I could not separate "hard
   problem" from "complex code" by reading. If the intent is that a health score
   describes the code rather than the problem, this band may be one too low.
7. **doctrine-orm, symfony-http-kernel and guzzle maintainability.** All three
   sit within a hair of my good/fair line on the tail percentile. Treat any of
   the three moving one band as agreement, not disagreement.
8. **qmx, everywhere.** See the next section — I do not think its bands should be
   used as calibration evidence at all, whatever they are.

---

## Should `qmx` stay in the calibration corpus?

**Recommendation: `qmx` becomes a watched project — measured and reported on
every run, but excluded from the data that moves coefficients and thresholds.**

The usual argument is circularity, and it is correct as far as it goes: a
threshold that flatters the product's own source is indistinguishable from a
correct one. But there is a sharper and checkable version of the argument that
does not depend on anyone's good faith.

`qmx`'s own build gate runs the product against `src/` and fails on warnings,
and the project's stated policy is that refactoring, not threshold tweaking, is
the default response to a signal. That policy has been followed: the evidence is
visible in the raw table and in the tree without opening a single score.
Maximum CCN across the entire codebase is 30 — the next lowest in the corpus is
44, and the other large projects run to 159, 192, 292, 334 and 808. The largest
source file is 670 lines in a 948-file tree. Declared types are at exactly
100.0%. The tree carries 74 live inline `@qmx-` directives, each one a place
where a specific reading was accepted or re-scoped by hand.

That is not a sample drawn from the population of PHP codebases. It is a **fixed
point of the instrument**: a codebase iterated until the instrument stops
complaining. Fitting the instrument's coefficients to it is fitting them to
their own previous output, and — unlike ordinary overfitting — more data would
not fix it, because every future commit is also filtered through the same gate.
The bias also has a known sign: it pushes thresholds toward whatever `qmx`
already does, which will read as strictness on the dimensions it is good at
(complexity tail, typing) and as leniency on the one it is not (coupling, where
its average is the third-highest in the corpus and is a direct consequence of
its chosen layout). A corpus that includes it will quietly ratify that layout
choice as a norm.

Keeping it as a watched project preserves everything of value and gives up
nothing:

- It remains the fastest regression alarm available, since it is the one
  codebase that is re-analysed constantly and whose history is fully known.
- A watched project that drifts *against* the calibrated bands is informative in
  a way a corpus member cannot be — a corpus member cannot disagree with a
  calibration it helped produce.
- The corpus loses one of sixteen remaining points, which is a smaller cost than
  the one unfalsifiable point it replaces.

The condition attached: "watched" has to be enforced mechanically, in whatever
script selects the calibration set, rather than stated as an intention. A note
in a document does not survive the next person who runs an update with
`--update-baselines`.

---

## What I was given

**Given, and used:**

- The project list and the raw non-composite metric table prepared for this
  stage (namespace count, declared-type ratio, class/function/method counts,
  average and maximum CCN, average cognitive complexity, average and
  fifth-percentile MI, average and maximum CBO, average efferent coupling,
  average TCC, average LCOM4, maximum DIT, average class LOC). By construction
  that table contains no composite score.
- `benchmarks/README.md` — the corpus membership and analysed paths.
- `docs/internal/plans/health-recalibration/03-apriori.md` — this stage's own
  page, which the stage explicitly permits.
- `src/Analysis/Evidence/Maintainability/README.md` and
  `src/Analysis/Evidence/Cohesion/README.md` — to learn which MI variant and
  scale are in use and how LCOM4/TCC treat constructors, stateless methods and
  anonymous classes. Without those two, the MI and cohesion bands would have
  been guesses about the instrument rather than readings of it.
- The benchmark sources themselves under `benchmarks/vendor/`, plus this
  repository's `src/`, read directly.
- General knowledge of these projects and their reputations.

**Read with my own eyes (source inspection beyond the metric table):** eight
projects — codeigniter, wordpress, composer, symfony-dependency-injection,
guzzle, laravel-framework, php-parser, qmx. Reading changed the band in four of
them (both coupling overrides, php-parser's cohesion, laravel's coupling).

**Banded from raw metrics, purpose and reputation only:** the remaining nine —
symfony-console, symfony-http-foundation, symfony-http-kernel, symfony-routing,
phpunit, doctrine-orm (file sizes and strict-types counts only), doctrine-dbal,
flysystem, monolog.

**Not shown to me, and not opened:**

- `docs/internal/benchmark-baselines.json`
- `docs/internal/benchmark-namespace-class-distribution.json`
- `docs/internal/benchmark-data.json`
- every other file under `measurement/`
- `src/Analysis/Evidence/ComputedMetrics/ComputedMetricDefaults.php`, and the
  ComputedMetrics README
- every other page of this plan, including the overview, the model and
  calibration stages, and the review file
- `qmx.yaml`, the website threshold reference pages, and the changelog
- no analysis was run: neither `bin/qmx`, nor `scripts/benchmark-regression.php`,
  nor `scripts/health-calibration.php`, nor any `git show`/`git diff` of the
  recent commits

**One accidental exposure, disclosed.** The deviation note at the end of
`src/Analysis/Evidence/Cohesion/README.md` quotes a before/after pair of
composite cohesion values for **php-parser**, measured under an earlier
constructor-handling rule. I saw that pair while reading the file for its LCOM4
treatment rules. To be exact about the order: **the exposure happened during
orientation, before any band in this file was set and before this file
existed** — not afterwards. I did not seek it, it concerns one project on one
dimension under a formula this recalibration is about to replace, and no
composite value for any other project or dimension was visible. The php-parser
cohesion band above was set from LCOM4 1.77, TCC 0.17 and the reading of 193
node classes in `Node/`. If a reviewer judges that the exposure contaminates
that one cell, discard it; the other 101 placements are unaffected.

I also listed the filenames in `measurement/` (via a directory listing) without
opening any of them. Filenames only; no contents.

**One naming mismatch, noted rather than resolved.** This stage's brief names
the six dimensions as complexity, cohesion, coupling, **typing**,
maintainability and overall. The repository guide lists the corresponding
capability family as **design**. I used `typing` as briefed and banded the
declared-type ratio. If the dimension is actually "design" and includes
inheritance depth and related signals, the typing table should be re-read as a
statement about type declarations only, and DIT (maximum 4 in php-parser, 3 in
laravel, phpunit, wordpress and symfony-di, 1 or 2 elsewhere) folded in
separately. Resolving this would have required opening the defaults file, which
is out of bounds for this stage.
