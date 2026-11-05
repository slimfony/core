<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Slimfony\Core\Auth\Principal;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Whoami
{
    public function __invoke(Request $request): Response
    {
        return new Response(Principal::from($request)->subject);
    }
}
