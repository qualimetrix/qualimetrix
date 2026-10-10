<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Core\Symbol\ClassNameSpelling;

/** Resolves bodies and response membership against exact collected owners. */
final class RfcResponseResolver
{
    private readonly RfcEffectiveMembers $members;
    private readonly RfcPropertyTypes $properties;

    public function __construct(private readonly RfcDeclarationIndex $index)
    {
        $this->members = new RfcEffectiveMembers($this->index);
        $this->properties = new RfcPropertyTypes($this->index, $this->members);
    }

    /** @return iterable<RfcDeclarationFacts> */
    public function declarations(): iterable
    {
        return $this->index->declarations();
    }

    /** @return array{own: int, external: int} */
    public function counts(RfcDeclarationFacts $consumer): array
    {
        $methods = $this->members->methods($consumer);
        $owned = [];
        foreach ($methods as $target) {
            $owned[$this->sourceKey($target)] = true;
        }
        $responses = [];
        foreach ($methods as $target) {
            foreach ($target['source']->responses[$target['member']] ?? [] as $row) {
                $key = $this->responseKey($row, $consumer, $owned);
                if ($key !== null) {
                    $responses[$key] = true;
                }
            }
        }

        return ['own' => \count($methods), 'external' => \count($responses)];
    }

    /** @param array<string, scalar> $row
     * @param array<string, true> $owned
     */
    private function responseKey(array $row, RfcDeclarationFacts $consumer, array $owned): ?string
    {
        if ($row['kind'] === 'function') {
            return 'function:' . strtolower((string) $row['method']);
        }
        $name = strtolower((string) $row['method']);
        $class = $this->receiver(isset($row['receiver']) ? (string) $row['receiver'] : null, $consumer);
        $declared = $class instanceof RfcDeclarationFacts ? $this->method($class, $name, []) : null;
        if ($declared !== null) {
            if (isset($owned[$this->sourceKey($declared)])) {
                return null;
            }

            return 'method:' . ClassNameSpelling::fold($declared['source']->declaration->logical->toString()) . '::' . $declared['member'];
        }
        if ($class instanceof RfcDeclarationFacts) {
            if ($this->incompleteUniverse($class, [])) {
                return 'method:unknown:' . $name;
            }
            $class = $class->declaration->logical->toString();
        }

        return 'method:' . ($class !== null ? ClassNameSpelling::fold($class) . '::' . $name : 'unknown:' . $name);
    }

    /** @param array<string, true> $active
     * @return array{source: RfcDeclarationFacts, member: string}|null
     */
    private function method(RfcDeclarationFacts $facts, string $member, array $active): ?array
    {
        $key = $facts->declaration->toCanonical();
        if (isset($active[$key])) {
            return null;
        }
        $active[$key] = true;
        $methods = $this->members->methods($facts);
        if (isset($methods[$member])) {
            return $methods[$member];
        }
        $parents = $this->index->parents($facts);
        if (\count($parents) !== 1) {
            return null;
        }
        $parent = $this->index->unique($parents[0]);

        return $parent !== null ? $this->method($parent, $member, $active) : null;
    }

    /** @param array<string, true> $active */
    private function incompleteUniverse(RfcDeclarationFacts $facts, array $active): bool
    {
        $key = $facts->declaration->toCanonical();
        if (isset($active[$key])) {
            return true;
        }
        $active[$key] = true;
        $this->members->methods($facts);
        if ($this->members->hasUnreadTrait($facts)) {
            return true;
        }
        $parents = $this->index->parents($facts);
        if ($parents === []) {
            return false;
        }
        if (\count($parents) !== 1) {
            return true;
        }
        $parent = $this->index->unique($parents[0]);

        return $parent === null || $this->incompleteUniverse($parent, $active);
    }

    private function receiver(?string $name, RfcDeclarationFacts $consumer): RfcDeclarationFacts|string|null
    {
        if ($name === null) {
            return null;
        }
        if (str_starts_with($name, 'property:')) {
            $property = substr($name, 9);
            $name = $this->properties->resolve($consumer, $property, []);
            if ($name === null) {
                return null;
            }
        }
        if ($name === 'self' || $name === 'static') {
            return $consumer;
        }
        if ($name === 'parent') {
            $parents = $this->index->parents($consumer);

            return \count($parents) === 1 ? $this->index->unique($parents[0]) : null;
        }
        return $this->index->namedReceiver($name);
    }

    /** @param array{source: RfcDeclarationFacts, member: string} $target */
    private function sourceKey(array $target): string
    {
        return $target['source']->declaration->toCanonical() . '::' . $target['member'];
    }
}
