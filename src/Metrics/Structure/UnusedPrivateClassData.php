<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

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

    /** @var array<string, true> */
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
    ) {}

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

        return array_diff_key($this->declaredMethods, $this->usedMethods);
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
