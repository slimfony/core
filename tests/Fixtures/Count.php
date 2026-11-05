<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Count
{
    public function __construct(private readonly Counter $counter) {}

    public function __invoke(Request $request): Response
    {
        return new Response((string) ++$this->counter->n);
    }
}
