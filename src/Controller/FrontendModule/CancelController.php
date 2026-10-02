<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\String\SimpleTokenParser;
use InspiredMinds\ContaoEventRegistration\Controller\FrontendModule\EventRegistrationCancelController;
use InspiredMinds\ContaoEventRegistration\EventRegistration;
use InspiredMinds\ContaoEventRegistration\Model\EventRegistrationModel;
use InspiredMinds\ContaoEventRegistration\WaitingListChecker;
use Plakart\ContaoEventRegistrationCompatch\Registration\Decision;
use Plakart\ContaoEventRegistrationCompatch\Registration\StatusChanger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Terminal42\NodeBundle\NodeManager;
use Terminal42\NotificationCenterBundle\NotificationCenter;

/**
 * Replaces the plugin's cancel module (higher fragment priority wins).
 */
#[AsFrontendModule(type: EventRegistrationCancelController::TYPE, category: 'events', template: 'mod_event_registration_cancel', priority: 10)]
final class CancelController extends AbstractRegistrationActionController
{
    public function __construct(
        EventRegistration $eventRegistration,
        NodeManager $nodeManager,
        TranslatorInterface $translator,
        SimpleTokenParser $simpleTokenParser,
        NotificationCenter $notificationCenter,
        StatusChanger $statusChanger,
        ContaoCsrfTokenManager $csrfTokenManager,
        private readonly WaitingListChecker $waitingListChecker,
    ) {
        parent::__construct($eventRegistration, $nodeManager, $translator, $simpleTokenParser, $notificationCenter, $statusChanger, $csrfTokenManager);
    }

    protected function getAction(): string
    {
        return EventRegistrationCancelController::ACTION;
    }

    protected function decide(EventRegistrationModel $registration, CalendarEventsModel $event, int $now): Decision
    {
        return $this->statusChanger->decideCancel(
            (bool) $registration->cancelled,
            self::toTimestamp($event->reg_cancelEnd),
            $now,
        );
    }

    protected function apply(EventRegistrationModel $registration): void
    {
        $registration->cancelled = true;
        $registration->save();
    }

    protected function afterChange(array $events): void
    {
        // A freed place may promote registrations from the waiting list.
        foreach ($events as $event) {
            ($this->waitingListChecker)($event);
        }
    }
}
