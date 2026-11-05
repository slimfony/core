<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;

final class NotAResponse
{
    public function __invoke(Request $request): string
    {
        return 'not a response';
    }
}
