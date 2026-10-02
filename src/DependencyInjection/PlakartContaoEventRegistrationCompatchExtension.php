<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\DependencyInjection;

use Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\CancelController;
use Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\ConfirmController;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class PlakartContaoEventRegistrationCompatchExtension extends Extension
{
    /**
     * @param array<array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        (new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config')))->load('services.yaml');

        $container->setParameter('plakart_contao_event_registration_compatch.confirm', $config['confirm']);
        $container->setParameter('plakart_contao_event_registration_compatch.cancel', $config['cancel']);

        // Without our controller, the original one is the only fragment for the type again.
        if (!$config['confirm']) {
            $container->removeDefinition(ConfirmController::class);
        }

        if (!$config['cancel']) {
            $container->removeDefinition(CancelController::class);
        }
    }
}
