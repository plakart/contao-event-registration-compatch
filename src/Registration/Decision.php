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
