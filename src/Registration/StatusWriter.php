<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Registration;

use Doctrine\DBAL\Connection;

/**
 * Changes the status with a conditional UPDATE, so of several overlapping
 * requests (e.g. link scanners) only the first one counts as a change.
 */
final class StatusWriter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function markConfirmed(int $id): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE tl_event_registration SET confirmed = 1 WHERE id = ? AND confirmed = 0 AND cancelled = 0',
            [$id],
        );
    }

    public function markCancelled(int $id): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE tl_event_registration SET cancelled = 1 WHERE id = ? AND cancelled = 0',
            [$id],
        );
    }
}
