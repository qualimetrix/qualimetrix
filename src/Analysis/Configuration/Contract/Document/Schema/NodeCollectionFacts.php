<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

final readonly class NodeCollectionFacts
{
    public function __construct(public NodeSchema $element, public BareElementPolicy $bareElement = BareElementPolicy::ListOnly) {}

    public function describe(): string
    {
        return $this->bareElement === BareElementPolicy::SingleAllowed ? 'a list or one element' : 'a list';
    }
}
