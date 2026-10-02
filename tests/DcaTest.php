<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests;

use Contao\System;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DcaTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testCheckboxIsAddedOnlyForActiveReplacements(): void
    {
        $this->loadDca(confirm: true, cancel: false);

        $palettes = $GLOBALS['TL_DCA']['tl_module']['palettes'];

        $this->assertStringContainsString('compatch_requireButton', $palettes['event_registration_confirm']);
        $this->assertStringNotContainsString('compatch_requireButton', $palettes['event_registration_cancel']);
        $this->assertArrayHasKey('compatch_requireButton', $GLOBALS['TL_DCA']['tl_module']['fields']);
    }

    public function testNoVariablesLeakIntoTheSharedDcaScope(): void
    {
        // Contao includes all DCA files of a table in one scope (and caches them combined).
        $variables = $this->loadDca(confirm: true, cancel: true);

        $this->assertSame([], $variables);
    }

    /**
     * @return array<string, mixed> variables left in the include scope
     */
    private function loadDca(bool $confirm, bool $cancel): array
    {
        $container = new ContainerBuilder();
        $container->setParameter('plakart_contao_event_registration_compatch.confirm', $confirm);
        $container->setParameter('plakart_contao_event_registration_compatch.cancel', $cancel);
        System::setContainer($container);

        $palette = '{title_legend},name,headline,type;{config_legend},nodes,nc_notification;{template_legend:hide},customTpl';
        $GLOBALS['TL_DCA']['tl_module']['palettes']['event_registration_confirm'] = $palette;
        $GLOBALS['TL_DCA']['tl_module']['palettes']['event_registration_cancel'] = $palette;

        return (static function (): array {
            require __DIR__.'/../contao/dca/tl_module.php';

            return get_defined_vars();
        })();
    }
}
