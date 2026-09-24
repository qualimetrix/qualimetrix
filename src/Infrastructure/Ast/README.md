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
already carried — a refused file as a `parse` entry in the run's coverage, and
the parser-version verdict by the parent's single warning.

Both parsers refuse anything that is not a readable regular file. That refusal
is deliberately duplicated rather than left to discovery: `file_get_contents()`
on a directory returns an empty string rather than `false`, which parses into an
empty AST and reports as a successfully analyzed file.
