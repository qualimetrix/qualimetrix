<?php

namespace Corpus\ExternalParent;

// The parent is outside the analysed path -- only `src/` is analysed -- but
// inside an install this case carries, so the walk can read its file without
// loading it. Its own parent makes the external chain two deep, which is what
// separates "followed the chain" from "found the first link and stopped".
class LocalWidget extends \Corpus\Upstream\Widget
{
}
