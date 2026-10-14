<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;

final readonly class ConsoleDiagnostics
{
    public function __construct(
        public ErrorStream $errorStream,
        public RefusalPresenter $refusalPresenter,
        public OutOfMemoryHint $outOfMemoryHint,
    ) {}

    public static function fresh(): self
    {
        return self::around(OutOfMemoryHint::register());
    }

    public static function around(OutOfMemoryHint $hint): self
    {
        $stream = new ErrorStream();

        return new self($stream, new RefusalPresenter($stream), $hint);
    }
}
