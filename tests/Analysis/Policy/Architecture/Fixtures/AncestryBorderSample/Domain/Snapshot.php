<?php

declare(strict_types=1);

namespace Fixtures\AncestryBorderSample\Domain;

use Fixtures\AncestryBorderSample\Infra\Db;
use JsonSerializable;

final class Snapshot implements JsonSerializable
{
    public function __construct(public Db $db) {}

    public function jsonSerialize(): mixed
    {
        return [];
    }
}
