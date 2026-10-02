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

The package is not on Packagist. Add the GitHub repository to the project's
`composer.json` and require `dev-main`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/plakart/contao-event-registration-compatch"
    }
],
"require": {
    "plakart/contao-event-registration-compatch": "dev-main"
}
```

```bash
composer update plakart/contao-event-registration-compatch
vendor/bin/contao-console contao:migrate
```

Both fixes are active immediately; existing modules and templates keep working
without changes.

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
  <p><?= $this->message ?></p>
<?php else: ?>
  <?= $this->content ?>
<?php endif; ?>
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
- Parallel requests (like scanners firing several at once), e.g.
  `for i in 1 2 3; do curl -s -o /dev/null '<link>' & done; wait` → exactly one mail.
- `confirm: false` → original behaviour again.
- The confirmation page is sent with `Cache-Control: private, no-store`.

## Maintenance

The bundle replaces the plugin's controllers and therefore depends on their
behaviour. When the plugin releases a new version: compare
`EventRegistrationConfirmController`, `EventRegistrationCancelController`,
`EventRegistration::getSimpleTokensForMultipleRegistrations()` and
`WaitingListChecker` with the supported versions, extend the version constraint
in `composer.json` and push to `main` (installations track `dev-main`). If the fix
is merged upstream, the bundle can be removed.

## License

LGPL-3.0-or-later
