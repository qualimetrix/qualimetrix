<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

use InvalidArgumentException;
use Qualimetrix\Core\Symbol\ClassType;

/** Facts read from one class-like declaration in the analysed Composer install. */
final readonly class ExternalSupertypes
{
    /**
     * @param list<string> $interfaces
     * @param list<string> $traits
     */
    public function __construct(
        public bool $placed,
        public ?string $declaredSpelling,
        public ?ClassType $classType,
        public ?string $parent,
        public array $interfaces,
        public array $traits,
        public bool $declaresToString,
        public bool $aliasesTraitMethodAsToString,
        public ?string $unreadable,
    ) {
        $this->assertDeclarationPair();
        $this->assertAbsentWhenNotPlaced();
        $this->assertPlacedResult();
        $this->assertReadableResult();
    }

    private function assertDeclarationPair(): void
    {
        if (($this->declaredSpelling === null) !== ($this->classType === null)) {
            throw new InvalidArgumentException('An external declaration spelling and class type must be present together.');
        }
    }

    private function assertAbsentWhenNotPlaced(): void
    {
        if (!$this->placed && (
            $this->declaredSpelling !== null
            || $this->parent !== null
            || $this->interfaces !== []
            || $this->traits !== []
            || $this->declaresToString
            || $this->aliasesTraitMethodAsToString
            || $this->unreadable !== null
        )) {
            throw new InvalidArgumentException('A type outside the Composer map cannot carry declaration facts.');
        }
    }

    private function assertPlacedResult(): void
    {
        if ($this->placed
            && $this->declaredSpelling === null
            && ($this->unreadable === null || $this->unreadable === '')) {
            throw new InvalidArgumentException('A placed type without declaration facts requires an unreadable reason.');
        }
    }

    private function assertReadableResult(): void
    {
        if ($this->declaredSpelling !== null && $this->unreadable !== null) {
            throw new InvalidArgumentException('A readable declaration cannot also carry an unreadable reason.');
        }
    }

    public static function notPlaced(): self
    {
        return new self(false, null, null, null, [], [], false, false, null);
    }

    public static function unreadable(string $reason): self
    {
        return new self(true, null, null, null, [], [], false, false, $reason);
    }
}
