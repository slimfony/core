<?php

declare(strict_types=1);

namespace Slimfony\Core\Worker;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Collects every service whose class implements ResetInterface (synthetic ones included: an object made before the
 * container, such as the request context) into the StateResetter, so that no one has to remember a tag; a service of any
 * other class can still be added with the tag "kernel.reset".
 * IGNORE_ON_UNINITIALIZED_REFERENCE: a lazy service that was never used is neither created nor reset.
 *
 * @internal
 */
final class ResetPass implements CompilerPassInterface
{
    public const string TAG = 'kernel.reset';

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $ids = array_keys($container->findTaggedServiceIds(self::TAG));
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id); // no class: the id is the class
            if (!$definition->isAbstract() && \is_string($class) && is_subclass_of($class, ResetInterface::class)) {
                $ids[] = $id;
            }
        }
        $references = [];
        foreach (array_unique($ids) as $id) {
            $references[] = new Reference($id, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        }

        $container->register(StateResetter::class, StateResetter::class)
            ->setArguments([new IteratorArgument($references)])
            ->setPublic(true);
    }
}
