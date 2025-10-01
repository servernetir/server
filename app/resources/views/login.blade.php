<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ServerNet | Login & Register</title>
    <link rel="stylesheet" href="{{ asset('css/login.css') }}" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <link rel="stylesheet" href="{{ asset('css/toastr.min.css') }}">
</head>
<body class="dark">
    <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme" title="Toggle theme">
        <span class="icon sun"><i class="fas fa-sun"></i></span>
        <span class="icon moon"><i class="fas fa-moon"></i></span>
        <span class="slider"></span>
    </button>
    <div class="container">
        <div class="tabs">
            <button id="loginTab"
                class="{{ $showVerification ? 'hidden' : (old('registering') ? '' : 'active') }}">Login</button>
            <button id="registerTab"
                class="{{ $showVerification ? 'hidden' : (old('registering') ? 'active' : '') }}">Register</button>
            <button id="verifyTab" class="{{ $showVerification ? 'active' : 'hidden' }}">Verify</button>
        </div>
        <!-- Login Form -->
        <form id="loginForm" class="form {{ $showVerification ? 'hidden' : (old('registering') ? '' : 'active') }}"
            method="POST" action="{{ route('auth') }}">
            @csrf
            <input type="email" name="email" placeholder="Email" value="{{ old('email') }}" required />
            <input type="password" name="password" placeholder="Password" required />
            <button type="submit" class="btn">
                <i class="fa fa-sign-in-alt"></i> Login
            </button>
            <p class="or">Or continue with</p>
            <div class="socials">
                <a class="social google" href="{{ url('auth/google') }}"><i class="fab fa-google"></i></a>
                <a class="social facebook" href="{{ url('auth/facebook') }}"><i class="fab fa-facebook-f"></i></a>
                <a class="social twitter" href="{{ url('auth/twitter') }}"><i class="fab fa-x-twitter"></i></a>
            </div>
        </form>
        <!-- Register Form -->
        <form id="registerForm" class="form {{ $showVerification ? 'hidden' : (old('registering') ? 'active' : '') }}"
            method="POST" action="{{ route('register.start') }}">
            @csrf
            <input type="hidden" name="registering" value="1">
            <input type="email" name="email" placeholder="Email" value="{{ old('email') }}" required />
            <input type="password" name="password" placeholder="Password" required />
            <input type="password" name="password_confirmation" placeholder="Confirm Password" required />
            <button type="submit" class="btn">
                <i class="fa fa-user-plus"></i> Register
            </button>
            <p class="or">Or sign up with</p>
            <div class="socials">
                <a class="social google" href="{{ url('auth/google') }}"><i class="fab fa-google"></i></a>
                <a class="social facebook" href="{{ url('auth/facebook') }}"><i class="fab fa-facebook-f"></i></a>
                <a class="social twitter" href="{{ url('auth/twitter') }}"><i class="fab fa-x-twitter"></i></a>
            </div>
        </form>

        <!-- Verify Code Form -->
        <form id="verifyForm" class="form {{ $showVerification ? 'active' : 'hidden' }}" method="POST"
            action="{{ route('register.verify') }}">
            @csrf
            <h3 style="text-align:center; margin-bottom:15px;">Enter Verification Code</h3>

            <div style="display:flex; justify-content:space-between; gap:8px; margin-bottom:18px;">
                <input type="text" maxlength="1" class="code-input" required />
                <input type="text" maxlength="1" class="code-input" required />
                <input type="text" maxlength="1" class="code-input" required />
                <input type="text" maxlength="1" class="code-input" required />
                <input type="text" maxlength="1" class="code-input" required />
                <input type="text" maxlength="1" class="code-input" required />
            </div>

            <!-- Hidden field to combine digits -->
            <input type="hidden" name="code" id="fullCode" />
            <p class="hint" style="text-align:center; font-size:12px; opacity:0.8; margin:8px 0;">Code expires in 2 minutes.</p>
            <button type="submit" class="btn"><i class="fa fa-check"></i> Verify Code</button>
            <p class="or">Didn’t get the code?</p>
            <div class="actions" style="margin-top: 8px; display:flex; gap:10px; justify-content:center;">
                <form id="resendForm" method="POST" action="{{ route('register.resend') }}">
                    @csrf
                    <button id="resendCodeBtn" type="submit" class="btn btn-secondary">Resend Code</button>
                </form>
                <form method="POST" action="{{ route('register.cancel') }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Back</button>
                </form>
            </div>
        </form>
    </div>

    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <script src="{{ asset('js/toastr.min.js') }}"></script>
    <script>
        // Flash messages
        @if (session('success'))
            toastr.success(@json(session('success')));
        @endif
        @if (session('error'))
            toastr.error(@json(session('error')));
        @endif
        @if (session('warning'))
            toastr.warning(@json(session('warning')));
        @endif
        @if (session('info'))
            toastr.info(@json(session('info')));
        @endif
        @if ($errors->any())
            @foreach ($errors->all() as $error)
                toastr.error(@json($error));
            @endforeach
        @endif
    </script>
    <script>
        window.isVerifyActive = {{ $showVerification ? 'true' : 'false' }};
    </script>
    <script src="{{ asset('js/login.js') }}"></script>
</body>
</html>