<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Display the login/register view.
     */
    public function showLoginForm()
    {
        return view('login');
    }

    /**
     * Handle user authentication.
     */
    public function auth(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();
            return redirect()->intended(route('home'));
        }

        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    /**
     * Log the user out.
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    /**
     * Return current session data (for debugging).
     */
    public function session()
    {
        return response()->json(session()->all());
    }

    /**
     * Redirect to social provider.
     */
    public function redirectToProvider($provider)
    {
        $allowedProviders = ['google', 'facebook', 'x'];
        if (!in_array($provider, $allowedProviders)) {
            return redirect()->route('login')->with('error', 'Invalid provider.');
        }

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle social provider callback.
     */
    public function handleProviderCallback($provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->user();

            $user = User::updateOrCreate(
                ['email' => $socialUser->getEmail()],
                [
                    'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'provider_token' => $socialUser->token,
                    'password' => Hash::make(Str::random(16)),
                    'email_verified_at' => now(),
                ]
            );

            Auth::login($user, true);
            return redirect()->intended(route('home'));
        } catch (\Exception $e) {
            return redirect()->route('login')->with('error', 'Social login failed: ' . $e->getMessage());
        }
    }

    /**
     * Display the registration form (same view as login).
     */
    public function showRegisterForm()
    {
        return view('auth-login');
    }

    /**
     * Handle user registration.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'] ?? 'User',
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => now(),
        ]);

        Auth::login($user, true);
        return redirect()->intended(route('home'));
    }
}