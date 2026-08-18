<?php
declare(strict_types=1);

namespace Ws\Http;

interface Middleware
{
    public function handle(Request $request, callable $next): Response;
}
