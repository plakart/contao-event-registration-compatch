# Event Registration Compatch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Contao bundle `plakart/contao-event-registration-compatch` that replaces the confirm/cancel front end modules of `inspiredminds/contao-event-registration` so notifications (and the waiting list) only fire on a real status change, with an optional per-module button (POST) against e-mail link scanners.

**Architecture:** Two controllers registered for the plugin's existing module types with fragment priority 10 (Contao 5.3 keeps only the highest priority per type). Shared flow lives in an abstract controller; the status rules live in a pure `StatusChanger` returning a `Decision` enum. Config switches remove a controller definition so the original takes over again; a `tl_module` checkbox enables the button mode per module.

**Tech Stack:** PHP >=8.1, Contao 5.3, Symfony 6.4, terminal42/notification_center 2.x, PHPUnit 11, PHPStan 2 (level 6), PHP-CS-Fixer.

**Spec:** `docs/superpowers/specs/2026-10-01-event-registration-compatch-design.md`

## Global Constraints

- Package `plakart/contao-event-registration-compatch`, namespace `Plakart\ContaoEventRegistrationCompatch\`, license `LGPL-3.0-or-later`.
- `php: >=8.1`, `contao/core-bundle: ^5.3`, `contao/calendar-bundle: ^5.3`, `inspiredminds/contao-event-registration: >=2.2 <2.4` (narrowed to `^2.3` if Task 1 finds differences), `terminal42/notification_center: ^2.0`, `terminal42/contao-node: *`.
- No `cweagans/composer-patches`, no `.patch` files.
- Config root key: `plakart_contao_event_registration_compatch` with booleans `confirm` and `cancel`, both default `true`.
- Container parameters: `plakart_contao_event_registration_compatch.confirm`, `plakart_contao_event_registration_compatch.cancel`.
- Module types/templates unchanged: `event_registration_confirm` / `mod_event_registration_confirm`, `event_registration_cancel` / `mod_event_registration_cancel`; fragment priority `10`.
- `tl_module` field `compatch_requireButton`, checkbox, SQL `boolean` default `false`.
- Form id: `compatch_<action>_<moduleId>` (e.g. `compatch_confirm_6`), sent as `FORM_SUBMIT`.
- Translation domain for new texts: `plakart_event_registration_compatch`; original messages stay in `im_contao_event_registration`.
- Responses of both modules (except the empty response for a foreign `action`): `Cache-Control` contains `private` and `no-store`.
- English identifiers, comments, commit messages, docs; user-facing texts `de` + `en`.
- Code style: PHP-CS-Fixer `@Symfony` + `declare_strict_types`; PHPStan level 6 on `src`.

## Working environment

- Package dir on the DEV server: `/var/www/contao-extensions/contao-event-registration-compatch` (Windows share: `\\192.168.26.200\Web\contao-extensions\contao-event-registration-compatch`). Files may be written via the share; **all commands run on the server** via
  `ssh -i "C:\Users\JannikNölke\.ssh\id_ed25519_plakartdev" stefan@192.168.26.200 'cd /var/www/contao-extensions/contao-event-registration-compatch && <command>'`.
  Below, "Run:" shows only `<command>`.
- Server tools: PHP 8.4, Composer 2.10, git 2.47. `gh` is **not** installed.
- Git identity is set locally in the repo (Jannik Nölke). End every commit message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Integration test project: `/var/www/pcb` (DEV copy of Sunshine PCB, plugin 2.3.3). Do **not** read `.env`/`.env.local` there.
- Original plugin code for reference: `/var/www/pcb/vendor/inspiredminds/contao-event-registration`.

## Review Focus

1. Duplicate UUIDs (`?uuid[]=A&uuid[]=A`) → the registration is changed once and exactly one mail is sent (decisions are computed before applying, so duplicates would otherwise both be `Allowed`). Pinned in Task 4 (`UuidNormalizerTest`).
2. `?uuid=abc` (scalar) and `?uuid[]=abc` both work; nested arrays / empty strings → 404, not a PHP error. Pinned in Task 4.
3. Registration whose event was deleted → 404 (`PageNotFoundException`), not a `TypeError`. Implemented in Task 4, checked in Task 7.
4. Browser reload of the POST after a successful action → "already confirmed/cancelled", no second mail. Follows from `StatusChanger` (Task 2 tests) and checked manually in Task 7.
5. Event title with special characters (`&`, quotes, `<`) in the question → escaped once, not double-escaped (`StringUtil::specialchars`, double-encode off). Checked manually in Task 7.

---

### Task 1: Scaffold the package and pin the plugin version range

**Files:**
- Create: `composer.json`, `.gitignore`, `.gitattributes`, `LICENSE`, `.php-cs-fixer.dist.php`, `phpstan.neon.dist`, `phpunit.xml.dist`, `tests/.gitkeep`

**Interfaces:**
- Produces: autoloading for `Plakart\ContaoEventRegistrationCompatch\` (`src/`) and `Plakart\ContaoEventRegistrationCompatch\Tests\` (`tests/`); composer scripts `test`, `phpstan`, `cs-fix`.

- [ ] **Step 1: Compare plugin versions 2.2.5 and 2.3.3**

Run (on the server, outside the package):
```bash
rm -rf /tmp/cer && git clone -q https://github.com/inspiredminds/contao-event-registration /tmp/cer && cd /tmp/cer && git diff 2.2.5 2.3.3 --stat -- src/ && git diff 2.2.5 2.3.3 -- src/Controller/FrontendModule/EventRegistrationConfirmController.php src/Controller/FrontendModule/EventRegistrationCancelController.php src/WaitingListChecker.php src/EventRegistration.php
```
Then check `terminal42/contao-node` in `git show 2.2.5:composer.json` and `NodeManager::generateMultiple(array $idsOrAliases): array` exists in the contao-node versions 2.2.5 allows.

Decision rule: the bundle relies on the controllers' constants `TYPE`/`ACTION`, `EventRegistration::getSimpleTokensForMultipleRegistrations(Collection|array)`, `WaitingListChecker::__invoke(CalendarEventsModel|null)`, `EventRegistrationModel::findOneByUuid()`, `NodeManager::generateMultiple(array)`. If any of these differs in 2.2.5 → use `"inspiredminds/contao-event-registration": "^2.3"`, otherwise `">=2.2 <2.4"`. Note the result for the commit message.

- [ ] **Step 2: Create `composer.json`**

```json
{
    "name": "plakart/contao-event-registration-compatch",
    "description": "Fixes for inspiredminds/contao-event-registration: confirm/cancel notifications only on real status changes, optional confirmation button against e-mail link scanners",
    "type": "contao-bundle",
    "license": "LGPL-3.0-or-later",
    "keywords": ["contao", "event registration", "notification", "link scanner"],
    "authors": [
        {
            "name": "Agentur plakart",
            "email": "technik@plakart.de",
            "homepage": "https://plakart.de",
            "role": "Publisher"
        }
    ],
    "require": {
        "php": ">=8.1",
        "contao/calendar-bundle": "^5.3",
        "contao/core-bundle": "^5.3",
        "inspiredminds/contao-event-registration": ">=2.2 <2.4",
        "terminal42/contao-node": "*",
        "terminal42/notification_center": "^2.0"
    },
    "require-dev": {
        "contao/manager-plugin": "^2.0",
        "friendsofphp/php-cs-fixer": "^3.40",
        "phpstan/phpstan": "^2.0",
        "phpunit/phpunit": "^10.5 || ^11.0"
    },
    "conflict": {
        "contao/manager-plugin": "<2.0 || >=3.0"
    },
    "autoload": {
        "psr-4": {
            "Plakart\\ContaoEventRegistrationCompatch\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Plakart\\ContaoEventRegistrationCompatch\\Tests\\": "tests/"
        }
    },
    "extra": {
        "contao-manager-plugin": "Plakart\\ContaoEventRegistrationCompatch\\ContaoManager\\Plugin"
    },
    "scripts": {
        "cs-fix": "php-cs-fixer fix",
        "phpstan": "phpstan analyse",
        "test": "phpunit"
    },
    "config": {
        "allow-plugins": {
            "contao-components/installer": true,
            "contao/manager-plugin": true,
            "php-http/discovery": true
        },
        "sort-packages": true
    }
}
```
Replace the plugin constraint according to Step 1.

- [ ] **Step 3: Create tooling files**

`.gitignore`:
```
/vendor/
composer.lock
.claude/settings.local.json
CLAUDE.local.md
.phpunit.result.cache
.phpunit.cache/
.php-cs-fixer.cache
.superpowers/
.idea/
```

`.gitattributes`:
```
* text=auto eol=lf
/docs export-ignore
/tests export-ignore
/.gitattributes export-ignore
/.gitignore export-ignore
/.php-cs-fixer.dist.php export-ignore
/phpstan.neon.dist export-ignore
/phpunit.xml.dist export-ignore
```

`.php-cs-fixer.dist.php`:
```php
<?php

