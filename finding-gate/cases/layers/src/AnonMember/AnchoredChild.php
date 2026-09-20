<?php

namespace Corpus\Layers\AnonMember;

use Corpus\Layers\GraphBase\Marker;

// A real, named member of both graph-extends (extends Marker for real) and
// anon-member (its own namespace). It resolves to graph-extends -- declared
// first -- keeping that layer reachable on both sides of the comparison,
// and it is what anon-member's own exclude clause removes on both sides too
// (real extends, unaffected by the anonymous-class defect), so that clause
// is never reported as unmatched regardless of which side ExtendsHost's
// anonymous class lands its extends edge on. AnchoredChild itself has no
// outgoing dependency, so it produces no violation of its own.
class AnchoredChild extends Marker
{
}
