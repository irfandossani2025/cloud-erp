<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (!Auth::attempt($credentials)) throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        $request->session()->regenerate();
        return redirect()->intended('/');
    }

    public function logout(Request $request)
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect('/login');
    }

    public function local(Request $request)
    {
        abort_unless(app()->environment('local') && in_array($request->ip(), ['127.0.0.1', '::1']) && in_array($request->getHost(), ['127.0.0.1', 'localhost', '[::1]']), 404);
        $user = User::where('email', 'local-preview@example.invalid')->first();
        if (!$user) {
            $user = DB::transaction(function () {
                $agent = DB::table('agents')->where('name', 'Irfan Dossani')->first();
                $agentId = $agent->id ?? (string) Str::uuid();
                if (!$agent) DB::table('agents')->insert(['id' => $agentId, 'name' => 'Irfan Dossani']);
                $user = new User;
                $user->name = 'Irfan Dossani'; $user->email = 'local-preview@example.invalid';
                $user->password = Hash::make(Str::random(64)); $user->is_admin = true; $user->agent_id = $agentId; $user->save();
                return $user;
            });
        }
        Auth::login($user); $request->session()->regenerate();
        return redirect('/');
    }
}
