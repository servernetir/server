<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login/Register UI</title>
    <link rel="stylesheet" href="{{ asset('css/login.css') }}" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
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
            @if ($errors->has('email'))
                <div class="error">{{ $errors->first('email') }}</div>
            @endif
            <input type="email" name="email" placeholder="Email" required />
            <input type="password" name="password" placeholder="Password" required />
            <button type="submit" class="btn">
                <i class="fa fa-sign-in-alt"></i> Login
            </button>
            <p class="or">Or continue with</p>
            <div class="socials">
                <a  class="social google"><i class="fab fa-google"></i></a>
                <a  class="social facebook"><i class="fab fa-facebook-f"></i></a>
                <a  class="social"><i class="fab fa-x-twitter"></i></a>
            </div>
        </form>

        <!-- Register Form -->
        <form id="registerForm" class="form" method="POST">
            @csrf
            @if ($errors->has('email') || $errors->has('password'))
                <div class="error">{{ $errors->first('email') ?: $errors->first('password') }}</div>
            @endif
            <input type="email" name="email" placeholder="Email" required />
            <input type="password" name="password" placeholder="Password" required />
            <input type="password" name="password_confirmation" placeholder="Confirm Password" required />
            <button type="submit" class="btn">
                <i class="fa fa-user-plus"></i> Register
            </button>
            <p class="or">Or sign up with</p>
            <div class="socials">
                <a  class="social google"><i class="fab fa-google"></i></a>
                <a  class="social facebook"><i class="fab fa-facebook-f"></i></a>
                <a  class="social"><i class="fab fa-x-twitter"></i></a>
            </div>
        </form>
    </div>

    <script src="{{ asset('js/login.js') }}"></script>
</body>

</html>