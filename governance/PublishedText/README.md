# Published text

`JsonEncodingPopulationTest.php` requires an examined owner for every direct
production `json_encode` call. Existing product regressions exercise known
source-byte routes; they cannot reach a new encoder in a newly introduced
owner. The census makes that new route require an explicit input decision.

PHP syntax is read by php-parser. A text prefilter selects files mentioning
`json_encode`; it does not determine declarations or calls. The check took
0.21 seconds including PHPUnit startup on the development machine.

This is an owner census, not a dataflow analysis. It does not recognize
dynamic calls, prove an allowed owner's input safe, or detect another direct
call added inside an owner already listed. The allow-list descriptions and
owning product tests carry those input decisions. A deliberate removal of the
last direct encoder also requires removing that owner's stale entry. Parser
errors and unreadable source files fail the census. A new safe encoder causes
a refusal until its input and owner are reviewed; that is its false-red mode.

`ProductGlyphVocabularyTest.php` reads decoded String_ and interpolated-string
literal values with php-parser. Product publication regressions cannot reach a
new glyph introduced in another producer, particularly a Unicode escape that
looks ASCII in the source. A new literal character requires an ASCII-table
decision. Runtime-generated glyphs, non-PHP producers and analysed source data
are outside this census. Comments are not parsed as literals.

The exact `SuppressionSyntax::SPACE_NAMES` array keys are input characters:
its diagnostic publishes their ASCII code-point names. They are excluded by
native constant/key nodes; other literals in that file remain examined. A new
literal used only as input data can cause a false refusal until its ownership
is inspected. A new product glyph also refuses until its replacement is
chosen. The owning check took 2.20 seconds including the three encoding tests
and PHPUnit startup on the development machine. Its one mutation must add an
escaped glyph to a real producer, rather than modify another check.
