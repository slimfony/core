<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UseBroken
{
    public function __construct(private readonly BrokenReset $broken) {}

    public function __invoke(Request $request): Response
    {
        return new Response($this->broken::class);
    }
}
