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
