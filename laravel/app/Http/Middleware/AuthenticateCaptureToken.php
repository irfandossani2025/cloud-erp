<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuthenticateCaptureToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->bearerToken();
        $row = $token !== '' ? DB::table('capture_tokens')->where('token_hash', hash('sha256', $token))->first() : null;
        $user = $row ? User::find($row->user_id) : null;
        abort_unless($user, 401, 'The capture token is missing or has been replaced. Create a new one in the ERP.');
        DB::table('capture_tokens')->where('user_id', $user->id)->update(['last_used_at' => now()->toIso8601String()]);
        Auth::setUser($user);

        return $next($request);
    }
}
