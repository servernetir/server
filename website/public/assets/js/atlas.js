/* ServerNet Atlas: geographic illustrations, not live network telemetry. */
(() => {
  'use strict';
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const narrow = window.matchMedia('(max-width: 820px)');
  const radians = Math.PI / 180;
  const regions = {
    iran: [32.4, 53.6], germany: [51.2, 10.4], france: [46.2, 2.2],
    netherlands: [52.1, 5.3], usa: [39.1, -98],
  };

  let globeBooted = false;
  let landRequest;
  function loadLand() {
    if (window.SNAtlasLand) return Promise.resolve(window.SNAtlasLand);
    if (!landRequest) landRequest = new Promise((resolve) => {
      const source = document.querySelector('[data-sn-globe]')?.dataset.landSrc;
      if (!source) { resolve(null); return; }
      const script = document.createElement('script');
      script.src = source; script.async = true;
      script.setAttribute('fetchpriority', 'low');
      script.onload = () => resolve(window.SNAtlasLand || null);
      script.onerror = () => { landRequest = null; resolve(null); };
      document.head.appendChild(script);
    });
    return landRequest;
  }
  async function bootGlobes() {
    if (globeBooted) return;
    globeBooted = true;
    const land = await loadLand();
    if (!land) { globeBooted = false; return; }
    document.querySelectorAll('[data-sn-globe]').forEach((host) => {
    const canvas = host.querySelector('canvas');
    let ctx;
    try { ctx = canvas?.getContext('2d', { alpha: true }); } catch { return; }
    if (!ctx || !land?.points?.length || !land?.coasts?.length) return;
    const group = host.closest('[data-sn-region-group]');
    const wire = host.dataset.wireframe === 'true';
    let width = 0, height = 0, radius = 0, centerX = 0, centerY = 0;
    let selected = group?.dataset.snSelected || 'iran';
    let yaw = selected === 'iran' ? 45 : regions[selected][1], targetYaw = yaw, drift = 0;
    let frame = 0, visible = !('IntersectionObserver' in window), lastDraw = 0;
    let theme = {}, active = true;
    const normalize = (angle) => ((angle + 540) % 360) - 180;

    function colors() {
      const style = getComputedStyle(host);
      theme = {
        earth: style.getPropertyValue('--sn-earth').trim() || '#111f32',
        land: style.getPropertyValue('--sn-land').trim() || '#72c5e6',
        light: style.getPropertyValue('--sn-white').trim() || '#e2f5ff',
        cyan: style.getPropertyValue('--sn-cyan').trim() || '#5de5ed',
        blue: style.getPropertyValue('--sn-blue').trim() || '#58a4ff',
      };
    }

    function project(lat, lon, elevation = 1) {
      const latitude = lat * radians;
      const longitude = normalize(lon - yaw - drift) * radians;
      const tilt = 22 * radians;
      const cl = Math.cos(latitude), sl = Math.sin(latitude);
      const x = cl * Math.sin(longitude);
      const y = sl * Math.cos(tilt) - cl * Math.cos(longitude) * Math.sin(tilt);
      const z = sl * Math.sin(tilt) + cl * Math.cos(longitude) * Math.cos(tilt);
      return { x: centerX + x * radius * elevation, y: centerY - y * radius * elevation, z };
    }

    function path(points, stroke, opacity, thickness = 0.6, elevation = 1) {
      ctx.beginPath();
      let connected = false;
      for (const [lon, lat] of points) {
        const p = project(lat, lon, elevation);
        if (p.z > 0.02) {
          if (connected) ctx.lineTo(p.x, p.y);
          else ctx.moveTo(p.x, p.y);
          connected = true;
        } else connected = false;
      }
      ctx.strokeStyle = stroke;
      ctx.globalAlpha = opacity;
      ctx.lineWidth = thickness;
      ctx.stroke();
      ctx.globalAlpha = 1;
    }

    function draw(timestamp = 0) {
      if (!width || !height) return;
      ctx.clearRect(0, 0, width, height);
      const halo = ctx.createRadialGradient(centerX, centerY, radius * .65, centerX, centerY, radius * 1.24);
      halo.addColorStop(0, 'rgba(44,146,216,0)');
      halo.addColorStop(.6, 'rgba(44,146,216,.045)');
      halo.addColorStop(1, 'rgba(44,146,216,0)');
      ctx.fillStyle = halo;
      ctx.fillRect(0, 0, width, height);
      ctx.beginPath(); ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
      ctx.fillStyle = theme.earth; ctx.globalAlpha = wire ? .3 : .78; ctx.fill();
      ctx.strokeStyle = theme.blue; ctx.lineWidth = .7; ctx.globalAlpha = .22; ctx.stroke(); ctx.globalAlpha = 1;

      // Orthographic latitude/longitude lines remain geographically meaningful.
      for (let latitude = -60; latitude <= 60; latitude += 30) {
        const line = [];
        for (let longitude = -180; longitude <= 180; longitude += 4) line.push([longitude, latitude]);
        path(line, theme.land, wire ? .16 : .07);
      }
      for (let longitude = -180; longitude < 180; longitude += 30) {
        const line = [];
        for (let latitude = -90; latitude <= 90; latitude += 4) line.push([longitude, latitude]);
        path(line, theme.land, wire ? .16 : .07);
      }

      if (wire) {
        for (const coastline of land.coasts) path(coastline, theme.land, .57, .75);
      } else {
        const step = narrow.matches ? 2 : 1;
        for (let i = 0; i < land.points.length; i += step) {
          const [lon, lat] = land.points[i];
          const p = project(lat, lon);
          if (p.z < .025) continue;
          ctx.globalAlpha = .13 + p.z * .76;
          ctx.fillStyle = p.z > .82 ? theme.light : theme.land;
          ctx.beginPath(); ctx.arc(p.x, p.y, Math.max(.68, radius / 190) * (.65 + p.z * .35), 0, Math.PI * 2); ctx.fill();
        }
        ctx.globalAlpha = 1;
        for (const coastline of land.coasts) path(coastline, theme.land, .12, .45);
      }

      if (!wire) {
        const origin = regions[selected];
        for (const [key, endpoint] of Object.entries(regions)) {
          if (key === selected) continue;
          const delta = normalize(endpoint[1] - origin[1]);
          const line = [];
          for (let i = 0; i <= 30; i++) {
            const fraction = i / 30;
            line.push([origin[1] + delta * fraction, origin[0] + (endpoint[0] - origin[0]) * fraction + Math.sin(fraction * Math.PI) * 9]);
          }
          path(line, theme.cyan, .3, .85, 1.015);
        }
        for (const [key, location] of Object.entries(regions)) {
          const p = project(...location);
          if (p.z < .12) continue;
          const chosen = key === selected;
          ctx.beginPath(); ctx.arc(p.x, p.y, chosen ? 4 : 2.2, 0, Math.PI * 2);
          ctx.fillStyle = chosen ? theme.cyan : theme.light; ctx.fill();
          if (chosen) {
            ctx.beginPath(); ctx.arc(p.x, p.y, 10, 0, Math.PI * 2);
            ctx.strokeStyle = theme.cyan; ctx.globalAlpha = .65; ctx.lineWidth = .8; ctx.stroke();
            ctx.beginPath(); ctx.arc(p.x, p.y, 16, 0, Math.PI * 2); ctx.globalAlpha = .15; ctx.stroke();
            ctx.globalAlpha = 1;
          }
        }
      }

      // Thin orbital framing is decorative, not an operational route claim.
      ctx.save(); ctx.translate(centerX, centerY); ctx.rotate(-.36);
      ctx.beginPath(); ctx.ellipse(0, 0, radius * 1.13, radius * .32, 0, 0, Math.PI * 2);
      ctx.strokeStyle = theme.blue; ctx.globalAlpha = .18; ctx.lineWidth = .6; ctx.stroke(); ctx.restore();
      host.classList.add('is-ready');
    }

    function tick(timestamp) {
      frame = 0;
      if (!active || !visible || document.hidden) return;
      const moving = Math.abs(normalize(targetYaw - yaw)) > .03;
      const animate = !motion.matches && (!narrow.matches || moving);
      if (moving) yaw += normalize(targetYaw - yaw) * (motion.matches ? 1 : .08);
      drift = animate && !narrow.matches ? Math.sin(timestamp / 16000) * 7 : 0;
      if (timestamp - lastDraw > 48 || motion.matches) { draw(timestamp); lastDraw = timestamp; }
      if (animate) frame = requestAnimationFrame(tick);
    }

    function wake() {
      if (frame) cancelAnimationFrame(frame);
      frame = 0;
      if (!active || !visible || document.hidden) return;
      draw();
      if (visible && !document.hidden && !motion.matches) frame = requestAnimationFrame(tick);
    }

    function resize() {
      const rect = host.getBoundingClientRect();
      if (width === rect.width && height === rect.height && canvas.width) return;
      width = rect.width; height = rect.height;
      const dpr = Math.min(window.devicePixelRatio || 1, 1.5);
      canvas.width = Math.round(width * dpr); canvas.height = Math.round(height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      centerX = width * .5; centerY = height * .49;
      radius = Math.min(width * .405, height * .44);
      colors(); wake();
    }

    if (group) group.addEventListener('sn-atlas-region', (event) => {
        const region = event.detail;
        if (!regions[region]) return;
        selected = region; targetYaw = regions[region][1];
        if (motion.matches) yaw = targetYaw;
        wake();
    });

    const resizeObserver = 'ResizeObserver' in window ? new ResizeObserver(resize) : null;
    resizeObserver?.observe(host);
    if (!resizeObserver) window.addEventListener('resize', resize, { passive: true });
    const observer = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
      visible = entries[0].isIntersecting; wake();
    }, { rootMargin: '60px' }) : null;
    observer?.observe(host);
    const themeObserver = new MutationObserver(() => { colors(); wake(); });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    document.addEventListener('visibilitychange', wake);
    motion.addEventListener?.('change', wake);
    narrow.addEventListener?.('change', resize);
    resize();
    window.addEventListener('pagehide', () => {
      active = false; cancelAnimationFrame(frame); observer?.disconnect(); resizeObserver?.disconnect(); themeObserver.disconnect();
    }, { once: true });
    window.addEventListener('pageshow', (event) => { if (event.persisted) { active = true; resizeObserver?.observe(host); observer?.observe(host); themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] }); resize(); } });
    });
  }

  // Region labels and crawlable destination links work independently of Canvas/data loading.
  document.querySelectorAll('[data-sn-region-group]').forEach((group) => {
    group.querySelectorAll('[data-sn-region]').forEach((button) => {
      button.addEventListener('click', () => {
        const region = button.dataset.snRegion;
        if (!regions[region]) return;
        group.dataset.snSelected = region;
        group.querySelectorAll('[data-sn-region]').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
        const label = group.querySelector('[data-sn-region-label]');
        const description = group.querySelector('[data-sn-region-description]');
        const link = group.querySelector('[data-sn-region-link]');
        if (label) label.textContent = button.dataset.label;
        if (description) description.textContent = button.dataset.description;
        if (link) link.href = button.dataset.url;
        group.dispatchEvent(new CustomEvent('sn-atlas-region', { detail: region }));
        bootGlobes();
      });
    });
  });

  // Let the static Earth and text paint before starting decorative Canvas work.
  // A first interaction starts it immediately, before the country-button click.
  const scheduleGlobes = () => {
    if ('requestIdleCallback' in window) window.requestIdleCallback(bootGlobes, { timeout: 1200 });
    else window.setTimeout(bootGlobes, 80);
  };
  if (document.readyState === 'complete') scheduleGlobes();
  else window.addEventListener('load', scheduleGlobes, { once: true });
  document.addEventListener('pointerdown', bootGlobes, { once: true, passive: true });
  document.addEventListener('keydown', bootGlobes, { once: true });

  // Keep the floating chat shortcut from covering either of the primary forms.
  const forms = [...document.querySelectorAll('.sn-atlas #domain-form,.sn-atlas #ct-form')];
  if ('IntersectionObserver' in window && forms.length) {
    const inView = new Set();
    const formObserver = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) inView.add(entry.target); else inView.delete(entry.target);
      }
      document.body.classList.toggle('sn-chat-clear', inView.size > 0);
    }, { rootMargin: '0px 0px 70px 0px' });
    forms.forEach((form) => formObserver.observe(form));
  }
})();
