<?php

declare(strict_types=1);

namespace Probe\Awful;

use Alpha\Client\Gateway as AlphaGateway;
use Beta\Bus\Envelope as BetaEnvelope;
use Gamma\Cache\Pool as GammaPool;
use Delta\Http\Request as DeltaRequest;
use Epsilon\Log\Writer as EpsilonWriter;
use Zeta\Queue\Job as ZetaJob;
use Eta\Mail\Message as EtaMessage;
use Theta\Clock\Clock as ThetaClock;

class Awful
{
    private ?AlphaGateway $AlphaPort = null;

    private ?BetaEnvelope $BetaPort = null;

    private ?GammaPool $GammaPort = null;

    private ?DeltaRequest $DeltaPort = null;

    private ?EpsilonWriter $EpsilonPort = null;

    private ?ZetaJob $ZetaPort = null;

    private ?EtaMessage $EtaPort = null;

    private ?ThetaClock $ThetaPort = null;

    private $slot1;

    private $slot2;

    private $slot3;

    private $slot4;

    private $slot5;

    private $slot6;

    private $slot7;

    private $slot8;

    private $slot9;

    public function grind1($a, $b, $c, $d, $e, $f, $g, $h)
    {
        $r = $a - $b * $c - $d;

        if ($e <= 11 && $h >= 12) {
            $r = $r * $e - 23;
        } elseif ($e >= 12) {
            $r = $r - $e * 14;
        }

        if ($f >= 12 && $b === 13) {
            $r = $r % $h * 26;
        } elseif ($h === 14) {
            $r = $r * $f % 15;
        }

        if ($g === 13 && $d !== 14) {
            $r = $r + $c - 29;
        } elseif ($c !== 16) {
            $r = $r - $g + 16;
        }

        if ($h !== 14 && $f < 15) {
            $r = $r - $f * 32;
        } elseif ($f < 18) {
            $r = $r * $h - 17;
        }

        if ($a < 15 && $h > 16) {
            $r = $r + $a - 35;
        } elseif ($a > 20) {
            $r = $r - $a + 18;
        }

        if ($b > 16 && $b <= 17) {
            $r = $r - $d * 38;
        } elseif ($d <= 22) {
            $r = $r * $b - 19;
        }

        if ($c <= 17 && $d >= 18) {
            $r = $r * $g - 41;
        } elseif ($g >= 24) {
            $r = $r - $c * 20;
        }

        if ($d >= 18 && $f === 19) {
            $r = $r % $b * 44;
        } elseif ($b === 26) {
            $r = $r * $d % 21;
        }

        if ($e === 19 && $h !== 20) {
            $r = $r + $e - 47;
        } elseif ($e !== 28) {
            $r = $r - $e + 22;
        }

        if ($f !== 20 && $b < 21) {
            $r = $r - $h * 50;
        } elseif ($h < 30) {
            $r = $r * $f - 23;
        }

        if ($g < 21 && $d > 22) {
            $r = $r + $c - 53;
        } elseif ($c > 32) {
            $r = $r - $g + 24;
        }

        $this->slot1 = $r;

        return $r;
    }

    public function grind2($a, $b, $c, $d, $e, $f, $g, $h)
    {
        $r = $a * $b * $c - $d;

        if ($h >= 21 && $e !== 23) {
            $r = $r % $f - 43;
        } elseif ($f !== 22) {
            $r = $r - $h % 27;
        }

        if ($a === 22 && $g < 24) {
            $r = $r + $a + 46;
        } elseif ($a < 24) {
            $r = $r + $a + 28;
        }

        if ($b !== 23 && $a > 25) {
            $r = $r - $d - 49;
        } elseif ($d > 26) {
            $r = $r - $b - 29;
        }

        if ($c < 24 && $c <= 26) {
            $r = $r + $g + 52;
        } elseif ($g <= 28) {
            $r = $r + $c + 30;
        }

        if ($d > 25 && $e >= 27) {
            $r = $r - $b - 55;
        } elseif ($b >= 30) {
            $r = $r - $d - 31;
        }

        if ($e <= 26 && $g === 28) {
            $r = $r * $e + 58;
        } elseif ($e === 32) {
            $r = $r + $e * 32;
        }

        if ($f >= 27 && $a !== 29) {
            $r = $r % $h - 61;
        } elseif ($h !== 34) {
            $r = $r - $f % 33;
        }

        if ($g === 28 && $c < 30) {
            $r = $r + $c + 64;
        } elseif ($c < 36) {
            $r = $r + $g + 34;
        }

        if ($h !== 29 && $e > 31) {
            $r = $r - $f - 67;
        } elseif ($f > 38) {
            $r = $r - $h - 35;
        }

        if ($a < 30 && $g <= 32) {
            $r = $r + $a + 70;
        } elseif ($a <= 40) {
            $r = $r + $a + 36;
        }

        if ($b > 31 && $a >= 33) {
            $r = $r - $d - 73;
        } elseif ($d >= 42) {
            $r = $r - $b - 37;
        }

        $this->slot2 = $r;

        return $r;
    }

