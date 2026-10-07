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
