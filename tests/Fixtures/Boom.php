<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Boom
{
    public function __invoke(Request $request): Response
    {
        throw new \RuntimeException('secret db password');
    }
}
