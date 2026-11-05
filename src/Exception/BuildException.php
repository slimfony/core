<?php

declare(strict_types=1);

namespace Slimfony\Core\Exception;

/**
 * The build-time step failed (warmup could not write, or the compiled cache is corrupt). Run the warmup again.
 *
 * @api
 */
final class BuildException extends \RuntimeException implements SlimfonyException {}
