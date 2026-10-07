<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext;

use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;

/** Derives PHP's implicit Stringable relation from declaration facts. */
final class ImplicitStringability
{
    /** @var array<string, bool|null> */
    private array $statuses = [];

    /** @var array<string, true> */
    private array $doubts = [];

    public function __construct(
        private readonly DeclarationRelations $relations,
        private readonly Ancestry $ancestry,
    ) {}

    public function applyToDeclarations(): void
    {
        foreach ($this->relations->declarationNames() as $fqn) {
            $this->apply($fqn);
        }
    }

    public function apply(string $fqn): void
    {
        $status = $this->status($fqn, []);
        if ($status === null) {
            $this->doubts[$fqn] = true;

            return;
        }
        if ($status) {
            $type = $this->ancestry->classTypeOf($fqn);
            if ($type !== null) {
                $this->relations->addImplicitStringable($fqn, $type);
            }
        }
    }

    /** @param list<string> $visited */
    public function knownFor(array $visited): bool
    {
        foreach ($visited as $fqn) {
            if (isset($this->doubts[$fqn])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, true> $visiting */
    private function status(string $fqn, array $visiting): ?bool
    {
        if ($fqn === 'Stringable') {
            return true;
        }
        $builtinInterfaces = PhpBuiltinClassHierarchy::interfacesOf($fqn);
        if ($builtinInterfaces !== null) {
            return \in_array('Stringable', $builtinInterfaces, true);
        }
        if (\array_key_exists($fqn, $this->statuses)) {
            return $this->statuses[$fqn];
        }
        if (isset($visiting[$fqn]) || \count($visiting) >= Ancestry::MAX_DEPTH) {
            return null;
        }

        $facts = $this->ancestry->declarationFacts($fqn);
        if ($facts === null) {
            return $this->statuses[$fqn] = null;
        }
        if ($facts->declaresToString) {
            return $this->statuses[$fqn] = true;
        }

        $visiting[$fqn] = true;
        $unknown = $facts->aliasesTraitMethodAsToString;
        foreach ([
            ...$this->relations->traitsOf($fqn),
            ...($this->relations->extendsOf($fqn) ?? []),
            ...$this->relations->implementsOf($fqn),
        ] as $related) {
            $status = $this->status($related, $visiting);
            if ($status === true) {
                return $this->statuses[$fqn] = true;
            }
            $unknown = $unknown || $status === null;
        }

        return $this->statuses[$fqn] = $unknown ? null : false;
    }
}
