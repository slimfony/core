<?php

declare(strict_types=1);

namespace Slimfony\Core\Exception;

/**
 * The application or its environment is configured or wired wrongly (services, routes, middleware, controllers, env variables).
 * Raised while booting, so a broken deployment fails at start, not under traffic.
 *
 * @api
 */
final class ConfigurationException extends \LogicException implements SlimfonyException {}
