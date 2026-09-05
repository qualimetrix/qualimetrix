<?php

namespace Corpus\Complexity;

final class LabelSource
{
    public const DEFAULT_LABEL = 'n/a';
}

class ConstantAccess
{
    public const NAME = 'constant-access';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getLabel(): string
    {
        return LabelSource::DEFAULT_LABEL;
    }
}
