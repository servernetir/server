/* ServerNet Atlas: geographic illustrations, not live network telemetry. */
(() => {
  'use strict';
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const narrow = window.matchMedia('(max-width: 820px)');
  const radians = Math.PI / 180;
  const globeGroup = document.querySelector('[data-sn-region-group]');
  let nodes = {};
  try { nodes = JSON.parse(globeGroup?.dataset.snNodes || '{}'); } catch { /* Keep the static illustration. */ }
  const regions = Object.fromEntries(Object.entries(nodes).map(([iso, node]) => [iso, node.position]));
  const normalize = (angle) => ((angle + 540) % 360) - 180;
  const edges = [];
  const pairs = new Set();
  Object.entries(regions).forEach(([iso, origin]) => {
    const nearby = Object.entries(regions).filter(([key]) => key !== iso).sort((a,b) => {
      const distance = (point) => Math.hypot(point[0]-origin[0], normalize(point[1]-origin[1])*Math.cos(origin[0]*radians));
      return distance(a[1])-distance(b[1]);
    }).slice(0,1);
    nearby.forEach(([key, end]) => { const pair=[iso,key].sort().join(':'); if(!pairs.has(pair)){pairs.add(pair);edges.push([origin,end]);} });
  });

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
  // Convert geographic geometry once; each frame only rotates Cartesian vectors.
  const vector = (lat, lon, elevation = 1) => {
    const p = lat * radians, q = lon * radians, c = Math.cos(p) * elevation;
    return new Float32Array([c * Math.sin(q), Math.sin(p) * elevation, c * Math.cos(q)]);
  };
  let bootPromise;
  function bootGlobes() {
    if (globeBooted) return bootPromise || Promise.resolve();
    if (!document.querySelector('[data-sn-globe]')) return Promise.resolve();
    globeBooted = true;
    bootPromise = loadLand().then(land => {
      if (!land?.points?.length || !land?.coasts?.length) { globeBooted = false; return; }
      const landVectors = land.points.map(([lon,lat]) => vector(lat,lon));
      const locationVectors = Object.fromEntries(Object.entries(regions).map(([iso,p]) => [iso,vector(...p)]));
      const locationEntries = Object.entries(locationVectors);
      const gridLines = [];
      for(let lat=-60;lat<=60;lat+=30){const line=[];for(let lon=-180;lon<=180;lon+=6)line.push(vector(lat,lon));gridLines.push(line);}
      for(let lon=-180;lon<180;lon+=45){const line=[];for(let lat=-90;lat<=90;lat+=6)line.push(vector(lat,lon));gridLines.push(line);}

      document.querySelectorAll('[data-sn-globe]').forEach(host => {
        const canvas=host.querySelector('canvas'); let ctx;
        try {ctx=canvas?.getContext('2d',{alpha:true});} catch {return;}
        if(!ctx)return;
        const group=host.closest('[data-sn-region-group]');
        let selected=group?.dataset.snSelected||'IR';
        let yaw=regions[selected]?.[1]??45,targetYaw=yaw,pitch=22;
        let width=0,height=0,radius=0,centerX=0,centerY=0,halo;
        let lastPaint=-Infinity,connectionsDirty=false;
        let frame=0,lastTick=0,visible=!('IntersectionObserver' in window),active=true;
        let dragging=false,pointerX=0,pointerY=0,pausedUntil=0;
        let theme={},connections=[],connectionLines=[],rendered=0,lastReport=0;
        let detailStep=1,paintAverage=0;
        let cy=1,sy=0,cp=1,sp=0;
        const point={x:0,y:0,z:0};
        const packetVector=new Float32Array(3);

        function colors(){
          const style=getComputedStyle(host);
          const value=(key,fallback)=>style.getPropertyValue(key).trim()||fallback;
          theme={earth:value('--sn-earth','#0d1d30'),land:value('--sn-land','#72c5e6'),
            light:value('--sn-white','#e2f5ff'),cyan:value('--sn-cyan','#5de5ed'),blue:value('--sn-blue','#58a4ff')};
        }
        function project(v){
          const z=v[0]*sy+v[2]*cy;
          point.x=centerX+(v[0]*cy-v[2]*sy)*radius;
          point.y=centerY-(v[1]*cp-z*sp)*radius;
          point.z=v[1]*sp+z*cp;
          return point;
        }
        function paths(lines,stroke,opacity,thickness){
          ctx.beginPath();
          for(const line of lines){
            let connected=false;
            for(const v of line){const p=project(v);if(p.z>.025){if(connected)ctx.lineTo(p.x,p.y);else ctx.moveTo(p.x,p.y);connected=true;}else connected=false;}
          }
          ctx.strokeStyle=stroke;ctx.globalAlpha=opacity;ctx.lineWidth=thickness;ctx.stroke();ctx.globalAlpha=1;
        }
        function buildConnections(){
          const hub=regions[selected];
          const spokes=['US','TR','IR','DE','SG','AU','AE','FR','NL'].filter(iso=>iso!==selected&&regions[iso]).map(iso=>[hub,regions[iso]]);
          const used=new Set();
          connections=edges.concat(spokes).filter(([a,b])=>{
            if(!a||!b)return false;const key=[a.join(','),b.join(',')].sort().join(':');
            if(used.has(key))return false;used.add(key);return true;
          }).map(([origin,end],index)=>{
            const delta=normalize(end[1]-origin[1]),vertices=[];
            for(let i=0;i<=32;i++){const t=i/32;vertices.push(vector(origin[0]+(end[0]-origin[0])*t+Math.sin(t*Math.PI)*7,origin[1]+delta*t,1.025));}
            return {vertices,phase:index*.173,duration:6500+(index%4)*650};
          });
          connectionLines=connections.map(c=>c.vertices);
        }
        function draw(timestamp){
          if(!width||!height)return;
          if(connectionsDirty){buildConnections();connectionsDirty=false;}
          const started=performance.now();
          cy=Math.cos(yaw*radians);sy=Math.sin(yaw*radians);cp=Math.cos(pitch*radians);sp=Math.sin(pitch*radians);
          ctx.clearRect(0,0,width,height);ctx.fillStyle=halo;ctx.fillRect(0,0,width,height);
          ctx.beginPath();ctx.arc(centerX,centerY,radius,0,Math.PI*2);
          ctx.fillStyle=theme.earth;ctx.globalAlpha=.72;ctx.fill();ctx.strokeStyle=theme.blue;ctx.lineWidth=.65;ctx.globalAlpha=.2;ctx.stroke();ctx.globalAlpha=1;
          paths(gridLines,theme.land,.045,.5);
          const dots=Array.from({length:6},()=>new Path2D());
          const step=narrow.matches?3:detailStep;
          for(let i=0;i<landVectors.length;i+=step){
            const p=project(landVectors[i]);if(p.z<.025)continue;
            const size=Math.max(.62,radius/205)*(.7+p.z*.3)*Math.sqrt(narrow.matches?1:detailStep),bucket=Math.min(5,Math.floor(p.z*6)),path=dots[bucket];
            path.moveTo(p.x+size,p.y);path.arc(p.x,p.y,size,0,Math.PI*2);
          }
          for(let i=0;i<dots.length;i++){ctx.globalAlpha=.11+((i+.5)/6)*.64;ctx.fillStyle=i===5?theme.light:theme.land;ctx.fill(dots[i]);}
          ctx.globalAlpha=1;
          paths(connectionLines,theme.cyan,.17,.65);
          for(const route of connections){
            const progress=motion.matches?.5:(timestamp/route.duration+route.phase)%1;
            // Interpolate precomputed samples so packets move every display frame.
            const offset=progress*32,index=Math.min(31,Math.floor(offset)),mix=offset-index;
            const a=route.vertices[index],b=route.vertices[index+1];
            for(let j=0;j<3;j++)packetVector[j]=a[j]+(b[j]-a[j])*mix;
            const p=project(packetVector);if(p.z<=.05)continue;
            ctx.beginPath();ctx.arc(p.x,p.y,1.6,0,Math.PI*2);ctx.fillStyle=theme.cyan;ctx.globalAlpha=.82;ctx.fill();
          }
          ctx.globalAlpha=1;
          for(const [iso,v] of locationEntries){
            const p=project(v);if(p.z<.12)continue;const chosen=iso===selected;
            ctx.beginPath();ctx.arc(p.x,p.y,chosen?3.6:1.8,0,Math.PI*2);ctx.fillStyle=chosen?theme.cyan:theme.light;ctx.fill();
            if(chosen){ctx.strokeStyle=theme.cyan;ctx.lineWidth=.75;ctx.globalAlpha=.6;ctx.beginPath();ctx.arc(p.x,p.y,9,0,Math.PI*2);ctx.stroke();ctx.globalAlpha=.13;ctx.beginPath();ctx.arc(p.x,p.y,15,0,Math.PI*2);ctx.stroke();ctx.globalAlpha=1;}
          }
          ctx.save();ctx.translate(centerX,centerY);ctx.rotate(-.36);ctx.beginPath();ctx.ellipse(0,0,radius*1.13,radius*.32,0,0,Math.PI*2);ctx.strokeStyle=theme.blue;ctx.globalAlpha=.14;ctx.lineWidth=.5;ctx.stroke();ctx.restore();
          if(!host.classList.contains('is-ready'))host.classList.add('is-ready');
          rendered++;
          const paintCost=performance.now()-started;
          paintAverage=paintAverage?paintAverage*.95+paintCost*.05:paintCost;
          // Reduce only decorative dot density on slower CPUs; routes and input stay at display cadence.
          if(rendered>30&&paintAverage>4)detailStep=2;
          if(timestamp-lastReport>250||motion.matches){
            host.dataset.rotation=yaw.toFixed(2);host.dataset.snFrames=String(rendered);
            host.dataset.snFrameTime=timestamp.toFixed(1);
            host.dataset.snFrameMs=paintCost.toFixed(2);host.dataset.snDetailStep=String(detailStep);lastReport=timestamp;
          }
        }
        function tick(timestamp){
          frame=0;if(!active||!visible||document.hidden){lastTick=0;lastPaint=-Infinity;return;}
          // Bound decorative painting; keep the main thread available for clicks and scrolling.
          const interval=dragging?1000/60:1000/30;
          if(timestamp-lastPaint<interval-.5){frame=requestAnimationFrame(tick);return;}
          const elapsed=lastTick?Math.min(timestamp-lastTick,80):0;lastTick=timestamp;
          const delta=normalize(targetYaw-yaw);
          if(!dragging&&Math.abs(delta)>.025)yaw+=delta*(1-Math.exp(-elapsed*.009));
          else if(!dragging&&!motion.matches&&!narrow.matches&&timestamp>pausedUntil){yaw=normalize(yaw+elapsed*.0009);targetYaw=yaw;}
          draw(timestamp);lastPaint=timestamp;
          const turning=Math.abs(normalize(targetYaw-yaw))>.025;
          // On mobile, settle on the selected country instead of painting forever.
          if(!motion.matches&&(!narrow.matches||dragging||turning))frame=requestAnimationFrame(tick);
          else lastTick=0;
        }
        function wake(){
          if(!active||!visible||document.hidden){if(frame)cancelAnimationFrame(frame);frame=0;lastTick=0;lastPaint=-Infinity;return;}
          if(motion.matches){if(frame)cancelAnimationFrame(frame);frame=0;draw(performance.now());return;}
          // Coalesce resize, pointer and observer events into the same display frame.
          if(!frame)frame=requestAnimationFrame(tick);
        }
        function resize(){
          const rect=host.getBoundingClientRect();
          if(rect.width<=0||rect.height<=0)return;
          if(width===rect.width&&height===rect.height&&canvas.width){wake();return;}
          width=rect.width;height=rect.height;const dpr=Math.min(devicePixelRatio||1,1.5);
          canvas.width=Math.round(width*dpr);canvas.height=Math.round(height*dpr);ctx.setTransform(dpr,0,0,dpr,0,0);
          centerX=width*.5;centerY=height*.49;radius=Math.min(width*.405,height*.44);
          halo=ctx.createRadialGradient(centerX,centerY,radius*.65,centerX,centerY,radius*1.24);
          halo.addColorStop(0,'rgba(44,146,216,0)');halo.addColorStop(.6,'rgba(44,146,216,.045)');halo.addColorStop(1,'rgba(44,146,216,0)');colors();wake();
        }
        group?.addEventListener('sn-atlas-region',event=>{
          if(!regions[event.detail])return;selected=event.detail;targetYaw=regions[selected][1];
          pausedUntil=performance.now()+2400;if(motion.matches)yaw=targetYaw;connectionsDirty=true;wake();
        });
        const startDrag=event=>{
          if(event.button!==undefined&&event.button!==0)return;
          dragging=true;pointerX=event.clientX;pointerY=event.clientY;host.classList.add('sn-dragging');
          try{host.setPointerCapture(event.pointerId);}catch{}wake();
        };
        host.addEventListener('pointerdown',startDrag);host.addEventListener('sn-atlas-pointer-start',event=>startDrag(event.detail));
        host.addEventListener('pointermove',event=>{
          if(!dragging)return;yaw=normalize(yaw-(event.clientX-pointerX)*.3);targetYaw=yaw;
          pitch=Math.max(-25,Math.min(55,pitch+(event.clientY-pointerY)*.2));pointerX=event.clientX;pointerY=event.clientY;wake();
        },{passive:true});
        const endDrag=()=>{dragging=false;host.classList.remove('sn-dragging');pausedUntil=performance.now()+2400;wake();};
        for(const event of ['pointerup','pointercancel','lostpointercapture'])host.addEventListener(event,endDrag);
        host.addEventListener('sn-atlas-key',event=>{
          const key=event.detail;if(key==='ArrowLeft'||key==='ArrowRight'){yaw+=key==='ArrowLeft'?-8:8;targetYaw=yaw;}
          if(key==='ArrowUp'||key==='ArrowDown')pitch=Math.max(-25,Math.min(55,pitch+(key==='ArrowUp'?5:-5)));
          pausedUntil=performance.now()+3000;wake();
        });
        const resizeObserver='ResizeObserver'in window?new ResizeObserver(resize):null;resizeObserver?.observe(host);
        if(!resizeObserver)window.addEventListener('resize',resize,{passive:true});
        const observer='IntersectionObserver'in window?new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;wake();},{rootMargin:'40px'}):null;observer?.observe(host);
        const themeObserver=new MutationObserver(()=>{colors();wake();});themeObserver.observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
        document.addEventListener('visibilitychange',wake);motion.addEventListener?.('change',wake);narrow.addEventListener?.('change',resize);
        buildConnections();resize();
        window.addEventListener('pagehide',()=>{active=false;wake();observer?.disconnect();resizeObserver?.disconnect();themeObserver.disconnect();});
        window.addEventListener('pageshow',event=>{if(event.persisted){active=true;lastTick=0;resizeObserver?.observe(host);observer?.observe(host);themeObserver.observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});resize();}});
      });
    });
    return bootPromise;
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

  // Text and SVG paint first. Desktop starts when visible; mobile starts on globe interaction.
  const scheduleGlobes = () => {
    // Mobile starts with the SVG; geographic Canvas is needed only when choosing a country.
    if (narrow.matches) return;
    const host = document.querySelector('[data-sn-globe]');
    if (!host) return;
    const start = () => {
      if ('requestIdleCallback' in window) window.requestIdleCallback(bootGlobes, { timeout: 1200 });
      else window.setTimeout(bootGlobes, 80);
    };
    if (!('IntersectionObserver' in window)) { start(); return; }
    const loader = new IntersectionObserver((entries) => {
      if (entries.some(entry => entry.isIntersecting)) { loader.disconnect(); start(); }
    });
    loader.observe(host);
  };
  if (document.readyState === 'complete') scheduleGlobes();
  else window.addEventListener('load', scheduleGlobes, { once: true });
  narrow.addEventListener?.('change', () => { if (!narrow.matches && document.readyState === 'complete') scheduleGlobes(); });

  document.querySelectorAll('[data-sn-globe]').forEach(host=>{
    host.addEventListener('pointerdown',event=>{
      host.dataset.snPointerDown='true';
      try{host.setPointerCapture(event.pointerId);}catch{}
      if(!host.classList.contains('is-ready'))bootGlobes().then(()=>{
        if(host.dataset.snPointerDown==='true')host.dispatchEvent(new CustomEvent('sn-atlas-pointer-start',{detail:{clientX:event.clientX,clientY:event.clientY,pointerId:event.pointerId,button:event.button}}));
      });
    },{passive:true});
    const releasePointer=()=>{host.dataset.snPointerDown='false';};
    for(const event of ['pointerup','pointercancel','lostpointercapture'])host.addEventListener(event,releasePointer);
    host.addEventListener('keydown',event=>{
      if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key))return;
      event.preventDefault();bootGlobes().then(()=>host.dispatchEvent(new CustomEvent('sn-atlas-key',{detail:event.key})));
    });
  });

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
