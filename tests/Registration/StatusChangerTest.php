<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\Registration;

use Plakart\ContaoEventRegistrationCompatch\Registration\Decision;
use Plakart\ContaoEventRegistrationCompatch\Registration\StatusChanger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusChangerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /**
     * @return iterable<string, array{bool, bool, int|null, Decision}>
     */
    public static function confirmProvider(): iterable
    {
        yield 'new, no end date' => [false, false, null, Decision::Allowed];
        yield 'new, end date in future' => [false, false, self::NOW + 1, Decision::Allowed];
        yield 'new, end date is now' => [false, false, self::NOW, Decision::Allowed];
        yield 'new, end date passed' => [false, false, self::NOW - 1, Decision::ConfirmExpired];
        yield 'already confirmed' => [true, false, null, Decision::AlreadyConfirmed];
        yield 'already cancelled' => [false, true, null, Decision::AlreadyCancelled];
        yield 'confirmed and cancelled: confirmed wins' => [true, true, null, Decision::AlreadyConfirmed];
        yield 'cancelled and expired: cancelled wins' => [false, true, self::NOW - 1, Decision::AlreadyCancelled];
    }

    #[DataProvider('confirmProvider')]
    public function testDecideConfirm(bool $confirmed, bool $cancelled, int|null $regEnd, Decision $expected): void
    {
        $this->assertSame($expected, (new StatusChanger())->decideConfirm($confirmed, $cancelled, $regEnd, self::NOW));
    }

    /**
     * @return iterable<string, array{bool, int|null, Decision}>
     */
    public static function cancelProvider(): iterable
    {
        yield 'new, no end date' => [false, null, Decision::Allowed];
        yield 'new, end date in future' => [false, self::NOW + 1, Decision::Allowed];
        yield 'new, end date is now' => [false, self::NOW, Decision::Allowed];
        yield 'new, end date passed' => [false, self::NOW - 1, Decision::CancelExpired];
        yield 'already cancelled' => [true, null, Decision::AlreadyCancelled];
        yield 'cancelled and expired: cancelled wins' => [true, self::NOW - 1, Decision::AlreadyCancelled];
    }

    #[DataProvider('cancelProvider')]
    public function testDecideCancel(bool $cancelled, int|null $cancelEnd, Decision $expected): void
    {
        $this->assertSame($expected, (new StatusChanger())->decideCancel($cancelled, $cancelEnd, self::NOW));
    }
}
