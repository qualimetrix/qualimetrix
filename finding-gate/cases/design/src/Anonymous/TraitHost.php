<?php

namespace Corpus\Design\Anonymous;

// use T; inside an anonymous class body never reaches ClassLikeHandler --
// it arrives through TraitUseHandler with the enclosing context still in
// place. No declaration reader (DIT, NOC, layer membership) follows
// trait_use edges, so this fixture has no metric surfaced by the gate; it
// exists so the source shape itself is covered by the corpus.
class TraitHost
{
    public function build(): object
    {
        return new class {
            use AnonTrait;
        };
    }
}
