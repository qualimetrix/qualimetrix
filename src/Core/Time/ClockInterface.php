<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Time;

use DateTimeImmutable;

/**
 * Answers "what time is it?" — the single fact a component needs when it
 * has to stamp its output with a moment.
 *
 * Why this exists rather than `new DateTimeImmutable()` at the call site:
 * a written artefact whose bytes are asserted (the baseline file's
 * `generated` field) cannot be byte-stable while its only clock is the wall
 * clock. Injecting the reading makes the whole file deterministic for one
 * analysis, which is the property §6 of the baseline contract commits to.
 *
 * Why it lives in `Core\Time` rather than in the one package that needs it
 * today: the subject is "the current time", and every subject that stamps an
 * artefact would otherwise carry its own copy — the duplication test for a
 * legitimate cross-cutting primitive (ADR 0016). It sits beside
 * {@see \Qualimetrix\Core\Path}, `Core\Progress` and `Core\Profiler`, which
 * are primitives of exactly this granularity.
 *
 * Why not `psr/clock`: it would add a runtime composer dependency for one
 * method. The contract below is that method; should the project ever depend
 * on `psr/clock` for another reason, {@see SystemClock} is the only place
 * that has to learn about it.
 */
interface ClockInterface
{
    /**
     * The current moment.
     */
    public function now(): DateTimeImmutable;
}
