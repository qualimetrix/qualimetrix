<?php

namespace Corpus\Layers\Orphan;

use Corpus\Layers\Extra\HelperService;

class Loner
{
    public function __construct(private HelperService $helper) {}

    public function run(): void
    {
        $this->helper->help();
    }
}