declare(strict_types=1);

$dirs = array_filter(
    [__DIR__.'/src', __DIR__.'/tests', __DIR__.'/contao'],
    'is_dir',
);

$finder = PhpCsFixer\Finder::create()->in($dirs);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder)
;
```

`phpstan.neon.dist`:
```neon
parameters:
    level: 6
    paths:
        - src
```

`phpunit.xml.dist`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

`tests/.gitkeep`: empty file.

`LICENSE`: Run `curl -fsSL https://www.gnu.org/licenses/lgpl-3.0.txt -o LICENSE` (LGPL 3.0 text; `head -3 LICENSE` must show "GNU LESSER GENERAL PUBLIC LICENSE").

- [ ] **Step 4: Install dependencies**

Run: `composer install --no-interaction`
Expected: completes without error. If Composer aborts because another plugin is not in `allow-plugins`, add it with `false` (or `true` if it is `contao-components/installer`-like and required), rerun, and include the change in the commit.

Run: `vendor/bin/phpunit --version && vendor/bin/phpstan --version && vendor/bin/php-cs-fixer --version`
Expected: three version lines.

- [ ] **Step 5: Commit**

```bash
git add composer.json .gitignore .gitattributes LICENSE .php-cs-fixer.dist.php phpstan.neon.dist phpunit.xml.dist tests/.gitkeep
git commit -m "chore: scaffold package (plugin range <result of step 1>)"
```

