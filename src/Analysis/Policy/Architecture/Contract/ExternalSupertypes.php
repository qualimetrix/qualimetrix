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
        if (($declaredSpelling === null) !== ($classType === null)) {
            throw new InvalidArgumentException('An external declaration spelling and class type must be present together.');
        }
        if (!$placed && (
            $declaredSpelling !== null
            || $parent !== null
            || $interfaces !== []
            || $traits !== []
            || $declaresToString
            || $aliasesTraitMethodAsToString
            || $unreadable !== null
        )) {
            throw new InvalidArgumentException('A type outside the Composer map cannot carry declaration facts.');
        }
        if ($placed && $declaredSpelling === null && ($unreadable === null || $unreadable === '')) {
            throw new InvalidArgumentException('A placed type without declaration facts requires an unreadable reason.');
        }
        if ($declaredSpelling !== null && $unreadable !== null) {
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
