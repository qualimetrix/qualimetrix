<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Every active class has a source producer and an observed class/side/scope witness. */
final class WitnessRegistry
{
    /** @param list<string> $classes
     * @param array<array-key,string> $producers
     * @param list<string> $observed classes witnessed with their actual scopes
     *
     * @return list<string>
     */
    public static function problems(array $classes, array $producers, array $observed): array
    {
        $problems = [];
        foreach ($classes as $class) {
            if (!\in_array($class, $producers, true)) {
                $problems[] = 'witness registry: ' . $class . ' is raised nowhere in the gate\'s source.';
            }
            if (!\in_array($class, $observed, true)) {
                $problems[] = 'witness registry: ' . $class . ' has no observed class/side/scope witness.';
            }
        }
        foreach (array_unique([...array_values($producers), ...$observed]) as $class) {
            if (!\in_array($class, $classes, true)) {
                $problems[] = 'witness registry: unknown failure class ' . $class . '.';
            }
        }
        return $problems;
    }
}
