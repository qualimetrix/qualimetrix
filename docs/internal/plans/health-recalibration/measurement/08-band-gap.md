# The scale against an independent judgement

`measurement/02-apriori-ranking.md` was written by someone who had not seen a
score, and committed before any coefficient moved. This is the comparison it
exists for — the C5 baseline, taken after M1 and before any calibration.

Documented band edges (`website/docs/reference/health-scores.md`, W=50):
Excellent > 80, Good > 65, Fair 50-65, Poor 25-50, Critical <= 25.

| project                 | measured overall | measured band | a-priori band | gap    |
| ----------------------- | ---------------- | ------------- | ------------- | ------ |
| flysystem               | 91.6             | excellent     | excellent     | —      |
| phpunit                 | 80.2             | excellent     | excellent     | —      |
| symfony-http-foundation | 86.2             | excellent     | good          | +1     |
| symfony-http-kernel     | 83.9             | excellent     | good          | +1     |
| symfony-console         | 81.4             | excellent     | good          | +1     |
| doctrine-dbal           | 84.5             | excellent     | good          | +1     |
| qmx                     | 82.9             | excellent     | good          | +1     |
| monolog                 | 80.9             | excellent     | good          | +1     |
| symfony-routing         | 78.3             | good          | good          | —      |
| doctrine-orm            | 79.1             | good          | good          | —      |
| php-parser              | 79.0             | good          | good          | —      |
| guzzle                  | 75.0             | good          | fair          | +1     |
| laravel-framework       | 72.4             | good          | fair          | +1     |
| symfony-di              | 68.5             | good          | fair          | +1     |
| composer                | 63.0             | fair          | fair          | —      |
| codeigniter             | 64.7             | fair          | poor          | +1     |
| wordpress               | 54.0             | fair          | critical      | **+2** |

**Six agree, eleven are too high, none is too low.** The error is one-directional
and close to uniform: the scale reads about one band optimistic across its whole
range, and two bands optimistic at the bottom, where it matters most.

## What this does and does not establish

It establishes the size and direction of the correction P4 has to make, from a
judgement formed without reference to the numbers. It does not establish that
the a-priori bands are right — they are one careful reading, and the file itself
lists six placements its author was unsure about, WordPress cohesion among them.

Two cells should be treated as weaker evidence than the rest: php-parser
cohesion, because the ranking's author disclosed seeing a pair of superseded
cohesion values for that project while reading a README, and WordPress cohesion,
which the ranking marks as untrustworthy on its own terms.

## The trap this sets for P4

Eleven of seventeen projects must come down by a band, and the cheapest way to
do that is to lower thresholds until they do. That would be corpus fitting of
exactly the kind the plan forbids, and it would move projects the ranking got
right along with the ones it got wrong — flysystem, phpunit, routing, orm,
php-parser and composer are already in the correct band and must stay there.

A correction that moves all seventeen equally is not a calibration; it is a
constant subtracted from a scale.
