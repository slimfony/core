<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Hello
{
    public function __construct(private readonly Greeter $greeter) {}

    public function __invoke(Request $request): Response
    {
        $name = $request->attributes->get('name');

        return new Response($this->greeter->greet(\is_string($name) ? $name : ''));
    }
}
