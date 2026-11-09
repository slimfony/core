<?php

declare(strict_types=1);

// FrankenPHP defines frankenphp_handle_request() in its classic mode too, where calling it throws. This stand-in does the same,
// so a test can see what code does there. $GLOBALS['slimfony_frankenphp_stub_message'] changes what it throws.
if (!function_exists('frankenphp_handle_request')) {
    function frankenphp_handle_request(callable $handler): bool
    {
        $message = $GLOBALS['slimfony_frankenphp_stub_message'] ?? 'frankenphp_handle_request() called while not in worker mode';
        throw new RuntimeException(is_string($message) ? $message : '');
    }
}
