<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;

/**
 * One configuration file read twice over: as written for the document engine,
 * and as normalized layer values. Finding temporarily reads its three raw
 * rule inputs while its resolver still owns that fold.
 *
 * A refusal of the normalized layer values is held rather than thrown, so the engine
 * judges the whole written document first and a refusal of a root it declares
 * comes in its words, naming the layer.
 */
final readonly class LoadedDocument
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public AuthoredNode $authored,
        public array $values,
        public ?ConfigurationRefusal $deferredRefusal = null,
    ) {}
}