---

### Task 2: `Decision` enum and `StatusChanger`

**Files:**
- Create: `src/Registration/Decision.php`, `src/Registration/StatusChanger.php`
- Test: `tests/Registration/DecisionTest.php`, `tests/Registration/StatusChangerTest.php`

**Interfaces:**
- Produces:
  - `enum Plakart\ContaoEventRegistrationCompatch\Registration\Decision { Allowed, AlreadyConfirmed, AlreadyCancelled, ConfirmExpired, CancelExpired }` with `translationKey(): string`, `cssClass(): string`, `templateFlag(): string` (all three throw `\LogicException` for `Allowed`).
  - `final class StatusChanger` with `decideConfirm(bool $confirmed, bool $cancelled, int|null $regEnd, int $now): Decision` and `decideCancel(bool $cancelled, int|null $cancelEnd, int $now): Decision`.

- [ ] **Step 1: Write the failing tests**

`tests/Registration/StatusChangerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\Registration;

use Plakart\ContaoEventRegistrationCompatch\Registration\Decision;
use Plakart\ContaoEventRegistrationCompatch\Registration\StatusChanger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusChangerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /**
     * @return iterable<string, array{bool, bool, int|null, Decision}>
     */
    public static function confirmProvider(): iterable
    {
        yield 'new, no end date' => [false, false, null, Decision::Allowed];
        yield 'new, end date in future' => [false, false, self::NOW + 1, Decision::Allowed];
        yield 'new, end date is now' => [false, false, self::NOW, Decision::Allowed];
        yield 'new, end date passed' => [false, false, self::NOW - 1, Decision::ConfirmExpired];
        yield 'already confirmed' => [true, false, null, Decision::AlreadyConfirmed];
        yield 'already cancelled' => [false, true, null, Decision::AlreadyCancelled];
        yield 'confirmed and cancelled: confirmed wins' => [true, true, null, Decision::AlreadyConfirmed];
        yield 'cancelled and expired: cancelled wins' => [false, true, self::NOW - 1, Decision::AlreadyCancelled];
    }

    #[DataProvider('confirmProvider')]
    public function testDecideConfirm(bool $confirmed, bool $cancelled, int|null $regEnd, Decision $expected): void
    {
        $this->assertSame($expected, (new StatusChanger())->decideConfirm($confirmed, $cancelled, $regEnd, self::NOW));
    }

    /**
     * @return iterable<string, array{bool, int|null, Decision}>
     */
    public static function cancelProvider(): iterable
    {
        yield 'new, no end date' => [false, null, Decision::Allowed];
        yield 'new, end date in future' => [false, self::NOW + 1, Decision::Allowed];
        yield 'new, end date is now' => [false, self::NOW, Decision::Allowed];
        yield 'new, end date passed' => [false, self::NOW - 1, Decision::CancelExpired];
        yield 'already cancelled' => [true, null, Decision::AlreadyCancelled];
        yield 'cancelled and expired: cancelled wins' => [true, self::NOW - 1, Decision::AlreadyCancelled];
    }

    #[DataProvider('cancelProvider')]
    public function testDecideCancel(bool $cancelled, int|null $cancelEnd, Decision $expected): void
    {
        $this->assertSame($expected, (new StatusChanger())->decideCancel($cancelled, $cancelEnd, self::NOW));
    }
}
```

`tests/Registration/DecisionTest.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\Registration;

use Plakart\ContaoEventRegistrationCompatch\Registration\Decision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionTest extends TestCase
{
    /**
     * @return iterable<string, array{Decision, string, string, string}>
     */
    public static function mappingProvider(): iterable
    {
        yield 'already confirmed' => [Decision::AlreadyConfirmed, 'already_confirmed', 'already-confirmed', 'alreadyConfirmed'];
        yield 'already cancelled' => [Decision::AlreadyCancelled, 'already_cancelled', 'already-cancelled', 'alreadyCancelled'];
        yield 'confirm expired' => [Decision::ConfirmExpired, 'cannot_confirm', 'cannot-confirm', 'cannotConfirm'];
        yield 'cancel expired' => [Decision::CancelExpired, 'cannot_cancel', 'cannot-cancel', 'cannotCancel'];
    }

    #[DataProvider('mappingProvider')]
    public function testMapping(Decision $decision, string $key, string $cssClass, string $flag): void
    {
        $this->assertSame($key, $decision->translationKey());
        $this->assertSame($cssClass, $decision->cssClass());
        $this->assertSame($flag, $decision->templateFlag());
    }

    public function testAllowedHasNoMessage(): void
    {
        $this->expectException(\LogicException::class);

        Decision::Allowed->translationKey();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Registration`
