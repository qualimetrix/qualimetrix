<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

/** A name written with no body under it. */
interface ResolvedBareNameInterface extends ResolvedValueInterface
{
    /** Nothing was written under the name. */
    public function plain(): null;
}
