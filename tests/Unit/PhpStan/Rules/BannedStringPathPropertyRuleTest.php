<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\PhpStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Qualimetrix\PhpStan\Rules\BannedStringPathPropertyRule;
use Qualimetrix\PhpStan\Rules\PathPropertyMatcher;

/**
 * @extends RuleTestCase<BannedStringPathPropertyRule>
 */
final class BannedStringPathPropertyRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new BannedStringPathPropertyRule(new PathPropertyMatcher());
    }

    #[Test]
    public function itReportsBannedStringPathPropertiesInScopedNamespace(): void
    {
        $this->analyse(
            [__DIR__ . '/data/banned-string-path-property.php'],
            [
                [
                    'Property Qualimetrix\\Reporting\\Health\\Fixture\\BannedStringPathPropertyFixture::$file should use Qualimetrix\\Core\\Path\\RelativePath or AbsolutePath, not a string-typed path.',
                    9,
                ],
                [
                    'Property Qualimetrix\\Reporting\\Health\\Fixture\\BannedStringPathPropertyFixture::$filePath should use Qualimetrix\\Core\\Path\\RelativePath or AbsolutePath, not a string-typed path.',
                    11,
                ],
                [
                    'Property Qualimetrix\\Reporting\\Health\\Fixture\\BannedStringPathPropertyFixture::$oldPath should use Qualimetrix\\Core\\Path\\RelativePath or AbsolutePath, not a string-typed path.',
                    13,
                ],
            ],
        );
    }

    #[Test]
    public function itIgnoresHtmlTreeNodeStylePathProperty(): void
    {
        // HtmlTreeNode-shaped $path is a namespace path, not a file path —
        // the rule must NOT flag the property name $path (only $file / $filePath / $oldPath).
        $this->analyse(
            [__DIR__ . '/data/banned-string-path-property-html-tree.php'],
            [],
        );
    }

    #[Test]
    public function itIgnoresPropertiesInUnscopedNamespace(): void
    {
        $this->analyse(
            [__DIR__ . '/data/banned-string-path-property-out-of-scope.php'],
            [],
        );
    }
}
