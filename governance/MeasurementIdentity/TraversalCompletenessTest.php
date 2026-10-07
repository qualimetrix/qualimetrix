<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\MeasurementIdentity;

use FilesystemIterator;
use PhpParser\NodeVisitor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Keep literal traversal-control names out of shared production AST visitors.
 *
 * php-parser stops calling remaining visitors when one cuts a shared traversal
 * short. The registrar and every producer must see the same nodes for stable
 * declaration numbering. This source scan guards literal names, not dynamic or
 * indirect runtime returns.
 */
final class TraversalCompletenessTest extends TestCase
{
    private const array FORBIDDEN = [
        'DONT_TRAVERSE_CHILDREN',
        'DONT_TRAVERSE_CURRENT_AND_CHILDREN',
        'STOP_TRAVERSAL',
        'REMOVE_NODE',
    ];

    /** AttemptWork searches one expression with its own traverser, outside the shared collector pass. */
    private const array PRIVATE_EXPRESSION_QUERY_CONTROL = [
        'src/Analysis/Evidence/CodeSmell/ControlFlow/AttemptWork.php' => [
            'DONT_TRAVERSE_CHILDREN',
            'STOP_TRAVERSAL',
        ],
    ];

    /**
     * The literal names must still exist on the php-parser visitor contract,
     * and the scan must have production files to read.
     */
    #[Test]
    public function itUsesForbiddenNamesTakenFromTheVisitorContractAndFindsSourceFilesToScan(): void
    {
        $declared = array_keys((new ReflectionClass(NodeVisitor::class))->getConstants());

        foreach (self::FORBIDDEN as $constant) {
            self::assertContains(
                $constant,
                $declared,
                \sprintf('%s no longer declares %s, so searching for it guards nothing.', NodeVisitor::class, $constant),
            );
        }

        self::assertNotSame([], self::sourceFiles(), 'The scanned source tree is empty.');
    }

    #[Test]
    public function itRejectsUnexpectedLiteralTraversalControlNamesInProduction(): void
    {
        $offenders = [];
        $allowedSeen = [];
        foreach (self::sourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            $relativePath = substr($file, \strlen(\dirname(__DIR__, 2)) + 1);
            foreach (self::FORBIDDEN as $constant) {
                if (!str_contains($source, $constant)) {
                    continue;
                }
                if (\in_array($constant, self::PRIVATE_EXPRESSION_QUERY_CONTROL[$relativePath] ?? [], true)) {
                    $allowedSeen[$relativePath][] = $constant;
                    continue;
                }
                $offenders[] = $relativePath . ': ' . $constant;
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
        self::assertSame(
            self::PRIVATE_EXPRESSION_QUERY_CONTROL,
            $allowedSeen,
            'A private expression-query allowance no longer matches a literal control name in its exact file.',
        );
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
