<?php

namespace Corpus\Design\Anonymous;

// The anonymous class below extends DitParent, which itself extends
// DitGrandparent. DitHost declares no parent of its own: its design.dit
// must stay 0. A defect that lends the anonymous class's own extends edge
// to the enclosing declaration would report DitHost's design.dit as 2 --
// the anonymous class's would-be depth -- instead.
class DitHost
{
    public function build(): object
    {
        return new class extends DitParent {
        };
    }
}
