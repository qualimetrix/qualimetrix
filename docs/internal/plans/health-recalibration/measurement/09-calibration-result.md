# The calibration, what it reached and what it did not

Measured on the seventeen-project corpus, 2026-09-14, with the offline bench;
every number below was reproduced independently of the executor who produced the
change.

## Band agreement against the frozen ranking

| dimension       | before | after  |
| --------------- | ------ | ------ |
| complexity      | 7      | 14     |
| cohesion        | 9      | 15     |
| coupling        | 7      | 7      |
| typing          | 12     | 12     |
| maintainability | 2      | 14     |
| **overall**     | **6**  | **14** |

Overall, every project, measured then banded against
`measurement/02-apriori-ranking.md`:

| project                 | before | after | band      | a-priori  |      |
| ----------------------- | ------ | ----- | --------- | --------- | ---- |
| flysystem               | 91.6   | 90.6  | excellent | excellent | ok   |
| symfony-http-foundation | 86.2   | 79.9  | good      | good      | ok   |
| doctrine-dbal           | 84.5   | 78.6  | good      | good      | ok   |
| symfony-http-kernel     | 83.9   | 76.6  | good      | good      | ok   |
| qmx                     | 82.9   | 76.5  | good      | good      | ok   |
| phpunit                 | 80.2   | 75.6  | good      | excellent | miss |
| php-parser              | 79.0   | 75.4  | good      | good      | ok   |
| monolog                 | 80.9   | 73.8  | good      | good      | ok   |
| symfony-console         | 81.4   | 73.4  | good      | good      | ok   |
| doctrine-orm            | 79.1   | 72.9  | good      | good      | ok   |
| symfony-routing         | 78.3   | 68.6  | good      | good      | ok   |
| laravel-framework       | 72.4   | 68.1  | good      | fair      | miss |
| guzzle                  | 75.0   | 64.8  | fair      | fair      | ok   |
| symfony-di              | 68.5   | 56.2  | fair      | fair      | ok   |
| composer                | 63.0   | 50.1  | fair      | fair      | ok   |
| codeigniter             | 64.7   | 49.2  | poor      | poor      | ok   |
| wordpress               | 54.0   | 38.9  | poor      | critical  | miss |

**Read this with its fragility.** Four of the fourteen agreements hold by less
than a point: composer sits 0.10 above the poor edge, symfony-http-foundation
0.08 below excellent, guzzle 0.24, codeigniter 0.78. Fourteen is the honest
maximum, not a stable plateau; any future change to a base metric will move
several projects across a band edge.

## Three misses, each with a reason that can be checked

**WordPress cannot reach critical, arithmetically.** Hold coupling at its
measured 68.2 — compensating for the metric's blindness is forbidden
(`04-metric-blindness.md`) — and it contributes 13.64. Put cohesion and
maintainability at the *floors of their own a-priori bands* (50 and 25) and they
add 10.00 and 5.00. That is 28.64 before complexity or typing contribute
anything, against a ceiling of 25. The band is unreachable without breaking the
ranking on another dimension or zeroing coupling's weight.

An earlier estimate in this session — that raising typing's weight from 0.10 to
0.25 would bring WordPress to about 47 — was checked and holds only for the
*uncalibrated* dimensions. On the calibrated ones any sensible vector with
typing at 0.25 lands near 38.

**phpunit** is marked overall excellent by a ranking that also marks its cohesion
fair and its coupling good. At weights of 0.20 each, that combination requires
both to sit at the ceilings of their bands. The ranking is internally strained
here, not the scale.

**laravel** traces to a maintainability cluster: `mi.avg` 83.3, 83.6 and 84.6
(a-priori good) against 85.9 (a-priori excellent). Separating bands across 1.3
points of MI with a monotone function would need a slope of about fifteen score
points per MI point at that spot.

## The weights did not move, and that is a measurement

A grid search over every weight vector at 0.05 resolution summing to one, each
dimension between 0.05 and 0.35, reaches at most 14 of 17 — exactly what the
existing 0.30/0.20/0.20/0.10/0.20 already reaches. No vector wins. The plan
warned that a weight states what matters rather than what discriminates; here it
does not even discriminate.

## A calibration anchor that does not exist

The class coupling formula carries the comment "HalsteadVisitor (ce=127, pkg≈1):
~80. ShowCommand (ce=43, pkg≈15): ~26" as its calibration example. Measured
across all 6922 classes of the seventeen projects, `coupling.ce-packages` has a
**maximum of 7**, a 99th percentile of 2, and 99.99% of classes sit at or below
the formula's threshold of 5.

A class with `pkg≈15` exists nowhere in the corpus — the anchor is twice the
largest value ever observed. The term it justifies therefore almost never fires,
which is why 97.1% of classes score exactly 100 on coupling.

Waking it was tried and reverted: lowering the threshold to 4 and raising the
raw weight to 3.0 drops class-coupling maxima from 6723 to 2577, but upward
monotonicity violations go from 0 to 106, because the namespace formula is
untouched and the parent then sits above every child. The trade curve was
measured (raw weight 1.0/1.5/2.0/2.5/3.0 gives 6/32/50/83/106 violations). This
is a model change across two levels, not a threshold move, and it is left for a
decision of its own.

## A premise of this plan, broken on purpose

The overview's premise 3 said the median namespace of every corpus project
scores 78.6 or better. After calibration the minimum project median is 49.2
(codeigniter) and eleven of seventeen still sit at or above 78.6, down from
sixteen. That premise was a defect statement; breaking it was the goal.

The class level is a different story and the target stated for it was **not**
reached: 63.4% of classes still score exactly 100 on complexity, 65.1% on
typing, 97.1% on coupling. For complexity and typing that is close to
legitimate — the median class in this corpus has two methods and no branching,
so there is nothing to penalise. For coupling it is the model defect above.
