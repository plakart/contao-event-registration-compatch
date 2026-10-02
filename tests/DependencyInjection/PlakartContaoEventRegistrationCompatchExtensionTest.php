<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\CancelController;
use Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\ConfirmController;
use Plakart\ContaoEventRegistrationCompatch\DependencyInjection\PlakartContaoEventRegistrationCompatchExtension;
use Plakart\ContaoEventRegistrationCompatch\Registration\StatusChanger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PlakartContaoEventRegistrationCompatchExtensionTest extends TestCase
{
    public function testAliasMatchesBundleName(): void
    {
        $this->assertSame(
            'plakart_contao_event_registration_compatch',
            (new PlakartContaoEventRegistrationCompatchExtension())->getAlias(),
        );
    }

    public function testBothControllersAreRegisteredByDefault(): void
    {
        $container = $this->load([]);

        $this->assertTrue($container->hasDefinition(ConfirmController::class));
        $this->assertTrue($container->hasDefinition(CancelController::class));
        $this->assertTrue($container->hasDefinition(StatusChanger::class));
        $this->assertTrue($container->getParameter('plakart_contao_event_registration_compatch.confirm'));
        $this->assertTrue($container->getParameter('plakart_contao_event_registration_compatch.cancel'));
    }

    public function testConfirmCanBeDisabled(): void
    {
        $container = $this->load([['confirm' => false]]);

        $this->assertFalse($container->hasDefinition(ConfirmController::class));
        $this->assertTrue($container->hasDefinition(CancelController::class));
        $this->assertFalse($container->getParameter('plakart_contao_event_registration_compatch.confirm'));
        $this->assertTrue($container->getParameter('plakart_contao_event_registration_compatch.cancel'));
    }

    public function testCancelCanBeDisabled(): void
    {
        $container = $this->load([['cancel' => false]]);

        $this->assertTrue($container->hasDefinition(ConfirmController::class));
        $this->assertFalse($container->hasDefinition(CancelController::class));
        $this->assertTrue($container->getParameter('plakart_contao_event_registration_compatch.confirm'));
        $this->assertFalse($container->getParameter('plakart_contao_event_registration_compatch.cancel'));
    }

    /**
     * @param array<array<string, mixed>> $configs
     */
    private function load(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new PlakartContaoEventRegistrationCompatchExtension())->load($configs, $container);

        return $container;
    }
}
