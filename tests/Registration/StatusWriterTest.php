<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\Registration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Plakart\ContaoEventRegistrationCompatch\Registration\StatusWriter;

final class StatusWriterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE tl_event_registration (id INTEGER PRIMARY KEY, confirmed INTEGER NOT NULL DEFAULT 0, cancelled INTEGER NOT NULL DEFAULT 0)');
    }

    public function testOnlyTheFirstConfirmWins(): void
    {
        // Two overlapping requests (e.g. link scanners) both decided "allowed".
        $this->insert(1, confirmed: false, cancelled: false);
        $writer = new StatusWriter($this->connection);

        $this->assertTrue($writer->markConfirmed(1));
        $this->assertFalse($writer->markConfirmed(1));
        $this->assertSame(1, $this->value(1, 'confirmed'));
    }

    public function testConfirmDoesNotTouchACancelledRegistration(): void
    {
        $this->insert(1, confirmed: false, cancelled: true);

        $this->assertFalse((new StatusWriter($this->connection))->markConfirmed(1));
        $this->assertSame(0, $this->value(1, 'confirmed'));
    }

    public function testOnlyTheFirstCancelWins(): void
    {
        $this->insert(1, confirmed: true, cancelled: false);
        $writer = new StatusWriter($this->connection);

        $this->assertTrue($writer->markCancelled(1));
        $this->assertFalse($writer->markCancelled(1));
        $this->assertSame(1, $this->value(1, 'cancelled'));
    }

    public function testOtherRegistrationsAreNotChanged(): void
    {
        $this->insert(1, confirmed: false, cancelled: false);
        $this->insert(2, confirmed: false, cancelled: false);

        (new StatusWriter($this->connection))->markConfirmed(1);

        $this->assertSame(0, $this->value(2, 'confirmed'));
    }

    private function insert(int $id, bool $confirmed, bool $cancelled): void
    {
        $this->connection->insert('tl_event_registration', ['id' => $id, 'confirmed' => (int) $confirmed, 'cancelled' => (int) $cancelled]);
    }

    private function value(int $id, string $column): int
    {
        return (int) $this->connection->fetchOne("SELECT $column FROM tl_event_registration WHERE id = ?", [$id]);
    }
}
