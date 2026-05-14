<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Architecture;

use RuntimeException;

/**
 * Thrown by {@see LayerExpansionStage::expand()} on conditions that make
 * the expansion result meaningless or unsafe to consume downstream:
 *
 * - Cartesian-blowup ceiling exceeded ({@code architecture.max_expanded_layers}).
 * - Name collision between a static layer and a template-expanded layer.
 * - Name collision between two template-expanded layers.
 * - Invalid concrete name produced by substitution (binding contains
 *   characters that violate {@see \Qualimetrix\Core\Architecture\Layer\LayerDefinition}'s
 *   expanded-mode name regex).
 *
 * Surfaces as a runtime error rather than a configuration error because the
 * triggering condition is discovered only after the project's class set is
 * known — the same template definition can be valid in one codebase and
 * collide in another.
 */
final class LayerExpansionException extends RuntimeException {}
