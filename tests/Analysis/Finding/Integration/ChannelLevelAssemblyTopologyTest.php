<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * A level reaches a channel code through {@see ViolationChannel::leveled()}
 * and nowhere else.
 *
 * Two questions, and only the second one is the guard that matters. The
 * first — "is a level suffix written out as a string?" — is what a grep
 * would ask, and `CboRule` passed a grep for years while the ternary
 * `$level === Namespace_ ? '.namespace' : '.class'` sat in it: the literal
 * was there, but so was the enum, and nothing said the two agreed. The
 * second question — "how many places assemble a level into a name?" — is
 * the property the vocabulary actually needs, because it is the number of
 * places that have to change when the level leaves the channel name.
 *
 * The level vocabulary is read from {@see SymbolLevel::cases()} rather than
 * spelled out here: a sixth level must be covered by this guard the day it
 * is added, not the day someone remembers this file.
 */
#[CoversClass(ViolationChannel::class)]
final class ChannelLevelAssemblyTopologyTest extends TestCase
{
    private const string ASSEMBLY_POINT = 'src/Analysis/Finding/Contract/ViolationChannel.php';

    #[Test]
    public function noProductionSourceSpellsALevelSuffixAsALiteral(): void
    {
        $suffixes = array_map(static fn(SymbolLevel $level): string => '.' . $level->value, SymbolLevel::cases());
        $offenders = [];

        foreach (self::productionFiles() as $file) {
            $finder = new NodeFinder();
            /** @var list<String_> $strings */
            $strings = $finder->findInstanceOf(self::parse($file), String_::class);

            foreach ($strings as $string) {
                if (\in_array($string->value, $suffixes, true)) {
                    $offenders[] = \sprintf('%s:%d "%s"', self::relative($file), $string->getStartLine(), $string->value);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A level suffix written as a string literal is a second spelling of ' . SymbolLevel::class
            . '. Build the code through ViolationChannel::leveled() instead.',
        );
    }

    #[Test]
    public function exactlyOnePlaceAssemblesALevelIntoAName(): void
    {
        $points = [];

        foreach (self::productionFiles() as $file) {
            foreach (self::assemblyPointsIn($file) as $line) {
                $points[] = self::relative($file) . ':' . $line;
            }
        }

        self::assertCount(
            1,
            $points,
            'A level is concatenated into a name in more than one place: ' . implode(', ', $points)
            . '. Every such place has to change when the level leaves the channel name.',
        );
        self::assertStringStartsWith(self::ASSEMBLY_POINT . ':', $points[0]);
    }

    /**
     * Lines of `<something typed SymbolLevel>->value` inside a string
     * concatenation.
     *
     * The operand is recognised by its declared type — an enum case, or a
     * parameter or promoted property the signature types as
     * {@see SymbolLevel} — rather than by the variable's name, so renaming
     * `$level` cannot hide an assembly point from this count.
     *
     * @return list<int>
     */
    private static function assemblyPointsIn(string $file): array
    {
        $finder = new NodeFinder();
        $lines = [];

        /** @var list<FunctionLike> $scopes */
        $scopes = $finder->find(self::parse($file), static fn(Node $node): bool => $node instanceof FunctionLike);

        foreach ($scopes as $scope) {
            $levelVariables = [];

            foreach ($scope->getParams() as $param) {
                if ($param->type instanceof Name
                    && $param->type->getLast() === 'SymbolLevel'
                    && $param->var instanceof Variable
                    && \is_string($param->var->name)
                ) {
                    $levelVariables[] = $param->var->name;
                }
            }

            /** @var list<Concat> $concats */
            $concats = $finder->findInstanceOf($scope, Concat::class);

            foreach ($concats as $concat) {
                foreach ([$concat->left, $concat->right] as $operand) {
                    if (self::isLevelValueRead($operand, $levelVariables)) {
                        $lines[] = $concat->getStartLine();
                    }
                }
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * @param list<string> $levelVariables
     */
    private static function isLevelValueRead(Node $operand, array $levelVariables): bool
    {
        if (!$operand instanceof PropertyFetch
            || !$operand->name instanceof Identifier
            || $operand->name->toString() !== 'value'
        ) {
            return false;
        }

        if ($operand->var instanceof ClassConstFetch && $operand->var->class instanceof Name) {
            return $operand->var->class->getLast() === 'SymbolLevel';
        }

        return $operand->var instanceof Variable
            && \is_string($operand->var->name)
            && \in_array($operand->var->name, $levelVariables, true);
    }

    /**
     * @return list<string>
     */
    private static function productionFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::sourceRoot(), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<Node>
     */
    private static function parse(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $file));
        }

        return (new ParserFactory())->createForHostVersion()->parse($contents) ?? [];
    }

    private static function sourceRoot(): string
    {
        return \dirname(__DIR__, 4) . '/src';
    }

    private static function relative(string $file): string
    {
        return 'src' . substr($file, \strlen(self::sourceRoot()));
    }
}
