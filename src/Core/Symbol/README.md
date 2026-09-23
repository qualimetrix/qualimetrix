# Core Symbol

Symbol values provide stable neutral identities for declarations and aggregates.
Their direct public surface is intentional: these values are the subject, not
adapters hidden behind a role-specific contract directory.

`PhpBuiltinClassRegistry` and `PhpBuiltinClassHierarchy` state what PHP itself
declares — which names are PHP's, and what is above each of them — as static
tables, so no answer depends on the PHP that runs the analysis. Governance
censuses in `governance/SymbolVocabulary/` compare both with the running PHP
and refuse on divergence; neither table is generated.
