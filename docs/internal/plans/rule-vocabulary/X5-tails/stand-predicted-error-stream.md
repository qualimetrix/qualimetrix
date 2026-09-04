# Stand, half two — the screen the rule predicts

The predicted half of the oracle for package 5 of X5, derived from the rule
below before the code was measured again. Read together with
`stand-today-error-stream.md`, which holds the measured half.

## The rule

> Every writer that can reach the run's error stream goes through one owner. The
> owner draws the progress frame in a section that sits **below** every
> diagnostic line, so a diagnostic write erases the frame, appends its line
> permanently, and redraws the frame underneath. Nothing else writes to that
> stream, and the frame is cleared on every path that ends the run.

Three consequences follow mechanically, and they are the predictions:

1. **Every byte a diagnostic writer emits survives on the final screen**, in
   emission order, at every verbosity — because no erase is ever counted over
   lines the frame does not own.
2. **No frame is left on the final screen** — the reporter clears it, and on the
   throwable path the owner clears it.
3. **stdout does not move.** The owner touches only the error stream, so the
   payload is byte-identical to today's, modulo what a second run of the same
   binary is allowed to change anyway (timestamp, durations, memory).

## Predicted table

| mode   | frames written | log lines written | log lines left on screen | frame left on screen | stdout  |
| ------ | -------------- | ----------------- | ------------------------ | -------------------- | ------- |
| (none) | ≥3             | 0                 | 0                        | no                   | unmoved |
| `-v`   | ≥3             | 7                 | **7**                    | no                   | unmoved |
| `-vv`  | ≥3             | 15                | **15**                   | no                   | unmoved |
| `-vvv` | ≥3             | 15                | **15**                   | no                   | unmoved |

Frames are predicted as `≥3` and not as `3`: a diagnostic write reprints the
frame, so a run that emits log lines between frames necessarily draws the frame
more often than one that does not. The number of frames is therefore not
invariant under the rule, and predicting `3` would be predicting a coincidence.

## What would falsify it

- a log line missing or truncated on the screen at any verbosity;
- a frame on the final screen at any verbosity;
- any change in the normalised stdout of any of the four modes;
- a diagnostic appearing in stdout (the fallback folding channels together).

## Measured afterwards

| mode   | frames | log lines written | on screen | frame left | stdout normalised, before vs after |
| ------ | ------ | ----------------- | --------- | ---------- | ---------------------------------- |
| (none) | 3      | 0                 | 0         | no         | identical                          |
| `-v`   | 3      | 7                 | 7         | no         | identical                          |
| `-vv`  | **6**  | 15                | **15**    | no         | identical                          |
| `-vvv` | **6**  | 15                | **15**    | no         | identical                          |

The frame count doubling at `-vv`/`-vvv` is the reprint the rule predicts, and
it is the only figure that moved beyond the prediction's `≥3`.

One artefact is unchanged and pre-existing: a single blank row is left where the
frame stood, at `-v` as well, because the frame's second row is cleared and its
newline is not. It is present in both halves of the measurement and is not
attributable to this change.
