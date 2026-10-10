<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;

final readonly class TraitMethodSource
{
    /**
     * @param 'public'|'protected'|'private' $visibility
     */
    public function __construct(
        public string $declaringTrait,
        public string $originalMethod,
        public string $visibility = 'public',
        public bool $isAbstract = false,
    ) {
        if (!\in_array($visibility, ['public', 'protected', 'private'], true)) {
            throw new InvalidArgumentException('Trait method visibility must be public, protected, or private');
        }
    }

    /**
     * @param 'public'|'protected'|'private' $visibility
     */
    public function withVisibility(string $visibility): self
    {
        return new self($this->declaringTrait, $this->originalMethod, $visibility, $this->isAbstract);
    }

    public function sameImplementation(self $other): bool
    {
        return ClassNameSpelling::fold($this->declaringTrait) === ClassNameSpelling::fold($other->declaringTrait)
            && ClassNameSpelling::fold($this->originalMethod) === ClassNameSpelling::fold($other->originalMethod)
            && $this->visibility === $other->visibility
            && $this->isAbstract === $other->isAbstract;
    }
}
