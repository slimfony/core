<?php

declare(strict_types=1);

namespace Slimfony\Core\Exception;

/**
 * Marker for every exception the core raises on purpose: `catch (SlimfonyException)` catches the core's own errors.
 *
 * @api
 */
interface SlimfonyException extends \Throwable {}
