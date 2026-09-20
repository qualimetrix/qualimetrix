<?php

namespace Corpus\Layers\AnonMember;

use Corpus\Layers\GraphBase\MarkerContract;

// No layer criterion reads MarkerContract, so this fixture cannot move any
// finding either way -- it exists only so the implements-on-an-anonymous-
// class shape is carried at all: the corpus needs one fixture per channel --
// extends, implements, an attribute and a use-trait body.
class ImplementsHost
{
    public function build(): object
    {
        return new class implements MarkerContract {
        };
    }
}
