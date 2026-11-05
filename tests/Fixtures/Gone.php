<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Slimfony\Core\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Gone
{
    public function __invoke(Request $request): Response
    {
        throw new HttpException(410, 'moved on', ['X-Reason' => 'test']);
    }
}
