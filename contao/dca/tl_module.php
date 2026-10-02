<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\System;
use InspiredMinds\ContaoEventRegistration\Controller\FrontendModule\EventRegistrationCancelController;
use InspiredMinds\ContaoEventRegistration\Controller\FrontendModule\EventRegistrationConfirmController;

// The field always exists so switching a replacement off does not drop the column.
$GLOBALS['TL_DCA']['tl_module']['fields']['compatch_requireButton'] = [
    'exclude' => true,
    'inputType' => 'checkbox',
    'eval' => ['tl_class' => 'w50 m12'],
    'sql' => ['type' => 'boolean', 'default' => false],
];

// A closure keeps the variables out of the include scope all DCA files share.
(static function (): void {
    $container = System::getContainer();

    foreach (['confirm' => EventRegistrationConfirmController::TYPE, 'cancel' => EventRegistrationCancelController::TYPE] as $switch => $type) {
        if (!$container->getParameter('plakart_contao_event_registration_compatch.'.$switch)) {
            continue;
        }

        PaletteManipulator::create()
            ->addField('compatch_requireButton', 'config_legend', PaletteManipulator::POSITION_APPEND)
            ->applyToPalette($type, 'tl_module')
        ;
    }
})();
