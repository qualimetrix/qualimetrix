<?php

namespace Corpus\ExternalParent;

// The parent is deliberately outside the analysed path and outside any install
// this run can see, which is what makes it reach external resolution at all.
// Stage 02 of the campaign gives the case an autoload map that can find it.
class LocalWidget extends \Corpus\Upstream\Widget
{
}
