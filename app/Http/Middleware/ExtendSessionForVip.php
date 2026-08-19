<?php
// app/Http/Middleware/ExtendSessionForVip.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExtendSessionForVip
{
    // Puedes usar IDs, un campo en la tabla users (never_expire = true), etc.
    protected array $vipUserIds = [1];

    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && in_array(Auth::id(), $this->vipUserIds)) {
            // 10 años en minutos (session.lifetime se maneja en minutos)
            config(['session.lifetime' => 60 * 24 * 365 * 10]);
            config(['session.expire_on_close' => false]);
        }

        return $next($request);
    }
}