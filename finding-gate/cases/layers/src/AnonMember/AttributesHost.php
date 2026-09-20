<?php

namespace Corpus\Layers\AnonMember;

use Corpus\Layers\GraphBase\MarkerAttribute;

// No layer criterion reads MarkerAttribute, so this fixture cannot move any
// finding either way -- it exists only so the attribute-on-an-anonymous-
// class shape is carried at all: the corpus needs one fixture per channel --
// extends, implements, an attribute and a use-trait body.
class AttributesHost
{
    public function build(): object
    {
        return new #[MarkerAttribute] class {
        };
    }
}
