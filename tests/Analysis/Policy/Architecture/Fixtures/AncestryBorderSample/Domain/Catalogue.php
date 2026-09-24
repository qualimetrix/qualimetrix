<?php

declare(strict_types=1);

namespace Fixtures\AncestryBorderSample\Domain;

use ArrayIterator;
use Fixtures\AncestryBorderSample\Infra\Db;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Db>
 */
final class Catalogue implements IteratorAggregate
{
    public function __construct(public Db $db) {}

    public function getIterator(): Traversable
    {
        return new ArrayIterator([$this->db]);
    }
}
