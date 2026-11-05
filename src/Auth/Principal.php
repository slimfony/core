<?php

declare(strict_types=1);

namespace Slimfony\Core\Auth;

use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Http\Attributes;
use Symfony\Component\HttpFoundation\Request;

/**
 * The authenticated caller, as established by an Authenticator.
 *
 * @api
 */
final readonly class Principal
{
    /**
     * @param list<string>         $scopes
     * @param array<string, mixed> $claims whatever else the authenticator knows (for a JWT: its claims)
     */
    public function __construct(
        public string $subject,
        public array $scopes = [],
        public array $claims = [],
    ) {
        if ('' === $subject) {
            throw new \InvalidArgumentException('A principal needs a non-empty subject.');
        }
    }

    /**
     * The principal of the current request; for use in controllers of protected routes.
     *
     * @throws ConfigurationException on a public route, or when AuthMiddleware is not in the chain
     */
    public static function from(Request $request): self
    {
        $principal = $request->attributes->get(Attributes::PRINCIPAL);

        return $principal instanceof self
            ? $principal
            : throw new ConfigurationException('No authenticated principal: is the route public, or is AuthMiddleware missing from the chain?');
    }

    public function hasScope(string $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }
}
