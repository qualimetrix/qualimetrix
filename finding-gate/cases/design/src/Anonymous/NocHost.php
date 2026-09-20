<?php

namespace Corpus\Design\Anonymous;

class NocHost
{
    public function build(): object
    {
        return new class extends NocParent {
        };
    }
}
