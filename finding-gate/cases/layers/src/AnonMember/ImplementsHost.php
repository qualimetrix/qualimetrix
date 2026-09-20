<?php

namespace Corpus\Layers\AnonMember;

use Corpus\Layers\GraphBase\MarkerContract;

// No layer criterion reads MarkerContract, so this fixture cannot move any
// finding either way -- it exists only so the implements-on-an-anonymous-
// class shape is covered by the corpus (see AGENTS.md P4: extends,
// implements, an attribute and a use-trait body all need a fixture).
class ImplementsHost
{
    public function build(): object
    {
        return new class implements MarkerContract {
        };
    }
}
