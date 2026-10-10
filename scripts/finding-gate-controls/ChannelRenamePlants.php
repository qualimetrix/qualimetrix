<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\CaseDefinition;
use QmxFindingGate\Corpus;
use QmxFindingGate\SubjectLevel;
use RuntimeException;

/**
 * The channel renames controls plant in the product, and the declarations each one drags along.
 *
 * Shared on purpose: two controls built on one rename must not drift apart over which declarations they
 * carry.
 */
final class ChannelRenamePlants
{
    /**
     * The map a control declares: every row the step tracks, plus the control's
     * own.
     *
     * A control that writes the map whole has to write the step's rows too, and
     * copying them into this file would be a second, silently ageing copy of a
     * tracked declaration. They are read from the tracked file instead, so the
     * only thing stated here is what this control adds.
     *
     * Whole-file, not an insertion: {@see Mutation} refuses an edit whose own
     * anchor survives it, and an appended row leaves whatever it anchored on in
     * place. The reason `channels.tsv`'s step rows must survive is measured
     * rather than tidy — they declare the split that explains the producer move,
     * and without them the health surfaces the step declares a delta for fail as
     * `delta-overreach`, which for the green control means no green at all.
     *
     * `$file` is one of `RenameMaps`' declared map filenames; the "cannot read"
     * guard below is a defensive check on the read, not an emptiness check on
     * the file's content — a header-only map (every map A1 tracks starts that
     * way) reads as non-empty and is exactly the state this is meant to append
     * to.
     *
     * @param list<string> $rows tab-separated old, new, reason
     */
    public static function trackedMapPlus(string $file, array $rows, string $description): Mutation
    {
        $path = 'finding-gate/maps/' . $file;
        $tracked = @file_get_contents(\dirname(__DIR__, 2) . '/' . $path);

        if ($tracked === false || trim($tracked) === '') {
            throw new RuntimeException(\sprintf(
                'Cannot read %s, so a control cannot state its declaration on top of the step\'s own rows.',
                $path,
            ));
        }

        return Mutation::replace(
            [$path => rtrim($tracked, "\n") . "\n" . implode("\n", $rows) . "\n"],
            $description,
        );
    }

    /**
     * {@see trackedMapPlus()}, fixed to `channels.tsv`.
     *
     * @param list<string> $rows tab-separated old, new, reason
     */
    public static function trackedChannelMapPlus(array $rows, string $description): Mutation
    {
        return self::trackedMapPlus('channels.tsv', $rows, $description);
    }

    /** Scratch declaration changes exercised by the owning semantic-claim mutation checks. */
    public static function unusedPrivateRenameDeclarations(): Mutation
    {
        $mutation = Mutation::edit(
            'governance/Channel/Fixtures/declared.txt',
            ['code-smell.unused-private higher class' => 'code-smell.unused-privat2 higher class'],
            'the tracked declaration fixture names the new channel',
        );

        foreach (Corpus::load(\dirname(__DIR__, 2))->cases as $case) {
            if (!\in_array('code-smell.unused-private@class', $case->channels, true)) {
                continue;
            }

            $mutation = $mutation->and(self::renamedCaseClaims(
                $case,
                'code-smell.unused-private',
                'code-smell.unused-privat2',
            ));
        }

        return $mutation->and(Mutation::renameInDerivedDeclarations(
            ['code-smell.unused-private' => 'code-smell.unused-privat2'],
            'any derived declaration that names the channel names the new one',
        ));
    }

    /** A claim's decoded channel is authoritative, regardless of its JSON spelling. */
    public static function renamedCaseClaims(CaseDefinition $case, string $old, string $new): Mutation
    {
        $relative = 'finding-gate/cases/' . $case->id . '/case.json';
        $document = json_decode(Shell::read($case->directory . '/case.json'), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($document) || !\is_array($document['channels'] ?? null)
            || $document['channels'] !== $case->channels) {
            throw new RuntimeException('Cannot read the current channel claims of case ' . $case->id . '.');
        }

        $renamed = 0;
        foreach ($document['channels'] as $index => $claim) {
            if (SubjectLevel::channelOf($claim) !== $old) {
                continue;
            }
            $document['channels'][$index] = $new . substr($claim, \strlen($old));
            ++$renamed;
        }
        if ($renamed === 0) {
            throw new RuntimeException('Case ' . $case->id . ' no longer claims channel ' . $old . '.');
        }

        return Mutation::replace(
            [$relative => json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n"],
            'the case claims the renamed channel',
        );
    }

    /** Both outward fingerprint representations move without changing a finding or Population. */
    public static function publishedUnusedPrivateFingerprintMutation(): Mutation
    {
        return Mutation::edit(
            'src/Reporting/Formatter/Sarif/SarifFormatter.php',
            [
                "'primaryLocationLineHash' => \$v->getFingerprint(),"
                    => "'primaryLocationLineHash' => \$v->code === 'code-smell.unused-private' ? 'unmapped:' . \$v->getFingerprint() : \$v->getFingerprint(),",
            ],
            'the unused-private SARIF fingerprint gains an unmapped prefix',
        )->and(Mutation::edit(
            'src/Reporting/Formatter/GitLabCodeQualityFormatter.php',
            [
                '        return md5($finding->getFingerprint());'
                    => "        return md5((\$finding->code === 'code-smell.unused-private' ? 'unmapped:' : '') . \$finding->getFingerprint());",
            ],
            'the GitLab fingerprint hashes the same changed fingerprint input',
        ));
    }

    /** JSON physical records, ranking and producer counts publish the same producer move. */
    public static function publishedUnusedPrivateProducerMutation(): Mutation
    {
        return Mutation::edit(
            'src/Reporting/Formatter/Json/JsonFindingSection.php',
            [
                '        return $this->record->of($finding, $context, $fileNamespaces);'
                    => "        \$record = \$this->record->of(\$finding, \$context, \$fileNamespaces);\n"
                        . "        if (\$record['channel'] === 'code-smell.unused-private') {\n"
                        . "            \$record['rule'] = 'code-smell.unused-privat2';\n"
                        . "        }\n"
                        . '        return $record;',
                '            $rule = $finding->ruleName;'
                    => "            \$rule = \$finding->code === 'code-smell.unused-private' ? 'code-smell.unused-privat2' : \$finding->ruleName;",
            ],
            'only the JSON producer and its complete producer counts move; finding identity and Population stay unchanged',
        );
    }

    /** The JSON section shares this publication between physical, ranked and grouped findings. */
    public static function publishedLcomChannelMutation(): Mutation
    {
        return Mutation::edit(
            'src/Reporting/Formatter/Json/JsonFindingSection.php',
            [
                '        return $this->record->of($finding, $context, $fileNamespaces);'
                    => "        \$record = \$this->record->of(\$finding, \$context, \$fileNamespaces);\n"
                        . "        if (\$record['channel'] === 'cohesion.lcom') {\n"
                        . "            \$record['channel'] = 'cohesion.lcom4';\n"
                        . "            \$record['code'] = 'cohesion.lcom4';\n"
                        . "        }\n"
                        . '        return $record;',
            ],
            'only the JSON finding channel and code move; producer, metric values and Population stay unchanged',
        );
    }
}
