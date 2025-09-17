<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="icon" href="{{ asset('css/images/favicon.ico') }}">
    <title>@yield('title')</title>
    <link rel="stylesheet" href="{{ asset('css/toastr.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/simplebar.css') }}">
    <link rel="stylesheet" href="{{ asset('css/animate.min.css') }}">
    <link rel="stylesheet" href="{{ asset('fonts/feather.ttf') }}">
    <link rel="stylesheet" href="{{ asset('vendor/tabler-icons/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('css/daterangepicker.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app-dark.css') }}" id="darkTheme">
    <style>
        #toast-container {z-index: 99999;}
        #toast-container>.toast {
            background-color: #1a1c20 !important;
            opacity: 1 !important;
            color: #e6edf3;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .6);
            border: 1px solid #30363d;
            border-radius: 12px;
            padding: 14px 18px;
            font-size: 18px;
            line-height: 1.6;
            min-width: 370px;
            max-width: 480px;
            background-image: none !important;
        }
        #toast-container>.toast-success,
        #toast-container>.toast-error,
        #toast-container>.toast-info,
        #toast-container>.toast-warning {
            background-image: none !important;
        }
        #toast-container>.toast-success {border-left: 5px solid #22c55e;}
        #toast-container>.toast-error {border-left: 5px solid #ef4444;}
        #toast-container>.toast-info {border-left: 5px solid #38bdf8;}
        #toast-container>.toast-warning {border-left: 5px solid #f59e0b;}
        #toast-container>.toast .toast-title {
            font-weight: 700;
            font-size: 1.8em;
            margin-bottom: 2px;
        }
        #toast-container>.toast .toast-message {font-weight: 500;}
        #toast-container>.toast .toast-close-button {
            font-size: 25px;
            color: #9aa4b2;
            opacity: 1;
            text-shadow: none;
        }
        #toast-container>.toast .toast-close-button:hover {color: #fff;}
        #toast-container .toast-progress {
            height: 3px;
            background: #1a4fa3;
            opacity: .95;
        }
        @media (max-width: 576px) {
            #toast-container>.toast {
                min-width: 0;
                max-width: 92vw;
                padding: 12px 14px;
                font-size: 15px;
            }
        }
    </style>
    @yield('extra-css')
</head>