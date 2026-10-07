<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Project, file, and namespace aggregate metrics and their source information. */
final class AggregateMetricIndex
{
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

    public function mergeWith(self $other): self
    {
        $merged = new self();
        $plain = RepositoryMerge::plain($this->metrics, $this->infos, $other->metrics, $other->infos);
        $merged->metrics = $plain['metrics'];
        $merged->infos = $plain['infos'];

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
