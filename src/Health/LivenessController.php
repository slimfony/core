<?php

declare(strict_types=1);

namespace Slimfony\Core\Health;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /healthz: the process is up and can serve a request. It must not touch any dependency: a database outage
 * must not make Kubernetes restart healthy workers.
 *
 * @api
 */
final class LivenessController
{
    public function __invoke(Request $request): Response
    {
        return new Response('ok', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
