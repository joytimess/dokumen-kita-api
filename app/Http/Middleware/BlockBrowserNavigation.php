<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockBrowserNavigation
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Fetch Metadata only identifies navigation; it is not an authorization boundary.
        if ($request->header('Sec-Fetch-Mode') === 'navigate' && $request->header('Sec-Fetch-Dest') === 'document') {
            return response('', 401);
        }

        return $next($request);
    }
}