Expected: FAIL / errors with `Class "Plakart\ContaoEventRegistrationCompatch\Registration\StatusChanger" not found` (and `Decision`).

- [ ] **Step 3: Implement**

`src/Registration/Decision.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Registration;

/**
 * Outcome of checking whether a registration may be confirmed or cancelled.
 *
 * The non-allowed cases map to the translation keys, template flags and CSS
 * classes the original plugin uses, so existing templates keep working.
 */
enum Decision
{
    case Allowed;
    case AlreadyConfirmed;
    case AlreadyCancelled;
    case ConfirmExpired;
    case CancelExpired;

    /**
     * Key in the plugin's translation domain "im_contao_event_registration".
     */
    public function translationKey(): string
    {
        return match ($this) {
            self::AlreadyConfirmed => 'already_confirmed',
            self::AlreadyCancelled => 'already_cancelled',
            self::ConfirmExpired => 'cannot_confirm',
            self::CancelExpired => 'cannot_cancel',
            self::Allowed => throw new \LogicException('An allowed decision has no message.'),
        };
    }

    public function cssClass(): string
    {
        return str_replace('_', '-', $this->translationKey());
    }

    public function templateFlag(): string
    {
        return lcfirst(str_replace('_', '', ucwords($this->translationKey(), '_')));
    }
}
```

`src/Registration/StatusChanger.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Registration;

/**
 * Decides whether a registration may change its status. Same rules and order as
 * the original plugin's processConfirm()/processCancel().
 */
final class StatusChanger
{
    public function decideConfirm(bool $confirmed, bool $cancelled, int|null $regEnd, int $now): Decision
    {
        if ($confirmed) {
            return Decision::AlreadyConfirmed;
        }

        if ($cancelled) {
            return Decision::AlreadyCancelled;
        }

        if (null !== $regEnd && $now > $regEnd) {
            return Decision::ConfirmExpired;
        }

        return Decision::Allowed;
    }

    public function decideCancel(bool $cancelled, int|null $cancelEnd, int $now): Decision
    {
        if ($cancelled) {
            return Decision::AlreadyCancelled;
        }

        if (null !== $cancelEnd && $now > $cancelEnd) {
            return Decision::CancelExpired;
        }

        return Decision::Allowed;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Registration`
