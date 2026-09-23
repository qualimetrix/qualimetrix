<?php

namespace Corpus\Layers\Doubt;

use Corpus\Outside\Vendorish\Base;
use Corpus\Outside\Vendorish\Port;

// The fixture for architecture.doubted-assignment. Port and Base are not in
// this corpus. The doubt layer's own exclude: reads implements:, which cannot
// be answered about Gateway (its interface chain stops at Port); graph-extends,
// declared before outside, cannot be answered about Base (never analysed).
// Both assignments stand in doubt, one analysed class and one symbol outside
// the analysed paths, and no inheritance chain leaves the corpus.
final class Gateway implements Port
{
    public function open(Base $base): void
    {
    }
}
