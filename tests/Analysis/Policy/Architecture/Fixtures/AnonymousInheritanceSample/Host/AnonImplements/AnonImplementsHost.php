<?php

declare(strict_types=1);

namespace Fixtures\AnonymousInheritanceSample\Host\AnonImplements;

use Fixtures\AnonymousInheritanceSample\Marker\Iface;
use Fixtures\AnonymousInheritanceSample\Sink\Sink;

/**
 * Implements nothing itself. The nested anonymous class implements Iface —
 * that fact belongs to the anonymous class, not to AnonImplementsHost.
 */
final class AnonImplementsHost
{
    public function build(): object
    {
        return new class implements Iface {};
    }

    public function useSink(Sink $sink): void {}
}
