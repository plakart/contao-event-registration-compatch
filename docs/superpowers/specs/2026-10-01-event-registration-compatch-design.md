# Design: plakart/contao-event-registration-compatch

Date: 2026-10-01
Status: draft (awaiting review)

## Purpose

`inspiredminds/contao-event-registration` (up to and including 2.3.3) sends the
confirm/cancel notification on **every** GET request to
`?action=confirm|cancel&uuid[]=…`, regardless of whether the registration status
actually changed. E-mail link scanners (Microsoft Defender/Safe Links, Proofpoint,
Mimecast …) plus the real click cause 2–4 identical mails per registration. For
cancellation it is worse: a scanner's GET request cancels a real registration.

This bundle replaces the two front end modules `event_registration_confirm` and
`event_registration_cancel` with fixed controllers. It is reusable across client
projects and published on GitHub/Packagist. Upstream references: issue #46,
PR #54 (open, not merged), issue #43.

### Success criteria

1. A notification is sent only when at least one registration actually changed
   status — in every mode, for confirm and cancel.
2. The waiting list is recalculated only when a registration was actually
   cancelled.
3. Optionally per module, a GET request only shows a button; the status changes
   only after a POST (protection against link scanners).
4. Existing projects need no changes to modules or templates to get (1) and (2).

## Decisions (from brainstorming)

| Topic | Decision |
|---|---|
| Scope | Reusable bundle for several client projects, one compatch package per plugin |
| Package | `plakart/contao-event-registration-compatch`, namespace `Plakart\ContaoEventRegistrationCompatch`, LGPL-3.0-or-later |
| Hosting | Public on GitHub (`plakart` organisation) and Packagist |
| Activation | Active automatically; each replacement can be switched off in `config/config.yaml` |
| Scope v1 | Confirm **and** cancel: mail only on real status change (always); button (POST) optional |
| Button mode | Opt-in per front end module (checkbox in `tl_module`), default off = previous behaviour |
| Runtime version check | None; the Composer constraint protects against untested plugin versions |
| Short-term Sunshine patch | Its logic becomes part of this bundle; no `.patch` file, no `cweagans/composer-patches` |
| Language | English identifiers, comments, commits and docs; user-facing texts in `de` + `en` |

## Architecture

```
contao-event-registration-compatch/
├── composer.json
├── config/services.yaml
├── contao/
│   ├── dca/tl_module.php                    (compatch_requireButton checkbox)
│   ├── languages/{de,en}/tl_module.php      (field label)
│   └── templates/
│       ├── mod_event_registration_confirm.html5
│       └── mod_event_registration_cancel.html5
├── translations/
│   └── plakart_event_registration_compatch.{de,en}.yaml   (question + button texts)
├── src/
│   ├── PlakartContaoEventRegistrationCompatchBundle.php
│   ├── ContaoManager/Plugin.php
│   ├── DependencyInjection/
│   │   ├── Configuration.php
│   │   └── PlakartContaoEventRegistrationCompatchExtension.php
│   ├── Controller/FrontendModule/
│   │   ├── AbstractRegistrationActionController.php
│   │   ├── ConfirmController.php
│   │   └── CancelController.php
│   └── Registration/
│       ├── StatusChanger.php
│       └── Decision.php                     (enum)
├── tests/
├── README.md  CHANGELOG.md  LICENSE
├── .gitignore  .gitattributes  .php-cs-fixer.dist.php  phpstan.neon.dist  phpunit.xml.dist
└── docs/superpowers/{specs,plans}/
```

Tooling follows `plakart/contao-css-utilities`: PHP-CS-Fixer (`@Symfony` +
`declare_strict_types`), PHPStan level 6, PHPUnit; composer scripts `cs-fix`,
`phpstan`, `test`.

### Replacing the original modules

Verified in Contao 5.3 (`RegisterFragmentsPass::registerFragments()`): tagged
fragment services are sorted by the tag's `priority`, and for each
`tag + type` only the **highest priority wins**; the others lose their tag.

So no custom compiler pass is needed (deviation from the approved draft, which
listed this as the alternative to verify):

