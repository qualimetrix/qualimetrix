<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ExternalParentSourceInterface;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ParentLookup;
use Qualimetrix\Infrastructure\Composer\Contract\AnalysedInstallAnchorInterface;
use Throwable;

/**
 * Reads the parent a class declares, out of the analysed project's own files.
 *
 * Placing the file is {@see ComposerAutoloadMap}'s job; this one opens it and
 * parses it. Nothing here loads a class, so no analysed code runs.
 */
final class DeclaredParentReader implements AnalysedInstallAnchorInterface, ExternalParentSourceInterface
{
    private readonly Parser $parser;

    /** @var array<string, ParentLookup> */
    private array $answers = [];

    public function __construct(
        private readonly ComposerAutoloadMap $map,
        ?Parser $parser = null,
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForHostVersion();
    }

    /**
     * Aiming a run clears what the last one learned. Without this a second run
     * in the same process -- a test suite, or a command that analyses twice --
     * would answer from the tree it is no longer looking at.
     *
     * @param list<string> $analysedPaths
     */
    public function pointAt(string $projectRoot, array $analysedPaths): void
    {
        $this->answers = [];
        $this->map->pointAt($projectRoot, $analysedPaths);
    }

    public function isConfigured(): bool
    {
        return $this->map->isConfigured();
    }

    public function parentOf(string $fqcn): ParentLookup
    {
        return $this->answers[$fqcn] ??= $this->read($fqcn);
    }

    private function read(string $fqcn): ParentLookup
    {
        $file = $this->map->fileFor($fqcn);

        if ($file === null || !is_file($file)) {
            return ParentLookup::notPlaced();
        }

        $source = @file_get_contents($file);

        if ($source === false) {
            return ParentLookup::notPlaced();
        }

        try {
            $ast = $this->parser->parse($source) ?? [];
        } catch (Throwable) {
            // A file this PHP version cannot parse is a chain that stops being
            // readable, not a depth to report.
            return ParentLookup::notPlaced();
        }

        // Without this the parent arrives as the source wrote it --
        // `class ResponseHeaderBag extends HeaderBag` yields the bare
        // `HeaderBag` -- which places nothing and reads as a broken chain. Most
        // parents are written relatively, so this is the common path.
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        return $this->declaredParent($traverser->traverse($ast), $fqcn);
    }

    /**
     * @param Node[] $ast
     */
    private function declaredParent(array $ast, string $fqcn): ParentLookup
    {
        $finder = new NodeFinder();

        /** @var list<Node\Stmt\Class_> $classes */
        $classes = $finder->findInstanceOf($ast, Node\Stmt\Class_::class);

        foreach ($classes as $class) {
            if ($class->namespacedName?->toString() === $fqcn) {
                return $class->extends === null
                    ? ParentLookup::root()
                    : ParentLookup::extending($class->extends->toString());
            }
        }

        /** @var list<Node\Stmt\Interface_> $interfaces */
        $interfaces = $finder->findInstanceOf($ast, Node\Stmt\Interface_::class);

        foreach ($interfaces as $interface) {
            if ($interface->namespacedName?->toString() === $fqcn) {
                // An interface may extend several; DIT is a single chain, so
                // the first is the one this metric follows.
                return $interface->extends === []
                    ? ParentLookup::root()
                    : ParentLookup::extending($interface->extends[0]->toString());
            }
        }

        // The file was placed but does not declare this name: the map and the
        // sources disagree, which is not a root.
        return ParentLookup::notPlaced();
    }
}
