<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('plakart_contao_event_registration_compatch');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('confirm')
                    ->info('Replace the "event_registration_confirm" front end module.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('cancel')
                    ->info('Replace the "event_registration_cancel" front end module.')
                    ->defaultTrue()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
