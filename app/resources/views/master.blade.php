<!doctype html>
<html lang="en">
@include('partials.head')
<body>
    <div class="app">
        @include('partials.sidebar')
        <main class="main">
            <meta name="csrf-token" content="{{ csrf_token() }}">
            @include('partials.topnav')
            @yield('content')
        </main>
    </div>
    @include('partials.scripts')
</body>
</html>