# Coverage varies little, so damping by coverage has almost nothing to act on

Measured across the seventeen-project capture, 2026-09-14. `.count` against the
project's class count, per dimension.

| project                 | classes | `tcc.count` | TCC coverage | `lcom.count` | `cbo.count` |
| ----------------------- | ------- | ----------- | ------------ | ------------ | ----------- |
| doctrine-dbal           | 380     | 104         | 27%          | 380          | 434         |
| guzzle                  | 62      | 18          | 29%          | 62           | 70          |
| doctrine-orm            | 410     | 130         | 32%          | 410          | 471         |
| symfony-di              | 172     | 57          | 33%          | 172          | 217         |
| symfony-http-kernel     | 154     | 51          | 33%          | 154          | 177         |
| qmx                     | 797     | 274         | 34%          | 797          | 950         |
| laravel-framework       | 1233    | 426         | 35%          | 1232         | 1600        |
| phpunit                 | 993     | 359         | 36%          | 993          | 1143        |
| symfony-http-foundation | 103     | 37          | 36%          | 103          | 116         |
| symfony-routing         | 53      | 21          | 40%          | 53           | 72          |
| monolog                 | 113     | 47          | 42%          | 113          | 125         |
| flysystem               | 41      | 18          | 44%          | 41           | 55          |
| composer                | 276     | 128         | 46%          | 276          | 308         |
| symfony-console         | 149     | 68          | 46%          | 149          | 173         |
| wordpress               | 589     | 270         | 46%          | 588          | 601         |
| php-parser              | 263     | 143         | 54%          | 262          | 270         |
| codeigniter             | 139     | 80          | 58%          | 137          | 140         |

## What it says

TCC is undefined for a class with fewer than two methods
(`TccLccCollector.php:82`), and in every codebase in the corpus most classes are
small. So cohesion coverage runs 27% to 58% — and **the two legacy anchors sit
at the top of that range**, not the bottom. Low coverage is a property of modern
library design, not of legacy code.

There is therefore no threshold available. Anything above 58% damps all
seventeen projects, which is the same as damping none; anything below 27% damps
nobody. A number chosen in between would separate doctrine-dbal from
CodeIgniter in the wrong direction and mean nothing.

LCOM is computed on essentially every class (137 of 139, 276 of 276), and CBO on
more symbols than there are classes. Neither has a coverage problem to solve.

## The consequence for C3

The plan's round-2 decision was to publish coverage **and** damp a score whose
coverage falls below a declared fraction. The first half stands: a reader is
better off knowing that a cohesion score describes a third of the classes.

The second half is withdrawn for every dimension measured here. The one
dimension whose coverage genuinely collapses to zero is the structural one, and
it collapses because the global namespace is dropped in aggregation — which M1
repairs at the source. Damping would have been a second, weaker treatment of a
defect already being fixed properly, and it would have mislabelled well-designed
libraries as poorly measured.

Damping stays available for a dimension where coverage is shown to collapse.
None is, after M1. "A fraction chosen in P3" is answered: none, and here is why.
