<?php

declare(strict_types=1);

namespace Probe\Coupled;

class Spoke
{
    public function reach(Hub $hub): Hub
    {
        return $hub;
    }
}
