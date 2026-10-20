# 0104. Source Bytes and Prose Publication

**Date:** 2026-10-08
**Status:** Accepted

## Context

PHP accepts identifiers containing bytes that are not valid UTF-8. Replacing
them with U+FFFD collapsed distinct declarations and fingerprints. Structured
encoders could refuse a complete analysis; prose could emit invalid bytes.
An ASCII switch interpreted any non-empty value as enabled and affected only
one formatter. Those are different responsibilities: identity must preserve
bytes, while presentation must preserve normal text and explain repairs.

## Decision

### Identity and display

Core's SourceBytes owns the byte primitive. Total identity encoding preserves
valid UTF-8, escapes literal percent as %25, escapes invalid bytes as %XX, and
escapes separators reserved by the enclosing canonical grammar. Canonical
declaration file components reserve #, keeping an occurrence suffix
distinct from a literal filename suffix. The first @ separates the logical
and file components; a literal @ inside the file component remains valid.
After that split and removal of any terminal occurrence suffix, rawurldecode
restores the file component.
Occurrence and token hashes keep valid inputs unchanged; invalid strings use
an explicit percent-encoded frame, distinct from a literal percent spelling.

Structured display repairs only malformed strings, including their literal
percent signs. Prose uses escapeInvalidBytes: only invalid bytes change, so
a percentage such as 50% stays readable even beside an invalid identifier.
Valid display strings stay unchanged. A displayed file is not a reversible
identity: a literal Pa%FFth.php and an invalid Pa<FF>th.php can have the same
display. Use subject for exact identity and SARIF uri for an artifact address.
SARIF percent-encodes the original path bytes, retaining %FF and encoding a
literal percent as %25; it does not build a URI from the repaired display.

Baseline entry keys and edge targets carry canonical encoding. Baseline scope
and exclusion selectors remain raw input facts consumed by raw comparisons.
Writing a baseline with a non-UTF-8 scope or selector refuses truthfully; it
does not silently rename that input. Analyse a containing UTF-8 directory to
record byte-named entries. This does not make raw-byte JSON selectors supported.

JSON violationGroups dictionary keys are identities rather than display strings.
They always use total SourceBytes encoding, including literal percent signs,
so a valid %FF path and an invalid byte path cannot overwrite one another.
Decode a group key with rawurldecode; the finding's file remains its display.
A source reason keeps ordinary native JSON text when valid. If the native
encoder refuses UTF-8, native PHP var_export preserves its bytes in the prose
until publication. HTML repairs this banner before its native HTML escaper,
adding to the existing report repair count.

### Publication ownership

FormatterInterface declares PublicationKind and returns FormattedReport with
body and escapedStrings. Structured publishers count repaired fields and keep
their native document marker where there is a slot. ResultPresenter publishes
prose as one body, then reports a positive count on stderr for every format.
Checkstyle and GitLab carry actual findings only; they have no synthetic repair
finding. SARIF retains a tool notification. Consumers of format() read body.

GlyphMode belongs to Reporting. Application judges QMX_ASCII as its first
action inside the refusal ladder, before changing directory or running a
command. Case-insensitive 1/true/yes/on enables ASCII; 0/false/no/off/empty and
absence select Unicode. Other values refuse with exit 3 and an explanation.
ErrorStream owns the invocation mode; a different second binding is a logic
error. Ordinary writers and native progress sections share that mode. Summary
renderers do not read environment variables or implement a second glyph mode.

The closed table replaces product glyph sequences with one ASCII character
in aligned columns, preserving other Unicode such as Café. The same sequence
inside a source name is also replaced: a completed body cannot recover its
provenance. For example, valid PHP K✓ becomes K+ in ASCII prose. Unicode and
structured identities preserve it. This is a closed table, not transliteration.
It applies to analysis prose reports, output files and ErrorStream diagnostics.
Other commands' stdout, including rules, directive text, selected debug output
and DOT, remains outside this choice. The finding gate explicitly constructs
child environments without QMX_ASCII.

### Native serializer limits

The logger leaves a valid native JSON context unchanged. Malformed context is
repairable only through scalars, arrays and exact stdClass with UTF-8 keys.
Other objects and malformed keys lose context with the original native error
stated explicitly. No custom object serializer or replacement dictionary schema
is introduced. An invalid context containing JsonSerializable may invoke its
native callback twice while distinguishing recursion; repair never adds a
third callback. Valid context uses the native encoder once.

### Evidence and cost

Regressions exercise byte-distinct identities, reserved separators, raw SARIF
paths, native structured documents, actual prose formatters, percentages,
environment refusals, buffered diagnostics and progress sections. A native
PhpParser glyph-vocabulary control covers future product literals absent from
the formatter fixtures, including escaped literals. It excludes only input
keys of SuppressionSyntax::SPACE_NAMES. It does not promise dynamic glyph or
non-PHP coverage. Glyph plus encoding tests took 2.200 seconds locally; the
escaped-glyph mutation made the control fail. New encoder population rows name
actual producers and explain their native encoding boundary. No grammar is
reimplemented before a native parser.

## Consequences

Consumers must treat display text separately from canonical identity, accept
percent spelling and observe stderr even for a successfully produced report.
Baseline identities for literal-percent paths and hash-suffixed declaration
files may need a one-time reviewed migration. Reinstall generated hooks when
updating the shared exit-code contract described in ADR 0105. ASCII cannot
promise preservation of the table's own sequences inside source names.
