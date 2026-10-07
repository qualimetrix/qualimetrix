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
use Qualimetrix\Core\Symbol\ClassNameSpelling;

/**
 * Reads class-like facts from source files placed by the analysed Composer install.
 *
 * The reader is stateless. Per-run memoization belongs to Architecture's
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext\ClassContextFactory},
 * while ComposerAutoloadMap owns and resets the placement snapshot when the
 * analysed project changes.
 */
final readonly class DeclaredSupertypeReader implements ExternalSupertypeSourceInterface
{
    private Parser $parser;
    private DeclaredClassLikeFactExtractor $factExtractor;

    public function __construct(
        private ComposerAutoloadMap $map,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForHostVersion();
        $this->factExtractor = new DeclaredClassLikeFactExtractor();
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
            return $this->factExtractor->facts($declaration);
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

}
