<?php

namespace Corpus\Design\Hierarchy;

class ExceptionGrandchild extends ExceptionParent
{
    private string $context = '';

    public function getContext(): string
    {
        return $this->context;
    }

    public function setContext(string $context): void
    {
        $this->context = $context;
    }
}
