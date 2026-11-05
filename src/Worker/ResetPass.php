<?php

declare(strict_types=1);

namespace Slimfony\Core\Worker;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects services tagged "kernel.reset" (autoconfigured for ResetInterface) into the StateResetter.
 * IGNORE_ON_UNINITIALIZED_REFERENCE: a lazy service that was never used is neither created nor reset.
 */
final class ResetPass implements CompilerPassInterface
{
    public const TAG = 'kernel.reset';

    public function process(ContainerBuilder $container): void
    {
        $references = [];
        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $id) {
            $references[] = new Reference($id, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        }

        $container->register(StateResetter::class, StateResetter::class)
            ->setArguments([new IteratorArgument($references)])
            ->setPublic(true);
    }
}
