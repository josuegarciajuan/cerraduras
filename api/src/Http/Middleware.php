<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Middleware contract.
 *
 * Each middleware receives the Request and a "next" callable that returns a
 * Response. Middlewares may short-circuit the pipeline by returning their own
 * Response without calling $next.
 */
interface Middleware
{
    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
