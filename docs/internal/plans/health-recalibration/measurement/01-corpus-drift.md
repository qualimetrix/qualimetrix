# What the corpus bump cost, isolated from everything else

All fourteen packages moved to their latest stable release, four crossing a
major (Symfony 7.4/8.0 to 8.1, Laravel 12 to 13, PHPUnit 12 to 13, Guzzle 7 to
8). No formula, aggregation or threshold was touched. The before table is
`00-before-project-scores.txt`, the after table `01-after-corpus-scores.txt`.

## The mover

| project | dimension       | before | after | delta |
| ------- | --------------- | ------ | ----- | ----- |
| guzzle  | coupling        | 93.3   | 63.3  | -30.0 |
| guzzle  | maintainability | 86.1   | 72.3  | -13.8 |
| guzzle  | complexity      | 81.8   | 74.6  | -7.2  |
| guzzle  | cohesion        | 74.9   | 81.2  | +6.3  |
| guzzle  | overall         | 83.3   | 75.0  | -8.3  |

Guzzle 8 is a substantially different codebase from Guzzle 7, and the guard said
so: it was the only project to breach its ranges, on exactly two dimensions,
which is the guard working rather than failing. Everything else moved by less
than three points, most by less than one.

Symfony's components moved little despite crossing a major and despite four of
the five jumping two minor lines (7.4 to 8.1): console -1.3 overall, di -0.6,
http-foundation -0.1, http-kernel +0.9, routing +0.3. PHPUnit 12 to 13 cost 1.4
overall. Laravel 12 to 13 gained 0.2.

## The re-baseline proved the P0 fix

`composer benchmark:update` wrote, with two expectations unmet. Before the P0
change it would have refused: writing required an empty failure list, and a
corpus bump produces disagreements by definition. The situation the command
exists for is exactly the situation it used to decline.

## What the new column shows

`health.typing` entered the table for the first time and immediately separated
the corpus: fourteen projects between 77.5 and 100.0, and **Laravel at 21.0**.
That is a twenty-point gap to the next lowest and a fifty-six point gap to the
median. The dimension that discriminates most sharply across real projects was
the one no baseline recorded and no summary printed.

Whether 21.0 is the right number for Laravel is a question for the calibration
stage. That it was invisible until now is a fact about the guard.

## A silent artefact, caught

The guard also writes a namespace/class distribution snapshot. The repository's
`.gitignore` carries a blanket `*.json` with explicit exceptions, and the new
file was not among them: it was written, reported as written, and ignored. The
same file already carries a comment about 858 files lost this way in an earlier
round. An exception was added.
