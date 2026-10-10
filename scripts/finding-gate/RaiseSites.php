<?php

declare(strict_types=1);

namespace QmxFindingGate;

use FilesystemIterator;
use PhpParser\Error;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Native PHP syntax supplies failure-class producers; source locations carry no witness obligation.
 * @phpstan-type Site array{class:string,file:string,line:int}
 */
final class RaiseSites
{
    /** @param array<string,Site> $sites
     * @param list<string> $problems
     * @param list<string> $classes
     */
    private function __construct(public readonly array $sites, public readonly array $problems, public readonly array $classes) {}

    public static function of(string $directory): self
    {
        if (!class_exists(ParserFactory::class)) {
            require_once \dirname(__DIR__, 2) . '/vendor/autoload.php';
        }
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $sites = [];
        $problems = [];
        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $name = $file->getBasename('.php');
            $relative = substr($file->getPathname(), \strlen($directory) + 1);
            if (str_starts_with($relative, 'tests/') || str_starts_with($name, 'SelfTest')
                || \in_array($name, ['CheckWitnesses', 'SyntheticTree', 'WitnessRegistry', 'RaiseSites'], true)) {
                continue;
            }
            try {
                $nodes = $parser->parse(Fs::read($file->getPathname())) ?? [];
            } catch (Error $error) {
                $problems[] = 'witness registry: ' . $relative . ' does not parse: ' . $error->getMessage();
                continue;
            }
            foreach ($finder->findInstanceOf($nodes, ClassLike::class) as $class) {
                if ($class->name !== null) {
                    $classes[] = $class->name->toString();
                }
            }
            foreach ($finder->find($nodes, static fn($node): bool => ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
                && $node->name instanceof Identifier && $node->name->toLowerString() === 'fail') as $call) {
                if (!$call instanceof MethodCall && !$call instanceof NullsafeMethodCall) {
                    continue;
                }
                $argument = $call->getArgs()[0] ?? null;
                $value = $argument instanceof Arg ? $argument->value : null;
                $constant = $value instanceof ClassConstFetch && $value->class instanceof Name
                    && $value->class->getLast() === 'FailureClass' && $value->name instanceof Identifier
                    ? FailureClass::class . '::' . $value->name->toString() : null;
                $failure = $constant !== null && \defined($constant) ? \constant($constant) : null;
                if (!\is_string($failure) || !\in_array($failure, FailureClass::ALL, true)) {
                    $problems[] = 'witness registry: ' . $relative . ':' . $call->getStartLine() . ' raises an unknown or indirect failure class.';
                    continue;
                }
                $sites[$relative . ':' . $call->getStartFilePos()] = ['class' => $failure, 'file' => $file->getPathname(), 'line' => $call->getStartLine()];
            }
        }
        return new self($sites, $problems, array_values(array_unique($classes)));
    }
}
