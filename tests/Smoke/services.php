<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Slimfony\Core\Health\LivenessController;
use Slimfony\Core\Health\ReadinessController;
use Slimfony\Core\Http\RequestIdMiddleware;
use Slimfony\Core\Http\SecurityHeadersMiddleware;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Observability\MetricsController;
use Slimfony\Core\Observability\MetricsMiddleware;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Tests\Fixtures\Count;
use Slimfony\Core\Tests\Fixtures\Counter;
use Slimfony\Core\Tests\Smoke\Info;
use Slimfony\Core\Tests\Smoke\Logs;
use Slimfony\Core\Tests\Smoke\Ping;
use Slimfony\Core\Worker\ResetPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

return static function (ContainerBuilder $c): void {
    // created in public/index.php (the logger needs them before the container exists), handed over as synthetic services
    $c->register(RequestContext::class)->setSynthetic(true)->setPublic(true)->addTag(ResetPass::TAG);
    $c->register(LoggerInterface::class)->setSynthetic(true)->setPublic(true);
    $c->register(MetricsRegistry::class)->setSynthetic(true)->setPublic(true);

    $c->register(MetricsMiddleware::class)->setAutowired(true);
    $c->register(RequestIdMiddleware::class)->setAutowired(true);
    $c->register(SecurityHeadersMiddleware::class);
    $c->setParameter('slimfony.middleware', [MetricsMiddleware::class, RequestIdMiddleware::class, SecurityHeadersMiddleware::class]);

    $c->register(LivenessController::class);
    $c->register(ReadinessController::class)->setArguments([[], new Reference(LoggerInterface::class)]); // no dependencies to check
    $c->register(MetricsController::class)->setArguments([new Reference(MetricsRegistry::class), '%env(METRICS_TOKEN)%']);

    $c->register(Ping::class);
    $c->register(Info::class);
    $c->register(Logs::class)->setAutowired(true);
    $c->register(Counter::class)->setAutoconfigured(true);
    $c->register(Count::class)->setAutowired(true);
};
