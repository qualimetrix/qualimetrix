# Infrastructure Ast

AST parsing and cache-backed parser adapters live here. Consumers depend on the
Core parser contract rather than parser implementations.

`CachedFileParser` is the only implementation the factory builds. Whether it
caches is asked of the cache configuration store per parse, because the
container builds this service before a run is configured — a decision taken in
the factory is a decision taken against the defaults. See
[Cache README](../Cache/README.md).

There are two factories because there are two ways the parser is composed.
`FileParserFactory` is the container's; `WorkerParserFactory` is the same
assembly for a parallel worker, which has no container and must build one
itself. It lives here rather than beside the worker so that the cache
vocabulary stays out of a namespace whose subject is parallelism, and it is the
one place that hands a parser a `NullLogger` on purpose: a worker's own STDERR
is not a channel the user reads, and both diagnostics it would silence are
already carried — a refused source as an `unreadable-file` coverage entry, and
the parser-version verdict by the parent's single warning.

Both parsers consume caller-supplied `parseContent()` bytes, retaining original
absolute file identity for diagnostics. They do not open source or infer cwd.
Run's private `SourceReader` refuses unreadable/non-regular source before parsing
with `unreadable-file` coverage, rather than an empty successful AST. Syntax
errors remain `parse` failures.
