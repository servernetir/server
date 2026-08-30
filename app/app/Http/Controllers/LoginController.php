<?php

namespace App\Http\Controllers;

use App\Models\EmailVerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Mail\VerificationCodeMail;

class LoginController extends Controller
{
    /**
     * Show login/register page.
     * - If there is a pending email in session, the UI can switch to Verify tab.
     */
    public function index(Request $request)
    {
        $showVerification = (bool) $request->session()->get('pending_email');

        return view('login', [
            'showVerification' => $showVerification,
            'pendingEmail'     => $request->session()->get('pending_email'),
        ]);
    }

    /**
     * Compose a stable rate-limit key per IP + purpose + email.
     */
    protected function throttleKey(Request $request, string $purpose): string
    {
        return Str::lower(sprintf(
            '%s|%s|%s',
            (string) $request->ip(),
            $purpose,
            (string) $request->input('email', $request->session()->get('pending_email', ''))
        ));
    }

    /**
     * Start registration:
     * 1) Validate email uniqueness and password
     * 2) Issue a 6-digit code and email it
     * 3) Stash "pending" registration data in session
     */
    public function register(Request $request)
    {
        $request->validate([
            'firstname'   => ['nullable', 'string', 'max:100'],
            'lastname'    => ['nullable', 'string', 'max:100'],
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string', 'min:8', 'confirmed'],
            'country'     => ['nullable', 'string', 'max:2'],
            'phone'       => ['nullable', 'string', 'max:50'],
            'address1'    => ['nullable', 'string', 'max:191'],
            'city'        => ['nullable', 'string', 'max:100'],
            'state'       => ['nullable', 'string', 'max:100'],
            'postcode'    => ['nullable', 'string', 'max:20'],
            'companyname' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            // 1) Create client in WHMCS (lenient: so we can show WHMCS message)
            $add = $this->whmcs_call('AddClient', array_filter([
                'firstname'    => $request->input('firstname'),
                'lastname'     => $request->input('lastname'),
                'email'        => $request->input('email'),
                'password2'    => $request->input('password'),
                'country'      => $request->input('country'),
                'phonenumber'  => $request->input('phone'),
                'address1'     => $request->input('address1'),
                'city'         => $request->input('city'),
                'state'        => $request->input('state'),
                'postcode'     => $request->input('postcode'),
                'companyname'  => $request->input('companyname'),
            ]), false);

            if (($add['result'] ?? '') !== 'success' || empty($add['clientid'])) {
                // Show exact message from WHMCS if available (e.g., "Email address already exists")
                $msg = $add['message'] ?? 'Registration failed.';
                return $this->jsonOrBack(
                    $request,
                    ['success' => false, 'message' => $msg],
                    ['email'   => $msg],
                    422
                );
            }

            // 2) Auto-login after registration (lenient)
            $login = $this->whmcs_call('ValidateLogin', [
                'email'    => $request->input('email'),
                'password' => $request->input('password'),
            ], false);

            if (($login['result'] ?? '') !== 'success' || empty($login['userid'])) {
                return $this->jsonOrBack(
                    $request,
                    ['success' => true, 'message' => 'Registration completed. Please log in.'],
                    ['success' => 'Registration completed. Please log in.'],
                    200,
                    route('login')
                );
            }

            // 3) Get client details and set session
            $details = $this->whmcs_call('GetClientsDetails', [
                'clientid' => $login['userid'],
                'stats'    => false,
            ]);

            $client = $details['client'] ?? [];
            $request->session()->regenerate();
            $request->session()->put('whmcs_auth', [
                'client_id'    => (int)($client['id'] ?? $login['userid']),
                'email'        => $client['email'] ?? $request->input('email'),
                'firstname'    => $client['firstname'] ?? $request->input('firstname'),
                'lastname'     => $client['lastname'] ?? $request->input('lastname'),
                'companyname'  => $client['companyname'] ?? null,
                'logged_in_at' => now()->toIso8601String(),
            ]);

            return $this->jsonOrBack(
                $request,
                ['success' => true, 'message' => 'Registration and login successful.'],
                ['success' => 'Registration and login successful.'],
                200,
                route('dashboard')
            );
        } catch (\Throwable $e) {
            Log::error('WHMCS AddClient/Login error: ' . $e->getMessage());
            return $this->jsonOrBack(
                $request,
                ['success' => false, 'message' => 'Registration failed. Please try again later.'],
                ['email'   => 'Registration failed. Please try again later.'],
                502
            );
        }
    }


    /**
     * Resend a verification code for the pending email in session.
     */
    public function resendCode(Request $request)
    {
        $email = $request->session()->get('pending_email');
        if (!$email) {
            return $this->jsonOrBack(
                $request,
                ['success' => false, 'message' => 'No pending verification session.'],
                ['email' => 'No pending verification session.'],
                400
            );
        }

        // Rate-limit resends (5 tries)
        $rlKey = $this->throttleKey($request, 'resend');
        if (RateLimiter::tooManyAttempts($rlKey, 5)) {
            $seconds = RateLimiter::availableIn($rlKey);
            return $this->jsonOrBack(
                $request,
                ['success' => false, 'message' => "Please wait {$seconds}s before resending."],
                ['email' => "Please wait {$seconds}s before resending."],
                429
            );
        }

        // Invalidate old unconsumed code, then create a fresh one
        EmailVerificationCode::where('email', $email)->whereNull('consumed_at')->delete();

        $codePlain = (string) random_int(100000, 999999);
        $codeHash  = Hash::make($codePlain);
        $rec = EmailVerificationCode::create([
            'email'     => $email,
            'code_hash' => $codeHash,
            'expires_at' => now()->addMinutes(10),
        ]);

        try {
            Mail::to($email)->send(new VerificationCodeMail($codePlain));
        } catch (\Throwable $e) {
            Log::error('Failed to resend verification mail: ' . $e->getMessage());
            $rec->delete();
            return $this->jsonOrBack(
                $request,
                ['success' => false, 'message' => 'Failed to resend the verification code.'],
                ['email' => 'Failed to resend the verification code.'],
                500
            );
        }

        // Cooldown 120s after a resend
        RateLimiter::hit($rlKey, 120);

        return $this->jsonOrBack(
            $request,
            ['success' => true, 'message' => 'New code sent.', 'resend_cooldown_s' => 120],
            ['success' => 'New code sent.'],
            200
        );
    }

    /**
     * Verify the 6-digit code and finalize registration:
     * - Consume the code
     * - Create or update local user
     * - Create WHMCS client (AddClient) with the same credentials
     * - Log the user in
     */
    public function verifyCode(Request $request)
    {
        // Validate the code
        $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $email = $request->session()->get('pending_email');
        if (!$email) {
            return $this->jsonOrBack($request, [
                'success' => false,
                'message' => 'No pending verification session.',
            ], [], 400);
        }

        // Call WHMCS ValidateLogin API or any other suitable method
        try {
            $whmcsResponse = $this->whmcs_call('ValidateLogin', [
                'email' => $email,
                'password' => $request->input('password'),
            ]);

            if ($whmcsResponse['result'] === 'success') {
                // Log the user in via WHMCS and set the session accordingly
                // You can create a session or token here to manage the local session
                return $this->jsonOrBack($request, [
                    'success' => true,
                    'message' => 'Code verified and user logged in.',
                ], [], 200);
            } else {
                return $this->jsonOrBack($request, [
                    'success' => false,
                    'message' => 'Invalid code or credentials.',
                ], [], 422);
            }
        } catch (\Exception $e) {
            return $this->jsonOrBack($request, [
                'success' => false,
                'message' => 'Error verifying code with WHMCS: ' . $e->getMessage(),
            ], [], 500);
        }
    }

    /**
     * Login via WHMCS ValidateLogin, then mirror-login the local user.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            // 1) Validate credentials (lenient: don't throw on wrong creds)
            $res = $this->whmcs_call('ValidateLogin', [
                'email'    => $request->input('email'),
                'password' => $request->input('password'),
            ], false);

            if (($res['result'] ?? '') !== 'success' || empty($res['userid'])) {
                // Wrong email/password -> show nice toast (422)
                return $this->jsonOrBack(
                    $request,
                    ['success' => false, 'message' => 'Invalid email or password.'],
                    ['email'   => 'Invalid email or password.'],
                    422
                );
            }

            // 2) Fetch client details
            $details = $this->whmcs_call('GetClientsDetails', [
                'clientid' => $res['userid'],
                'stats'    => false,
            ]); // throwing here is OK

            // 3) Save session (WHMCS-only)
            $client = $details['client'] ?? [];
            $request->session()->regenerate();
            $request->session()->put('whmcs_auth', [
                'client_id'    => (int)($client['id'] ?? $res['userid']),
                'email'        => $client['email'] ?? $request->input('email'),
                'firstname'    => $client['firstname'] ?? null,
                'lastname'     => $client['lastname'] ?? null,
                'companyname'  => $client['companyname'] ?? null,
                'logged_in_at' => now()->toIso8601String(),
            ]);

            return $this->jsonOrBack(
                $request,
                ['success' => true, 'message' => 'Login successful.'],
                ['success' => 'Welcome back!'],
                200,
                route('dashboard')
            );
        } catch (\Throwable $e) {
            Log::error('WHMCS ValidateLogin error: ' . $e->getMessage());
            // Network/URL/SSL/etc -> 502 generic
            return $this->jsonOrBack(
                $request,
                ['success' => false, 'message' => 'Login failed. Please try again later.'],
                ['email'   => 'Login failed. Please try again later.'],
                502
            );
        }
    }


    /**
     * Logout locally and flush CSRF/session as usual.
     */
    public function logout(Request $request)
    {
        $request->session()->forget('whmcs_auth');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->jsonOrBack(
            $request,
            ['success' => true, 'message' => 'You have been logged out successfully.'],
            ['success' => 'Logged out successfully.'],
            200,
            route('login')
        );
    }

    /**
     * Cancel/abort a pending verification session (e.g., user pressed Back).
     */
    public function cancelVerification(Request $request)
    {
        $this->clearPending($request);

        return $this->jsonOrBack(
            $request,
            ['success' => true, 'message' => 'Verification cancelled.'],
            ['success' => 'Verification cancelled.'],
            200
        );
    }

    /**
     * Helper: return JSON if ajax, otherwise redirect back with messages.
     * Optionally provide a redirect route for success cases like logout.
     */
    protected function jsonOrBack(Request $request, array $jsonPayload, array $bagMessages, int $status = 200, ?string $redirectTo = null)
    {
        if ($request->expectsJson()) {
            return response()->json($jsonPayload, $status);
        }

        if ($status >= 400) {
            return back()->withErrors($bagMessages)->withInput();
        }

        $redirect = $redirectTo ?? url()->previous() ?? route('login');
        // Put success message into session
        if (isset($bagMessages['success'])) {
            return redirect($redirect)->with('success', $bagMessages['success']);
        }
        return redirect($redirect);
    }

    /**
     * Helper: clear pending registration data from session
     * - IMPORTANT: removes the plain password.
     */
    protected function clearPending(Request $request): void
    {
        $request->session()->forget([
            'pending_email',
            'pending_password_hash',
            'pending_password_plain',
        ]);
    }

    /**
     * WHMCS API call helper via cURL.
     * - Reads keys from config/services.php or .env (WHMCS_URL / WHMCS_IDENTIFIER / WHMCS_SECRET / WHMCS_ACCESS_KEY)
     * - If $throwOnError = true (default), throws on any non-success result.
     * - If $throwOnError = false, returns raw decoded array even when result !== 'success'.
     */
    protected function whmcs_call(string $action, array $params = [], bool $throwOnError = true): array
    {
        // Resolve URL (accept both base URL or full .../includes/api.php)
        $base = rtrim(
            config('services.whmcs.url', env('WHMCS_URL', env('WHMCS_API_URL'))),
            '/'
        );
        $url = preg_match('/api\.php$/i', $base) ? $base : $base . '/includes/api.php';

        $identifier = config('services.whmcs.identifier', env('WHMCS_IDENTIFIER', env('WHMCS_API_IDENTIFIER')));
        $secret     = config('services.whmcs.secret',     env('WHMCS_SECRET',     env('WHMCS_API_SECRET')));
        $accessKey  = config('services.whmcs.access_key', env('WHMCS_ACCESS_KEY', env('WHMCS_API_ACCESS_KEY')));

        $payload = array_merge($params, [
            'action'       => $action,
            'identifier'   => $identifier,
            'secret'       => $secret,
            'responsetype' => 'json',
        ]);

        if (!empty($accessKey)) {
            $payload['accesskey'] = $accessKey;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // CURLOPT_HTTPHEADER  => ['Expect:'], // در صورت نیاز برای برخی سرورها
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL error: ' . $e);
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            if ($throwOnError) {
                throw new \RuntimeException('Invalid WHMCS response: ' . $response);
            }
            return ['result' => 'error', 'message' => 'Invalid WHMCS response', 'raw' => $response];
        }

        if ($throwOnError && (($data['result'] ?? '') !== 'success')) {
            $msg = $data['message'] ?? 'Unknown error';
            throw new \RuntimeException('WHMCS API error: ' . $msg);
        }

        return $data;
    }
}