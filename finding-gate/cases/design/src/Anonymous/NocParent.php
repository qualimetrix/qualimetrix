<?php

namespace Corpus\Design\Anonymous;

// No named class in this corpus extends NocParent. Its design.noc must
// stay 0. A defect that lends an anonymous subclass's extends edge to the
// class enclosing that anonymous class would report design.noc = 1 here,
// even though NocHost below does not itself extend NocParent.
class NocParent
{
}
