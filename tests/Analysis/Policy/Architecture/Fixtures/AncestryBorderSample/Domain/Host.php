<?php

declare(strict_types=1);

namespace Fixtures\AncestryBorderSample\Domain;

use AllowDynamicProperties;
use Fixtures\AncestryBorderSample\Infra\Db;
use JsonSerializable;

final class Host
{
    public function __construct(public Db $db) {}

    public function describe(): object
    {
        return new #[AllowDynamicProperties] class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return [];
            }
        };
    }
}