- `ConfirmController` is registered with
  `#[AsFrontendModule(type: 'event_registration_confirm', category: 'events', template: 'mod_event_registration_confirm', priority: 10)]`,
  `CancelController` analogously for `event_registration_cancel` /
  `mod_event_registration_cancel`.
- The original controllers (registered via the `@FrontendModule` annotation,
  priority 0) stay defined but lose their tag and are never called.
- Type strings are taken from the original constants
  (`EventRegistrationConfirmController::TYPE` etc.), so the module types, the
  plugin's palettes and the module records in client projects stay unchanged.

### Configuration

```yaml
# config/config.yaml in the client project (optional, these are the defaults)
plakart_contao_event_registration_compatch:
    confirm: true   # replace the "confirm" module
    cancel: true    # replace the "cancel" module
```

The root key follows from the bundle name (Symfony requires the extension alias
to match it), hence `plakart_contao_event_registration_compatch` instead of
`plakart_event_registration_compatch` from the draft.

`PlakartContaoEventRegistrationCompatchExtension::load()` loads
`config/services.yaml` and **removes** the definition of a controller whose switch
is `false`; then the original controller is the only one tagged for that type and
takes over again. It also sets the parameters
`plakart_contao_event_registration_compatch.confirm|cancel`, which the DCA uses to
show the checkbox only for replaced module types.

### Bundle loading

`ContaoManager/Plugin` loads the bundle after
`InspiredMinds\ContaoEventRegistration\ContaoEventRegistrationBundle`, so the
bundle's templates in `contao/templates` override the plugin's templates of the
same name. (To verify during implementation: Contao 5.3 legacy template
resolution gives the later bundle precedence. If not, rename the templates to
`mod_event_registration_confirm_compatch` etc. and use those as default
templates in the attributes.)

## Components

### `Registration\Decision` (enum)

`Allowed`, `AlreadyConfirmed`, `AlreadyCancelled`, `ConfirmExpired`,
`CancelExpired`. Each non-`Allowed` case maps to the plugin's translation key
(`already_confirmed`, `already_cancelled`, `cannot_confirm`, `cannot_cancel`,
domain `im_contao_event_registration`) and to the template flag/CSS class the
original sets (`alreadyConfirmed` / `already-confirmed`, …).

### `Registration\StatusChanger`

Pure logic, no Contao models, fully unit-testable:

```php
public function decideConfirm(bool $confirmed, bool $cancelled, int|null $regEnd, int $now): Decision;
public function decideCancel(bool $cancelled, int|null $cancelEnd, int $now): Decision;
```

Rules exactly as in the original (order matters):

- Confirm: `confirmed` → `AlreadyConfirmed`; `cancelled` → `AlreadyCancelled`;
  `regEnd` set and `now > regEnd` → `ConfirmExpired`; else `Allowed`.
- Cancel: `cancelled` → `AlreadyCancelled`; `cancelEnd` set and
  `now > cancelEnd` → `CancelExpired`; else `Allowed`.

(Empty/`0`/`''` for the end date counts as "not set", matching `!empty()` in the
original; the controller normalises the model value to `int|null`.)

### `Controller\FrontendModule\AbstractRegistrationActionController`

Shared flow for both modules, extends Contao's
`AbstractFrontendModuleController`. Subclasses provide: the action name
(`confirm`/`cancel`), the decision for a registration, how to apply the change,
and a hook after changes (used by cancel for the waiting list).

Constructor dependencies (autowired): `EventRegistration`, `NodeManager`,
`TranslatorInterface`, `SimpleTokenParser`, `NotificationCenter`,
`StatusChanger`, `ContaoCsrfTokenManager`; `CancelController` additionally
`WaitingListChecker`.

### `ConfirmController` / `CancelController`

Thin subclasses. Apply = set `confirmed`/`cancelled` to `true` and `save()`.
`CancelController::afterChange()` runs `($this->waitingListChecker)($event)` once
per **distinct event** among the actually cancelled registrations (the original
ran it once, for the last event of the loop, even when nothing changed).

## Request flow

Identical for confirm and cancel; `requireButton` = the module's
`compatch_requireButton`.

