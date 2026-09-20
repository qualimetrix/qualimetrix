<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Support;

use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ExternalParentSourceInterface;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ParentLookup;

/**
 * The parents a case wants known, and nothing else.
 *
 * Reading a real install would make a test's answer depend on whichever
 * packages happen to be installed beside it, which is the coupling the
 * measurement under test is escaping.
 *
 * A name absent from the array is one this source cannot place; a name mapped
 * to null is one it placed and found to be a root. `unconfigured()` is the
 * third state: no install to read at all.
 */
final readonly class FixedParentSource implements ExternalParentSourceInterface
{
    /** @param array<string, string|null> $parents FQCN => its parent, or null for a root */
    public function __construct(private array $parents = [], private bool $configured = true) {}

    public static function unconfigured(): self
    {
        return new self([], false);
    }

    public function parentOf(string $fqcn): ParentLookup
    {
        $name = ltrim($fqcn, '\\');

        if (!\array_key_exists($name, $this->parents)) {
            return ParentLookup::notPlaced();
        }

        $parent = $this->parents[$name];

        return $parent === null ? ParentLookup::root() : ParentLookup::extending($parent);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}
