<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;

/** A name one layer wrote into a map whose vocabulary is known only after the merge. */
final readonly class PendingName
{
    /** @param list<string> $mapPath canonical path of the named map */
    public function __construct(
        public array $mapPath,
        public string $name,
        public NameVocabulary $vocabulary,
        public Provenance $provenance,
    ) {}
}
