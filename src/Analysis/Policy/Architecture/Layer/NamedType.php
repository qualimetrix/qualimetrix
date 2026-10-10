<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;

/** A named-type criterion together with the configuration value that declared it. */
final readonly class NamedType
{
    public function __construct(
        public string $fqn,
        public Provenance $provenance,
    ) {
        if ($fqn === '') {
            throw new InvalidArgumentException('NamedType FQN must not be empty.');
        }
    }
}
