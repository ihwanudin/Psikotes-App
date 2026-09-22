<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). A disabled admin
 * must be rejected on the very next panel request, not merely unable to
 * log in again -- checked here, on every authenticated request, rather
 * than by deleting session rows.
 *
 * Deliberately not a "delete this admin's session rows" approach: Laravel's
 * database session handler stamps `sessions.user_id` from the DEFAULT
 * guard (`Guard::class` resolves the app's default guard, not `admin`
 * specifically -- confirmed in
 * Illuminate\Session\DatabaseSessionHandler::userId()), so that column
 * does not reliably identify which rows belong to an authenticated Admin
 * at all. Deleting by a numeric ID match there risks deleting an unrelated
 * User's (participant's) session that happens to share the same id. A
 * live check on every request achieves the same outcome (next request
 * rejected) without that risk.
 */
final class RejectDisabledAdmin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if ($admin instanceof Admin && $admin->disabled_at !== null) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'Akun ini telah dinonaktifkan.');
        }

        return $next($request);
    }
}
