# Stand, half one — the screen the product leaves today

The measured half of the oracle for package 5 of X5. Taken on the tree at
`aa948685` (the branch as packages 1-4 left it), in a clean checkout of that
commit with its own `vendor/`, before any of package 5's code existed.

## The stand

A run of the real binary with **stderr on a pseudo-terminal and stdout on a
pipe** — the frame is drawn only on a decorated stream, so a run behind two
pipes cannot show the defect, and a run behind two terminals cannot be read back
as bytes. The stderr bytes are then replayed through a terminal that implements
`CUU` (`ESC[nA`), `ED` (`ESC[0J`), `EL` (`ESC[2K`), `\r` and `\n`, and the
**final screen** is the observation.

Byte comparison is not an oracle here: an intact run and a destroyed run both
contain erase sequences, and their byte counts differ either way (3 168 against
2 441 below). What separates them only exists after the erases are applied.

The stand is committed as
`tests/Infrastructure/Console/Support/PseudoTerminalRun.php` and
`tests/Infrastructure/Console/Support/TerminalScreen.php`, and it is exercised by
`tests/Infrastructure/Console/Functional/ErrorStreamPseudoTerminalTest.php`.

Fixture: twenty one-class files (above `ConsoleProgressBar`'s ten-file floor —
below it no frame is drawn and every case passes vacuously), analysed with
`--workers=0 --format=json`, `COLUMNS=120`, `TERM=xterm-256color`.

## Measured

| mode   | frames written | log lines written | log lines left on screen | frame left on screen | stdout bytes | `ESC` in stdout |
| ------ | -------------- | ----------------- | ------------------------ | -------------------- | ------------ | --------------- |
| (none) | 3              | 0                 | 0                        | no                   | 46 382       | 0               |
| `-v`   | 3              | 7                 | 7                        | no                   | 46 382       | 0               |
| `-vv`  | 3              | 15                | **14**                   | **yes**              | 46 382       | 0               |
| `-vvv` | 3              | 15                | **14**                   | **yes**              | 46 382       | 0               |

The damage at `-vv` and `-vvv`, read off the replayed screen:

- `StrategySelector: sequential mode requested` is **gone**;
- the line above it, `StrategySelector: selecting strategy`, is **truncated
  mid-path** — the screen keeps two of its three wrapped rows;
- the frame `0/20 [▓░░…] 0% < 1 ms/< 1 ms 24.0 MiB` and its message row
  `Starting analysis...` **stay on the screen for good**: the two later frames
  landed inside foreign output and were erased by the next diagnostic.

So the user loses the log *and* the progress at the same time, and what remains
is a frozen `0%`.

## What this half fixes about the record

- **The threshold is `-vv`, not `-vvv`.** At `-v` the frame is drawn, three
  frames, and the screen survives intact.
- **The price is larger than the record says.** The record says a frame erases
  an interleaved log line. Measured: a line is destroyed, a second is truncated,
  and the bar itself loses its place.
- **Verbosity is not the subject.** It only supplies writers. Any unsectioned
  writer to stderr during a run breaks the same way — see the seam table in
  `enumeration-error-stream-writers.md`, where the writer guaranteed to fire with
  a frame up is not a log line at all but an uncaught throwable, at every
  verbosity.
