<?php

namespace Corpus\AppliedThreshold;

class Accepted
{
    /**
     * The applied override in the other direction, which publishes nothing:
     * seven parameters are an error at the configured threshold of 6, and the
     * annotation accepts them. Its evidence is therefore the absence of a
     * channel this case does not claim -- taking the annotation away makes
     * `code-smell.long-parameter-list@callable` fire, which is a pair the case
     * has to fire exactly none of.
     *
     * @qmx-threshold code-smell.long-parameter-list warning=20 error=30
     */
    public function assemble(int $a, int $b, int $c, int $d, int $e, int $f, int $g): int
    {
        return $a + $b + $c + $d + $e + $f + $g;
    }
}
