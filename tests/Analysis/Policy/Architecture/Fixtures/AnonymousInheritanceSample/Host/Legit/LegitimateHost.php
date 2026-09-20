<?php

declare(strict_types=1);

namespace Fixtures\AnonymousInheritanceSample\Host\Legit;

use Fixtures\AnonymousInheritanceSample\Marker\Iface;
use Fixtures\AnonymousInheritanceSample\Marker\L1;
use Fixtures\AnonymousInheritanceSample\Marker\Mark;
use Fixtures\AnonymousInheritanceSample\Sink\Sink;

/**
 * Positive control: a REAL named subclass/implementor/attribute-bearer, not
 * an anonymous one. Proves the cure does not blind membership to a
 * legitimate own declaration. Extends L1 (so transitively also L0),
 * implements Iface, and carries #[Mark].
 */
#[Mark]
final class LegitimateHost extends L1 implements Iface
{
    public function useSink(Sink $sink): void {}
}
