<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\Controller\FrontendModule;

use Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\AbstractRegistrationActionController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShouldExecuteTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, Request, bool}>
     */
    public static function provider(): iterable
    {
        $formId = 'compatch_confirm_6';

        yield 'no button, GET' => [false, Request::create('/x'), true];
        yield 'no button, POST' => [false, Request::create('/x', 'POST', ['FORM_SUBMIT' => $formId]), true];
        yield 'button, GET' => [true, Request::create('/x'), false];
        yield 'button, POST own form' => [true, Request::create('/x', 'POST', ['FORM_SUBMIT' => $formId]), true];
        yield 'button, POST foreign form' => [true, Request::create('/x', 'POST', ['FORM_SUBMIT' => 'compatch_confirm_7']), false];
        yield 'button, POST without FORM_SUBMIT' => [true, Request::create('/x', 'POST'), false];
        yield 'button, GET with FORM_SUBMIT in query' => [true, Request::create('/x?FORM_SUBMIT='.$formId), false];
    }

    #[DataProvider('provider')]
    public function testShouldExecute(bool $requireButton, Request $request, bool $expected): void
    {
        $this->assertSame(
            $expected,
            AbstractRegistrationActionController::shouldExecute($requireButton, $request, 'compatch_confirm_6'),
        );
    }
}
