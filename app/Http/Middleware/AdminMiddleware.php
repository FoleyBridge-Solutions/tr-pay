<?php

// app/Http/Middleware/AdminMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdminMiddleware
 *
 * Ensures the user is authenticated and active before accessing admin routes.
 */
class AdminMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('admin.login');
        }

        $user = Auth::user();

        if (! $user->isActive()) {
            Auth::logout();

            return redirect()->route('admin.login')
                ->with('error', 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
