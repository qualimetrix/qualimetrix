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
        \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder::class => 'Only finite positive float weights from native ConstantNode values checked by WeightedHealthFormula::weightOf are encoded, preserving the JSON fraction.',
        \Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule::class => 'Native copy ordinals and content hashes identify the copy; its existing canonical file subject escapes invalid source bytes.',
        \Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation::class => 'Group keys contain validated producer/channel metadata, declared gate reasons and closed units; source examples remain outside the encoded key.',
        \Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity::class => 'Source authority, cycle member and edge endpoint strings are framed; symbol canonicals already escape invalid source bytes.',
        \Qualimetrix\Analysis\Finding\Population\PopulationTrace::class => 'Group keys contain validated producer/channel metadata, declared gate reasons and closed units; native source identity examples are stored separately.',
        \Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit::class => 'Configured selectors and channel names originate in parsed UTF-8 configuration; native ordinal preserves duplicate positions.',
        \Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration\LayerDeclarationRule::class => 'Layer names originate in parsed UTF-8 architecture configuration; the pair records native precedence.',
        \Qualimetrix\Analysis\Policy\Architecture\Observation\EdgeEvidenceWalk::class => 'Dependency endpoints are byte-safe canonical SymbolPath identities; the dependency type is closed vocabulary.',
        \Qualimetrix\Analysis\Policy\Inline\Directive\Audit\DirectiveUsagePopulation::class => 'Authored file, form and target strings are byte-framed; native line and position are numeric.',
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
