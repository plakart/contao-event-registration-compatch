<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\Registration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plakart\ContaoEventRegistrationCompatch\Registration\Decision;

final class DecisionTest extends TestCase
{
    /**
     * @return iterable<string, array{Decision, string, string, string}>
     */
    public static function mappingProvider(): iterable
    {
        yield 'already confirmed' => [Decision::AlreadyConfirmed, 'already_confirmed', 'already-confirmed', 'alreadyConfirmed'];
        yield 'already cancelled' => [Decision::AlreadyCancelled, 'already_cancelled', 'already-cancelled', 'alreadyCancelled'];
        yield 'confirm expired' => [Decision::ConfirmExpired, 'cannot_confirm', 'cannot-confirm', 'cannotConfirm'];
        yield 'cancel expired' => [Decision::CancelExpired, 'cannot_cancel', 'cannot-cancel', 'cannotCancel'];
    }

    #[DataProvider('mappingProvider')]
    public function testMapping(Decision $decision, string $key, string $cssClass, string $flag): void
    {
        $this->assertSame($key, $decision->translationKey());
        $this->assertSame($cssClass, $decision->cssClass());
        $this->assertSame($flag, $decision->templateFlag());
    }

    public function testAllowedHasNoMessage(): void
    {
        $this->expectException(\LogicException::class);

        Decision::Allowed->translationKey();
    }
}
