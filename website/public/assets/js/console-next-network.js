/* Decorative ServerNet globe: fictional network, no external data or requests. */
(function () {
    'use strict';

    var PI = Math.PI;
    var RAD = PI / 180;
    var controllers = [];
    var palettes = {
        dark: {
            halo: ['rgba(24,110,207,0)', 'rgba(31,123,220,.12)', 'rgba(45,194,237,.06)', 'rgba(45,194,237,0)'],
            surface: ['rgba(17,53,88,.86)', 'rgba(9,30,56,.9)', 'rgba(5,17,35,.96)'],
            rim: 'rgba(89,185,246,.3)', grid: 'rgba(82,155,203,.1)',
            land: 'rgba(87,193,232,', ocean: 'rgba(75,137,187,', route: 'rgba(61,161,245,.32)',
            packetGlow: ['rgba(136,248,255,.92)', 'rgba(74,218,255,.6)', 'rgba(44,175,252,0)'],
            packet: '#c1faff', markerRing: 'rgba(73,221,255,.3)', marker: '#7feaff'
        },
        light: {
            halo: ['rgba(101,184,235,0)', 'rgba(87,176,232,.12)', 'rgba(79,187,221,.06)', 'rgba(79,187,221,0)'],
            surface: ['rgba(255,255,255,.98)', 'rgba(230,245,255,.96)', 'rgba(196,224,244,.98)'],
            rim: 'rgba(42,127,185,.36)', grid: 'rgba(45,123,173,.15)',
            land: 'rgba(28,111,169,', ocean: 'rgba(94,156,193,', route: 'rgba(20,112,195,.5)',
            packetGlow: ['rgba(21,109,197,.88)', 'rgba(28,155,215,.4)', 'rgba(44,175,252,0)'],
            packet: '#1269ad', markerRing: 'rgba(17,121,184,.4)', marker: '#1675b5'
        }
    };
    // Deliberately coarse silhouettes, sufficient for a small decorative globe.
    // Coordinates are longitude/latitude, not a geographic or coverage dataset.
    var continents = [
        [[-168,72],[-144,70],[-127,58],[-123,49],[-117,32],[-106,23],[-99,17],[-89,16],[-82,8],[-78,10],[-85,23],[-81,30],[-67,45],[-55,52],[-62,60],[-85,73],[-112,78]],
        [[-73,60],[-54,59],[-23,75],[-37,83],[-58,82]],
        [[-81,12],[-67,10],[-51,4],[-35,-6],[-40,-23],[-53,-36],[-67,-55],[-75,-47],[-74,-24],[-80,-4]],
        [[-10,36],[-9,44],[1,44],[5,53],[14,54],[20,60],[28,70],[36,70],[39,56],[31,46],[43,41],[26,35],[13,38],[4,36]],
        [[-18,35],[5,37],[25,32],[33,31],[44,12],[51,12],[43,-12],[34,-26],[19,-35],[11,-20],[9,-5],[-3,5],[-16,12]],
        [[28,71],[62,76],[98,78],[134,71],[179,65],[165,51],[144,44],[135,35],[127,38],[119,26],[110,20],[107,5],[99,9],[93,22],[85,22],[78,8],[72,21],[60,27],[52,15],[43,13],[35,30],[27,42],[40,53]],
        [[112,-11],[132,-12],[146,-18],[154,-28],[143,-39],[126,-35],[114,-25]],
        [[46,-13],[51,-16],[49,-25],[44,-25]],
        [[129,31],[142,46],[146,43],[140,34],[133,30]],
        [[95,5],[107,-6],[119,-8],[130,-4],[122,2],[109,1]],
        [[166,-35],[175,-38],[173,-46],[166,-46]],
        [[-8,50],[1,51],[-2,59],[-7,58]]
    ];
    var hubs = [
        [4.9,52.4], [8.7,50.1], [51.4,35.7], [29,41],
        [103.8,1.35], [-74,40.7], [-122.4,37.8], [139.7,35.7]
    ];
    var connections = [[0,1],[1,2],[1,3],[2,3],[2,4],[0,5],[5,6],[4,7],[1,7]];

    function onLand(lon, lat) {
        return continents.some(function (polygon) {
            var inside = false;
            for (var i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
                var a = polygon[i], b = polygon[j];
                if ((a[1] > lat) !== (b[1] > lat) && lon < (b[0] - a[0]) * (lat - a[1]) / (b[1] - a[1]) + a[0]) inside = !inside;
            }
            return inside;
        });
    }

    function vector(lon, lat) {
        var c = Math.cos(lat * RAD);
        return [c * Math.sin(lon * RAD), -Math.sin(lat * RAD), c * Math.cos(lon * RAD)];
    }

    var dots = [];
    for (var lat = -56; lat <= 82; lat += 4) {
        var step = 4 / Math.max(.35, Math.cos(lat * RAD));
        for (var lon = -178; lon < 180; lon += step) {
            var land = onLand(lon, lat);
            if (land || (Math.round(lon + lat) % 4 === 0)) dots.push({ position: vector(lon, lat), land: land });
        }
    }
    var markers = hubs.map(function (hub) { return vector(hub[0], hub[1]); });
    var routes = connections.map(function (pair) {
        var a = markers[pair[0]], b = markers[pair[1]];
        var angle = Math.acos(Math.max(-1, Math.min(1, a[0] * b[0] + a[1] * b[1] + a[2] * b[2])));
        var sine = Math.sin(angle) || 1;
        var rise = .06 + Math.min(.25, angle * .16);
        var points = [];
        for (var i = 0; i <= 48; i++) {
            var t = i / 48, scale = 1 + Math.sin(t * PI) * rise;
            var left = Math.sin((1 - t) * angle) / sine, right = Math.sin(t * angle) / sine;
            points.push(a.map(function (value, axis) { return (value * left + b[axis] * right) * scale; }));
        }
        return points;
    });

    // Graticule geometry is static; reuse it rather than allocate arrays per frame.
    var graticule = [];
    for (var latitude = -60; latitude <= 60; latitude += 30) {
        var parallel = [];
        for (var longitude = -180; longitude <= 180; longitude += 5) parallel.push(vector(longitude, latitude));
        graticule.push(parallel);
    }
    for (var meridian = -180; meridian < 180; meridian += 30) {
        var line = [];
        for (var degree = -89; degree <= 89; degree += 4) line.push(vector(meridian, degree));
        graticule.push(line);
    }

    function createGlobe(canvas) {
        var ctx = canvas.getContext('2d', { alpha: true });
        if (!ctx) return { destroy: function () {} };
        var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        var width = 0, height = 0, radius = 0, cx = 0, cy = 0;
        var rotation = -17 * RAD, elapsed = 0, previous = 0, lastPaint = 0, raf = 0;
        var sinRotation = Math.sin(rotation), cosRotation = Math.cos(rotation);
        var sinTilt = Math.sin(-.17), cosTilt = Math.cos(-.17);
        var intersecting = canvas.getClientRects().length > 0, destroyed = false;
        var themeAttribute = canvas.dataset.themeAttribute || 'data-sn-theme';
        var theme = document.documentElement.getAttribute(themeAttribute) === 'light' ? 'light' : 'dark';
        var palette = palettes[theme];
        canvas.style.width = '100%';
        canvas.style.height = '100%';
        canvas.style.display = 'block';

        function project(point) {
            var s = sinRotation, c = cosRotation;
            var x = point[0] * c + point[2] * s;
            var z = point[2] * c - point[0] * s;
            var st = sinTilt, ct = cosTilt;
            var y = point[1] * ct - z * st;
            z = point[1] * st + z * ct;
            return { x: cx + x * radius, y: cy + y * radius, z: z };
        }

        function polyline(points, color, lineWidth) {
            ctx.beginPath();
            var drawing = false;
            points.forEach(function (point) {
                var p = project(point);
                if (p.z < .015) { drawing = false; return; }
                if (drawing) ctx.lineTo(p.x, p.y); else ctx.moveTo(p.x, p.y);
                drawing = true;
            });
            ctx.strokeStyle = color;
            ctx.lineWidth = lineWidth;
            ctx.stroke();
        }

        function draw() {
            if (!width || !height || destroyed) return;
            sinRotation = Math.sin(rotation); cosRotation = Math.cos(rotation);
            ctx.clearRect(0, 0, width, height);
            var halo = ctx.createRadialGradient(cx, cy, radius * .65, cx, cy, radius * 1.5);
            halo.addColorStop(0, palette.halo[0]);
            halo.addColorStop(.44, palette.halo[1]);
            halo.addColorStop(.65, palette.halo[2]);
            halo.addColorStop(1, palette.halo[3]);
            ctx.fillStyle = halo;
            ctx.fillRect(0, 0, width, height);
            var surface = ctx.createRadialGradient(cx - radius * .38, cy - radius * .4, 0, cx, cy, radius);
            surface.addColorStop(0, palette.surface[0]);
            surface.addColorStop(.65, palette.surface[1]);
            surface.addColorStop(1, palette.surface[2]);
            ctx.beginPath(); ctx.arc(cx, cy, radius, 0, PI * 2);
            ctx.fillStyle = surface; ctx.fill();
            ctx.strokeStyle = palette.rim; ctx.lineWidth = .7; ctx.stroke();

            graticule.forEach(function (points) { polyline(points, palette.grid, .55); });
            dots.forEach(function (dot) {
                var p = project(dot.position);
                if (p.z <= .01) return;
                var alpha = (dot.land ? .28 : .06) + p.z * (dot.land ? .62 : .12);
                ctx.fillStyle = (dot.land ? palette.land : palette.ocean) + alpha + ')';
                ctx.beginPath();
                ctx.arc(p.x, p.y, Math.max(.55, radius * .012) * (.72 + p.z * .28), 0, PI * 2);
                ctx.fill();
            });
            routes.forEach(function (route, index) {
                polyline(route, palette.route, .8);
                var packet = route[Math.floor(((elapsed * .00011 + index * .173) % 1) * (route.length - 1))];
                var p = project(packet);
                if (p.z < .03) return;
                var glow = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, 5);
                glow.addColorStop(0, palette.packetGlow[0]);
                glow.addColorStop(.3, palette.packetGlow[1]);
                glow.addColorStop(1, palette.packetGlow[2]);
                ctx.fillStyle = glow; ctx.fillRect(p.x - 5, p.y - 5, 10, 10);
                ctx.beginPath(); ctx.arc(p.x, p.y, 1.2, 0, PI * 2); ctx.fillStyle = palette.packet; ctx.fill();
            });
            markers.forEach(function (point, index) {
                var p = project(point);
                if (p.z < .025) return;
                var pulse = motion.matches ? 0 : Math.sin(elapsed * .002 + index) * .8;
                ctx.beginPath(); ctx.arc(p.x, p.y, 4 + pulse, 0, PI * 2);
                ctx.strokeStyle = palette.markerRing; ctx.lineWidth = .7; ctx.stroke();
                ctx.beginPath(); ctx.arc(p.x, p.y, 1.7, 0, PI * 2); ctx.fillStyle = palette.marker; ctx.fill();
            });
            canvas.dataset.theme = theme;
            if (canvas.dataset.rendered !== 'true') canvas.dataset.rendered = 'true';
        }

        function visible() { return intersecting && !document.hidden && width > 0 && height > 0; }
        function stop() { window.cancelAnimationFrame(raf); raf = 0; previous = 0; }
        function animate(now) {
            if (!visible() || motion.matches || destroyed) { sync(); return; }
            if (!previous) previous = now;
            var delta = Math.min(100, now - previous); previous = now;
            elapsed += delta; rotation += delta * .000035;
            if (now - lastPaint >= 1000 / 24) { draw(); lastPaint = now; }
            raf = window.requestAnimationFrame(animate);
        }
        function sync() {
            stop();
            if (destroyed) return;
            canvas.dataset.motion = !visible() ? 'paused' : motion.matches ? 'reduced' : 'running';
            if (visible()) { draw(); if (!motion.matches) raf = window.requestAnimationFrame(animate); }
        }
        function resize() {
            var box = canvas.parentElement.getBoundingClientRect();
            width = Math.round(box.width); height = Math.round(box.height);
            var dpr = Math.min(window.devicePixelRatio || 1, 1.5);
            canvas.width = Math.round(width * dpr); canvas.height = Math.round(height * dpr);
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            radius = Math.min(width * .36, height * .42);
            cx = width * .5; cy = height * .52;
            intersecting = box.width > 0 && box.height > 0;
            sync();
        }
        var sizeObserver = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(resize) : null;
        var intersectionObserver = typeof IntersectionObserver !== 'undefined' ? new IntersectionObserver(function (entries) {
            intersecting = entries[0].isIntersecting; sync();
        }, { threshold: 0.01 }) : null;
        if (sizeObserver) sizeObserver.observe(canvas.parentElement); else window.addEventListener('resize', resize);
        if (intersectionObserver) intersectionObserver.observe(canvas);
        // Canvas pixels do not inherit CSS colours. Repaint even when motion is reduced.
        var themeObserver = new MutationObserver(function () {
            theme = document.documentElement.getAttribute(themeAttribute) === 'light' ? 'light' : 'dark';
            palette = palettes[theme];
            if (visible()) draw();
        });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: [themeAttribute] });
        document.addEventListener('visibilitychange', sync);
        if (motion.addEventListener) motion.addEventListener('change', sync); else motion.addListener(sync);
        resize();

        return { destroy: function () {
            destroyed = true; stop();
            canvas.dataset.motion = 'paused';
            if (sizeObserver) sizeObserver.disconnect();
            if (intersectionObserver) intersectionObserver.disconnect();
            themeObserver.disconnect();
            window.removeEventListener('resize', resize);
            document.removeEventListener('visibilitychange', sync);
            if (motion.removeEventListener) motion.removeEventListener('change', sync); else motion.removeListener(sync);
        } };
    }

    function init() {
        controllers = Array.from(document.querySelectorAll('canvas[data-network-globe]')).map(createGlobe);
    }
    window.addEventListener('pagehide', function () { controllers.forEach(function (controller) { controller.destroy(); }); controllers = []; });
    window.addEventListener('pageshow', function (event) { if (event.persisted) init(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
