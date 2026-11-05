<?php

declare(strict_types=1);

namespace Slimfony\Core\Observability;

use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Exception\HttpException;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The /metrics endpoint. It refuses to exist unprotected: configure a bearer token (at least 16 characters)
 * and/or a list of client IPs/CIDRs (the Prometheus servers). Register the route yourself; nothing is exposed by default.
 *
 * @api
 */
final class MetricsController
{
    private const int MIN_TOKEN_LENGTH = 16;

    /**
     * @param list<string> $allowedCidrs
     */
    public function __construct(
        private readonly MetricsRegistry $registry,
        private readonly ?string $bearerToken = null,
        private readonly array $allowedCidrs = [],
    ) {
        if (null === $bearerToken && [] === $allowedCidrs) {
            throw new ConfigurationException('Refusing to expose /metrics unprotected: set a bearer token and/or allowed client IPs.');
        }
        if (null !== $bearerToken && \strlen($bearerToken) < self::MIN_TOKEN_LENGTH) {
            throw new ConfigurationException(\sprintf('The /metrics bearer token must be at least %d characters.', self::MIN_TOKEN_LENGTH));
        }
    }

    public function __invoke(Request $request): Response
    {
        $header = $request->headers->get('Authorization');
        $tokenOk = null !== $this->bearerToken && \is_string($header) && hash_equals('Bearer ' . $this->bearerToken, $header);
        $ip = $request->getClientIp();
        $ipOk = [] !== $this->allowedCidrs && null !== $ip && IpUtils::checkIp($ip, $this->allowedCidrs);

        if (!$tokenOk && !$ipOk) {
            throw null !== $this->bearerToken
                ? new HttpException(401, headers: ['WWW-Authenticate' => 'Bearer'])
                : new HttpException(403);
        }

        return new Response($this->registry->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }
}
