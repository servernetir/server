<nav class="topnav navbar navbar-light">
    <button type="button" class="navbar-toggler text-muted mt-2 p-0 mr-3 collapseSidebar d-lg-none">
        <i class="ti ti-menu navbar-toggler-icon"></i>
    </button>
    <ul class="nav ml-auto">
        <li class="nav-item">
            <button type="button" class="btn my-2 mr-1 btn-dark btn-sm">0 $</button>
            <a href="{{ route('profile') }}" class="btn my-2 mr-1 btn-dark btn-sm">Your profile</a>
            <form method="POST" action="{{ route('logout') }}" class="d-inline">
                @csrf
                <button type="submit" class="btn my-2 btn-outline-danger btn-sm">Logout</button>
            </form>
        </li>
    </ul>
</nav>