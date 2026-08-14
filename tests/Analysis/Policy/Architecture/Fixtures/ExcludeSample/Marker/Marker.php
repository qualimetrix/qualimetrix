<?php

declare(strict_types=1);

namespace Fixtures\ExcludeSample\Marker;

/**
 * Shared dependency target for every fixture class. Every classified layer
 * has a self-only allow-list, so any dependency on {@see Marker} produces
 * an architecture violation whose source FQN is the only evidence of layer
 * assignment.
 */
final class Marker {}