1. `action` query parameter ≠ own action → empty `Response` (as original).
2. No `uuid` → `PageNotFoundException` (as original). Unknown UUID →
   `PageNotFoundException` (as original).
3. For every UUID: load registration and event, compute the `Decision`. Set
   `$template->event` / `$template->registration` to the last one (as original).
   For every non-`Allowed` decision add its message, flag and CSS class to the
   template (as original).
4. **Execute or ask?**
   - `requireButton` off → execute.
   - `requireButton` on and the request is a POST with
     `FORM_SUBMIT = compatch_<action>_<moduleId>` → execute.
   - otherwise (button mode, GET) → ask.
5. **Ask:** if at least one decision is `Allowed`, set `showButton = true`
   plus question, button label, form id, request token. Nothing is saved, no
   notification, no waiting list. If none is `Allowed`, only the messages are
   shown (no button) — success criterion: already confirmed/cancelled/expired
   registrations show the message directly.
6. **Execute:** apply the change to every `Allowed` registration and collect
   them as `$changed`.
7. Template: `message` = unique messages joined by a space; `content` = the
   module's nodes parsed with the simple tokens of **all** registrations of the
   request (as original).
8. **Only if `$changed` is not empty:** send `nc_notification` (if set) with the
   simple tokens of the **changed** registrations; cancel: run `afterChange()`.
9. Response: always `private` with `Cache-Control: no-store` (the page must never
   be served from the shared cache — neither a stale request token in button mode
   nor a cached result that skips the action).

POST with an invalid/missing request token is rejected by Contao's CSRF
handling before the controller runs (standard Contao error page).

Multiple UUIDs share one question and one button (`uuid[]` stays in the query
string; the form posts to the current URL including the query string).

## Templates

The bundle ships `mod_event_registration_confirm.html5` and
`mod_event_registration_cancel.html5`, which render exactly like the originals
unless `showButton` is set:

```php
<?php $this->extend('block_unsearchable'); ?>
<?php $this->block('content'); ?>
  <?php if ($this->showButton): ?>
    <?php if ($this->message): ?><p><?= $this->message ?></p><?php endif; ?>
    <form method="post" action="<?= $this->formAction ?>" id="<?= $this->formId ?>">
      <input type="hidden" name="FORM_SUBMIT" value="<?= $this->formId ?>">
      <input type="hidden" name="REQUEST_TOKEN" value="<?= $this->requestToken ?>">
      <p><?= $this->question ?></p>
      <button type="submit" class="submit"><?= $this->buttonLabel ?></button>
    </form>
  <?php elseif ($this->message): ?><p><?= $this->message ?></p>
  <?php else: ?><?= $this->content ?><?php endif; ?>
<?php $this->endblock(); ?>
```

New template variables: `showButton`, `question`, `buttonLabel`, `formId`,
`formAction`, `requestToken` (all HTML-escaped by the controller where they
contain user data, e.g. the event title in `question`).

**Custom project templates** (e.g. Sunshine's
`mod_event_registration_confirm_TEST.html5`) keep working in the default mode.
If button mode is enabled for a module with a custom template, that template
needs the form block — without it there is no button and nobody can confirm.
The README documents the snippet and this pitfall prominently.

## Texts

`translations/plakart_event_registration_compatch.{de,en}.yaml`:

| Key | de | en |
|---|---|---|
| `confirm_question` | Möchten Sie Ihre Anmeldung für „%events%“ verbindlich bestätigen? | Do you want to confirm your registration for "%events%"? |
| `confirm_button` | Anmeldung bestätigen | Confirm registration |
| `cancel_question` | Möchten Sie Ihre Anmeldung für „%events%“ wirklich stornieren? | Do you really want to cancel your registration for "%events%"? |
| `cancel_button` | Anmeldung stornieren | Cancel registration |

`%events%` = distinct titles of the events with an `Allowed` decision, joined
with `", "`. Projects override texts via their own `translations/` files.

Backend field (`contao/dca/tl_module.php`):

- `compatch_requireButton`, checkbox, `tl_class: 'w50 m12'`,
  SQL `boolean` default `false` (column created by `contao:migrate`).
