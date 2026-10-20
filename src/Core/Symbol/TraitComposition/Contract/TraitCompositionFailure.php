<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol\TraitComposition\Contract;

use InvalidArgumentException;

use Qualimetrix\Core\Symbol\DeclarationPath;
use Throwable;

/** Invalid source-level method composition, optionally bound to its exact declaration. */
final class TraitCompositionFailure extends InvalidArgumentException
{
    public function __construct(string $message, public readonly ?DeclarationPath $declaration = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function at(DeclarationPath $declaration): self
    {
        return $this->declaration !== null ? $this : new self($this->getMessage(), $declaration, $this);
    }
}
