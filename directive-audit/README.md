# directive-audit — independent evidence for inline-directive decisions

This directory owns the data that checks whether inline directives are found.
It is evidence for the audit, not product configuration and not a replacement
for the audit's own verdict report.

## Artifacts

| file                                   | role                                                                     | owner                                    | refresh                                                   |
| -------------------------------------- | ------------------------------------------------------------------------ | ---------------------------------------- | --------------------------------------------------------- |
| `enumeration-threshold-directives.tsv` | tokenizer-derived inventory of authored `@qmx-threshold` sites in `src/` | `scripts/directive-audit/Enumerator.php` | `php scripts/enumerate-inline-directives.php src --write` |

The threshold enumeration is generated. Do not hand-edit it: `composer
enumeration:directives:check` compares it with a fresh tokenizer scan. The
tokenizer intentionally does not reuse the product extractor, so agreement is
evidence rather than two spellings of one defect.

## Operating checks

```bash
composer enumeration:directives:check
composer directives:audit
composer directives:narrow-control
```

`directives:narrow-control` compares narrow and full sweeps over a heterogeneous
fixture before it measures `src/`; equal answers over a uniform population are
not sufficient evidence.
