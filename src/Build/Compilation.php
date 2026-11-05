<?php

declare(strict_types=1);

namespace Slimfony\Core\Build;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The compiled application: the service container and the compiled route table.
 */
final readonly class Compilation
{
    /** Container parameter: list of middleware service ids, outermost first. */
    public const string MIDDLEWARE_PARAMETER = 'slimfony.middleware';

    /**
     * @param array<mixed> $routes
     */
    public function __construct(
        public ContainerInterface $container,
        public array $routes,
    ) {}

    /**
     * @return list<string>
     */
    public function middlewareIds(): array
    {
        $ids = $this->container->getParameter(self::MIDDLEWARE_PARAMETER);
        if (!\is_array($ids) || !array_is_list($ids)) {
            throw new \LogicException(\sprintf('Parameter "%s" must be a list of service ids.', self::MIDDLEWARE_PARAMETER));
        }
        foreach ($ids as $id) {
            if (!\is_string($id)) {
                throw new \LogicException(\sprintf('Parameter "%s" must be a list of service ids.', self::MIDDLEWARE_PARAMETER));
            }
        }

        return $ids;
    }
}
