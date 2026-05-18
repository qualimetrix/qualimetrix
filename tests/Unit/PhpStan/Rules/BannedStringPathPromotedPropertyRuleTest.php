<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\PhpStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Qualimetrix\PhpStan\Rules\BannedStringPathPromotedPropertyRule;
use Qualimetrix\PhpStan\Rules\PathPropertyMatcher;

/**
 * @extends RuleTestCase<BannedStringPathPromotedPropertyRule>
 */
final class BannedStringPathPromotedPropertyRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new BannedStringPathPromotedPropertyRule(new PathPropertyMatcher());
    }

    #[Test]
    public function itReportsBannedPromotedStringPathProperties(): void
    {
        $this->analyse(
            [__DIR__ . '/data/banned-string-path-promoted-property.php'],
            [
                [
                    'Promoted property Qualimetrix\\Infrastructure\\Parallel\\Fixture\\FileProcessingTaskFixture::$filePath should use Qualimetrix\\Core\\Path\\RelativePath or AbsolutePath, not a string-typed path.',
                    14,
                ],
                [
                    'Promoted property Qualimetrix\\Infrastructure\\Parallel\\Fixture\\FileProcessingTaskFixture::$file should use Qualimetrix\\Core\\Path\\RelativePath or AbsolutePath, not a string-typed path.',
                    15,
                ],
            ],
        );
    }
}
