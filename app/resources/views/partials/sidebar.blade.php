<aside class="sidebar">
    <div class="brand"><a href="{{ route('home') }}">SERVERNET</a></div>
    <div class="section">
        <div class="section-title">create</div>
        <nav>
            <ul class="nav">
                <li><a class="item" href="{{ route('vps') }}">
                        <span class="item-title">Virtual server</span>
                        <span class="badge--red">-15%</span>
                    </a></li>
                <li><a class="item" href="{{ route('hi-cpu') }}">
                        <span class="item-title">Hi-CPU server</span>
                        <span class="badge--red">-15%</span>
                    </a></li>
                <li><a class="item" href="{{ route('dedicated') }}">
                        <span class="item-title">Dedicated Server</span>
                    </a></li>
                <li><a class="item" href="{{ route('host') }}">
                        <span class="item-title">Host</span>
                    </a></li>
                <li><a class="item" href="{{ route('domain') }}">
                        <span class="item-title">Domain</span>
                    </a></li>
                <li><a class="item" href="{{ route('vpn') }}">
                        <span class="item-title">VPN</span>
                    </a></li>
                <li><a class="item" href="{{ route('license') }}">
                        <span class="item-title">License</span>
                        <span class="badge--blue">-40%</span>
                    </a></li>
            </ul>
        </nav>
    </div>
    <div class="section">
        <div class="section-title">my services</div>
        <nav>
            <ul class="nav">
                <li><a class="item" href="{{ route('home') }}"><span class="item-title">Services</span></a></li>
                <li><a class="item" href="{{ route('finances') }}"><span class="item-title">Finances</span></a></li>
                <li><a class="item" href="{{ route('profile') }}"><span class="item-title">Settings</span></a></li>
                {{-- <li><a class="item" href="{{ route('referral-system') }}"><span class="item-title">Referral system</span></a></li> --}}
                {{-- <li><a class="item" href="{{ route('limits') }}"><span class="item-title">Limits</span></a></li> --}}
            </ul>
        </nav>
    </div>
    <div class="side-footer">
        <a class="mini-link" href="https://server.net/">😎 Support</a>
        <a class="mini-link" href="https://server.net/">🤖 Terminator</a>
        <a class="mini-link" href="https://server.net/">🌐 aeza.net</a>
    </div>
</aside>