Expected: PASS (19 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Registration tests/Registration
git rm -q --cached tests/.gitkeep && rm tests/.gitkeep
git commit -m "feat: add status decision logic for confirm and cancel"
```

---

### Task 3: Bundle, Manager plugin, configuration and extension

**Files:**
- Create: `src/PlakartContaoEventRegistrationCompatchBundle.php`, `src/ContaoManager/Plugin.php`, `src/DependencyInjection/Configuration.php`, `src/DependencyInjection/PlakartContaoEventRegistrationCompatchExtension.php`, `config/services.yaml`
- Create (stubs, completed in Task 4): `src/Controller/FrontendModule/ConfirmController.php`, `src/Controller/FrontendModule/CancelController.php`
- Test: `tests/DependencyInjection/PlakartContaoEventRegistrationCompatchExtensionTest.php`

**Interfaces:**
- Consumes: `StatusChanger` (Task 2) — registered as a service via the resource scan.
- Produces: service IDs `Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\ConfirmController` and `...\CancelController` (FQCN); parameters `plakart_contao_event_registration_compatch.confirm|cancel` (bool).

- [ ] **Step 1: Write the failing test**

`tests/DependencyInjection/PlakartContaoEventRegistrationCompatchExtensionTest.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\DependencyInjection;

use Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\CancelController;
use Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\ConfirmController;
use Plakart\ContaoEventRegistrationCompatch\DependencyInjection\PlakartContaoEventRegistrationCompatchExtension;
use Plakart\ContaoEventRegistrationCompatch\Registration\StatusChanger;
use PHPUnit\Framework\TestCase;
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/DependencyInjection`
Expected: FAIL with `Class "...PlakartContaoEventRegistrationCompatchExtension" not found`.

- [ ] **Step 3: Implement**

`src/PlakartContaoEventRegistrationCompatchBundle.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class PlakartContaoEventRegistrationCompatchBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
```

`src/ContaoManager/Plugin.php`:
```php
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
```

`src/DependencyInjection/Configuration.php`:
```php
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
```

`src/DependencyInjection/PlakartContaoEventRegistrationCompatchExtension.php`:
```php
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
```

`config/services.yaml`:
```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    Plakart\ContaoEventRegistrationCompatch\:
        resource: ../src/
        exclude: ../src/{ContaoManager,DependencyInjection,Registration/Decision.php,PlakartContaoEventRegistrationCompatchBundle.php}
```

Stub controllers so the resource scan finds the classes (replaced completely in Task 4):

`src/Controller/FrontendModule/ConfirmController.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule;

final class ConfirmController
{
}
```

`src/Controller/FrontendModule/CancelController.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule;

final class CancelController
{
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests of Task 2 and 3).

- [ ] **Step 5: Commit**

```bash
git add src config tests/DependencyInjection
git commit -m "feat: add bundle, manager plugin and config switches"
```

---

### Task 4: Controllers (shared flow, confirm, cancel)

**Files:**
- Create: `src/Controller/FrontendModule/AbstractRegistrationActionController.php`, `src/Request/UuidNormalizer.php`
- Modify (replace stubs): `src/Controller/FrontendModule/ConfirmController.php`, `src/Controller/FrontendModule/CancelController.php`
- Test: `tests/Request/UuidNormalizerTest.php`, `tests/Controller/FrontendModule/ShouldExecuteTest.php`

**Interfaces:**
- Consumes: `StatusChanger::decideConfirm()/decideCancel()`, `Decision::translationKey()/cssClass()/templateFlag()` (Task 2); service IDs and parameters (Task 3).
- Produces:
  - `final class UuidNormalizer { public static function normalize(mixed $raw): array }` → `list<string>`; throws `Contao\CoreBundle\Exception\PageNotFoundException` for empty/invalid input.
  - `AbstractRegistrationActionController::shouldExecute(bool $requireButton, Request $request, string $formId): bool` (public static).
  - Template variables used by Task 5: `showButton` (bool), `question` (string, escaped), `buttonLabel` (string), `formId` (string), `formAction` (string, escaped), `requestToken` (string), plus the original `message`, `content`, `event`, `registration`, `class` and flags.
  - Translation keys used by Task 5: `confirm_question`, `confirm_button`, `cancel_question`, `cancel_button` (domain `plakart_event_registration_compatch`, placeholder `%events%`).

- [ ] **Step 1: Write the failing tests**

`tests/Request/UuidNormalizerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\Request;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Plakart\ContaoEventRegistrationCompatch\Request\UuidNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UuidNormalizerTest extends TestCase
{
    public function testScalarUuid(): void
    {
        $this->assertSame(['abc'], UuidNormalizer::normalize('abc'));
    }

    public function testListOfUuids(): void
    {
        $this->assertSame(['abc', 'def'], UuidNormalizer::normalize(['abc', 'def']));
    }

    public function testDuplicatesAreRemoved(): void
    {
        $this->assertSame(['abc', 'def'], UuidNormalizer::normalize(['abc', 'def', 'abc']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'empty array' => [[]];
        yield 'nested array' => [['abc', ['def']]];
        yield 'empty element' => [['abc', '']];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidInputIsNotFound(mixed $raw): void
    {
        $this->expectException(PageNotFoundException::class);

        UuidNormalizer::normalize($raw);
    }
}
```

`tests/Controller/FrontendModule/ShouldExecuteTest.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests\Controller\FrontendModule;

use Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\AbstractRegistrationActionController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShouldExecuteTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, Request, bool}>
     */
    public static function provider(): iterable
    {
        $formId = 'compatch_confirm_6';

        yield 'no button, GET' => [false, Request::create('/x'), true];
        yield 'no button, POST' => [false, Request::create('/x', 'POST', ['FORM_SUBMIT' => $formId]), true];
        yield 'button, GET' => [true, Request::create('/x'), false];
        yield 'button, POST own form' => [true, Request::create('/x', 'POST', ['FORM_SUBMIT' => $formId]), true];
        yield 'button, POST foreign form' => [true, Request::create('/x', 'POST', ['FORM_SUBMIT' => 'compatch_confirm_7']), false];
        yield 'button, POST without FORM_SUBMIT' => [true, Request::create('/x', 'POST'), false];
        yield 'button, GET with FORM_SUBMIT in query' => [true, Request::create('/x?FORM_SUBMIT='.$formId), false];
    }

    #[DataProvider('provider')]
    public function testShouldExecute(bool $requireButton, Request $request, bool $expected): void
    {
        $this->assertSame(
            $expected,
            AbstractRegistrationActionController::shouldExecute($requireButton, $request, 'compatch_confirm_6'),
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Request tests/Controller`
Expected: errors `Class "...UuidNormalizer" not found` and `Class "...AbstractRegistrationActionController" not found`.

- [ ] **Step 3: Implement `UuidNormalizer`**

`src/Request/UuidNormalizer.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Request;

use Contao\CoreBundle\Exception\PageNotFoundException;

final class UuidNormalizer
{
    /**
     * Turns the raw "uuid" query value (string or list) into unique, non-empty
     * strings. Duplicates are removed so a registration is changed only once.
     *
     * @return list<string>
     */
    public static function normalize(mixed $raw): array
    {
        $uuids = \is_array($raw) ? $raw : [$raw];

        if ([] === $uuids) {
            throw new PageNotFoundException('No UUID given.');
        }

        foreach ($uuids as $uuid) {
            if (!\is_string($uuid) || '' === $uuid) {
                throw new PageNotFoundException('Invalid UUID given.');
            }
        }

        return array_values(array_unique($uuids));
    }
}
```

- [ ] **Step 4: Implement the abstract controller**

`src/Controller/FrontendModule/AbstractRegistrationActionController.php`:
```php
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

    abstract protected function apply(EventRegistrationModel $registration): void;

    /**
     * Called once after at least one registration changed.
     *
     * @param list<CalendarEventsModel> $events distinct events of the changed registrations
     */
    protected function afterChange(array $events): void
    {
    }

    protected static function toTimestamp(mixed $value): int|null
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
        $registrations = [];
        $allowed = [];
        $messages = [];

        $template->showButton = false;

        foreach ($uuids as $uuid) {
            if (!$registration = EventRegistrationModel::findOneByUuid($uuid)) {
                throw new PageNotFoundException('No registration found.');
            }

            if (!$event = CalendarEventsModel::findById((int) $registration->pid)) {
                throw new PageNotFoundException('No event found.');
            }

            $registrations[] = $registration;
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
            $this->apply($registration);
            $changed[] = $registration;
            $changedEvents[(int) $event->id] = $event;
        }

        $tokens = $this->eventRegistration->getSimpleTokensForMultipleRegistrations($registrations);

        $template->content = function () use ($model, $tokens): string|null {
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
        $template->formAction = StringUtil::specialchars($request->getRequestUri());
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
```

- [ ] **Step 5: Implement the concrete controllers**

`src/Controller/FrontendModule/ConfirmController.php`:
```php
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
```

`src/Controller/FrontendModule/CancelController.php`:
```php
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
```

- [ ] **Step 6: Run all tests and static analysis**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests, including the Task 3 extension tests, which now load the real controllers).

