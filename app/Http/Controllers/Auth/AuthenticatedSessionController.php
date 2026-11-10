<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\UserRoles;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $defaultRedirect = match (Auth::user()?->role) {
            UserRoles::SELLER, UserRoles::LEGACY_SELLER => route('umkm.dashboard', absolute: false),
            'admin_bumdes' => route('admin.dashboard', absolute: false),
            'kurir' => route('kurir.dashboard', absolute: false),
            'kepala_bumdes' => route('kepala-bumdes.dashboard', absolute: false),
            default => route('pelanggan.dashboard', absolute: false),
        };

        return redirect()->intended($defaultRedirect);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
