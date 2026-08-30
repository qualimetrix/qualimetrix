<?php

namespace Corpus\AppliedThreshold;

class Retuned
{
    /**
     * The applied override, in the direction that publishes itself: CCN 3 is
     * below every configured threshold of this case, so the finding exists
     * only because the annotation was read, and it prints the annotated number
     * rather than the configured one. Taking the annotation away leaves the
     * case firing nothing on this channel, which is a claim its `channels`
     * makes.
     *
     * @qmx-threshold complexity.cyclomatic warning=2
     */
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

    /**
     * The near scope witness: the same CCN as the annotated method, in the
     * same file and the same class, with no annotation of its own. An override
     * bound to the file rather than to the declaration it is written on fires
     * here; `Neighbour` is the same witness one file further out, since the
     * binding is built per file and a file-wide leak is invisible to a witness
     * living in another one.
     */
    public function untouched(int $value, string $mode): string
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
