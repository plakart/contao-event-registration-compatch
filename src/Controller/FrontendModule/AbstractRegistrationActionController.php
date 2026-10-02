<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\String\SimpleTokenParser;
use Contao\ModuleModel;
use Contao\StringUtil;
use Contao\Template;
use InspiredMinds\ContaoEventRegistration\EventRegistration;
use InspiredMinds\ContaoEventRegistration\Model\EventRegistrationModel;
use Plakart\ContaoEventRegistrationCompatch\Registration\Decision;
use Plakart\ContaoEventRegistrationCompatch\Registration\StatusChanger;
use Plakart\ContaoEventRegistrationCompatch\Registration\StatusWriter;
use Plakart\ContaoEventRegistrationCompatch\Request\UuidNormalizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Terminal42\NodeBundle\NodeManager;
use Terminal42\NotificationCenterBundle\NotificationCenter;

/**
 * Shared flow of the confirm and cancel modules: the status is only changed on a
 * real action (directly, or after a button click in button mode), and the
 * notification is only sent when at least one registration actually changed.
 */
abstract class AbstractRegistrationActionController extends AbstractFrontendModuleController
{
    public function __construct(
        private readonly EventRegistration $eventRegistration,
        private readonly NodeManager $nodeManager,
        private readonly TranslatorInterface $translator,
        private readonly SimpleTokenParser $simpleTokenParser,
        private readonly NotificationCenter $notificationCenter,
        protected readonly StatusChanger $statusChanger,
        protected readonly StatusWriter $statusWriter,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
    ) {
    }

    public static function shouldExecute(bool $requireButton, Request $request, string $formId): bool
    {
        if (!$requireButton) {
            return true;
        }

        return $request->isMethod('POST') && $formId === $request->request->get('FORM_SUBMIT');
    }

    /**
     * The value of the "action" query parameter this module reacts to.
     */
    abstract protected function getAction(): string;

    abstract protected function decide(EventRegistrationModel $registration, CalendarEventsModel $event, int $now): Decision;

    /**
     * Changes the status atomically. Returns false if a parallel request was faster.
     */
    abstract protected function apply(EventRegistrationModel $registration): bool;

    /**
     * Called once after at least one registration changed.
     *
     * @param list<CalendarEventsModel> $events distinct events of the changed registrations
     */
    protected function afterChange(array $events): void
    {
    }

    protected static function toTimestamp(mixed $value): ?int
    {
        return empty($value) ? null : (int) $value;
    }

    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
    {
        if ($this->getAction() !== $request->query->get('action')) {
            return new Response();
        }

        $uuids = UuidNormalizer::normalize($request->query->all()['uuid'] ?? null);
        $now = time();

        /** @var array<int, EventRegistrationModel> $registrations keyed by registration ID */
        $registrations = [];
        $allowed = [];
        $messages = [];

        $template->showButton = false;

        $registrationAdapter = $this->getContaoAdapter(EventRegistrationModel::class);
        $eventAdapter = $this->getContaoAdapter(CalendarEventsModel::class);

        foreach ($uuids as $uuid) {
            /** @var EventRegistrationModel|null $registration */
            $registration = $registrationAdapter->findOneByUuid($uuid);

            if (!$registration) {
                throw new PageNotFoundException('No registration found.');
            }

            // Different spellings of a UUID can load the same registration.
            if (isset($registrations[(int) $registration->id])) {
                continue;
            }

            if (!$event = $eventAdapter->findById((int) $registration->pid)) {
                throw new PageNotFoundException('No event found.');
            }

            $registrations[(int) $registration->id] = $registration;
            $template->event = $event;
            $template->registration = $registration;

            $decision = $this->decide($registration, $event, $now);

            if (Decision::Allowed === $decision) {
                $allowed[] = [$registration, $event];

                continue;
            }

            $template->class .= ' '.$decision->cssClass();
            $template->{$decision->templateFlag()} = true;
            $messages[] = $this->translator->trans($decision->translationKey(), [], 'im_contao_event_registration');
        }

        $template->message = implode(' ', array_unique($messages));

        $formId = 'compatch_'.$this->getAction().'_'.$model->id;

        if ([] !== $allowed && !self::shouldExecute((bool) $model->compatch_requireButton, $request, $formId)) {
            $this->addButtonToTemplate($template, $request, $formId, $allowed);

            return $this->noStore($template->getResponse());
        }

        $changed = [];
        $changedEvents = [];

        foreach ($allowed as [$registration, $event]) {
            $isChanged = $this->apply($registration);
            $registration->refresh();

            if (!$isChanged) {
                continue;
            }

            $changed[] = $registration;
            $changedEvents[(int) $event->id] = $event;
        }

        $tokens = $this->eventRegistration->getSimpleTokensForMultipleRegistrations(array_values($registrations));

        $template->content = function () use ($model, $tokens): ?string {
            if ($nodes = StringUtil::deserialize($model->nodes, true)) {
                return $this->simpleTokenParser->parse(implode('', $this->nodeManager->generateMultiple($nodes)), $tokens);
            }

            return null;
        };

        if ([] !== $changed) {
            if ($model->nc_notification) {
                $this->notificationCenter->sendNotification(
                    (int) $model->nc_notification,
                    $this->eventRegistration->getSimpleTokensForMultipleRegistrations($changed),
                );
            }

            $this->afterChange(array_values($changedEvents));
        }

        return $this->noStore($template->getResponse());
    }

    /**
     * @param list<array{EventRegistrationModel, CalendarEventsModel}> $allowed
     */
    private function addButtonToTemplate(Template $template, Request $request, string $formId, array $allowed): void
    {
        $titles = array_values(array_unique(array_map(
            static fn (array $pair): string => (string) $pair[1]->title,
            $allowed,
        )));

        $template->showButton = true;
        $template->formId = $formId;
        // Rebuild the URL instead of echoing the raw request URI: the normalized query
        // string percent-encodes "{" and "}", so no insert tags reach the output.
        $queryString = $request->getQueryString();
        $formAction = $request->getBaseUrl().$request->getPathInfo().(null !== $queryString ? '?'.$queryString : '');
        $template->formAction = StringUtil::specialchars($formAction, true);
        $template->requestToken = $this->csrfTokenManager->getDefaultTokenValue();
        $template->question = $this->translator->trans(
            $this->getAction().'_question',
            ['%events%' => StringUtil::specialchars(implode(', ', $titles))],
            'plakart_event_registration_compatch',
        );
        $template->buttonLabel = $this->translator->trans($this->getAction().'_button', [], 'plakart_event_registration_compatch');
    }

    private function noStore(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
