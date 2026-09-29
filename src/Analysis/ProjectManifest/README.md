# Project manifest

## Subject and boundary

`Analysis\ProjectManifest` owns facts from the analysed project's Composer
manifest: source identity, read state, accepted autoload declarations, section
integrity, metadata and typed issues. It does not own discovery, coverage,
namespace attribution, external ancestry or report policy.

## Structure

```text
ProjectManifest/
└── Contract/
    ├── ComposerManifestReaderInterface.php # one canonical-root snapshot per invocation
    ├── ComposerAutoloadSection.php         # accepted section records, integrity, targets and PSR-4 roots
    ├── ComposerManifestFacts.php           # accepted production/development records and integrity
    ├── ComposerManifestDecoder.php         # pure bytes-to-facts grammar
    ├── ManifestReadState.php               # absent, unreadable, invalid, read
    ├── ManifestIssue.php                   # source, location, kind and detail
    ├── ManifestIssueKind.php               # closed vocabulary of rejected source facts
    └── ManifestSnapshotControlInterface.php # invocation reset and already observed issues
```

These declarations are exact public promises. Configuration, Run,
Measurement, Reporting and Console consume facts; only
Infrastructure's Composer reader consumes the decoder.
Console owns the snapshot control's invocation boundary.

## Source grammar

The root must be a JSON object. PSR-4 and PSR-0 prefix values accept strings
or lists of strings; classmap and files require lists of strings. Trailing
slashes are removed, and an empty result, including an authored empty string,
becomes `.`. Invalid records are retained as typed issues,
with their source and section/key/index location; accepted siblings survive.
Production and development are immutable section values with separate completeness, so an excluded damaged
development section does not invalidate production coverage.
Invalid metadata does not invalidate an otherwise complete code universe.
A numeric-looking string such as `"0"` stays a string.

## Lifetime and consumers

`Infrastructure\Composer\ComposerManifestReader` owns filesystem reads,
classmap glob expansion and the cache. Repeated reads of a canonical directory
return the same snapshot, including absence and failure.
`Application` begins one invocation after choosing its working directory;
neither runtime configuration nor a formatter clears that snapshot.
`observedIssues()` returns only issues already observed, with no lazy reads.

Configuration derives autoload defaults. Run measures the selected universe.
Measurement binds namespace prefixes before collection through its own source
control. Reporting derives project metadata from the same facts.
Infrastructure resolves install-backed classes using bounded root discovery
and separate installed/classmap data readers. No consumer executes Composer
PHP or loads runtime classes.

## Definition of done

- Exact object and path grammar; accepted records and rejected locations retained.
- Selected section integrity preserved independently of metadata.
- One cached root read per invocation, including missing and failed manifests.
- No constructor filesystem reads or fresh formatter IO.
- Exact owner, public imports and composition bindings registered in the manifest.
