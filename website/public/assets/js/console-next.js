/* Parallel customer console: navigation and appearance only. Account forms stay native. */
(function () {
    'use strict';

    function init() {
        var body = document.body;
        if (!body || !body.classList.contains('console-next')) return;
        var root = document.documentElement;
        var sidebar = document.getElementById('cn-sidebar');
        var backdrop = document.querySelector('.cn-drawer-backdrop');
        var mobile = window.matchMedia('(max-width: 1000px)');
        var menus = Array.from(document.querySelectorAll('details[data-cn-menu]'));
        var drawerTriggers = Array.from(document.querySelectorAll('[data-cn-open-drawer]'));
        var backgrounds = Array.from(document.querySelectorAll('[data-cn-background], .cn-mobile-nav, .cn-imp-bar, .chat-fab, .chat-panel'));
        var backgroundState = new Map();
        var drawerOpen = false, drawerTrigger = null, previousOverflow = '';
        var search = document.getElementById('cn-search-dialog');
        var searchInput = document.querySelector('[data-cn-search-input]');
        var searchResults = document.querySelector('[data-cn-search-results]');
        var searchEmpty = document.querySelector('[data-cn-search-empty]');
        var searchTrigger = null;
        var impersonation = document.querySelector('.cn-imp-bar');
        var mobileNav = document.querySelector('.cn-mobile-nav');
        var checkoutDock = document.querySelector('.cvb-dock');
        var searchable = Array.from(document.querySelectorAll('[data-cn-searchable]')).map(function (link) {
            var label = link.querySelector('span');
            return { href: link.href, label: (label ? label.textContent : link.textContent).trim() };
        });

        function focusable(node) {
            return Array.from(node.querySelectorAll('a[href], button:not([disabled]), summary, input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]')).filter(function (el) {
                return el.getClientRects().length > 0 && !el.closest('[hidden],[inert]');
            });
        }
        function closeMenus(restore) {
            var active = null;
            menus.forEach(function (menu) {
                if (!menu.open) return;
                if (menu.contains(document.activeElement)) active = menu.querySelector('summary');
                menu.open = false;
                menu.querySelector('summary').setAttribute('aria-expanded', 'false');
            });
            if (restore && active) active.focus({ preventScroll: true });
        }
        menus.forEach(function (menu) {
            var summary = menu.querySelector('summary');
            if (!summary) return;
            summary.setAttribute('aria-expanded', String(menu.open));
            menu.addEventListener('toggle', function () {
                summary.setAttribute('aria-expanded', String(menu.open));
                if (menu.open) menus.forEach(function (other) { if (other !== menu) other.open = false; });
            });
        });

        function syncDrawer() {
            if (!sidebar) return;
            sidebar.inert = mobile.matches && !drawerOpen;
            body.dataset.cnDrawer = drawerOpen ? 'open' : 'closed';
            body.classList.toggle('cn-drawer-open', drawerOpen);
            sidebar.classList.toggle('is-open', drawerOpen);
            sidebar.dataset.open = String(drawerOpen);
            if (drawerOpen) {
                sidebar.setAttribute('role', 'dialog');
                sidebar.setAttribute('aria-modal', 'true');
            } else {
                sidebar.removeAttribute('role');
                sidebar.removeAttribute('aria-modal');
            }
            drawerTriggers.forEach(function (trigger) { trigger.setAttribute('aria-expanded', String(drawerOpen)); });
            if (backdrop) backdrop.hidden = !drawerOpen;
        }
        function openDrawer(trigger) {
            if (!mobile.matches || drawerOpen || !sidebar) return;
            closeMenus(false);
            drawerTrigger = trigger || document.activeElement;
            drawerOpen = true;
            previousOverflow = body.style.overflow;
            body.style.overflow = 'hidden';
            backgrounds.forEach(function (node) { backgroundState.set(node, node.inert); node.inert = true; });
            syncDrawer();
            var first = sidebar.querySelector('[data-cn-close-drawer]');
            (first || sidebar).focus({ preventScroll: true });
        }
        function closeDrawer(restore) {
            if (!drawerOpen) { syncDrawer(); return; }
            drawerOpen = false;
            body.style.overflow = previousOverflow;
            backgrounds.forEach(function (node) { node.inert = backgroundState.get(node) || false; });
            backgroundState.clear();
            syncDrawer();
            if (restore !== false && drawerTrigger && drawerTrigger.isConnected && drawerTrigger.getClientRects().length) drawerTrigger.focus({ preventScroll: true });
        }

        function normalize(value) { return value.normalize('NFKC').toLocaleLowerCase(root.lang || 'en').trim(); }
        function renderSearch() {
            if (!searchInput || !searchResults) return;
            var term = normalize(searchInput.value), count = 0;
            searchResults.replaceChildren();
            searchable.forEach(function (item) {
                if (term && !normalize(item.label + ' ' + item.href).includes(term)) return;
                var link = document.createElement('a');
                link.href = item.href;
                link.textContent = item.label;
                searchResults.appendChild(link);
                count++;
            });
            if (searchEmpty) searchEmpty.hidden = count > 0;
        }
        function openSearch(trigger) {
            if (!search || search.open) return;
            if (drawerOpen) closeDrawer(false);
            closeMenus(false);
            searchTrigger = trigger || document.activeElement;
            searchInput.value = '';
            renderSearch();
            search.showModal();
            searchInput.focus();
        }
        if (search) {
            search.addEventListener('close', function () {
                if (searchTrigger && searchTrigger.isConnected && searchTrigger.getClientRects().length) searchTrigger.focus({ preventScroll: true });
            });
            search.addEventListener('click', function (event) {
                if (event.target !== search) return;
                var bounds = search.getBoundingClientRect();
                if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) search.close();
            });
        }
        if (searchInput) searchInput.addEventListener('input', renderSearch);

        function syncThemeButtons() {
            var light = root.dataset.theme === 'light';
            document.querySelectorAll('[data-cn-theme]').forEach(function (button) { button.setAttribute('aria-pressed', String(light)); });
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.content = light ? '#f3f8ff' : '#080e1a';
        }
        function toggleTheme() {
            var value = root.dataset.theme === 'light' ? 'dark' : 'light';
            if (value === 'light') root.dataset.theme = 'light'; else delete root.dataset.theme;
            try { localStorage.setItem('snet-theme', value); } catch (_) { /* Appearance still works when storage is unavailable. */ }
            try {
                var domain = /(^|\.)servernet\.cloud$/i.test(location.hostname) ? '; domain=.servernet.cloud' : '';
                document.cookie = 'snet-theme=' + value + '; path=/; max-age=31536000; samesite=lax' + domain + (location.protocol === 'https:' ? '; secure' : '');
            } catch (_) { /* Host policy can deny cookies. */ }
            syncThemeButtons();
        }
        document.addEventListener('click', function (event) {
            var element = event.target instanceof Element ? event.target : null;
            if (!element) { closeMenus(false); return; }
            var target = element.closest('button,a');
            if (target) {
                if (target.hasAttribute('data-cn-open-drawer')) openDrawer(target);
                if (target.hasAttribute('data-cn-close-drawer')) closeDrawer(true);
                if (target.hasAttribute('data-cn-open-search')) openSearch(target);
                if (target.hasAttribute('data-cn-close-search') && search) search.close();
                if (target.hasAttribute('data-cn-theme')) toggleTheme();
                if (target.matches('a')) {
                    if (drawerOpen && sidebar.contains(target)) closeDrawer(false);
                    closeMenus(false);
                }
            }
            if (!element.closest('details[data-cn-menu]')) closeMenus(false);
        });
        document.addEventListener('keydown', function (event) {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openSearch(document.querySelector('[data-cn-open-search]')); return; }
            if (event.key === 'Escape') {
                if (search && search.open) { event.preventDefault(); search.close(); return; }
                if (drawerOpen) closeDrawer(true); else closeMenus(true);
            }
            if (event.key === 'Tab' && drawerOpen && sidebar) {
                var items = focusable(sidebar), first = items[0], last = items[items.length - 1];
                if (!first) { event.preventDefault(); sidebar.focus(); return; }
                if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
            }
        });
        document.addEventListener('focusin', function (event) {
            if (drawerOpen && sidebar && !sidebar.contains(event.target)) (focusable(sidebar)[0] || sidebar).focus({ preventScroll: true });
            else if (event.target instanceof Element && !event.target.closest('details[data-cn-menu]')) closeMenus(false);
        });
        function sizeMobileNav() {
            if (!mobileNav || !mobile.matches || !mobileNav.getClientRects().length) { body.style.removeProperty('--cn-mobile-nav-h'); return; }
            var height = Math.ceil(mobileNav.getBoundingClientRect().height);
            if (height > 0 && body.style.getPropertyValue('--cn-mobile-nav-h') !== height + 'px') body.style.setProperty('--cn-mobile-nav-h', height + 'px');
        }
        function sizeCheckoutDock() {
            var height = checkoutDock && checkoutDock.getClientRects().length ? Math.ceil(checkoutDock.getBoundingClientRect().height) : 0;
            if (body.style.getPropertyValue('--cn-checkout-dock-h') !== height + 'px') body.style.setProperty('--cn-checkout-dock-h', height + 'px');
        }
        function measureBottomControls() { sizeMobileNav(); sizeCheckoutDock(); }
        function mediaChanged() { closeMenus(false); if (!mobile.matches) closeDrawer(false); else syncDrawer(); measureBottomControls(); }
        if (mobile.addEventListener) mobile.addEventListener('change', mediaChanged); else mobile.addListener(mediaChanged);
        window.addEventListener('resize', function () { closeMenus(false); measureBottomControls(); });
        if (mobileNav && typeof ResizeObserver !== 'undefined') new ResizeObserver(sizeMobileNav).observe(mobileNav);
        if (checkoutDock && typeof ResizeObserver !== 'undefined') new ResizeObserver(sizeCheckoutDock).observe(checkoutDock);
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(measureBottomControls);
        measureBottomControls();
        if (impersonation) {
            var sizeImpersonation = function () { body.style.setProperty('--cn-imp-h', Math.ceil(impersonation.getBoundingClientRect().height) + 'px'); };
            if (typeof ResizeObserver !== 'undefined') new ResizeObserver(sizeImpersonation).observe(impersonation);
            else window.addEventListener('resize', sizeImpersonation);
            sizeImpersonation();
        }
        syncDrawer();
        syncThemeButtons();
        root.dataset.cnReady = 'true';
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
})();
