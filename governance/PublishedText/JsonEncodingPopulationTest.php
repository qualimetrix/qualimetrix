<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\PublishedText;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A new encoder can abort an analysis before its publication regression runs.
 * This census requires a decision for a new direct encoder owner. It does not
 * infer dataflow, inspect dynamic function calls, or judge another call added
 * to an owner already listed here. PHP syntax belongs to the native parser.
 */
final class JsonEncodingPopulationTest extends TestCase
{
    /** @var array<class-string, string> */
    private const array OWNERS = [
        \Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateContentMerger::class => 'Source strings are framed before hashing.',
        \Qualimetrix\Analysis\Finding\Contract\OccurrenceKey::class => 'Source kind, evidence names and values are framed before hashing.',
        \Qualimetrix\Analysis\Policy\Architecture\Layer\UnmatchedTypeOccurrence::class => 'Named types and provenance come from parsed UTF-8 configuration.',
        \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentLayout::class => 'Source identity keys are already escaped; raw scope is validated before publication.',
        \Qualimetrix\Analysis\Policy\Baseline\BaselineEntryPayload::class => 'Input was already accepted by json_decode.',
        \Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry::class => 'Input was already accepted by json_decode.',
        \Qualimetrix\Infrastructure\Console\Command\ChannelRenameReporter::class => 'Renamed channels come from parsed baseline documents.',
        \Qualimetrix\Infrastructure\Logging\LoggerHelperTrait::class => 'Invalid strings are escaped after the native UTF-8 refusal.',
        \Qualimetrix\Infrastructure\Profiler\Export\ChromeTracingExporter::class => 'Span names are product vocabulary.',
        \Qualimetrix\Infrastructure\Profiler\Export\JsonExporter::class => 'Span names are product vocabulary.',
        \Qualimetrix\Reporting\DrillDown\OutOfScopeFindings::class => 'Occurrence-preserving multiset keys contain canonical published identities.',
        \Qualimetrix\Reporting\Formatter\PublishedUtf8::class => 'Shared publication repair boundary.',
        \Qualimetrix\Reporting\ReportProjectScope::class => 'Scope reasons are configuration facts; prose encoding has no throw flag.',
    ];

    #[Test]
    public function itRequiresAnExaminedOwnerForEveryDirectJsonEncoder(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $owners = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, $file->getPathname());
            if (!str_contains(strtolower($source), 'json_encode')) {
                continue;
            }
            $nodes = $parser->parse($source) ?? [];
            $nodes = (new NodeTraverser(new NameResolver()))->traverse($nodes);
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $class) {
                $calls = $finder->find($class->stmts, static fn(Node $node): bool => $node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && strtolower($node->name->toString()) === 'json_encode');
                if ($calls !== []) {
                    self::assertNotNull($class->namespacedName, $file->getPathname());
                    $owners[] = $class->namespacedName->toString();
                }
            }
        }
        $owners = array_values(array_unique($owners));
        $examined = array_keys(self::OWNERS);
        sort($owners);
        sort($examined);
        self::assertSame($examined, $owners, 'Examine a new direct encoder owner for source-byte input; remove a stale owner when its encoder disappears.');
    }
}
