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
