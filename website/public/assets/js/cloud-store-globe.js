/* ServerNet cloud-store Atlas. Geographic illustration, not network telemetry.
 * Local Natural Earth data: atlas-geography-LICENSE.txt. cvx:location supplies
 * a display position; cvx:country-select only browses the real country catalogue.
 */
(function () {
  'use strict';
  var pendingLocation = null;
  var selectLocation = function (detail) { pendingLocation = detail || {}; };
  document.addEventListener('cvx:location', function (event) { selectLocation(event.detail); });
  window.SNCloudStoreGlobe = { select: function (detail) { selectLocation(detail); } };

  function boot() {
    var host = document.getElementById('cvx-globe'), geography = window.SNCloudStoreGeography;
    if (!host || !geography) return;
    var canvas = host.querySelector('canvas'), fallback = host.querySelector('.cvx-globe-fallback');
    var context, terrain = document.createElement('canvas'), terrainContext;
    try {
      context = canvas && canvas.getContext('2d', { alpha: true });
      terrainContext = terrain.getContext('2d', { alpha: true });
    } catch (error) { host.dataset.globeState = 'unavailable'; return; }
    if (!context || !terrainContext) { host.dataset.globeState = 'unavailable'; return; }

    var rad = Math.PI / 180, circle = Math.PI * 2;
    var motion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
    var width = 0, height = 0, ratio = 1, radius = 0, cx = 0, cy = 0, padding = 0;
    var yaw = 45, pitch = 25, camera = null, velocityYaw = 0, velocityPitch = 0;
    var point = null, location = {}, frame = 0, lastTick = 0;
    var visible = !('IntersectionObserver' in window), active = true, ready = false;
    var landVectors = [], gridVectors = [], countries = [], routes = [], routeLines = [];
    var colors = {}, baseDirty = true, baseYaw = null, basePitch = null, detailStep = 1, paintAverage = 0;
    var projectCosYaw = 1, projectSinYaw = 0, projectCosPitch = 1, projectSinPitch = 0;
    var projected = { x: 0, y: 0, z: 0 }, packetVector = [0, 0, 0], markerTargets = [];
    var pointer = null, hoverCountry = '', rendered = 0, lastReport = 0;
    var wrap = function (angle) { return ((angle + 180) % 360 + 360) % 360 - 180; };
    var clamp = function (value, min, max) { return Math.min(max, Math.max(min, value)); };
    var vector = function (latitude, longitude) {
      var a = latitude * rad, b = longitude * rad, cosine = Math.cos(a);
      return [cosine * Math.sin(b), Math.sin(a), cosine * Math.cos(b)];
    };
    var project = function (v) {
      var z = v[0] * projectSinYaw + v[2] * projectCosYaw;
      projected.x = cx + (v[0] * projectCosYaw - v[2] * projectSinYaw) * radius;
      projected.y = cy - (v[1] * projectCosPitch - z * projectSinPitch) * radius;
      projected.z = v[1] * projectSinPitch + z * projectCosPitch;
      return projected;
    };

    function refreshColors() {
      var style = getComputedStyle(host), light = document.documentElement.getAttribute('data-theme') === 'light';
      var token = function (name, alternate) { return style.getPropertyValue(name).trim() || alternate; };
      // Homepage Atlas tokens, with a flat ocean and no luminous halo.
      colors = {
        earth: token('--cvx-globe-ocean', light ? '#dfebf8' : '#111f32'),
        land: token('--cvx-globe-land', light ? '#29698f' : '#72c5e6'),
        light: token('--cvx-globe-coast', light ? '#246680' : '#e2f5ff'),
        cyan: token('--cvx-globe-marker', light ? '#00869b' : '#5de5ed'),
        blue: token('--cvx-globe-grid', light ? '#2566cf' : '#58a4ff')
      };
      baseDirty = true;
    }
    function paths(ctx, lines, stroke, opacity, thickness) {
      ctx.beginPath();
      for (var i = 0; i < lines.length; i++) {
        var connected = false, line = lines[i];
        for (var j = 0; j < line.length; j++) {
          var p = project(line[j]);
          if (p.z > .025) { if (connected) ctx.lineTo(p.x, p.y); else ctx.moveTo(p.x, p.y); connected = true; }
          else connected = false;
        }
      }
      ctx.strokeStyle = stroke; ctx.globalAlpha = opacity; ctx.lineWidth = thickness; ctx.stroke(); ctx.globalAlpha = 1;
    }
    function catalogue() {
      var codes = [];
      try { codes = JSON.parse(host.dataset.countries || '[]'); } catch (error) { /* Use the rendered catalogue. */ }
      if (!Array.isArray(codes) || !codes.length) codes = Array.from(document.querySelectorAll('.cvx-country[data-country]')).map(function (node) { return node.dataset.country; });
      var seen = {};
      countries = codes.map(function (code) {
        code = String(code || '').trim().toUpperCase();
        var position = /^[A-Z]{2}$/.test(code) && !seen[code] ? geography.resolve({ country: code, approximate: true }) : null;
        seen[code] = true;
        return position ? { country: code, position: position, vector: vector(position.latitude, position.longitude) } : null;
      }).filter(Boolean).sort(function (a, b) { return a.country < b.country ? -1 : a.country > b.country ? 1 : 0; });
    }
    function orbitalVisibility(p) {
      var distance = Math.hypot(p.x - cx, p.y - cy) / radius;
      // Elevated far-side routes remain visible beyond the globe's silhouette,
      // while the solid sphere occludes portions passing behind its face.
      var limb = p.z >= 0 ? 1 : clamp((distance - 1) / .065, 0, 1);
      var depth = p.z >= 0 ? .75 + clamp(p.z, 0, 1.5) / 6 : .46;
      var edge = Math.min(p.x + padding, p.y + padding, width + padding - p.x, height + padding - p.y);
      return limb * depth * clamp(edge / Math.max(18, radius * .09), 0, 1);
    }
    function orbitalPaths() {
      // Cache six opacity buckets with the terrain. The animated packets below
      // reuse these exact elevated samples, including occlusion and edge fade.
      var buckets = [[], [], [], [], [], []];
      routeLines.forEach(function (line) {
        var previous = null;
        line.forEach(function (v) {
          var p = project(v), alpha = orbitalVisibility(p);
          if (previous && previous.alpha > .01 && alpha > .01) {
            var bucket = Math.min(5, Math.floor((previous.alpha + alpha) * 3));
            buckets[bucket].push(previous.x, previous.y, p.x, p.y);
          }
          previous = { x: p.x, y: p.y, alpha: alpha };
        });
      });
      buckets.forEach(function (segments, bucket) {
        terrainContext.beginPath();
        for (var i = 0; i < segments.length; i += 4) { terrainContext.moveTo(segments[i], segments[i + 1]); terrainContext.lineTo(segments[i + 2], segments[i + 3]); }
        terrainContext.strokeStyle = colors.cyan; terrainContext.globalAlpha = .27 * (bucket + .5) / 6; terrainContext.lineWidth = .8; terrainContext.stroke();
      });
      terrainContext.globalAlpha = 1;
    }
    function buildConnections() {
      var pairs = {}, links = [];
      function add(a, b) {
        if (!a || !b || a.country === b.country) return;
        var key = [a.country, b.country].sort().join(':');
        if (!pairs[key]) { pairs[key] = true; links.push([a, b]); }
      }
      // A fixed, restrained web: three nearby catalogue countries per node.
      // Country-centre endpoints never follow the selected country or city;
      // selection moves only the camera and location marker. Decorative routes
      // are not live connectivity or exact datacentre locations.
      countries.forEach(function (origin) {
        countries.filter(function (node) { return node !== origin; }).sort(function (a, b) {
          function distance(node) { return 1 - origin.vector.reduce(function (sum, value, axis) { return sum + value * node.vector[axis]; }, 0); }
          return distance(a) - distance(b);
        }).slice(0, 3).forEach(function (end) { add(origin, end); });
      });
      routes = links.slice(0, 96).map(function (link, index) {
        var a = link[0].vector, b = link[1].vector;
        var angle = Math.acos(clamp(a[0] * b[0] + a[1] * b[1] + a[2] * b[2], -1, 1));
        var sine = Math.sin(angle), vertices = [];
        // Balanced arches, with higher orbits on longer routes. Their 9–26.5%
        // lift is halfway between the surface routes and the taller orbit style.
        // Endpoints remain attached to their actual surface locations.
        var elevation = .09 + Math.sin(angle / 2) * .125 + (index % 5) * .0125;
        for (var i = 0; i <= 40; i++) {
          var t = i / 40, scale = 1 + Math.sin(t * Math.PI) * elevation;
          var start = sine > .001 ? Math.sin((1 - t) * angle) / sine : 1 - t;
          var end = sine > .001 ? Math.sin(t * angle) / sine : t;
          vertices.push([(a[0] * start + b[0] * end) * scale, (a[1] * start + b[1] * end) * scale, (a[2] * start + b[2] * end) * scale]);
        }
        return { vertices: vertices, phase: index * .173, duration: 5200 + (index % 6) * 430 };
      });
      routeLines = routes.map(function (route) { return route.vertices; });
      host.dataset.globeRoutes = String(routes.length); baseDirty = true;
    }
    function drawTerrain() {
      terrainContext.clearRect(-padding, -padding, width + padding * 2, height + padding * 2);
      terrainContext.beginPath(); terrainContext.arc(cx, cy, radius, 0, circle);
      terrainContext.fillStyle = colors.earth; terrainContext.globalAlpha = .72; terrainContext.fill();
      terrainContext.strokeStyle = colors.blue; terrainContext.globalAlpha = .18; terrainContext.lineWidth = .65; terrainContext.stroke(); terrainContext.globalAlpha = 1;
      paths(terrainContext, gridVectors, colors.land, .045, .5);
      // Project once, then paint depth buckets. Idle packets reuse the entire
      // terrain canvas instead of re-projecting thousands of land points.
      var buckets = [[], [], [], [], [], []], step = width < 480 ? Math.max(2, detailStep) : detailStep;
      for (var i = 0; i < landVectors.length; i += step) {
        var p = project(landVectors[i]); if (p.z < .025) continue;
        buckets[Math.min(5, Math.floor(p.z * 6))].push(p.x, p.y, Math.max(.68, radius / 205) * (.7 + p.z * .3) * Math.sqrt(step));
      }
      for (var bucket = 0; bucket < buckets.length; bucket++) {
        var dots = buckets[bucket]; terrainContext.beginPath();
        for (var j = 0; j < dots.length; j += 3) { terrainContext.moveTo(dots[j] + dots[j + 2], dots[j + 1]); terrainContext.arc(dots[j], dots[j + 1], dots[j + 2], 0, circle); }
        terrainContext.globalAlpha = .11 + ((bucket + .5) / 6) * .64;
        terrainContext.fillStyle = bucket === 5 ? colors.light : colors.land; terrainContext.fill();
      }
      terrainContext.globalAlpha = 1; orbitalPaths();
      terrainContext.save(); terrainContext.translate(cx, cy); terrainContext.rotate(-.36);
      terrainContext.beginPath(); terrainContext.ellipse(0, 0, radius * 1.075, radius * .3, 0, 0, circle);
      terrainContext.strokeStyle = colors.blue; terrainContext.globalAlpha = .12; terrainContext.lineWidth = .5; terrainContext.stroke(); terrainContext.restore();
      baseYaw = yaw; basePitch = pitch; baseDirty = false;
    }
    function draw(timestamp) {
      if (!width || !height || !landVectors.length) return;
      var started = performance.now();
      projectCosYaw = Math.cos(yaw * rad); projectSinYaw = Math.sin(yaw * rad);
      projectCosPitch = Math.cos(pitch * rad); projectSinPitch = Math.sin(pitch * rad);
      var terrainChanged = baseDirty || yaw !== baseYaw || pitch !== basePitch;
      if (terrainChanged) drawTerrain();
      context.clearRect(-padding, -padding, width + padding * 2, height + padding * 2);
      context.drawImage(terrain, -padding, -padding, width + padding * 2, height + padding * 2);
      for (var i = 0; i < routes.length; i++) {
        var route = routes[i];
        for (var packet = 0; packet < 2; packet++) {
          var travel = motion.matches ? .3 : (timestamp / route.duration + route.phase) % 1;
          var progress = packet ? 1 - travel : travel;
          var offset = progress * 40, index = Math.min(39, Math.floor(offset)), mix = offset - index;
          var a = route.vertices[index], b = route.vertices[index + 1];
          for (var axis = 0; axis < 3; axis++) packetVector[axis] = a[axis] + (b[axis] - a[axis]) * mix;
          var p = project(packetVector), alpha = orbitalVisibility(p); if (alpha <= .01) continue;
          context.beginPath(); context.arc(p.x, p.y, Math.max(1.15, radius / 200), 0, circle);
          context.fillStyle = colors.cyan; context.globalAlpha = (packet ? .58 : .94) * alpha; context.fill();
        }
      }
      context.globalAlpha = 1; markerTargets = [];
      countries.forEach(function (node) {
        var chosen = point && node.country === point.country;
        var position = chosen ? vector(point.latitude, point.longitude) : node.vector, pin = project(position);
        if (pin.z < .12) return;
        markerTargets.push({ country: node.country, x: pin.x, y: pin.y, z: pin.z });
        var hover = hoverCountry === node.country;
        context.beginPath(); context.arc(pin.x, pin.y, chosen ? 3.6 : hover ? 3 : 2.1, 0, circle);
        context.fillStyle = chosen || hover ? colors.cyan : colors.light; context.fill();
        if (chosen || hover) {
          context.strokeStyle = colors.cyan; context.lineWidth = .75; context.globalAlpha = .65;
          context.beginPath(); context.arc(pin.x, pin.y, 9, 0, circle); context.stroke();
          if (chosen) { context.globalAlpha = .16; context.beginPath(); context.arc(pin.x, pin.y, 15, 0, circle); context.stroke(); }
          context.globalAlpha = 1;
        }
        if (hover) {
          context.font = '11px ui-monospace, monospace'; context.textAlign = 'center'; context.textBaseline = 'bottom';
          context.fillStyle = colors.light; context.fillText(node.country, pin.x, pin.y - 13);
        }
      });
      rendered++;
      if (terrainChanged) {
        var paintCost = performance.now() - started; paintAverage = paintAverage ? paintAverage * .95 + paintCost * .05 : paintCost;
        if (rendered > 30 && paintAverage > 5 && detailStep < 3) { detailStep++; baseDirty = true; paintAverage = 0; }
      }
      if (timestamp - lastReport > 200 || motion.matches || !ready) {
        host.dataset.rotation = wrap(yaw).toFixed(3); host.dataset.tilt = pitch.toFixed(3);
        host.dataset.globeFrames = String(rendered); host.dataset.globeDetailStep = String(detailStep); lastReport = timestamp;
      }
      if (!ready) {
        ready = true; host.classList.add('is-ready'); host.dataset.globeState = 'ready';
        if (fallback) fallback.hidden = true;
        document.dispatchEvent(new CustomEvent('cvx:globe-ready'));
      }
    }
    function focus(longitude, latitude, duration) {
      velocityYaw = 0; velocityPitch = 0;
      if (motion.matches || !ready) { yaw = longitude; pitch = latitude; camera = null; }
      else camera = { yaw: yaw, pitch: pitch, delta: wrap(longitude - yaw), latitude: latitude,
        started: performance.now(), duration: duration || clamp(650 + Math.abs(wrap(longitude - yaw)) * 3, 650, 1150) };
      host.dataset.globeSettled = String(!camera); wake();
    }
    function tick(timestamp) {
      frame = 0;
      if (!active || !visible || document.hidden || !landVectors.length) { lastTick = 0; return; }
      var elapsed = lastTick ? Math.min(64, timestamp - lastTick) : 16; lastTick = timestamp;
      if (motion.matches) { if (camera) { yaw = camera.yaw + camera.delta; pitch = camera.latitude; camera = null; } velocityYaw = 0; velocityPitch = 0; }
      if (camera && !pointer) {
        var progress = clamp((timestamp - camera.started) / camera.duration, 0, 1), ease = progress * progress * (3 - 2 * progress);
        yaw = camera.yaw + camera.delta * ease; pitch = camera.pitch + (camera.latitude - camera.pitch) * ease;
        if (progress === 1) camera = null;
      } else if (!pointer && (velocityYaw || velocityPitch)) {
        yaw = wrap(yaw + velocityYaw * elapsed); pitch = clamp(pitch + velocityPitch * elapsed, -72, 72);
        var decay = Math.exp(-elapsed * .007); velocityYaw *= decay; velocityPitch *= decay;
        if (Math.abs(velocityYaw) < .0005) velocityYaw = 0;
        if (Math.abs(velocityPitch) < .0005) velocityPitch = 0;
      }
      draw(timestamp); host.dataset.globeSettled = String(!camera && !velocityYaw && !velocityPitch && !(pointer && pointer.moved));
      // Packets share one display-cadence loop. Offscreen/hidden and reduced-
      // motion globes consume no continuous animation frames.
      if (!motion.matches) frame = requestAnimationFrame(tick); else lastTick = 0;
    }
    function wake() {
      if (!active || !visible || document.hidden || !landVectors.length) { if (frame) cancelAnimationFrame(frame); frame = 0; lastTick = 0; return; }
      if (!frame) frame = requestAnimationFrame(tick);
    }
    function resize() {
      var rect = host.getBoundingClientRect(); if (rect.width <= 0 || rect.height <= 0) return;
      var nextRatio = Math.min(window.devicePixelRatio || 1, 1.5);
      if (width !== rect.width || height !== rect.height || ratio !== nextRatio) {
        width = rect.width; height = rect.height; ratio = nextRatio;
        radius = Math.min(width * .452, height * .455);
        cx = width / 2; cy = Math.min(height / 2, radius + 24);
        padding = Math.ceil(radius * .5 + 16);
        var drawingWidth = width + padding * 2, drawingHeight = height + padding * 2;
        canvas.width = terrain.width = Math.max(1, Math.round(drawingWidth * ratio)); canvas.height = terrain.height = Math.max(1, Math.round(drawingHeight * ratio));
        // Expand the drawing surface without enlarging the pointer target or
        // moving geographic hit boxes. Routes may travel beneath adjacent text.
        canvas.style.left = -padding + 'px'; canvas.style.top = -padding + 'px';
        canvas.style.right = 'auto'; canvas.style.bottom = 'auto';
        canvas.style.width = drawingWidth + 'px'; canvas.style.height = drawingHeight + 'px';
        context.setTransform(ratio, 0, 0, ratio, padding * ratio, padding * ratio);
        terrainContext.setTransform(ratio, 0, 0, ratio, padding * ratio, padding * ratio);
        refreshColors();
      }
      wake();
    }
    function describe() {
      var name = document.getElementById('cvx-globe-name'), detail = document.getElementById('cvx-globe-detail'), coordinates = document.getElementById('cvx-globe-coordinates');
      var labels = { fa: ['نمای تقریبی کشور', 'موقعیت شهر', 'شبکهٔ جهانی'], en: ['Country overview', 'City location', 'Global network'], tr: ['Ülkeye genel bakış', 'Şehir konumu', 'Küresel ağ'] }[(document.documentElement.lang || 'fa').slice(0, 2)] || ['Country overview', 'City location', 'Global network'];
      if (name) name.textContent = location.label || location.city || location.country || '';
      if (detail) detail.textContent = location.detail || (!point ? labels[2] : point.approximate ? (host.dataset.approximateLabel || labels[0]) : (host.dataset.exactLabel || labels[1]));
      if (coordinates) coordinates.textContent = !point ? '—' : (point.approximate ? '≈ ' : '') + Math.abs(point.latitude).toFixed(2) + '° ' + (point.latitude < 0 ? 'S' : 'N') + ' · ' + Math.abs(point.longitude).toFixed(2) + '° ' + (point.longitude < 0 ? 'W' : 'E');
    }
    selectLocation = function (detail) {
      location = detail || {}; point = geography.resolve(location);
      host.dataset.focus = point ? point.country : ''; host.dataset.approximate = String(!point || point.approximate);
      host.dataset.locationCode = String(location.code || '');
      if (point) { host.dataset.latitude = point.latitude; host.dataset.longitude = point.longitude; }
      else { delete host.dataset.latitude; delete host.dataset.longitude; }
      describe(); if (point) focus(point.longitude, point.latitude); else wake();
    };
    function hitTest(event) {
      var rect = host.getBoundingClientRect(), x = event.clientX - rect.left, y = event.clientY - rect.top;
      var limit = event.pointerType === 'touch' ? 24 : 17, nearest = null, distance = limit * limit;
      markerTargets.forEach(function (target) { var squared = (target.x - x) * (target.x - x) + (target.y - y) * (target.y - y); if (squared < distance) { distance = squared; nearest = target; } });
      return nearest;
    }
    function hover(country) { hoverCountry = country || ''; host.dataset.hoverCountry = hoverCountry; host.classList.toggle('is-marker-hover', !!hoverCountry); }
    function choose(country) { document.dispatchEvent(new CustomEvent('cvx:country-select', { detail: { country: country } })); }
    function endPointer(event, cancelled) {
      if (!pointer || (event.pointerId !== undefined && event.pointerId !== pointer.id)) return;
      var previous = pointer; pointer = null; host.classList.remove('is-dragging');
      if (cancelled || motion.matches || !previous.moved) { velocityYaw = 0; velocityPitch = 0; }
      if (!cancelled && !previous.moved) { var target = hitTest(event); if (target) choose(target.country); }
      try { if (host.hasPointerCapture(previous.id)) host.releasePointerCapture(previous.id); } catch (error) { /* Capture may already have ended. */ }
      wake();
    }
    host.addEventListener('pointerdown', function (event) {
      if ((event.button !== undefined && event.button !== 0) || event.isPrimary === false || pointer) return;
      camera = null; velocityYaw = 0; velocityPitch = 0;
      pointer = { id: event.pointerId, x: event.clientX, y: event.clientY, startX: event.clientX, startY: event.clientY, time: performance.now(), moved: false };
      try { host.setPointerCapture(event.pointerId); } catch (error) { /* Normal events work without capture. */ } wake();
    });
    host.addEventListener('pointermove', function (event) {
      if (!pointer) { var target = hitTest(event); hover(target && target.country); wake(); return; }
      if (event.pointerId !== pointer.id) return;
      var totalX = event.clientX - pointer.startX, totalY = event.clientY - pointer.startY;
      if (!pointer.moved) {
        if (Math.hypot(totalX, totalY) < 5) return;
        if (event.pointerType === 'touch' && Math.abs(totalY) > Math.abs(totalX) * 1.25) { endPointer(event, true); return; }
        pointer.moved = true; host.classList.add('is-dragging'); hover('');
      }
      var now = performance.now(), elapsed = Math.max(8, now - pointer.time);
      var deltaYaw = -(event.clientX - pointer.x) * .27, deltaPitch = (event.clientY - pointer.y) * .21;
      yaw = wrap(yaw + deltaYaw); pitch = clamp(pitch + deltaPitch, -72, 72);
      velocityYaw = clamp(deltaYaw / elapsed, -.22, .22); velocityPitch = clamp(deltaPitch / elapsed, -.14, .14);
      pointer.x = event.clientX; pointer.y = event.clientY; pointer.time = now; wake();
    }, { passive: true });
    host.addEventListener('pointerup', function (event) { endPointer(event, false); });
    host.addEventListener('pointercancel', function (event) { endPointer(event, true); });
    host.addEventListener('lostpointercapture', function (event) { endPointer(event, true); });
    host.addEventListener('pointerleave', function () { if (!pointer) { hover(''); wake(); } });
    host.addEventListener('keydown', function (event) {
      var longitude = yaw, latitude = pitch;
      if (event.key === 'ArrowLeft') longitude -= 14;
      else if (event.key === 'ArrowRight') longitude += 14;
      else if (event.key === 'ArrowUp') latitude = clamp(pitch + 10, -72, 72);
      else if (event.key === 'ArrowDown') latitude = clamp(pitch - 10, -72, 72);
      else if (event.key === 'Home') { longitude = point ? point.longitude : 45; latitude = point ? point.latitude : 25; }
      else if (event.key === 'Enter' || event.key === ' ') { var target = markerTargets.slice().sort(function (a, b) { return b.z - a.z; })[0]; if (target) choose(target.country); event.preventDefault(); return; }
      else return;
      event.preventDefault(); focus(longitude, latitude, 300);
    });

    // Unwrap dateline rings: a distant island must not paint a horizontal strip.
    function landSamples(land) {
      var rings = land.coasts.map(function (ring) {
        var previous = ring[0][0], unwrapped = [[previous, ring[0][1]]];
        for (var i = 1; i < ring.length; i++) { previous += wrap(ring[i][0] - previous); unwrapped.push([previous, ring[i][1]]); }
        var xs = unwrapped.map(function (p) { return p[0]; }), ys = unwrapped.map(function (p) { return p[1]; });
        return { points: unwrapped, minX: Math.min.apply(null, xs), maxX: Math.max.apply(null, xs), minY: Math.min.apply(null, ys), maxY: Math.max.apply(null, ys) };
      });
      return land.points.filter(function (sample) {
        return rings.some(function (ring) {
          if (sample[1] < ring.minY || sample[1] > ring.maxY) return false;
          var x = sample[0] + Math.round(((ring.minX + ring.maxX) / 2 - sample[0]) / 360) * 360;
          if (x < ring.minX || x > ring.maxX) return false;
          var inside = false;
          for (var i = 0, j = ring.points.length - 1; i < ring.points.length; j = i++) {
            var a = ring.points[i], b = ring.points[j];
            if ((a[1] > sample[1]) !== (b[1] > sample[1]) && x < (b[0] - a[0]) * (sample[1] - a[1]) / (b[1] - a[1]) + a[0]) inside = !inside;
          }
          return inside;
        });
      });
    }
    function installLand(land) {
      if (!land || !Array.isArray(land.points) || !land.points.length || !Array.isArray(land.coasts) || !land.coasts.length) { host.dataset.globeState = 'unavailable'; return; }
      try {
        landVectors = landSamples(land).map(function (p) { return vector(p[1], p[0]); });
        for (var lat = -60; lat <= 60; lat += 30) { var parallel = []; for (var lon = -180; lon <= 180; lon += 6) parallel.push(vector(lat, lon)); gridVectors.push(parallel); }
        for (var meridian = -180; meridian < 180; meridian += 45) { var line = []; for (var latitude = -90; latitude <= 90; latitude += 6) line.push(vector(latitude, meridian)); gridVectors.push(line); }
        resize();
      } catch (error) { landVectors = []; host.dataset.globeState = 'unavailable'; }
    }
    catalogue(); buildConnections();
    selectLocation(pendingLocation || { country: host.dataset.country || '', city: host.dataset.city || '', code: host.dataset.code || '', label: host.dataset.label || '', approximate: host.dataset.approximate === 'true' });
    var resizeObserver = 'ResizeObserver' in window ? new ResizeObserver(resize) : null;
    if (resizeObserver) resizeObserver.observe(host); else window.addEventListener('resize', resize, { passive: true });
    var intersectionObserver = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) { visible = entries[0].isIntersecting; wake(); }, { rootMargin: '32px' }) : null;
    if (intersectionObserver) intersectionObserver.observe(host);
    var themeObserver = new MutationObserver(function () { refreshColors(); wake(); });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
    document.addEventListener('visibilitychange', wake);
    if (motion.addEventListener) motion.addEventListener('change', wake);
    window.addEventListener('pagehide', function () { active = false; pointer = null; host.classList.remove('is-dragging'); wake(); });
    window.addEventListener('pageshow', function (event) { if (event.persisted) { active = true; resize(); } });

    host.dataset.globeState = 'loading';
    if (window.SNAtlasLand) { installLand(window.SNAtlasLand); return; }
    try {
      var source = new URL(host.dataset.landSrc || '', window.location.href);
      if (!host.dataset.landSrc || source.origin !== window.location.origin) { host.dataset.globeState = 'unavailable'; return; }
      var script = document.createElement('script'); script.src = source.href; script.async = true;
      script.onload = function () { installLand(window.SNAtlasLand); }; script.onerror = function () { host.dataset.globeState = 'unavailable'; }; document.head.appendChild(script);
    } catch (error) { host.dataset.globeState = 'unavailable'; }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
})();
