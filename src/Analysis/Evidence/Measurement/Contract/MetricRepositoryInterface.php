<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\MixedSpelling;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Mutable Measurement repository promise shared by collection, aggregation, and rules.
 *
 * @qmx-threshold coupling.cbo 54 -- Collection, aggregation and rules share the exact-subject repository promise; splitting its reads transfers their common identity dependencies.
 */
interface MetricRepositoryInterface
{
    /** Returns a merged repository, or null when implementations are incompatible. */
    public function mergedWith(self $other): ?self;

    /** Returns file, namespace, or project aggregate metrics; declarations require getSubject(). */
    public function get(SymbolPath $symbol): MetricBag;

    /**
     * Returns callable declarations or file, namespace, and project aggregates.
     * Class enumeration requires allClassDeclarations() or allLogicalClasses().
     *
     * The key is the level, not the declaration kind: a caller asking for
     * {@see SymbolLevel::Callable} gets methods and global functions in one
     * enumeration, and reads the kind off each symbol when it needs it.
     * That query is the same enumeration as {@see self::allCallables()}.
     *
     * @return iterable<SymbolInfo>
     */
    public function all(SymbolLevel $level): iterable;

    /**
     * Checks if metrics exist for given symbol.
     */
    public function has(SymbolPath $symbol): bool;

    /**
     * Adds or merges aggregate or logical-class metrics.
     *
     * If the symbol already has metrics, new metrics are merged (new values override).
     *
     * @param SymbolPath $symbol The symbol to add metrics for
     * @param MetricBag $metrics The metrics to add
     * @param ?RelativePath $file The source file path; null for symbols without one owning file
     * @param ?int $line The line number (null for aggregated/namespace metrics)
     */
    public function add(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): void;

    /**
     * Returns metrics for an exact declaration, logical class, or aggregate subject.
     * An exact class view overlays graph metrics owned by its logical name. If
     * several declarations share that name, each view samples those same graph
     * values once; exact declaration metrics remain separate.
     */
    public function getSubject(MetricSubject $subject): MetricBag;

    /** Checks whether an exact declaration, logical class, or aggregate subject exists. */
    public function hasSubject(MetricSubject $subject): bool;

    /** Adds or merges metrics without collapsing declaration identities to SymbolPath. */
    public function addSubject(MetricSubject $subject, MetricBag $metrics, ?RelativePath $file, ?int $line): void;

    /** Adds or merges one callable while preserving its declaration metadata. */
    public function addCallable(CallableWithMetrics $callable): void;

    /** @return iterable<SymbolInfo> exact declaration subjects */
    public function allDeclarations(): iterable;

    /**
     * @return iterable<SymbolInfo> exact callable declaration subjects — the
     *                              same enumeration as `all(SymbolLevel::Callable)`
     */
    public function allCallables(): iterable;

    /** @return iterable<SymbolInfo> logical class subjects */
    public function allLogicalClasses(): iterable;

    /** @return iterable<SymbolInfo> exact class declarations */
    public function allClassDeclarations(): iterable;

    /**
     * Adds a single scalar metric to an existing symbol.
     *
     * Unlike add(), this only touches scalar metrics and never duplicates
     * DataBag entries. Use this when you need to enrich existing symbols
     * with computed metrics (e.g., in global collectors).
     *
     * If the symbol does not exist, the metric is silently ignored.
     */
    public function addScalar(SymbolPath $symbol, string $key, int|float $value): void;

    /**
     * Adds a single scalar metric to an existing exact subject.
     *
     * The declaration-addressed counterpart of {@see self::addScalar()}, and
     * the reason it exists rather than callers passing a one-key bag: a global
     * collector enriching declarations would otherwise have to build a
     * {@see MetricBag} per declaration, which makes every such collector a
     * dependent of that class for no other reason.
     *
     * If the subject does not exist, the metric is silently ignored.
     */
    public function addSubjectScalar(MetricSubject $subject, string $key, int|float $value): void;

    /**
     * Returns all namespaces that have metrics.
     *
     * @return list<string>
     */
    public function getNamespaces(): array;

    /**
     * Returns all metrics for symbols in a given namespace.
     *
     * @return list<SymbolInfo>
     */
    public function forNamespace(string $namespace): array;

    /** @return list<MixedSpelling> case-insensitive identities seen with distinct spellings */
    public function mixedSpellings(): array;
}
