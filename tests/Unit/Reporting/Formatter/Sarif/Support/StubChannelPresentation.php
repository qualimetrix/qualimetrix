<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Sarif\Support;

use Qualimetrix\Analysis\Finding\Contract\ChannelPresentation;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;

/**
 * A fixed, non-null answer for any code — these formatter-mechanics tests
 * exercise SARIF JSON shape (fingerprints, locations, schema conformance),
 * never description or `helpUri` text, so a single deterministic answer is
 * enough. Coverage of the real join lives in
 * `tests/Reporting/Formatter/Sarif/Integration/SarifRuleDescriptorCoverageTest.php`
 * (P4).
 */
final class StubChannelPresentation implements ChannelPresentationInterface
{
    public function presentationFor(string $code): ChannelPresentation
    {
        return new ChannelPresentation('Stub description for tests', 'rules/complexity.md');
    }
}
