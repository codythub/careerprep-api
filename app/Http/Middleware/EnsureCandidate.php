<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCandidate
{
    /**
     * Admin accounts are separate from candidate accounts and may not use candidate features.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->isAdmin() !== false, 403, 'This action is unauthorized.');

        return $next($request);
    }
}
