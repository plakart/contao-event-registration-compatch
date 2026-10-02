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
