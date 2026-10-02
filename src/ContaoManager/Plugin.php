<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\ContaoManager;

use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use InspiredMinds\ContaoEventRegistration\ContaoEventRegistrationBundle;
use Plakart\ContaoEventRegistrationCompatch\PlakartContaoEventRegistrationCompatchBundle;

class Plugin implements BundlePluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        // Loaded after the plugin so our templates, DCA and translations win.
        return [
            BundleConfig::create(PlakartContaoEventRegistrationCompatchBundle::class)
                ->setLoadAfter([ContaoEventRegistrationBundle::class]),
        ];
    }
}
