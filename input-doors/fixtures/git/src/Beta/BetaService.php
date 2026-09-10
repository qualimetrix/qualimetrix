<?php

declare(strict_types=1);

namespace Fixture\Beta;

/** Second namespace and second class carrying findings — see AlphaService. */
class BetaService
{
    public function decide(bool $enabled, bool $verbose, bool $strict, int $limit): string
    {
        $out = '';

        if ($enabled && $verbose) {
            $out .= 'a';
        }

        if ($strict || $limit > 10) {
            $out .= 'b';
        }

        switch ($limit) {
            case 1:
                $out .= 'c';

                break;
            case 2:
                $out .= 'd';

                break;
            case 3:
                $out .= 'e';

                break;
            default:
                $out .= 'f';
        }

        foreach (range(0, $limit) as $index) {
            if ($index % 2 === 0 && $index > 4) {
                $out .= (string) $index;
            }
        }

        return $out;
    }

    public function debug(): void
    {
        var_dump('trace');
    }
}
