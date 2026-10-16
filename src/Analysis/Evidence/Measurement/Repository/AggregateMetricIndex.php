<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

/** Project, file, and namespace aggregate metrics and their source information. */
final class AggregateMetricIndex
{
    public static function assertAggregateSymbol(SymbolPath $symbol): void
    {
        if (\in_array($symbol->getType(), [SymbolType::Class_, SymbolType::Method, SymbolType::Function_], true)) {
            throw new LogicException('Declaration metrics require an exact subject');
        }
    }

    /** @var array<string, MetricBag> */
    private array $metrics = [];

    /** @var array<string, SymbolInfo> */
    private array $infos = [];

    public function get(SymbolPath $symbol): ?MetricBag
    {
        return $this->metrics[$symbol->toCanonical()] ?? null;
    }

    public function has(SymbolPath $symbol): bool
    {
        return isset($this->metrics[$symbol->toCanonical()]);
    }

    public function add(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): SymbolInfo
    {
        if (\in_array($symbol->getType(), [SymbolType::Namespace_, SymbolType::Project], true)) {
            $file = null;
            $line = null;
        }
        $canonical = $symbol->toCanonical();
        $info = new SymbolInfo($symbol, $file, $line);
        if (isset($this->metrics[$canonical])) {
            $this->metrics[$canonical] = $this->metrics[$canonical]->merge($metrics);
            $this->infos[$canonical] = RepositoryMerge::plainInfo($this->infos[$canonical], $info);
        } else {
            $this->metrics[$canonical] = $metrics;
            $this->infos[$canonical] = $info;
        }

        return $this->infos[$canonical];
    }

    public function addScalar(SymbolPath $symbol, string $key, int|float $value): void
    {
        $canonical = $symbol->toCanonical();
        if (isset($this->metrics[$canonical])) {
            $this->metrics[$canonical] = $this->metrics[$canonical]->with($key, $value);
        }
    }

    /** @return array<string, SymbolInfo> */
    public function infos(): array
    {
        return $this->infos;
    }

    /** @return iterable<SymbolInfo> */
    public function all(SymbolLevel $level): iterable
    {
        foreach ($this->infos as $info) {
            if (SymbolLevelProjection::ofDeclaration($info->symbolPath->getType()) === $level) {
                yield $info;
            }
        }
    }

    public function mergeWith(self $other, NamespaceMetricIndex $namespaceSpellings): self
    {
        $merged = new self();
        foreach ([$this, $other] as $source) {
            foreach ($source->infos as $key => $info) {
                $symbol = $info->symbolPath;
                if ($symbol->getType() === SymbolType::Namespace_) {
                    $symbol = SymbolPath::forNamespace($namespaceSpellings->canonical($symbol->namespace ?? ''));
                }
                $merged->add($symbol, $source->metrics[$key], $info->file, $info->line);
            }
        }

        return $merged;
    }

    public function moveNamespace(string $previous, string $canonical): void
    {
        $previousKey = SymbolPath::forNamespace($previous)->toCanonical();
        if (!isset($this->metrics[$previousKey])) {
            return;
        }

        $symbol = SymbolPath::forNamespace($canonical);
        $canonicalKey = $symbol->toCanonical();
        $previousInfo = $this->infos[$previousKey];
        $this->metrics[$canonicalKey] = $this->metrics[$previousKey];
        $this->infos[$canonicalKey] = new SymbolInfo($symbol, $previousInfo->file, $previousInfo->line);
        unset($this->metrics[$previousKey], $this->infos[$previousKey]);
    }
}
