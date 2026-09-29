# Project manifest

Composer manifest facts for the analysed project. The pure decoder accepts valid records and records rejected ones with their source location. Read state distinguishes absent, unreadable, invalid and read documents; completeness is separate for production and development autoload sections. Metadata errors do not invalidate the code universe.

`Contract/` exposes `ComposerManifestReaderInterface`, `ComposerManifestFacts`, `ManifestReadState`, `ManifestIssue`, `ManifestIssueKind`, `ManifestSnapshotControlInterface` and `ComposerManifestDecoder`. Configuration, Run, Measurement, Reporting and Console consume facts; only the Infrastructure Composer reader consumes the pure decoder.

Infrastructure owns filesystem reads, classmap glob expansion and the invocation snapshot. Console begins an invocation after selecting the working directory. Repeated reads of a canonical root return the same facts, including failures. Observed issues do not trigger reads. Consumers own policy: Run judges scope, Measurement places namespaces, Reporting renders project metadata and Infrastructure resolves installed classes.

Definition of done: exact object and path grammar; accepted records retained; selected section integrity preserved; root reads cached once per invocation; no constructor reads, runtime class loading or fresh formatter IO.