Run: `vendor/bin/phpstan analyse --no-progress`
Expected: `[OK] No errors`. If PHPStan reports undefined magic properties on Contao models (`$model->compatch_requireButton`, `$event->reg_regEnd` …), add a targeted `ignoreErrors` entry with `identifier: property.notFound` and the exact `path` to `phpstan.neon.dist` — no blanket ignores.

- [ ] **Step 7: Commit**

```bash
git add src tests phpstan.neon.dist
git commit -m "feat: add confirm and cancel controllers that notify only on real changes"
```

---

### Task 5: Templates, translations and backend field

**Files:**
- Create: `contao/templates/mod_event_registration_confirm.html5`, `contao/templates/mod_event_registration_cancel.html5`
- Create: `translations/plakart_event_registration_compatch.de.yaml`, `translations/plakart_event_registration_compatch.en.yaml`
- Create: `contao/dca/tl_module.php`, `contao/languages/de/tl_module.php`, `contao/languages/en/tl_module.php`
- Test: `tests/TranslationsTest.php`

**Interfaces:**
- Consumes: template variables and translation keys from Task 4; container parameters from Task 3.
- Produces: `tl_module.compatch_requireButton` (read by Task 4 as `$model->compatch_requireButton`).

- [ ] **Step 1: Write the failing test**

`tests/TranslationsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Plakart\ContaoEventRegistrationCompatch\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TranslationsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'de' => ['de'];
        yield 'en' => ['en'];
    }

    #[DataProvider('localeProvider')]
    public function testAllKeysExist(string $locale): void
    {
        $messages = Yaml::parseFile(__DIR__.'/../translations/plakart_event_registration_compatch.'.$locale.'.yaml');

        $this->assertSame(
            ['cancel_button', 'cancel_question', 'confirm_button', 'confirm_question'],
            $this->sortedKeys($messages),
        );
        $this->assertStringContainsString('%events%', $messages['confirm_question']);
        $this->assertStringContainsString('%events%', $messages['cancel_question']);
    }

    /**
     * @param array<string, string> $messages
     *
     * @return list<string>
     */
    private function sortedKeys(array $messages): array
    {
        $keys = array_keys($messages);
        sort($keys);

        return $keys;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TranslationsTest.php`
Expected: FAIL/error because the YAML files do not exist.

- [ ] **Step 3: Create translations**

`translations/plakart_event_registration_compatch.de.yaml`:
```yaml
confirm_question: 'Möchten Sie Ihre Anmeldung für „%events%“ verbindlich bestätigen?'
confirm_button: 'Anmeldung bestätigen'
cancel_question: 'Möchten Sie Ihre Anmeldung für „%events%“ wirklich stornieren?'
cancel_button: 'Anmeldung stornieren'
```

`translations/plakart_event_registration_compatch.en.yaml`:
```yaml
confirm_question: 'Do you want to confirm your registration for "%events%"?'
confirm_button: 'Confirm registration'
cancel_question: 'Do you really want to cancel your registration for "%events%"?'
cancel_button: 'Cancel registration'
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TranslationsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Create the templates**

`contao/templates/mod_event_registration_confirm.html5` and `contao/templates/mod_event_registration_cancel.html5` (identical content):
```php
<?php $this->extend('block_unsearchable'); ?>

