<!DOCTYPE html>
<html>

@include('partials.head')

<body class="vertical dark">
    <div class="wrapper">
        @include('partials.topnav')
        @include('partials.sidebar')
        <div id="main-content" style="position: relative;">
            <meta name="csrf-token" content="{{ csrf_token() }}">
            @yield('content')
        </div>
        @include('partials.modal-notif')
    </div>
    @include('partials.scripts')
</body>

</html>
