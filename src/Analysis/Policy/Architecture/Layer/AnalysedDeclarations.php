<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * The set of declarations one run read, as membership criteria need to ask
 * about it: "did this run see this FQN's own declaration?"
 *
 * It exists because the answer has three states and only two of them are a set
 * membership test. {@see unknown()} is the third: a caller that never told the
 * factory which declarations the run analysed. That caller had every answer
 * before the set existed, so it keeps getting them — {@see contains()} says yes
 * to everything, and nothing becomes undecidable that was not already. Folding
 * that state into an empty set instead would turn a plain unit-test registry
 * into one where no criterion can be decided at all.
 *
 * Named rather than left as a nullable array on
 * {@see ClassContextFactory}: "the declarations this run read" is the subject,
 * and the two questions asked of it — build the index, test one FQN — are its
 * whole surface.
 *
 * @internal Consumed by {@see ClassContextFactory}.
 */
final readonly class AnalysedDeclarations
{
    /**
     * @param array<string, true>|null $fqns Null means the set was never
     *                                       supplied — see the class docblock.
     */
    private function __construct(private ?array $fqns) {}

    /**
     * The state of a factory nobody told: every FQN counts as analysed, which
     * is the behaviour every caller had before the set was threaded through.
     */
    public static function unknown(): self
    {
        return new self(null);
    }

    /**
     * @param iterable<SymbolPath> $classes The run's class universe.
     */
    public static function of(iterable $classes): self
    {
        $index = [];
        foreach ($classes as $class) {
            $fqn = self::fqnFor($class);
            if ($fqn !== null) {
                $index[$fqn] = true;
            }
        }

        return new self($index);
    }

    public function contains(string $fqn): bool
    {
        return $this->fqns === null || isset($this->fqns[$fqn]);
    }

    private static function fqnFor(SymbolPath $class): ?string
    {
        $namespace = $class->namespace;
        $type = $class->type;

        if ($type === null || $type === '') {
            return null;
        }

        return $namespace === null || $namespace === '' ? $type : $namespace . '\\' . $type;
    }
}
