<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

use RuntimeException;

/**
 * Retired: every throw site in {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage::expand()}
 * and {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerInstantiator::instantiate()}
 * now raises {@see \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal}
 * instead. This class and its remaining consumers are kept only until a
 * later cleanup removes them.
 *
 * The conditions it used to surface — a cumulative expansion ceiling
 * exceeded, a name collision between two expanded layers, or an invalid
 * substituted name — are a configuration refusal the author of the template
 * layer fixes, not an internal crash: the refusal's kind is decided by who
 * fixes it, not by which phase discovered it. That the triggering condition
 * is only knowable once the project's class set has been observed does not
 * change who is responsible for the fix.
 */
final class ArchitecturePreparationException extends RuntimeException {}
