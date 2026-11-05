<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Smoke;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Logs
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function __invoke(Request $request): Response
    {
        $this->logger->info('smoke log line', ['password' => 'must-not-appear']);

        return new Response('logged');
    }
}
