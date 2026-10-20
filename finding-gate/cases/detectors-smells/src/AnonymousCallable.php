<?php

declare(strict_types=1);

namespace Corpus\Smells;

$anonymous = new class {
    public function hidden(): void
    {
        $callback = static fn(bool $flag): null => null;
    }
};
