<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use LogicException;
use PhpParser\Error as ParserError;
use PhpParser\ErrorHandler\Throwing;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypes;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypeSourceInterface;
use Qualimetrix\Core\Ast\NameResolution;
use Qualimetrix\Core\Ast\ResolvedName;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\ClassType;

/**
 * Reads class-like facts from source files placed by the analysed Composer install.
 *
 * The reader is stateless. Per-run memoization belongs to Architecture's
 * ClassContextFactory, while ComposerAutoloadMap owns and resets the placement
 * snapshot when the analysed project changes.
 */
final readonly class DeclaredSupertypeReader implements ExternalSupertypeSourceInterface
{
    private Parser $parser;

    public function __construct(
        private ComposerAutoloadMap $map,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForHostVersion();
    }

    public function isConfigured(): bool
    {
        return $this->map->isConfigured();
    }

    public function supertypesOf(string $fqcn): ExternalSupertypes
    {
        $file = $this->map->fileFor($fqcn);
        if ($file === null) {
            return ExternalSupertypes::notPlaced();
        }

        $source = @file_get_contents($file);
        if ($source === false) {
            return ExternalSupertypes::unreadable('the mapped source file could not be read');
        }

        try {
            $ast = $this->parser->parse($source) ?? [];
            NameResolution::resolve($ast, new Throwing());
        } catch (ParserError) {
            return ExternalSupertypes::unreadable('the mapped source file could not be parsed');
        }

        $declaration = $this->unconditionalDeclaration($ast, $fqcn);
        if ($declaration === null) {
            return ExternalSupertypes::unreadable(
                $this->containsDeclaration($ast, $fqcn)
                    ? 'the mapped declaration is conditional'
                    : 'the mapped source file does not declare the requested PHP identity',
            );
        }

        try {
            return $this->facts($declaration);
        } catch (LogicException) {
            return ExternalSupertypes::unreadable('the mapped declaration contains a class name that could not be resolved');
        }
    }

    /**
     * @param array<int, Node> $ast
     */
    private function unconditionalDeclaration(array $ast, string $fqcn): ?Stmt\ClassLike
    {
        foreach ($ast as $node) {
            if ($node instanceof Stmt\ClassLike && self::declares($node, $fqcn)) {
                return $node;
            }
            if (!$node instanceof Stmt\Namespace_) {
                continue;
            }
            foreach ($node->stmts as $statement) {
                if ($statement instanceof Stmt\ClassLike && self::declares($statement, $fqcn)) {
                    return $statement;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, Node> $ast
     */
    private function containsDeclaration(array $ast, string $fqcn): bool
    {
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($ast, Stmt\ClassLike::class) as $declaration) {
            if (self::declares($declaration, $fqcn)) {
                return true;
            }
        }

        return false;
    }

    private static function declares(Stmt\ClassLike $declaration, string $fqcn): bool
    {
        $spelling = $declaration->namespacedName?->toString();

        return $spelling !== null
            && ClassNameSpelling::fold($spelling) === ClassNameSpelling::fold(ltrim($fqcn, '\\'));
    }

    private function facts(Stmt\ClassLike $declaration): ExternalSupertypes
    {
        $spelling = $declaration->namespacedName?->toString()
            ?? throw new LogicException('A named declaration must carry its resolved name');
        $parent = $declaration instanceof Stmt\Class_ && $declaration->extends !== null
            ? ResolvedName::className($declaration->extends)
            : null;
        $interfaces = match (true) {
            $declaration instanceof Stmt\Class_ => self::resolvedNames($declaration->implements),
            $declaration instanceof Stmt\Interface_ => self::resolvedNames($declaration->extends),
            $declaration instanceof Stmt\Enum_ => self::resolvedNames($declaration->implements),
            default => [],
        };
        $traits = [];
        $aliasesToString = false;
        foreach ($declaration->stmts as $statement) {
            if (!$statement instanceof Stmt\TraitUse) {
                continue;
            }
            foreach (self::resolvedNames($statement->traits) as $trait) {
                $traits[] = $trait;
            }
            foreach ($statement->adaptations as $adaptation) {
                if (
                    $adaptation instanceof Stmt\TraitUseAdaptation\Alias
                    && $adaptation->newName !== null
                    && strcasecmp($adaptation->newName->toString(), '__toString') === 0
                ) {
                    $aliasesToString = true;
                }
            }
        }

        $declaresToString = false;
        foreach ($declaration->getMethods() as $method) {
            if (strcasecmp($method->name->toString(), '__toString') === 0) {
                $declaresToString = true;

                break;
            }
        }

        return new ExternalSupertypes(
            true,
            $spelling,
            self::classType($declaration),
            $parent,
            array_values(array_unique($interfaces)),
            array_values(array_unique($traits)),
            $declaresToString,
            $aliasesToString,
            null,
        );
    }

    /**
     * @param array<int, Node\Name> $names
     *
     * @return list<string>
     */
    private static function resolvedNames(array $names): array
    {
        $resolved = [];
        foreach ($names as $name) {
            $fqcn = ResolvedName::className($name);
            if ($fqcn === null) {
                throw new LogicException('A class-name position did not resolve to a class');
            }
            $resolved[] = $fqcn;
        }

        return $resolved;
    }

    private static function classType(Stmt\ClassLike $declaration): ClassType
    {
        return match (true) {
            $declaration instanceof Stmt\Class_ => ClassType::Class_,
            $declaration instanceof Stmt\Interface_ => ClassType::Interface_,
            $declaration instanceof Stmt\Trait_ => ClassType::Trait_,
            $declaration instanceof Stmt\Enum_ => ClassType::Enum_,
            default => throw new LogicException('Unsupported class-like declaration'),
        };
    }
}
