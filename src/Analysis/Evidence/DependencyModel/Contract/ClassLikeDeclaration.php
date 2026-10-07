<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;

/** Facts declared by one named class-like body, including degree-zero declarations. */
final readonly class ClassLikeDeclaration
{
    private function __construct(
        public DeclarationPath $declaration,
        public LogicalClassPath $logical,
        public ClassType $type,
        public bool $declaresToString,
        public bool $aliasesTraitMethodAsToString,
    ) {}

    /** @qmx-ignore code-smell.boolean-argument -- Both booleans are direct-body facts in one immutable declaration snapshot. */
    public static function of(
        DeclarationPath $declaration,
        ClassType $type,
        bool $declaresToString,
        bool $aliasesTraitMethodAsToString,
    ): self {
        return new self(
            $declaration,
            new LogicalClassPath($declaration->logical),
            $type,
            $declaresToString,
            $aliasesTraitMethodAsToString,
        );
    }

    public function withLogicalClass(LogicalClassPath $logical): self
    {
        return new self(
            $this->declaration,
            $logical,
            $this->type,
            $this->declaresToString,
            $this->aliasesTraitMethodAsToString,
        );
    }
}
