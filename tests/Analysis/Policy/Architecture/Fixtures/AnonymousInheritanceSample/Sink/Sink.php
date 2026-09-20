<?php

declare(strict_types=1);

namespace Fixtures\AnonymousInheritanceSample\Sink;

/**
 * Dependency target. A Host class typed on Sink produces a cross-layer edge
 * that only turns into an `architecture.layer-violation` finding when the
 * Host class itself is classified into a self-allow-only layer — the
 * finding's presence (or absence) is the observable evidence of
 * classification used throughout this fixture.
 */
final class Sink {}
