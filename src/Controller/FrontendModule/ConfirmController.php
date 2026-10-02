<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use InspiredMinds\ContaoEventRegistration\Controller\FrontendModule\EventRegistrationConfirmController;
use InspiredMinds\ContaoEventRegistration\Model\EventRegistrationModel;
use Plakart\ContaoEventRegistrationCompatch\Registration\Decision;

/**
 * Replaces the plugin's confirm module (higher fragment priority wins).
 */
#[AsFrontendModule(type: EventRegistrationConfirmController::TYPE, category: 'events', template: 'mod_event_registration_confirm', priority: 10)]
final class ConfirmController extends AbstractRegistrationActionController
{
    protected function getAction(): string
    {
        return EventRegistrationConfirmController::ACTION;
    }

    protected function decide(EventRegistrationModel $registration, CalendarEventsModel $event, int $now): Decision
    {
        return $this->statusChanger->decideConfirm(
            (bool) $registration->confirmed,
            (bool) $registration->cancelled,
            self::toTimestamp($event->reg_regEnd),
            $now,
        );
    }

    protected function apply(EventRegistrationModel $registration): void
    {
        $registration->confirmed = true;
        $registration->save();
    }
}
