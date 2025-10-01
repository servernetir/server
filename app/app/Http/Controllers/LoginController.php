<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\EmailVerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Mail\VerificationCodeMail;

class LoginController extends Controller
{
    public function index(Request $request)
    {
        // If we've started registration, show the code entry step.
        $showVerification = (bool) $request->session()->get('pending_email');
        return view('login', [
            'showVerification' => $showVerification,
            'pendingEmail'     => $request->session()->get('pending_email'),
        ]);
    }

    protected function throttleKey(Request $request, string $suffix = 'auth'): string
    {
        return Str::lower($request->ip() . '|' . $suffix . '|' . (string) $request->input('email'));
    }

    public function startRegistration(Request $request)
    {
        // Separate validation: first check email uniqueness independently
        $emailValidator = Validator::make($request->all(), [
            'email' => ['required', 'email', Rule::unique('users', 'email')],
        ]);

        if ($emailValidator->fails()) {
            throw ValidationException::withMessages($emailValidator->errors()->messages());
        }

        // If email is valid and unique, now validate password
        $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        // Rate limit sending
        $key = $this->throttleKey($request, 'send-code');
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => "Too many attempts. Try again in $seconds seconds."
            ]);
        }

        $email = Str::lower($request->input('email'));
        $codePlain = (string) random_int(100000, 999999);

        // Upsert latest code for this email (invalidate previous unconsumed ones)
        EmailVerificationCode::where('email', $email)->whereNull('consumed_at')->delete();

        // Send email
        Mail::to($email)->send(new VerificationCodeMail($codePlain));

        // Persist pending state in session
        $request->session()->put('pending_email', $email);
        $request->session()->put('pending_password_hash', Hash::make($request->input('password')));

        RateLimiter::hit($key, 120); // decay after 60 seconds

        return redirect()->route('login')->with('info', 'Verification code sent. Please check your email.')->with('show_verify', true);
    }

    public function resendCode(Request $request)
    {
        $email = $request->session()->get('pending_email');
        if (!$email) {
            return redirect()->route('login')->withErrors(['email' => 'No pending verification session.']);
        }

        $key = $this->throttleKey($request, 'resend');
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'code' => "Too many resends. Try again in $seconds seconds."
            ]);
        }

        $codePlain = (string) random_int(100000, 999999);
        $codeHash  = Hash::make($codePlain);

        EmailVerificationCode::where('email', $email)->whereNull('consumed_at')->delete();

        EmailVerificationCode::create([
            'email'       => $email,
            'code_hash'   => $codeHash,
            'expires_at'  => now()->addMinutes(2),
            'resend_count' => 0,
            'ip'          => $request->ip(),
        ]);

        Mail::to($email)->send(new VerificationCodeMail($codePlain));

        RateLimiter::hit($key, 120);

        return back()->with('success', 'New code sent.');
    }

    public function verifyCode(Request $request)
    {
        $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $email = $request->session()->get('pending_email');
        $passHash = $request->session()->get('pending_password_hash');
        if (!$email || !$passHash) {
            return redirect()->route('login')->withErrors(['email' => 'Your verification session has expired. Start again.']);
        }

        $key = $this->throttleKey($request, 'verify');
        if (RateLimiter::tooManyAttempts($key, 6)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'code' => "Too many attempts. Try again in $seconds seconds."
            ]);
        }

        $rec = EmailVerificationCode::where('email', $email)->whereNull('consumed_at')->latest()->first();
        if (!$rec) {
            RateLimiter::hit($key);
            return back()->withErrors(['code' => 'No active code. Please request a new one.']);
        }

        if (now()->greaterThan($rec->expires_at)) {
            $rec->delete();
            RateLimiter::hit($key);
            return back()->withErrors(['code' => 'Code expired. Please request a new one.']);
        }

        if (!Hash::check($request->input('code'), $rec->code_hash)) {
            RateLimiter::hit($key);
            return back()->withErrors(['code' => 'Incorrect code.']);
        }

        // Success: consume code
        $rec->update(['consumed_at' => now()]);

        // Create or update user, then login
        $user = User::where('email', $email)->first();
        if (!$user) {
            $user = new User();
            $user->name = $email; // or extract from a 'name' field in form if you add it
            $user->email = $email;
            $user->password = $passHash;
            if ($user->isFillable('email_verified_at')) {
                $user->email_verified_at = now();
            }
            $user->save();
        } else {
            $user->password = $passHash;
            if ($user->isFillable('email_verified_at')) {
                $user->email_verified_at = now();
            }
            $user->save();
        }

        // Clear session
        $request->session()->forget(['pending_email', 'pending_password_hash', 'show_verify']);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home')->with('success', 'Email verified. Welcome!');
    }

    public function loginExisting(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = $this->throttleKey($request, 'login');
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => "Login timeout. Try again in $seconds seconds"
            ]);
        }

        $remember = $request->boolean('remember');

        if (!Auth::attempt($request->only('email', 'password'), $remember)) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages([
                'email' => 'Incorrect email or password'
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->route('home')->with('success', 'Logged in ✅');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login')->with('success', 'You have been logged out');
    }

    public function cancelVerification(Request $request)
    {
        // Clear verification session data
        $request->session()->forget(['pending_email', 'pending_password_hash', 'show_verify']);
        return redirect()->route('login')->with('info', 'Verification cancelled. Please start again.');
    }
}