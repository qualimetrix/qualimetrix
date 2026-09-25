<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * A step of one surface's comparison, registered by a declaration form, which
 * runs just before the built-in step it names ({@see SurfaceComparison::STAGES}).
 */
interface SurfaceStage extends GateExtension
{
    /** A name of {@see SurfaceComparison::STAGES}. */
    public function before(): string;

    public function applyStage(SurfacePair $pair): void;
}