- Added via `PaletteManipulator` to the `config_legend` of the
  `event_registration_confirm` / `event_registration_cancel` palettes, each only
  if the corresponding replacement is active.
- Label de: „Erst nach Klick auf einen Button ausführen“ / description „Der Link
  aus der E-Mail zeigt nur einen Button. Schützt vor E-Mail-Link-Scannern, die
  Links automatisch aufrufen. Eigene Templates müssen das Formular enthalten
  (siehe README).“; en analogous.

## Dependencies

```json
"require": {
    "php": ">=8.1",
    "contao/core-bundle": "^5.3",
    "contao/calendar-bundle": "^5.3",
    "inspiredminds/contao-event-registration": ">=2.2 <2.4",
    "terminal42/notification_center": "^2.0",
    "terminal42/contao-node": "*"
}
```

`terminal42/contao-node` is constrained by the plugin; it is listed because the
bundle uses `NodeManager` directly. Dev: `contao/manager-plugin ^2.0`,
`phpunit/phpunit ^10.5 || ^11`, `phpstan/phpstan ^2.0`,
`friendsofphp/php-cs-fixer ^3`.

**Version range check during implementation:** diff the two controllers,
`EventRegistration::getSimpleTokensForMultipleRegistrations()`,
`WaitingListChecker::__invoke()` and `NodeManager::generateMultiple()` between
plugin tags 2.2.5 and 2.3.3. If 2.2.x differs in anything the bundle relies on,
narrow the constraint to `^2.3`. When the plugin releases a new minor, the code
is compared again and the range extended in a new release (README section
"Maintenance").

## Testing

PHPUnit:

- `StatusChangerTest` — confirm: new, already confirmed, already cancelled,
  expired (`regEnd` in the past), `regEnd` empty, `regEnd` in the future;
  confirmed **and** cancelled → `AlreadyConfirmed` (order). Cancel: new, already
  cancelled, expired, `cancelEnd` empty.
- `ExtensionTest` — both switches on: both controller definitions present;
  `confirm: false` / `cancel: false`: the respective definition is removed;
  parameters set.
- Controller mode decision (execute vs. ask) for button off/on × GET/POST with
  matching/foreign `FORM_SUBMIT`. This decision is a small pure method on the
  abstract controller so it is testable without booting Contao.

Manual checklist (README, run on the DEV copy of Sunshine before release):

- **Without button:** open link → confirmed, exactly one mail; open again →
  "already confirmed", no mail.
- **With button:** open link → no status change, no mail, button visible; click
  → exactly one mail; open again → "already confirmed", no button, no mail;
  reload of the POST → "already confirmed", no mail.
- Same for cancel, including the waiting list: only the real cancellation
  promotes waiting-list registrations.
- Multiple UUIDs: one button; mixed states show messages plus button.
- `confirm: false` in `config.yaml` → original behaviour (multiple mails) again.
- Response of the confirmation page has `Cache-Control: private, no-store`.

## Rollout at Sunshine PCB (after release)

Remove from `composer.json`: `cweagans/composer-patches`, its `allow-plugins`
entry, `extra.patches`, `extra.composer-exit-on-patch-failure`; delete
`patches/`. Then `composer require plakart/contao-event-registration-compatch`
and `contao:migrate`. Download the server `composer.json` first (PhpStorm
auto-upload to production, server-only `allow-plugins` entries). The DEV copy
at `/var/www/pcb` (plugin 2.3.3, no patch) is used for integration testing via
a Composer path repository before the release.

## Out of scope

- Other modules of the plugin (registration form, lists, member registrations).
- Telling bots and humans apart (not reliable; the button mode is the answer).
- Upstream contribution (comment on #46/#54) — separate task.
- Fixing Sunshine's TEST module using notification 4 instead of 7.

## Risks

- **Upstream merges PR #54** → cancel behaviour changes upstream; the version
  range must be re-checked before extending it (README "Maintenance").
- **Template precedence** (see "Bundle loading") — verified early in
  implementation; fallback documented.
- **Custom templates without the form** in button mode → nobody can confirm.
  Mitigated by default-off and README; the backend field description warns.
