<?php

declare(strict_types=1);

namespace QmxFindingGate;

use JsonException;

/**
 * The part of the HTML report that carries findings.
 *
 * An HTML report is three things in one file: a shell, the report application's
 * own JavaScript bundle, and the payload the bundle renders. Only the payload
 * is a published finding surface; the other two are the tool. Comparing the file
 * whole makes every rebuild of the bundle a surface difference — Ш5e3 renamed
 * the metric keys the bundle reads, and 31.8 KB of minified JavaScript came back
 * as fourteen mismatches that said nothing about findings, on top of which the
 * minifier had reallocated its own variable names.
 *
 * What this narrowing costs is stated rather than implied: a change to the
 * report's JavaScript stops being visible to the gate. Its behaviour is covered
 * by `composer test:js`, and the data it reads — every metric key, finding and
 * score in the payload — is compared exactly as before.
 *
 * The extraction is loud on purpose. A missing or unparsable payload used to be
 * indistinguishable from an unchanged one once the file was reduced to it: two
 * empty strings compare equal, and a broken report would pass as equivalent.
 * That is the failure this narrowing must not buy, so it is reported as its own
 * class rather than folded into a mismatch.
 */
final class ReportPayload
{
    private const string ANCHOR = '~<script type="application/json" id="report-data">(.*?)</script>~s';

    /**
     * @throws GateError when the artifact carries no payload this gate can read
     */
    public static function of(string $html, string $artifactKey, string $side): string
    {
        if (preg_match(self::ANCHOR, $html, $matches) !== 1) {
            throw new GateError(\sprintf(
                'The %s HTML report for %s carries no `report-data` payload. The gate compares that payload and not'
                . ' the file around it, so a report without one would compare as nothing at all.',
                $side,
                $artifactKey,
            ));
        }

        try {
            $decoded = json_decode($matches[1], false, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new GateError(\sprintf(
                'The %s HTML report for %s carries a `report-data` payload that is not JSON (%s).',
                $side,
                $artifactKey,
                $error->getMessage(),
            ));
        }

        return (string) json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private function __construct() {}
}
