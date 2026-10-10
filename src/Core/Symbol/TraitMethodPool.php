<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;

/**
 * @internal
 *
 * @phpstan-import-type TraitAlias from TraitUseFacts
 * @phpstan-import-type TraitPrecedence from TraitUseFacts
 *
 * @phpstan-type MethodCandidate array{source: TraitMethodSource, alias: bool}
 */
final class TraitMethodPool
{
    /**
     * @param array<string, array<string, TraitMethodSource>> $original
     * @param array<string, true> $incomplete
     */
    public function __construct(
        private readonly array $original,
        private readonly array $incomplete,
    ) {}

    /**
     * @param list<string> $ownMethods
     * @param list<TraitUseFacts> $uses
     *
     * @return array<string, TraitMethodSource>
     */
    public function compose(array $ownMethods, array $uses): array
    {
        $candidates = $this->adaptCandidates($this->selectOriginals($uses), $uses);
        $own = array_fill_keys(array_map(ClassNameSpelling::fold(...), $ownMethods), true);
        $result = [];
        foreach ($candidates as $name => $sources) {
            if (!isset($own[$name])) {
                $result[$name] = $this->choose($name, $sources);
            }
        }

        return $result;
    }

    /**
     * @param list<TraitUseFacts> $uses
     *
     * @return array<string, array<string, TraitMethodSource>>
     */
    private function selectOriginals(array $uses): array
    {
        $selected = $this->original;
        foreach ($uses as $use) {
            foreach ($use->precedences as $precedence) {
                $this->applyPrecedence($selected, $precedence);
            }
        }

        return $selected;
    }

    /**
     * @param array<string, array<string, TraitMethodSource>> $selected
     * @param list<TraitUseFacts> $uses
     *
     * @return array<string, non-empty-list<MethodCandidate>>
     */
    private function adaptCandidates(array $selected, array $uses): array
    {
        $aliases = [];
        foreach ($uses as $use) {
            foreach ($use->aliases as $alias) {
                $this->applyAlias($selected, $aliases, $alias);
            }
        }

        $candidates = $aliases;
        foreach ($selected as $methods) {
            foreach ($methods as $name => $source) {
                $candidates[$name][] = ['source' => $source, 'alias' => false];
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, array<string, TraitMethodSource>> $selected
     * @param TraitPrecedence $precedence
     */
    private function applyPrecedence(array &$selected, array $precedence): void
    {
        $method = ClassNameSpelling::fold($precedence['method']);
        $this->qualifiedSource($precedence['trait'], $method);
        foreach ($precedence['insteadOf'] as $excluded) {
            $identity = ClassNameSpelling::fold($excluded);
            if (!\array_key_exists($identity, $this->original)) {
                throw new InvalidArgumentException('Precedence names unused trait ' . $excluded . ' for method ' . $method);
            }
            unset($selected[$identity][$method]);
        }
    }

    /**
     * @param array<string, array<string, TraitMethodSource>> $selected
     * @param array<string, non-empty-list<MethodCandidate>> $aliases
     * @param TraitAlias $alias
     */
    private function applyAlias(array &$selected, array &$aliases, array $alias): void
    {
        $method = ClassNameSpelling::fold($alias['method']);
        $trait = $alias['trait'] === null ? $this->unqualifiedTrait($method) : ClassNameSpelling::fold($alias['trait']);
        if ($trait === null) {
            return;
        }
        $source = $this->qualifiedSource($trait, $method);
        if ($source === null) {
            return;
        }
        $adapted = $alias['visibility'] === null ? $source : $source->withVisibility($alias['visibility']);
        if ($alias['alias'] !== null) {
            $aliases[ClassNameSpelling::fold($alias['alias'])][] = ['source' => $adapted, 'alias' => true];
        } elseif (isset($selected[$trait][$method])) {
            $selected[$trait][$method] = $adapted;
        }
    }

    private function qualifiedSource(string $trait, string $method): ?TraitMethodSource
    {
        $identity = ClassNameSpelling::fold($trait);
        if (!\array_key_exists($identity, $this->original)) {
            throw new InvalidArgumentException('Adaptation names unused trait ' . $trait . ' for method ' . $method);
        }
        if (isset($this->original[$identity][$method])) {
            return $this->original[$identity][$method];
        }
        if (isset($this->incomplete[$identity])) {
            return null;
        }

        throw new InvalidArgumentException('Unknown trait method ' . $trait . '::' . $method);
    }

    private function unqualifiedTrait(string $method): ?string
    {
        $traits = [];
        foreach ($this->original as $trait => $methods) {
            if (isset($methods[$method])) {
                $traits[] = $trait;
            }
        }
        if (\count($traits) > 1) {
            throw new InvalidArgumentException('Ambiguous trait alias for method ' . $method);
        }
        if ($this->incomplete !== []) {
            return null;
        }
        if ($traits === []) {
            throw new InvalidArgumentException('Unknown trait alias method ' . $method);
        }

        return $traits[0];
    }

    /** @param non-empty-list<MethodCandidate> $sources */
    private function choose(string $name, array $sources): TraitMethodSource
    {
        $concrete = array_values(array_filter($sources, static fn(array $candidate): bool => !$candidate['source']->isAbstract));
        if ($concrete === []) {
            return $sources[0]['source'];
        }
        $first = $concrete[0];
        foreach (\array_slice($concrete, 1) as $candidate) {
            if ($first['alias'] || $candidate['alias'] || !$first['source']->sameImplementation($candidate['source'])) {
                throw new InvalidArgumentException('Conflicting trait methods for ' . $name);
            }
        }

        return $first['source'];
    }
}
