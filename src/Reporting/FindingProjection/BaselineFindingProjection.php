<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;

use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;

/** Judges one configured baseline and publishes its late audit. */
final readonly class BaselineFindingProjection
{
    public function __construct(
        private BaselineLoader $loader,
        private ChannelDeclarationRegistryInterface $declarations,
        private UnusedEntryAudit $audit,
    ) {}

    /**
     * @param list<Finding> $findings
     *
     * @return array{ceiling: CeilingOutcome, audit: list<Finding>, scope: list<string>, auditPublished: bool, population: JudgedPopulation}
     */
    public function project(array $findings, FindingProjectionOptions $options, ChannelPublication $publication): array
    {
        $coverage = $options->runCoverage ?? throw new LogicException('Baseline projection requires current run coverage');
        $ruleCoverage = $options->ruleCoverage ?? throw new LogicException('Baseline projection requires rule publication');
        $document = $options->baselineDocument ?? throw new LogicException('Baseline projection requires a baseline document');
        $baseline = $this->loader->load($document);
        $stage = new BaselineCeilingStage(
            $baseline,
            $this->declarations,
            $coverage,
            $ruleCoverage->classify(array_map(static fn($entry) => $entry->identity, $baseline->entries)),
        );
        $ceiling = $stage->judgeAll($findings);
        $audit = $this->audit->auditResult($ceiling, $document->path, $publication);

        return [
            'ceiling' => $ceiling,
            'audit' => $audit['findings'],
            'population' => $audit['population'],
            'scope' => $stage->baselineScope(),
            'auditPublished' => $audit['findings'] !== [] || ($ceiling->staleEntries === [] && $ceiling->inertEntries === []),
        ];
    }
}
