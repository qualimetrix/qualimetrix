<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Support;

use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateSearchRequest;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\RetokenizedFiles;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenNormalizer;

final class SplitSameContentFixture
{
    public const int CONTENT_LENGTH = 132;
    public const int FIRST_OFFSET = 244;
    public const int SECOND_OFFSET = 1256;

    public static function request(): DuplicateSearchRequest
    {
        $sources = self::sources();
        $normalizer = new TokenNormalizer();

        return new DuplicateSearchRequest(
            hashIndex: [
                313613831 => [
                    PackedPosition::pack(0, 244),
                    PackedPosition::pack(0, 1256),
                    PackedPosition::pack(1, 227),
                    PackedPosition::pack(1, 1018),
                    PackedPosition::pack(1, 1405),
                    PackedPosition::pack(2, 363),
                    PackedPosition::pack(2, 869),
                ],
                999362996 => [
                    PackedPosition::pack(0, 624),
                    PackedPosition::pack(1, 16),
                    PackedPosition::pack(1, 1347),
                    PackedPosition::pack(2, 305),
                    PackedPosition::pack(2, 675),
                    PackedPosition::pack(2, 1011),
                    PackedPosition::pack(2, 1313),
                ],
            ],
            retokenized: new RetokenizedFiles(array_map($normalizer->normalize(...), $sources), $sources),
            filePaths: ['src/F04.php', 'src/F05.php', 'src/F10.php'],
            minTokens: 70,
            minLines: 5,
        );
    }

    /** @return list<string> */
    public static function sources(): array
    {
        return [
            self::source('F04', [[5, 2, 7], [0, 10, 1, 8, 5], [11, 5, 9, 7]]),
            self::source('F05', [[1, 11, 4, 7, 10], [11, 4], [4, 6, 5, 0, 7], [2, 9, 1, 7]]),
            self::source('F10', [[6, 11, 10, 1, 7], [0, 3, 1, 10, 7], [1, 5, 9], [1, 0]]),
        ];
    }

    /** @param list<list<int>> $methods */
    private static function source(string $class, array $methods): string
    {
        $counts = [0 => 5, 1 => 4, 2 => 6, 3 => 3, 4 => 3, 5 => 7, 6 => 3, 7 => 5, 8 => 7, 9 => 3, 10 => 7, 11 => 4];
        $lines = ['<?php', 'namespace App;', 'final class ' . $class, '{'];
        foreach ($methods as $method => $chunks) {
            $lines[] = '    public function m' . $method . '($a, $b)';
            $lines[] = '    {';
            foreach ($chunks as $chunk) {
                for ($call = 0; $call < $counts[$chunk]; $call++) {
                    $lines[] = \sprintf('        $v%d_%d = $this->svc%d->call%d($a, $b + %d, \'%d\');', $chunk, $call, $chunk, $call, $call, $chunk);
                }
                $lines[] = \sprintf('        if ($v%d_0 > %d) { $this->log(\'c%d\', $v%d_0); }', $chunk, $chunk, $chunk, $chunk);
            }
            $lines[] = '        return $a;';
            $lines[] = '    }';
        }
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }
}
