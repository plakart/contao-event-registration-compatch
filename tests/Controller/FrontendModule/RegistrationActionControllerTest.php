<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\Controller\FrontendModule;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\String\SimpleTokenParser;
use Contao\FrontendTemplate;
use Contao\ModuleModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InspiredMinds\ContaoEventRegistration\EventRegistration;
use InspiredMinds\ContaoEventRegistration\Model\EventRegistrationModel;
use InspiredMinds\ContaoEventRegistration\WaitingListChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\AbstractRegistrationActionController;
use Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\CancelController;
use Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\ConfirmController;
use Plakart\ContaoEventRegistrationCompatch\Registration\StatusChanger;
use Plakart\ContaoEventRegistrationCompatch\Registration\StatusWriter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Terminal42\NodeBundle\NodeManager;
use Terminal42\NotificationCenterBundle\NotificationCenter;
use Terminal42\NotificationCenterBundle\Receipt\ReceiptCollection;

/**
 * Runs the whole request flow of the controllers with an in-memory database and
 * mocked Contao models, so "one mail per real change" is pinned end to end.
 */
final class RegistrationActionControllerTest extends TestCase
{
    private Connection $connection;

    /**
     * @var array<string, EventRegistrationModel&MockObject>
     */
    private array $registrations = [];

    /**
     * @var array<int, CalendarEventsModel&MockObject>
     */
    private array $events = [];

    /**
     * @var list<list<int>> registration ids passed per notification
     */
    private array $sentNotifications = [];

    /**
     * @var list<int> event ids passed to the waiting list checker
     */
    private array $waitingListEvents = [];

    private \ArrayObject $templateData;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE tl_event_registration (id INTEGER PRIMARY KEY, confirmed INTEGER NOT NULL DEFAULT 0, cancelled INTEGER NOT NULL DEFAULT 0)');

