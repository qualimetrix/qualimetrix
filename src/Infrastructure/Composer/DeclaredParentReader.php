<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use LogicException;
use PhpParser\Error as ParserError;
use PhpParser\ErrorHandler\Throwing;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ExternalParentSourceInterface;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ParentLookup;
use Qualimetrix\Core\Ast\NameResolution;
use Qualimetrix\Core\Ast\ResolvedName;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Infrastructure\Composer\Contract\AnalysedInstallAnchorInterface;

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

    public function observedRootOmissions(): array
    {
        return $this->map->observedRootOmissions();
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

        // Without this the parent arrives as the source wrote it --
        // `class ResponseHeaderBag extends HeaderBag` yields the bare
        // `HeaderBag` -- which places nothing and reads as a broken chain. Most
        // parents are written relatively, so this is the common path.
        try {
            $ast = $this->parser->parse($source) ?? [];
            NameResolution::resolve($ast, new Throwing());
        } catch (ParserError) {
            // A file this PHP version cannot parse or resolve is a chain that
            // stops being readable, not a depth to report.
            return ParentLookup::notPlaced();
        }

        try {
            return $this->declaredParent($ast, $fqcn);
        } catch (LogicException) {
            return ParentLookup::notPlaced();
        }
    }

    /**
     * @param Node[] $ast
     */
    private function declaredParent(array $ast, string $fqcn): ParentLookup
    {
        $finder = new NodeFinder();
        /** @var list<Node\Stmt\Class_> $classes */
        $classes = $finder->findInstanceOf($ast, Node\Stmt\Class_::class);
        $class = self::matchingClass($classes, $fqcn);
        if ($class !== null) {
            return self::classParent($class);
        }

        /** @var list<Node\Stmt\Interface_> $interfaces */
        $interfaces = $finder->findInstanceOf($ast, Node\Stmt\Interface_::class);
        $interface = self::matchingInterface($interfaces, $fqcn);

        return $interface !== null ? self::interfaceParent($interface) : ParentLookup::notPlaced();
    }

    /**
     * @param list<Node\Stmt\Class_> $classes
     */
    private static function matchingClass(array $classes, string $fqcn): ?Node\Stmt\Class_
    {
        foreach ($classes as $class) {
            if (self::sameIdentity($class->namespacedName?->toString(), $fqcn)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @param list<Node\Stmt\Interface_> $interfaces
     */
    private static function matchingInterface(array $interfaces, string $fqcn): ?Node\Stmt\Interface_
    {
        foreach ($interfaces as $interface) {
            if (self::sameIdentity($interface->namespacedName?->toString(), $fqcn)) {
                return $interface;
            }
        }

        return null;
    }

    private static function classParent(Node\Stmt\Class_ $class): ParentLookup
    {
        return $class->extends === null
            ? ParentLookup::root()
            : ParentLookup::extending(ResolvedName::className($class->extends)
                ?? throw new LogicException('A parent class name did not resolve'));
    }

    private static function interfaceParent(Node\Stmt\Interface_ $interface): ParentLookup
    {
        // An interface may extend several; DIT is a single chain, so the first
        // is the one this metric follows.
        return $interface->extends === []
            ? ParentLookup::root()
            : ParentLookup::extending(ResolvedName::className($interface->extends[0])
                ?? throw new LogicException('A parent interface name did not resolve'));
    }

    private static function sameIdentity(?string $declared, string $requested): bool
    {
        return $declared !== null
            && ClassNameSpelling::fold($declared) === ClassNameSpelling::fold(ltrim($requested, '\\'));
    }
}
