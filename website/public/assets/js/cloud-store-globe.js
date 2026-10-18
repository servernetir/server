/* ServerNet cloud-store globe. Geographic illustration, not network telemetry.
 * Uses the existing local Natural Earth data; see atlas-geography-LICENSE.txt.
 * Search/selection is supplied by the form through the cvx:location event.
 */
(function () {
  'use strict';
  var pendingLocation = null;
  var selectLocation = function (detail) { pendingLocation = detail || {}; };
  document.addEventListener('cvx:location', function (event) { selectLocation(event.detail); });
  window.SNCloudStoreGlobe = { select: function (detail) { selectLocation(detail); } };

  function boot() {
    var host = document.getElementById('cvx-globe');
    var geography = window.SNCloudStoreGeography;
    if (!host || !geography) return;
    var canvas = host.querySelector('canvas');
    var fallback = host.querySelector('.cvx-globe-fallback');
    var context;
    try { context = canvas && canvas.getContext('2d', { alpha: true }); } catch (error) { return; }
    if (!context) { host.dataset.globeState = 'unavailable'; return; }

    var rad = Math.PI / 180;
    var motion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
    var width = 0, height = 0, ratio = 1, radius = 0, cx = 0, cy = 0;
    var yaw = 45, pitch = 25, targetYaw = yaw, targetPitch = pitch;
    var point = null, location = {}, frame = 0, lastTick = 0, dirty = true;
    var visible = !('IntersectionObserver' in window), active = true, ready = false;
    var landVectors = [], coastVectors = [], gridVectors = [];
    var colors = {}, halo = null, ocean = null;
    var projectCosYaw = 1, projectSinYaw = 0, projectCosPitch = 1, projectSinPitch = 0;
    var rendered = 0;
    var wrap = function (angle) { return ((angle + 180) % 360 + 360) % 360 - 180; };
    var vector = function (latitude, longitude) {
      var a = latitude * rad, b = longitude * rad, cosine = Math.cos(a);
      return [cosine * Math.sin(b), Math.sin(a), cosine * Math.cos(b)];
    };
    var project = function (v) {
      var z = v[0] * projectSinYaw + v[2] * projectCosYaw;
      return {
        x: cx + (v[0] * projectCosYaw - v[2] * projectSinYaw) * radius,
        y: cy - (v[1] * projectCosPitch - z * projectSinPitch) * radius,
        z: v[1] * projectSinPitch + z * projectCosPitch
      };
    };

    function refreshColors() {
      var style = getComputedStyle(host);
      var light = document.documentElement.getAttribute('data-theme') === 'light';
      var token = function (name, alternate) { return style.getPropertyValue(name).trim() || alternate; };
      colors = {
        ocean: token('--cvx-globe-ocean', light ? '#e4f2ef' : '#081c29'),
        land: token('--cvx-globe-land', light ? '#37827b' : '#86d5cc'),
        coast: token('--cvx-globe-coast', light ? '#196e65' : '#a6ece1'),
        grid: token('--cvx-globe-grid', light ? '#649e97' : '#71c9bd'),
        marker: token('--cvx-globe-marker', light ? '#0a775b' : '#c0ffe5'),
        halo: light ? 'rgba(35,143,116,.11)' : 'rgba(74,207,176,.11)'
      };
      if (!width || !height) return;
      halo = context.createRadialGradient(cx, cy, radius * .72, cx, cy, radius * 1.25);
      halo.addColorStop(0, 'rgba(54,175,142,0)');
      halo.addColorStop(.67, colors.halo);
      halo.addColorStop(1, 'rgba(54,175,142,0)');
      ocean = context.createRadialGradient(cx - radius * .32, cy - radius * .38, radius * .08, cx, cy, radius);
      ocean.addColorStop(0, light ? '#f6fffb' : '#123543');
      ocean.addColorStop(1, colors.ocean);
    }

    function paths(lines, stroke, opacity, thickness) {
      context.beginPath();
      lines.forEach(function (line) {
        var connected = false;
        line.forEach(function (v) {
          var p = project(v);
          if (p.z > .008) {
            if (connected) context.lineTo(p.x, p.y); else context.moveTo(p.x, p.y);
            connected = true;
          } else connected = false;
        });
      });
      context.strokeStyle = stroke;
      context.globalAlpha = opacity;
      context.lineWidth = thickness;
      context.stroke();
      context.globalAlpha = 1;
    }

    function draw() {
      if (!width || !height || !landVectors.length) return;
      projectCosYaw = Math.cos(yaw * rad); projectSinYaw = Math.sin(yaw * rad);
      projectCosPitch = Math.cos(pitch * rad); projectSinPitch = Math.sin(pitch * rad);
      context.clearRect(0, 0, width, height);
      context.fillStyle = halo; context.fillRect(0, 0, width, height);
      context.save();
      context.beginPath(); context.arc(cx, cy, radius, 0, Math.PI * 2);
      context.fillStyle = ocean; context.fill(); context.clip();
      paths(gridVectors, colors.grid, .12, .6);
      // Depth buckets give the sampled land a round silhouette without a texture
      // request or a second rendering loop.
      for (var bucket = 0; bucket < 4; bucket++) {
        context.beginPath();
        for (var i = 0; i < landVectors.length; i++) {
          var p = project(landVectors[i]);
          if (p.z <= .015 || Math.min(3, Math.floor(p.z * 4)) !== bucket) continue;
          var size = Math.max(.75, radius / 180) * (.7 + p.z * .3);
          context.moveTo(p.x + size, p.y); context.arc(p.x, p.y, size, 0, Math.PI * 2);
        }
        context.fillStyle = colors.land; context.globalAlpha = .28 + bucket * .18;
        context.fill();
      }
      context.globalAlpha = 1;
      paths(coastVectors, colors.coast, .48, Math.max(.6, radius / 450));
      context.restore();
      context.beginPath(); context.arc(cx, cy, radius, 0, Math.PI * 2);
      context.strokeStyle = colors.land; context.globalAlpha = .25; context.lineWidth = .8; context.stroke();
      context.globalAlpha = 1;
      if (point) {
        var pin = project(vector(point.latitude, point.longitude));
        if (pin.z > .025) {
          var alpha = Math.min(1, pin.z * 4);
          context.globalAlpha = alpha * .13; context.fillStyle = colors.marker;
          context.beginPath(); context.arc(pin.x, pin.y, 18, 0, Math.PI * 2); context.fill();
          context.globalAlpha = alpha * .65; context.strokeStyle = colors.marker; context.lineWidth = 1;
          context.beginPath(); context.arc(pin.x, pin.y, 9, 0, Math.PI * 2); context.stroke();
          context.globalAlpha = alpha; context.beginPath(); context.arc(pin.x, pin.y, 3.6, 0, Math.PI * 2); context.fill();
          context.globalAlpha = 1;
        }
      }
      rendered++;
      host.dataset.rotation = wrap(yaw).toFixed(3);
      host.dataset.tilt = pitch.toFixed(3);
      host.dataset.globeFrames = String(rendered);
      if (!ready) {
        ready = true; host.classList.add('is-ready'); host.dataset.globeState = 'ready';
        if (fallback) fallback.hidden = true;
        document.dispatchEvent(new CustomEvent('cvx:globe-ready'));
      }
      dirty = false;
    }

    function tick(timestamp) {
      frame = 0;
      if (!active || !visible || document.hidden || !landVectors.length) { lastTick = 0; return; }
      var elapsed = lastTick ? Math.min(80, timestamp - lastTick) : 16;
      lastTick = timestamp;
      var previousYaw = yaw, previousPitch = pitch;
      var longitudeDelta = wrap(targetYaw - yaw), latitudeDelta = targetPitch - pitch;
      var settled = Math.abs(longitudeDelta) < .025 && Math.abs(latitudeDelta) < .025;
      if (motion.matches || settled) {
        yaw = targetYaw; pitch = targetPitch; settled = true;
      } else {
        var ease = 1 - Math.exp(-elapsed * .012);
        yaw += longitudeDelta * ease; pitch += latitudeDelta * ease;
      }
      if (dirty || yaw !== previousYaw || pitch !== previousPitch) draw();
      host.dataset.globeSettled = String(settled);
      if (!settled) frame = requestAnimationFrame(tick); else lastTick = 0;
    }

    function wake() {
      dirty = true;
      if (!active || !visible || document.hidden || !landVectors.length) {
        if (frame) cancelAnimationFrame(frame);
        frame = 0; lastTick = 0; return;
      }
      if (!frame) frame = requestAnimationFrame(tick);
    }

    function resize() {
      var rect = host.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0) return;
      var nextRatio = Math.min(window.devicePixelRatio || 1, 2);
      if (width !== rect.width || height !== rect.height || ratio !== nextRatio) {
        width = rect.width; height = rect.height; ratio = nextRatio;
        canvas.width = Math.max(1, Math.round(width * ratio)); canvas.height = Math.max(1, Math.round(height * ratio));
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        cx = width / 2; cy = height / 2; radius = Math.min(width, height) * .414;
        refreshColors();
      }
      wake();
    }

    function describe() {
      var name = document.getElementById('cvx-globe-name');
      var detail = document.getElementById('cvx-globe-detail');
      var coordinates = document.getElementById('cvx-globe-coordinates');
      var lang = (document.documentElement.lang || 'fa').slice(0, 2);
      var labels = {
        fa: ['نمای تقریبی کشور', 'موقعیت شهر', 'شبکهٔ جهانی'],
        en: ['Country overview', 'City location', 'Global network'],
        tr: ['Ülkeye genel bakış', 'Şehir konumu', 'Küresel ağ']
      }[lang] || ['Country overview', 'City location', 'Global network'];
      if (name) name.textContent = location.label || location.city || location.country || '';
      if (detail) detail.textContent = location.detail || (!point ? labels[2]
        : point.approximate ? (host.dataset.approximateLabel || labels[0]) : (host.dataset.exactLabel || labels[1]));
      if (coordinates) coordinates.textContent = !point ? '—'
        : (point.approximate ? '≈ ' : '') + Math.abs(point.latitude).toFixed(2) + '° ' + (point.latitude < 0 ? 'S' : 'N')
          + ' · ' + Math.abs(point.longitude).toFixed(2) + '° ' + (point.longitude < 0 ? 'W' : 'E');
    }

    selectLocation = function (detail) {
      location = detail || {};
      point = geography.resolve(location);
      if (point) { targetYaw = point.longitude; targetPitch = point.latitude; }
      host.dataset.focus = point ? point.country : '';
      host.dataset.approximate = String(!point || point.approximate);
      host.dataset.locationCode = String(location.code || '');
      if (point) { host.dataset.latitude = point.latitude; host.dataset.longitude = point.longitude; }
      else { delete host.dataset.latitude; delete host.dataset.longitude; }
      describe(); wake();
    };
    selectLocation(pendingLocation || {
      country: host.dataset.country || '', city: host.dataset.city || '', code: host.dataset.code || '',
      label: host.dataset.label || '', approximate: host.dataset.approximate === 'true'
    });

    // Some outer rings cross the antimeridian. Unwrap them before testing the
    // existing dot samples, so a dateline island does not paint dots in oceans.
    function landSamples(land) {
      var rings = land.coasts.map(function (ring) {
        var previous = ring[0][0], unwrapped = [[previous, ring[0][1]]];
        for (var i = 1; i < ring.length; i++) {
          previous += wrap(ring[i][0] - previous); unwrapped.push([previous, ring[i][1]]);
        }
        var xs = unwrapped.map(function (p) { return p[0]; }), ys = unwrapped.map(function (p) { return p[1]; });
        return { points: unwrapped, minX: Math.min.apply(null, xs), maxX: Math.max.apply(null, xs),
          minY: Math.min.apply(null, ys), maxY: Math.max.apply(null, ys) };
      });
      return land.points.filter(function (sample) {
        return rings.some(function (ring) {
          if (sample[1] < ring.minY || sample[1] > ring.maxY) return false;
          var x = sample[0] + Math.round(((ring.minX + ring.maxX) / 2 - sample[0]) / 360) * 360;
          if (x < ring.minX || x > ring.maxX) return false;
          var inside = false;
          for (var i = 0, j = ring.points.length - 1; i < ring.points.length; j = i++) {
            var a = ring.points[i], b = ring.points[j];
            if ((a[1] > sample[1]) !== (b[1] > sample[1])
              && x < (b[0] - a[0]) * (sample[1] - a[1]) / (b[1] - a[1]) + a[0]) inside = !inside;
          }
          return inside;
        });
      });
    }

    function installLand(land) {
      if (!land || !Array.isArray(land.points) || !land.points.length || !Array.isArray(land.coasts) || !land.coasts.length) {
        host.dataset.globeState = 'unavailable'; return;
      }
      try {
        landVectors = landSamples(land).map(function (p) { return vector(p[1], p[0]); });
        coastVectors = land.coasts.map(function (ring) { return ring.map(function (p) { return vector(p[1], p[0]); }); });
        for (var lat = -60; lat <= 60; lat += 30) {
          var parallel = []; for (var lon = -180; lon <= 180; lon += 5) parallel.push(vector(lat, lon));
          gridVectors.push(parallel);
        }
        for (var meridian = -180; meridian < 180; meridian += 30) {
          var line = []; for (var latitude = -90; latitude <= 90; latitude += 5) line.push(vector(latitude, meridian));
          gridVectors.push(line);
        }
        resize();
      } catch (error) { landVectors = []; host.dataset.globeState = 'unavailable'; }
    }

    var resizeObserver = 'ResizeObserver' in window ? new ResizeObserver(resize) : null;
    if (resizeObserver) resizeObserver.observe(host); else window.addEventListener('resize', resize, { passive: true });
    var intersectionObserver = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
      visible = entries[0].isIntersecting; wake();
    }, { rootMargin: '32px' }) : null;
    if (intersectionObserver) intersectionObserver.observe(host);
    var themeObserver = new MutationObserver(function () { refreshColors(); wake(); });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
    document.addEventListener('visibilitychange', wake);
    if (motion.addEventListener) motion.addEventListener('change', wake);
    window.addEventListener('pagehide', function () { active = false; wake(); });
    window.addEventListener('pageshow', function (event) { if (event.persisted) { active = true; resize(); } });

    host.dataset.globeState = 'loading';
    if (window.SNAtlasLand) { installLand(window.SNAtlasLand); return; }
    try {
      var source = new URL(host.dataset.landSrc || '', window.location.href);
      if (!host.dataset.landSrc || source.origin !== window.location.origin) { host.dataset.globeState = 'unavailable'; return; }
      var script = document.createElement('script'); script.src = source.href; script.async = true;
      script.onload = function () { installLand(window.SNAtlasLand); };
      script.onerror = function () { host.dataset.globeState = 'unavailable'; };
      document.head.appendChild(script);
    } catch (error) { host.dataset.globeState = 'unavailable'; }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
})();
