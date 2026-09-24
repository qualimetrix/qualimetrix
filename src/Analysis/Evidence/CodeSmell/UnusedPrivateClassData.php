<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

/**
 * Internal data structure for tracking private member declarations and usages within a class.
 *
 * Used by UnusedPrivateVisitor to compute unused private members.
 */
final class UnusedPrivateClassData
{
    /** @var array<string, int> name => line */
    public array $declaredMethods = [];

    /** @var array<string, int> name => line */
    public array $declaredProperties = [];

    /** @var array<string, int> name => line */
    public array $declaredConstants = [];

    /** @var array<string, true> lowercase name => true */
    public array $usedMethods = [];

    /** @var array<string, true> */
    public array $usedProperties = [];

    /** @var array<string, true> */
    public array $usedConstants = [];

    public bool $hasMagicCall = false;

    public bool $hasMagicCallStatic = false;

    public bool $hasMagicGet = false;

    public bool $hasMagicSet = false;

    public function __construct(
        public readonly ?string $namespace,
        public readonly string $className,
        public readonly int $line,
        public readonly int $startFilePos = 0,
    ) {}

    /**
     * Records the dynamic access a declared magic method opens for the whole class, whatever its
     * visibility and letter case, and whether it is declared in the class or in a used trait.
     */
    public function noteMethodDeclaration(string $name): void
    {
        match (strtolower($name)) {
            '__call' => $this->hasMagicCall = true,
            '__callstatic' => $this->hasMagicCallStatic = true,
            '__get' => $this->hasMagicGet = true,
            '__set' => $this->hasMagicSet = true,
            default => null,
        };
    }

    /**
     * Returns unused private methods (name => line).
     *
     * If the class defines __call or __callStatic, all private methods are
     * considered potentially reachable and an empty array is returned.
     *
     * @return array<string, int>
     */
    public function getUnusedMethods(): array
    {
        if ($this->hasMagicCall || $this->hasMagicCallStatic) {
            return [];
        }

        return array_filter(
            $this->declaredMethods,
            fn(string $name): bool => !isset($this->usedMethods[strtolower($name)]),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Returns unused private properties (name => line).
     *
     * If the class defines __get or __set, all private properties are
     * considered potentially reachable and an empty array is returned.
     *
     * @return array<string, int>
     */
    public function getUnusedProperties(): array
    {
        if ($this->hasMagicGet || $this->hasMagicSet) {
            return [];
        }

        return array_diff_key($this->declaredProperties, $this->usedProperties);
    }

    /**
     * Returns unused private constants (name => line).
     *
     * @return array<string, int>
     */
    public function getUnusedConstants(): array
    {
        return array_diff_key($this->declaredConstants, $this->usedConstants);
    }
}
