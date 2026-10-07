<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypes;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Declaration relations used to build one run's class contexts. */
final class DeclarationRelations
{
    /** @var array<string, ClassLikeDeclaration> */
    private array $declarations = [];

    private readonly DeclarationRelationIndex $relations;

    public function __construct(
        DependencyGraphInterface $graph,
        private readonly NameSpellingIndex $spellings,
    ) {
        $this->relations = new DeclarationRelationIndex($graph, $this->nameOf(...));
        $this->collectDeclarations($graph);
    }

    public function spellingOf(string $fqn): string
    {
        return PhpBuiltinClassRegistry::canonicalName($fqn)
            ?? $this->spellings->spellingOf($fqn)
            ?? $fqn;
    }

    /** @return list<string>|null */
    public function attributesOf(string $fqn): ?array
    {
        return $this->relations->attributesOf($fqn);
    }

    /** @return list<string> */
    public function memberAttributesOf(string $fqn): array
    {
        return $this->relations->memberAttributesOf($fqn);
    }

    /** @return list<string>|null */
    public function extendsOf(string $fqn): ?array
    {
        return $this->relations->extendsOf($fqn);
    }

    /** @return list<string> */
    public function implementsOf(string $fqn): array
    {
        return $this->relations->implementsOf($fqn);
    }

    /** @return array<string, list<string>> */
    public function implementsMap(): array
    {
        return $this->relations->implementsMap();
    }

    /** @return list<string> */
    public function traitsOf(string $fqn): array
    {
        return $this->relations->traitsOf($fqn);
    }

    public function isInterface(string $fqn): bool
    {
        return $this->relations->isInterface($fqn);
    }

    public function declarationOf(string $fqn): ?ClassLikeDeclaration
    {
        return $this->declarations[$fqn] ?? null;
    }

    /** @return list<string> */
    public function declarationNames(): array
    {
        return array_keys($this->declarations);
    }

    public function addExternal(string $fqn, ExternalSupertypes $facts): void
    {
        $this->relations->addExternal($fqn, $facts);
    }

    public function addImplicitStringable(string $fqn, ClassType $type): void
    {
        $this->relations->addImplicitStringable($fqn, $type);
    }

    private function collectDeclarations(DependencyGraphInterface $graph): void
    {
        foreach ($graph->getClassLikeDeclarations() as $declaration) {
            $fqn = $this->nameOf($declaration->logical->symbolPath);
            if ($fqn === null) {
                continue;
            }
            $this->declarations[$fqn] = $declaration;
            $this->relations->recordDeclarationKind($fqn, $declaration->type);
        }
    }

    private function nameOf(SymbolPath $path): ?string
    {
        $fqn = self::fqn($path);

        return $fqn === null ? null : $this->spellingOf($fqn);
    }

    private static function fqn(SymbolPath $path): ?string
    {
        if ($path->type === null || $path->type === '') {
            return null;
        }

        return $path->namespace === null || $path->namespace === ''
            ? $path->type
            : $path->namespace . '\\' . $path->type;
    }
}
