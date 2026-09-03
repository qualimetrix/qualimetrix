<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

use RuntimeException;

/**
 * The one refusal this library raises: something it was asked to read is not of
 * a shape it recognises.
 *
 * One class rather than a taxonomy because every caller does the same thing
 * with it — stops, and says so with its own exit code. A report whose verdicts
 * cannot be read and an enumeration line with a column missing are the same
 * event for a CI step: the measurement it was about to judge is not there.
 */
final class AuditReportError extends RuntimeException {}
