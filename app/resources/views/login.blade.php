<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ServerNet | Login & Register</title>
    <link rel="stylesheet" href="{{ asset('css/login.css') }}" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <link rel="stylesheet" href="{{ asset('css/toastr.min.css') }}">
    <style>
        #toast-container {z-index: 99999;}
        #toast-container>.toast {background-color: #1a1c20 !important;opacity: 1 !important;color: #e6edf3;text-align: center;box-shadow: 0 10px 30px rgba(0, 0, 0, .6);border: 1px solid #30363d;border-radius: 12px;padding: 14px 18px;font-size: 18px;line-height: 1.6;min-width: 370px;max-width: 480px;background-image: none !important;}
        #toast-container>.toast-success,
        #toast-container>.toast-error,
        #toast-container>.toast-info,
        #toast-container>.toast-warning {background-image: none !important;}
        #toast-container>.toast-success {border-left: 5px solid #22c55e;}
        #toast-container>.toast-error {border-left: 5px solid #ef4444;}
        #toast-container>.toast-info {border-left: 5px solid #38bdf8;}
        #toast-container>.toast-warning {border-left: 5px solid #f59e0b;}
        #toast-container>.toast .toast-title {font-weight: 700;font-size: 1.8em;margin-bottom: 2px;}
        #toast-container>.toast .toast-message {font-weight: 500;}
        #toast-container>.toast .toast-close-button {font-size: 25px;color: #9aa4b2;opacity: 1;text-shadow: none;}
        #toast-container>.toast .toast-close-button:hover {color: #fff;}
        #toast-container .toast-progress {height: 3px;background: #1a4fa3;opacity: .95;}
        @media (max-width: 576px) {#toast-container>.toast {min-width: 0;max-width: 92vw;padding: 12px 14px;font-size: 15px;}}
        /* === Verify Code Modal === */
        .modal-overlay{position: fixed; inset: 0;background: rgba(0,0,0,.65);display: none; align-items: center; justify-content: center;z-index: 9999; backdrop-filter: blur(2px);}
        .modal-overlay.active{ display: flex; }
        .modal{width: min(480px, 92vw);background: #0f1115; color: #e6edf3;border: 1px solid #30363d; border-radius: 14px;box-shadow: 0 20px 60px rgba(0,0,0,.6);padding: 22px 22px 18px;position: relative;}
        .modal h3{ margin: 0 0 14px; font-size: 20px; font-weight: 700; }
        .modal .close{position:absolute; top:10px; right:12px;font-size: 22px; color:#9aa4b2; cursor:pointer; border:none; background:transparent;}
        .modal .close:hover{ color:#fff; }
        .modal .field{display:block; width:100%; margin:10px 0 14px;background:#0b0d11; border:1px solid #30363d; color:#e6edf3;border-radius: 10px; padding: 12px 14px; font-size: 16px;}
        .modal .actions{ display:flex; gap:10px; align-items:center; }
        .modal .btn-primary{flex:1; display:inline-flex; align-items:center; justify-content:center;background:#1a4fa3; color:#fff; border:none; border-radius:10px; padding: 12px 14px;cursor:pointer; font-weight:700;}
        .modal .btn-primary:hover{ filter:brightness(1.05); }
        .modal .link{background:none; border:none; color:#38bdf8; text-decoration:underline; cursor:pointer; padding: 0 4px;}
        .modal small.hint{ display:block; color:#9aa4b2; margin-top:6px; }
        @media (max-width:576px){.modal{ padding:18px 16px 14px; }}
    </style>
</head>
<body class="dark">
    <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme" title="Toggle theme">
        <span class="icon sun"><i class="fas fa-sun"></i></span>
        <span class="icon moon"><i class="fas fa-moon"></i></span>
        <span class="slider"></span>
    </button>
    <div class="container">
        <div class="tabs">
            <button id="loginTab" class="active">Login</button>
            <button id="registerTab">Register</button>
        </div>
        <!-- Login Form -->
        <form id="loginForm" class="form active" method="POST" action="{{ route('auth') }}">
            @csrf
            <input type="email" name="email" placeholder="Email" value="{{ old('email') }}" required />
            <input type="password" name="password" placeholder="Password" required />
            {{-- <label class="remember"><input type="checkbox" name="remember" /> Remember me</label> --}}
            <button type="submit" class="btn">
                <i class="fa fa-sign-in-alt"></i> Login
            </button>
            <p class="or">Or continue with</p>
            <div class="socials">
                <a class="social google"><i class="fab fa-google"></i></a>
                <a class="social facebook"><i class="fab fa-facebook-f"></i></a>
                <a class="social"><i class="fab fa-x-twitter"></i></a>
            </div>
        </form>
        <!-- Register Form -->
        <form id="registerForm" class="form" method="POST" action="{{ route('register') }}">
            @csrf
            <input type="email" name="email" placeholder="Email" value="{{ old('email') }}" required />
            <input type="password" name="password" placeholder="Password" required />
            <input type="password" name="password_confirmation" placeholder="Confirm Password" required />
            <button type="submit" class="btn">
                <i class="fa fa-user-plus"></i> Register
            </button>
            <p class="or">Or sign up with</p>
            <div class="socials">
                <a class="social google"><i class="fab fa-google"></i></a>
                <a class="social facebook"><i class="fab fa-facebook-f"></i></a>
                <a class="social"><i class="fab fa-x-twitter"></i></a>
            </div>
        </form>
    </div>
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <script src="{{ asset('js/toastr.min.js') }}"></script>
    <script>
        toastr.options = {
            "closeButton": true,
            "progressBar": true,
            "newestOnTop": true,
            "preventDuplicates": true,
            "timeOut": 4000,
            "positionClass": "toast-top-center"
        };

        // Flash messages:
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
    <script src="{{ asset('js/login.js') }}"></script>
</body>
</html>