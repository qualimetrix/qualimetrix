<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

use InvalidArgumentException;

/**
 * Thrown by {@see LayerDefinition} on construction-time validation failures.
 *
 * Examples of conditions that trigger this exception:
 * - The layer name is empty or violates the naming pattern `[a-z][a-z0-9_-]*`.
 * - The list of patterns is empty.
 * - Any individual pattern is an empty string.
 */
final class InvalidLayerDefinitionException extends InvalidArgumentException {}
