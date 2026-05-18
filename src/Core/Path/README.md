# Core\Path

Value objects for file paths: `AbsolutePath` and `RelativePath`, plus the boundary
factory `PathFactory`. They replace untyped `string` file paths across the analysis
pipeline so PHPStan rejects mismatched mixes (absolute vs. relative vs. git-relative)
at compile time.

See [ADR 0015](../../../docs/adr/0015-relative-path-vo.md) for the migration plan,
the bug class this closes, and the locked design decisions.
