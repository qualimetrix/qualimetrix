<?php

namespace Corpus\AppliedThreshold;

/**
 * The scope witness: the same CCN as the annotated method, in the same case,
 * with no annotation of its own. A product binding an override to the file or
 * to the run instead of to the declaration it is written on would fire here
 * too, and without this class the corpus reported the same findings either
 * way -- both annotations sit on the only callable able to fire their channel,
 * so nothing measured the binding.
 */
class Neighbour
{
    public function classify(int $value, string $mode): string
    {
        if ($value > 10) {
            return 'high';
        }

        if ($mode === 'strict') {
            return 'strict';
        }

        return 'low';
    }
}
