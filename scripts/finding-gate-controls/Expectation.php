<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;
use RuntimeException;

/**
 * One failure a control declares in advance: a class from the gate's own
 * vocabulary, optionally pinned to the surface it must land on.
 *
 * The class string is validated against FailureClass::ALL at construction, so a
 * step that renames a failure class fails the harness loudly here instead of
 * quietly never matching.
 */
final class Expectation
{
    public function __construct(
        public readonly string $failureClass,
        public readonly ?string $scopeContains = null,
    ) {
        if (!\in_array($failureClass, FailureClass::ALL, true)) {
            throw new RuntimeException(\sprintf(
                'No failure class "%s" in the gate\'s vocabulary. It was renamed or removed: re-point this'
                . ' expectation rather than asserting on a string the gate can never emit.',
                $failureClass,
            ));
        }
    }

    public function matches(string $failureClass, string $scope): bool
    {
        return $failureClass === $this->failureClass
            && ($this->scopeContains === null || str_contains($scope, $this->scopeContains));
    }

    public function label(): string
    {
        return $this->scopeContains === null
            ? $this->failureClass
            : $this->failureClass . ' @ ' . $this->scopeContains;
    }
}