<?php $this->block('content'); ?>

  <?php if ($this->showButton): ?>
    <?php if ($this->message): ?>
      <p><?= $this->message ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $this->formAction ?>" id="<?= $this->formId ?>">
      <input type="hidden" name="FORM_SUBMIT" value="<?= $this->formId ?>">
      <input type="hidden" name="REQUEST_TOKEN" value="<?= $this->requestToken ?>">
      <p><?= $this->question ?></p>
      <button type="submit" class="submit"><?= $this->buttonLabel ?></button>
    </form>
  <?php elseif ($this->message): ?>
    <p><?= $this->message ?></p>
  <?php else: ?>
    <?= $this->content ?>
  <?php endif; ?>

<?php $this->endblock(); ?>
```

- [ ] **Step 6: Create the DCA and labels**

`contao/dca/tl_module.php`:
```php
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
```

`contao/languages/de/tl_module.php`:
```php
<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_module']['compatch_requireButton'] = [
    'Erst nach Klick auf einen Button ausführen',
    'Der Link aus der E-Mail zeigt nur einen Button. Schützt vor E-Mail-Link-Scannern, die Links automatisch aufrufen. Eigene Templates müssen das Formular enthalten (siehe README).',
];
```

`contao/languages/en/tl_module.php`:
```php
<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_module']['compatch_requireButton'] = [
    'Execute only after clicking a button',
    'The link from the e-mail only shows a button. Protects against e-mail link scanners that open links automatically. Custom templates must contain the form (see README).',
];
```

- [ ] **Step 7: Lint and commit**

Run: `for f in contao/dca/*.php contao/languages/*/*.php contao/templates/*.html5; do php -l "$f" || exit 1; done && vendor/bin/phpunit`
Expected: `No syntax errors detected` for each file; tests PASS.

```bash
git add contao translations tests/TranslationsTest.php
git commit -m "feat: add button templates, texts and per-module button setting"
```

---

### Task 6: README, CHANGELOG and code style

**Files:**
- Create: `README.md`, `CHANGELOG.md`
- Modify: any file touched by `php-cs-fixer`

- [ ] **Step 1: Write `README.md`**

````markdown
# Contao Event Registration Compatch

Fixes for [`inspiredminds/contao-event-registration`](https://github.com/inspiredminds/contao-event-registration)
(2.2/2.3) until they are solved upstream (see issue #46, PR #54, issue #43).

## What it fixes

The plugin's front end modules **"Event registration confirmation"** and
**"Event registration cancellation"** send their notification on *every* call of the
link from the e-mail, even if the registration was already confirmed or cancelled.
E-mail link scanners (Microsoft Defender/Safe Links, Proofpoint, Mimecast …) open
these links automatically, so customers and admins receive the same mail 2–4 times.
For cancellation it is worse: a scanner opening the link cancels the registration.

With this bundle:

- the notification is sent **only if at least one registration actually changed**,
- the waiting list is recalculated **only after a real cancellation**,
- optionally per module, the link **only shows a button**; the action is executed
  after the click (POST), so link scanners cannot confirm or cancel anything.

## Installation

```bash
composer require plakart/contao-event-registration-compatch
vendor/bin/contao-console contao:migrate
```

or install it with the Contao Manager. Both fixes are active immediately; existing
modules and templates keep working without changes.

## Button mode

Edit the confirm or cancel module and enable **"Execute only after clicking a
button"**. The link then shows a question and a button; the status changes only
after the click. Already confirmed/cancelled/expired registrations show their
message directly.

> **Custom templates:** If the module uses a custom template
> (e.g. `mod_event_registration_confirm_custom.html5`), the template must contain
> the form below. Without it, nobody sees a button and nobody can confirm.

```php
<?php if ($this->showButton): ?>
  <?php if ($this->message): ?><p><?= $this->message ?></p><?php endif; ?>
  <form method="post" action="<?= $this->formAction ?>" id="<?= $this->formId ?>">
    <input type="hidden" name="FORM_SUBMIT" value="<?= $this->formId ?>">
    <input type="hidden" name="REQUEST_TOKEN" value="<?= $this->requestToken ?>">
    <p><?= $this->question ?></p>
    <button type="submit" class="submit"><?= $this->buttonLabel ?></button>
  </form>
<?php elseif ($this->message): ?>
  …
```

Texts can be changed in `translations/plakart_event_registration_compatch.<locale>.yaml`
of your project (keys `confirm_question`, `confirm_button`, `cancel_question`,
`cancel_button`; `%events%` = event titles).

## Configuration

Both replacements can be switched off in `config/config.yaml`; the plugin's
original module is used again:

```yaml
plakart_contao_event_registration_compatch:
    confirm: true
    cancel: true
```

## Manual test checklist

Run on a test installation before every release:

- **Without button:** open link → confirmed, exactly one mail; open again →
  "already confirmed", no mail.
- **With button:** open link → no status change, no mail, button visible; click →
  exactly one mail; open the link again → "already confirmed", no button, no mail;
  reload after the click → "already confirmed", no mail.
- The same for cancellation, including the waiting list: only the real
  cancellation promotes waiting-list registrations.
- Multiple registrations in one link: one button; mixed states show the messages
  plus the button.
- `confirm: false` → original behaviour again.
- The confirmation page is sent with `Cache-Control: private, no-store`.

## Maintenance

The bundle replaces the plugin's controllers and therefore depends on their
behaviour. When the plugin releases a new version: compare
`EventRegistrationConfirmController`, `EventRegistrationCancelController`,
`EventRegistration::getSimpleTokensForMultipleRegistrations()` and
`WaitingListChecker` with the supported versions, extend the version constraint
in `composer.json` and release a new version. If the fix is merged upstream,
the bundle can be removed.

## License

LGPL-3.0-or-later
````

- [ ] **Step 2: Write `CHANGELOG.md`**

```markdown
# Changelog

## [Unreleased]

### Added

- Replacement controllers for the "event_registration_confirm" and
  "event_registration_cancel" front end modules: notifications only on real status
  changes, waiting list only after a real cancellation.
- Optional button mode per module (`compatch_requireButton`) against e-mail link
  scanners.
- Config switches `confirm` and `cancel`.
```

- [ ] **Step 3: Run code style, static analysis and tests**

Run: `vendor/bin/php-cs-fixer fix && vendor/bin/phpstan analyse --no-progress && vendor/bin/phpunit`
Expected: cs-fixer lists fixed files (or none), PHPStan `[OK] No errors`, PHPUnit PASS.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "docs: add README and changelog; apply code style"
```

---

### Task 7: Integration test on the DEV copy of Sunshine (with the user)

**Files:**
- Modify: `/var/www/pcb/composer.json` (DEV copy only — never the Sunshine production project)

This task changes the DEV installation and sends real notifications. Ask the user before Step 1 and agree on: DEV site URL, which module(s)/registrations to use, and whether mails from DEV may go out to the configured recipients.

- [ ] **Step 1: Install the bundle via path repository**

Run (in `/var/www/pcb`):
```bash
cp composer.json /tmp/pcb-composer.json.bak
composer config repositories.compatch '{"type": "path", "url": "../contao-extensions/contao-event-registration-compatch", "options": {"symlink": true}}'
composer require "plakart/contao-event-registration-compatch:@dev" --no-interaction
vendor/bin/contao-console contao:migrate --no-interaction
vendor/bin/contao-console cache:clear
```
Expected: package symlinked; migrate adds `tl_module.compatch_requireButton`.

- [ ] **Step 2: Verify replacement and template precedence**

Run:
```bash
vendor/bin/contao-console debug:container 'Plakart\ContaoEventRegistrationCompatch\Controller\FrontendModule\ConfirmController' --show-arguments | head -20
vendor/bin/contao-console debug:contao-twig | grep -A12 -E '^mod_event_registration_(confirm|cancel)$'
```
Expected: the controller service is public and tagged `contao.frontend_module` with type `event_registration_confirm`; for both templates the entry with the highest precedence (listed first) has a `Path` inside `contao-extensions/contao-event-registration-compatch/contao/templates/`, not the plugin's `vendor/inspiredminds/…`.
If the plugin's template wins: rename both bundle templates to `mod_event_registration_confirm_compatch.html5` / `mod_event_registration_cancel_compatch.html5`, change the `template:` argument in both `#[AsFrontendModule]` attributes accordingly, document it in the README, rerun the tests, commit (`fix: use own template names`).

- [ ] **Step 3: Manual checks with the user**

Walk through the README checklist with the user on the DEV site, plus:
- Duplicate UUID link (`…&uuid[]=A&uuid[]=A`) → one change, one mail.
- Link with nested array (`uuid[x][]=A`) → 404 page, no PHP error in `var/logs`.
- A registration whose event was deleted → 404.
- Event title with `&` / quotes → question shows the title correctly (no `&amp;amp;`).
- `curl -sI '<confirm URL with action/uuid>'` → `Cache-Control` contains `private` and `no-store`.
- Set `plakart_contao_event_registration_compatch: { confirm: false }` in `config/config.yaml`, clear cache → original behaviour; remove it again.
Record the results in the commit message / hand them to the user.

- [ ] **Step 4: Leave the DEV copy installed**

Keep the path repository in `/var/www/pcb` until the package is on Packagist (Task 8 is a separate, user-approved step). `/tmp/pcb-composer.json.bak` allows rollback.

- [ ] **Step 5: Commit fixes (if any)**

Commit any fixes found in this task in the package repository, each with a test where feasible.

---

### Task 8 (only after explicit user approval): Publish

Not part of the automatic execution. Prerequisites: user approval for the public GitHub repository; `gh` is not installed on the DEV server, so either install/authenticate it or create the repository in the browser.

- [ ] Create the public repository `plakart/contao-event-registration-compatch`, add the remote (the existing repos use `git@github.com:plakart/<name>.git` or the SSH host alias `github-jannik:`), push `main`.
- [ ] Tag `1.0.0` (move the CHANGELOG entry from `[Unreleased]` to `[1.0.0] - <date>`), push the tag.
- [ ] User submits the package on Packagist.
- [ ] Rollout at Sunshine per spec section "Rollout at Sunshine PCB" (separate session; production).
