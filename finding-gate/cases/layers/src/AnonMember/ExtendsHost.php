<?php

namespace Corpus\Layers\AnonMember;

use Corpus\Layers\GraphBase\Marker;
use Corpus\Layers\Repository\UserRepository;

// ExtendsHost itself matches no layer by patterns or suffix. Only the
// anonymous class it builds extends Marker. A defect that lends that
// extends edge to ExtendsHost would put ExtendsHost in the graph-extends
// layer, whose empty allow-list turns the dependency on UserRepository
// below into a layer-violation finding that a correct attribution does
// not produce.
class ExtendsHost
{
    public function __construct(private UserRepository $repo)
    {
    }

    public function fetch(int $id): ?string
    {
        return $this->repo->find($id);
    }

    public function build(): object
    {
        return new class extends Marker {
        };
    }
}
