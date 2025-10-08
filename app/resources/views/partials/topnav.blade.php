<div class="topbar">
    <span class="badge-money">0 €</span>
    <a href="{{ route('profile') }}" class="chip">Your profile</a>
    <form method="POST" action="{{ route('logout') }}" class="d-inline">
        @csrf
        <button type="submit" class="logout">Logout</button>
    </form>
</div>