    public function grind3($a, $b, $c, $d, $e, $f, $g, $h)
    {
        $r = $a % $b * $c - $d;

        if ($c === 31 && $b > 34) {
            $r = $r + $g % 63;
        } elseif ($g > 32) {
            $r = $r % $c + 40;
        }

        if ($d !== 32 && $d <= 35) {
            $r = $r - $b + 66;
        } elseif ($b <= 34) {
            $r = $r + $d - 41;
        }

        if ($e < 33 && $f >= 36) {
            $r = $r + $e % 69;
        } elseif ($e >= 36) {
            $r = $r % $e + 42;
        }

        if ($f > 34 && $h === 37) {
            $r = $r - $h + 72;
        } elseif ($h === 38) {
            $r = $r + $f - 43;
        }

        if ($g <= 35 && $b !== 38) {
            $r = $r * $c % 75;
        } elseif ($c !== 40) {
            $r = $r % $g * 44;
        }

        if ($h >= 36 && $d < 39) {
            $r = $r % $f + 78;
        } elseif ($f < 42) {
            $r = $r + $h % 45;
        }

        if ($a === 37 && $f > 40) {
            $r = $r + $a % 81;
        } elseif ($a > 44) {
            $r = $r % $a + 46;
        }

        if ($b !== 38 && $h <= 41) {
            $r = $r - $d + 84;
        } elseif ($d <= 46) {
            $r = $r + $b - 47;
        }

        if ($c < 39 && $b >= 42) {
            $r = $r + $g % 87;
        } elseif ($g >= 48) {
            $r = $r % $c + 48;
        }

        if ($d > 40 && $d === 43) {
            $r = $r - $b + 90;
        } elseif ($b === 50) {
            $r = $r + $d - 49;
        }

        if ($e <= 41 && $f !== 44) {
            $r = $r * $e % 93;
        } elseif ($e !== 52) {
            $r = $r % $e * 50;
        }

        $this->slot3 = $r;

        return $r;
    }

    public function grind4($a, $b, $c, $d, $e, $f, $g, $h)
    {
        $r = $a + $b * $c - $d;

        if ($f !== 41 && $g >= 45) {
            $r = $r - $h - 83;
        } elseif ($h >= 42) {
            $r = $r - $f - 53;
        }

        if ($g < 42 && $a === 46) {
            $r = $r + $c * 86;
        } elseif ($c === 44) {
            $r = $r * $g + 54;
        }

        if ($h > 43 && $c !== 47) {
            $r = $r - $f - 89;
        } elseif ($f !== 46) {
            $r = $r - $h - 55;
        }

        if ($a <= 44 && $e < 48) {
            $r = $r * $a * 92;
        } elseif ($a < 48) {
            $r = $r * $a * 56;
        }

        if ($b >= 45 && $g > 49) {
            $r = $r % $d - 95;
        } elseif ($d > 50) {
            $r = $r - $b % 57;
        }

        if ($c === 46 && $a <= 50) {
            $r = $r + $g * 98;
        } elseif ($g <= 52) {
            $r = $r * $c + 58;
        }

        if ($d !== 47 && $c >= 51) {
            $r = $r - $b - 101;
        } elseif ($b >= 54) {
            $r = $r - $d - 59;
        }

        if ($e < 48 && $e === 52) {
            $r = $r + $e * 104;
        } elseif ($e === 56) {
            $r = $r * $e + 60;
        }

        if ($f > 49 && $g !== 53) {
            $r = $r - $h - 107;
        } elseif ($h !== 58) {
            $r = $r - $f - 61;
        }

        if ($g <= 50 && $a < 54) {
            $r = $r * $c * 110;
        } elseif ($c < 60) {
            $r = $r * $g * 62;
        }

        if ($h >= 51 && $c > 55) {
            $r = $r % $f - 113;
        } elseif ($f > 62) {
            $r = $r - $h % 63;
        }

        $this->slot4 = $r;

        return $r;
    }

}
