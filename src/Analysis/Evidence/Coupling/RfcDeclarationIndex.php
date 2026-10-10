<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;

/** Indexes exact collected declarations without choosing duplicate logical names. */
final class RfcDeclarationIndex
{
    /** @var array<string, RfcDeclarationFacts> */
    private array $declarations = [];

    /** @var array<string, list<RfcDeclarationFacts>> */
    private array $names = [];

    /** @var array<string, list<string>> */
    private array $parents = [];

    public function __construct(DependencyGraphInterface $graph, MetricRepositoryInterface $repository)
    {
        $members = self::members($repository);
        foreach ($repository->allClassDeclarations() as $info) {
            $this->read($info, $repository, $members);
        }
        foreach ($graph->getDeclarationDependencies() as $edge) {
            if ($edge->type === DependencyType::Extends && !$edge->describesNestedAnonymousClass) {
                $this->parents[$edge->source->toCanonical()][] = $edge->target->symbolPath->toString();
            }
        }
    }

    public function unique(string $name): ?RfcDeclarationFacts
    {
        $choices = $this->names[ClassNameSpelling::fold($name)] ?? [];

        return \count($choices) === 1 ? $choices[0] : null;
    }

    public function namedReceiver(string $name): RfcDeclarationFacts|string|null
    {
        $choices = $this->names[ClassNameSpelling::fold($name)] ?? [];

        return \count($choices) === 1 ? $choices[0] : ($choices === [] ? $name : null);
    }

    /** @return iterable<RfcDeclarationFacts> */
    public function declarations(): iterable
    {
        return $this->declarations;
    }

    /** @return list<string> */
    public function parents(RfcDeclarationFacts $facts): array
    {
        return $this->parents[$facts->declaration->toCanonical()] ?? [];
    }

    /** @return array<string, array<string, true>> */
    private static function members(MetricRepositoryInterface $repository): array
    {
        $members = [];
        foreach ($repository->allCallables() as $callable) {
            if ($callable->classAggregationOwner !== null && $callable->symbolPath->member !== null) {
                $member = $callable->callableKind === CallableKind::PropertyHook ? $callable->symbolPath->member : strtolower($callable->symbolPath->member);
                $members[$callable->classAggregationOwner->toCanonical()][$member] = true;
            }
        }

        return $members;
    }

    /** @param array<string, array<string, true>> $members */
    private function read(SymbolInfo $info, MetricRepositoryInterface $repository, array $members): void
    {
        $declaration = $info->subject?->declarationPath();
        if ($declaration === null) {
            return;
        }
        $rows = $repository->getSubject(MetricSubject::declaration($declaration))->entries(RfcCollector::FACTS);
        if ($rows === []) {
            return;
        }
        $facts = new RfcDeclarationFacts($declaration, $rows, $members[$declaration->toCanonical()] ?? []);
        $this->declarations[$declaration->toCanonical()] = $facts;
        $this->names[ClassNameSpelling::fold($declaration->logical->toString())][] = $facts;
    }
}
