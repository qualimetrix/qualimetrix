<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ModularOwnership;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `Analysis\Run` reaches the dependency graph through the traversal contract
 * and never through the extraction internals behind it.
 *
 * Read as text rather than as imports, because the defect this guards is an
 * inline fully-qualified name as much as a `use` statement, and only the source
 * carries both. It sits here, not beside the analyzer's unit cases, because the
 * subject is where one owner may reach, not what the analyzer computes.
 */
final class DependencyGraphAnalyzerTraversalSeamTest extends TestCase
{
    private const string ANALYZER = 'src/Analysis/Run/Pipeline/DependencyGraphAnalyzer.php';

    private const string BANNED_SEGMENT = 'DependencyModel\\Extraction';

    #[Test]
    public function itKeepsTheRunPipelineOutOfDependencyModelExtraction(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/' . self::ANALYZER);

        self::assertIsString($source, self::ANALYZER . ' must be readable for this control to judge anything');
        self::assertStringNotContainsString(self::BANNED_SEGMENT, $source, \sprintf(
            "%s names %s. Analysis.Run owns no part of that namespace: reach the graph through the\n"
            . 'traversal participant contract instead.',
            self::ANALYZER,
            self::BANNED_SEGMENT,
        ));
    }

    /**
     * The corpus is one named file, so the control's own failure mode is that
     * the file moved and the read silently found nothing. Proving it can refuse
     * needs a string it must reject, handed to the same assertion.
     */
    #[Test]
    public function itRefusesASourceThatNamesTheExtractionNamespace(): void
    {
        self::assertStringContainsString(
            self::BANNED_SEGMENT,
            'use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;',
        );
        self::assertStringNotContainsString(
            self::BANNED_SEGMENT,
            'use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;',
        );
    }
}
