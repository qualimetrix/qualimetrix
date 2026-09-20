<?php

namespace Corpus\Layers\AnonMember;

use Corpus\Layers\GraphBase\MarkerAttribute;

// No layer criterion reads MarkerAttribute, so this fixture cannot move any
// finding either way -- it exists only so the attribute-on-an-anonymous-
// class shape is covered by the corpus (see AGENTS.md P4: extends,
// implements, an attribute and a use-trait body all need a fixture).
class AttributesHost
{
    public function build(): object
    {
        return new #[MarkerAttribute] class {
        };
    }
}