        $this->addEvent(10, 'Workshop');
    }

    public function testConfirmsAndNotifiesOnce(): void
    {
        $this->addRegistration('abc', 1, 10);

        $response = $this->confirm($this->get('?action=confirm&uuid[]=abc'));

        $this->assertSame(1, $this->dbValue(1, 'confirmed'));
        $this->assertSame([[1]], $this->sentNotifications);
        $this->assertSame('', $this->templateData['message']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function testAlreadyConfirmedSendsNoMail(): void
    {
        $this->addRegistration('abc', 1, 10, confirmed: true);

        $this->confirm($this->get('?action=confirm&uuid[]=abc'));

        $this->assertSame([], $this->sentNotifications);
        $this->assertSame('already_confirmed', $this->templateData['message']);
        $this->assertTrue($this->templateData['alreadyConfirmed']);
    }

    public function testLostRaceSendsNoMail(): void
    {
        // A parallel request confirmed the row after this one loaded the model.
        $this->addRegistration('abc', 1, 10);
        $this->connection->executeStatement('UPDATE tl_event_registration SET confirmed = 1 WHERE id = 1');

        $this->confirm($this->get('?action=confirm&uuid[]=abc'));

        $this->assertSame([], $this->sentNotifications);
    }

    public function testForeignActionReturnsEmptyResponse(): void
    {
        $this->addRegistration('abc', 1, 10);

        $response = $this->confirm($this->get('?action=cancel&uuid[]=abc'));

        $this->assertSame('', $response->getContent());
        $this->assertSame(0, $this->dbValue(1, 'confirmed'));
        $this->assertSame([], $this->sentNotifications);
    }

    public function testButtonModeGetOnlyAsks(): void
    {
        $this->addRegistration('abc', 1, 10);

        $this->confirm($this->get('?action=confirm&uuid[]=abc'), requireButton: true);

        $this->assertSame(0, $this->dbValue(1, 'confirmed'));
        $this->assertSame([], $this->sentNotifications);
        $this->assertTrue($this->templateData['showButton']);
        $this->assertSame('compatch_confirm_6', $this->templateData['formId']);
        $this->assertSame('token', $this->templateData['requestToken']);
        $this->assertSame('confirm_question[Workshop]', $this->templateData['question']);
    }

    public function testButtonModePostExecutes(): void
    {
        $this->addRegistration('abc', 1, 10);
        $request = Request::create('/bestaetigung?action=confirm&uuid[]=abc', 'POST', ['FORM_SUBMIT' => 'compatch_confirm_6']);

        $this->confirm($request, requireButton: true);

        $this->assertSame(1, $this->dbValue(1, 'confirmed'));
        $this->assertSame([[1]], $this->sentNotifications);
        $this->assertFalse($this->templateData['showButton']);
    }

    public function testMultipleRegistrationsNotifyOnlyChangedOnes(): void
    {
        $this->addRegistration('abc', 1, 10);
        $this->addRegistration('def', 2, 10, confirmed: true);

        $this->confirm($this->get('?action=confirm&uuid[]=abc&uuid[]=def'));

        $this->assertSame([[1]], $this->sentNotifications);
        $this->assertSame('already_confirmed', $this->templateData['message']);
    }

    public function testCaseVariantsOfOneUuidCountOnce(): void
    {
        // The uuid column compares case-insensitively, so both values load the same registration.
        $registration = $this->addRegistration('abc', 1, 10);
        $this->registrations['ABC'] = $registration;

        $this->confirm($this->get('?action=confirm&uuid[]=abc&uuid[]=ABC'));

        $this->assertSame([[1]], $this->sentNotifications);
        $this->assertSame([1], $this->templateData['contentRegistrationIds']);
    }

    public function testInsertTagsInTheUrlAreNotOutput(): void
    {
        $this->addRegistration('abc', 1, 10);
        $request = $this->get('?action=confirm&uuid[]=abc');
        $request->server->set('REQUEST_URI', '/bestaetigung?action=confirm&uuid[]=abc&x={{date}}');

        $this->confirm($request, requireButton: true);

        $this->assertStringNotContainsString('{{', $this->templateData['formAction']);
        $this->assertStringStartsWith('/bestaetigung?', $this->templateData['formAction']);
        $this->assertStringContainsString('action=confirm', $this->templateData['formAction']);
        $this->assertStringContainsString('uuid%5B0%5D=abc', $this->templateData['formAction']);
    }

    public function testCancelRunsWaitingListOncePerChangedEvent(): void
    {
        $this->addEvent(20, 'Seminar');
        $this->addRegistration('abc', 1, 10);
        $this->addRegistration('def', 2, 10);
        $this->addRegistration('ghi', 3, 20, cancelled: true);

        $this->cancel($this->get('?action=cancel&uuid[]=abc&uuid[]=def&uuid[]=ghi'));

        $this->assertSame(1, $this->dbValue(1, 'cancelled'));
        $this->assertSame(1, $this->dbValue(2, 'cancelled'));
        $this->assertSame([[1, 2]], $this->sentNotifications);
        $this->assertSame([10], $this->waitingListEvents);
    }

    public function testCancelWithoutChangeSkipsWaitingList(): void
    {
        $this->addRegistration('abc', 1, 10, cancelled: true);

        $this->cancel($this->get('?action=cancel&uuid[]=abc'));

        $this->assertSame([], $this->sentNotifications);
        $this->assertSame([], $this->waitingListEvents);
    }

    private function confirm(Request $request, bool $requireButton = false): Response
    {
        $controller = new ConfirmController(...$this->dependencies());

        return $this->handle($controller, $request, $requireButton);
    }

    private function cancel(Request $request): Response
    {
        $waitingListChecker = $this->createMock(WaitingListChecker::class);
        $waitingListChecker
            ->method('__invoke')
            ->willReturnCallback(function (?CalendarEventsModel $event): void {
                $this->waitingListEvents[] = (int) $event?->id;
            })
        ;

        $controller = new CancelController(...[...$this->dependencies(), $waitingListChecker]);

        return $this->handle($controller, $request, false);
    }

    /**
     * @return list<object>
     */
    private function dependencies(): array
    {
        $eventRegistration = $this->createMock(EventRegistration::class);
        $eventRegistration
            ->method('getSimpleTokensForMultipleRegistrations')
            ->willReturnCallback(static fn (array $registrations): array => [
                'ids' => array_map(static fn ($registration): int => (int) $registration->id, $registrations),
            ])
        ;

        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = []): string => [] === $parameters ? $id : $id.'['.implode(',', $parameters).']')
        ;

        $notificationCenter = $this->createMock(NotificationCenter::class);
        $notificationCenter
            ->method('sendNotification')
            ->willReturnCallback(function (int $id, array $tokens): ReceiptCollection {
                $this->assertSame(4, $id);
                $this->sentNotifications[] = $tokens['ids'];

                return new ReceiptCollection();
            })
        ;

        $csrfTokenManager = $this->createMock(ContaoCsrfTokenManager::class);
        $csrfTokenManager->method('getDefaultTokenValue')->willReturn('token');

        $simpleTokenParser = $this->createMock(SimpleTokenParser::class);
        $simpleTokenParser
            ->method('parse')
            ->willReturnCallback(function (string $content, array $tokens): string {
                $this->templateData['contentRegistrationIds'] = $tokens['ids'];

                return $content;
            })
        ;

        return [
            $eventRegistration,
            $this->createMock(NodeManager::class),
            $translator,
            $simpleTokenParser,
            $notificationCenter,
            new StatusChanger(),
            new StatusWriter($this->connection),
            $csrfTokenManager,
        ];
    }

    private function handle(AbstractRegistrationActionController $controller, Request $request, bool $requireButton): Response
    {
        $registrationAdapter = $this->createMock(Adapter::class);
        $registrationAdapter
            ->method('__call')
            ->willReturnCallback(fn (string $method, array $arguments) => match ($method) {
                'findOneByUuid' => $this->registrations[$arguments[0]] ?? null,
            })
        ;

        $eventAdapter = $this->createMock(Adapter::class);
        $eventAdapter
            ->method('__call')
            ->willReturnCallback(fn (string $method, array $arguments) => match ($method) {
                'findById' => $this->events[$arguments[0]] ?? null,
            })
        ;

        $framework = $this->createMock(ContaoFramework::class);
        $framework
            ->method('getAdapter')
            ->willReturnCallback(static fn (string $class): Adapter => match ($class) {
                EventRegistrationModel::class => $registrationAdapter,
                CalendarEventsModel::class => $eventAdapter,
            })
        ;

        $container = new Container();
        $container->set('contao.framework', $framework);
        $controller->setContainer($container);

        $model = $this->mockWithProperties(ModuleModel::class, [
            'id' => 6,
            'nodes' => serialize([1]),
            'nc_notification' => '4',
            'compatch_requireButton' => $requireButton ? '1' : '',
        ]);

        $this->templateData = new \ArrayObject(['class' => 'mod_test']);
        $template = $this->mockWithProperties(FrontendTemplate::class, $this->templateData);
        $template
            ->method('getResponse')
            ->willReturnCallback(function (): Response {
                // Resolve the lazy content so the tokens it was built with are visible.
                $content = $this->templateData['content'] ?? null;
                $this->templateData['contentRegistrationIds'] = null;

                if ($content instanceof \Closure) {
                    $content();
                }

                return new Response('rendered');
            })
        ;

        $method = new \ReflectionMethod($controller, 'getResponse');

        return $method->invoke($controller, $template, $model, $request);
    }

    private function get(string $query): Request
    {
        return Request::create('/bestaetigung'.$query);
    }

    private function addEvent(int $id, string $title): void
    {
        $this->events[$id] = $this->mockWithProperties(CalendarEventsModel::class, [
            'id' => $id,
            'title' => $title,
            'reg_regEnd' => '',
            'reg_cancelEnd' => '',
        ]);
    }

    private function addRegistration(string $uuid, int $id, int $pid, bool $confirmed = false, bool $cancelled = false): EventRegistrationModel&MockObject
    {
        $this->connection->insert('tl_event_registration', ['id' => $id, 'confirmed' => (int) $confirmed, 'cancelled' => (int) $cancelled]);

        return $this->registrations[$uuid] = $this->mockWithProperties(EventRegistrationModel::class, [
            'id' => $id,
            'pid' => $pid,
            'uuid' => $uuid,
            'confirmed' => $confirmed ? '1' : '',
            'cancelled' => $cancelled ? '1' : '',
        ]);
    }

    private function dbValue(int $id, string $column): int
    {
        return (int) $this->connection->fetchOne("SELECT $column FROM tl_event_registration WHERE id = ?", [$id]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>                                  $class
     * @param array<string, mixed>|\ArrayObject<string, mixed> $properties
     *
     * @return T&MockObject
     */
    private function mockWithProperties(string $class, array|\ArrayObject $properties): MockObject
    {
        $data = $properties instanceof \ArrayObject ? $properties : new \ArrayObject($properties);

        $mock = $this->createMock($class);
        $mock->method('__get')->willReturnCallback(static fn (string $key): mixed => $data[$key] ?? null);
        $mock->method('__isset')->willReturnCallback(static fn (string $key): bool => isset($data[$key]));
        $mock->method('__set')->willReturnCallback(static function (string $key, mixed $value) use ($data): void {
            $data[$key] = $value;
        });

        return $mock;
    }
}
