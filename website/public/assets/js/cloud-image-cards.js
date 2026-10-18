/* Progressive enhancement: existing image radios remain the sole order payload. */
(function (root) {
  'use strict';
  var names = {ubuntu:'Ubuntu',debian:'Debian',rocky:'Rocky Linux',almalinux:'AlmaLinux',
    centos:'CentOS',fedora:'Fedora',alpine:'Alpine Linux',arch:'Arch Linux',opensuse:'openSUSE',
    windows:'Windows Server',freebsd:'FreeBSD',docker:'Docker',wordpress:'WordPress',
    nextcloud:'Nextcloud',nginx:'NGINX',gitlab:'GitLab',n8n:'n8n',cpanel:'cPanel',plesk:'Plesk',
    mysql:'MySQL',postgresql:'PostgreSQL',redis:'Redis',mongodb:'MongoDB',
    mikrotik:'MikroTik',pfsense:'pfSense',proxmox:'Proxmox',finnix:'Finnix'};
  var logos = ['ubuntu','debian','rocky','almalinux','centos','fedora','alpine','arch',
    'opensuse','windows','freebsd','docker','wordpress','nextcloud','nginx','gitlab','n8n',
    'cpanel','plesk','mysql','postgresql','redis','mongodb'];
  var order = ['ubuntu','debian','almalinux','rocky','centos','fedora','opensuse','alpine','arch','windows'];

  function describe(family, label, key, logo) {
    family = String(family || '').toLowerCase();
    label = String(label || '').trim();
    var id = family;
    if (!names[id]) {
      var haystack = (label + ' ' + key).toLowerCase();
      id = Object.keys(names).find(function (name) { return haystack.indexOf(name) === 0; }) || '';
    }
    var name = names[id] || label.replace(/\s+v?\d[\s\S]*$/, '').trim() || 'Linux';
    var version = label;
    // Only trim an actual name prefix; never parse or rewrite the submitted image key.
    [name, family].some(function (prefix) {
      if (prefix && version.toLowerCase().indexOf(prefix.toLowerCase() + ' ') === 0) {
        version = version.slice(prefix.length).trim(); return true;
      }
      return false;
    });
    return {id:id || name.toLowerCase(),name:name,version:version,
      label:/^\d/.test(label) ? name + ' ' + label : label,
      logo:logo && logo.indexOf('/assets/apps/') === 0 ? logo :
        (logos.indexOf(id) !== -1 ? '/assets/os/' + id + '.svg' : logo)};
  }

  function mount(form, words) {
    var doc = form.ownerDocument, cards = [], labels = {}, imageLogos = {};
    var activeMenu = null, menuSequence = 0;
    function el(tag, className, text) {
      var node = doc.createElement(tag);
      if (className) node.className = className;
      if (text !== undefined) node.textContent = text;
      return node;
    }
    function icon(id, className) {
      var svg = doc.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('class', className); svg.setAttribute('aria-hidden', 'true');
      var use = doc.createElementNS('http://www.w3.org/2000/svg', 'use');
      use.setAttribute('href', '#i-' + id); svg.appendChild(use); return svg;
    }
    // Animated version menu, adapted to the existing non-React form. The native
    // select remains the source of truth; only the original radio is submitted.
    function closeVersionMenu(restoreFocus) {
      if (!activeMenu) return;
      var old=activeMenu; activeMenu=null;
      old.picker.button.setAttribute('aria-expanded','false');
      old.picker.button.classList.remove('is-open');
      old.popup.remove();
      if (restoreFocus) old.picker.button.focus();
    }
    function versionPicker(select) {
      var button=el('button','cvb-version-trigger'), text=el('span','cvb-version-value');
      var arrow=el('span','cvb-version-arrow'); arrow.setAttribute('aria-hidden','true');
      arrow.appendChild(el('i')); arrow.appendChild(el('i'));
      button.type='button'; button.appendChild(text); button.appendChild(arrow);
      var id='cvb-version-menu-'+(++menuSequence);
      button.setAttribute('aria-haspopup','listbox'); button.setAttribute('aria-expanded','false');
      button.setAttribute('aria-controls',id);
      select.hidden=true; select.insertAdjacentElement('afterend',button);
      var picker={button:button};
      function options() { return Array.from(select.options).filter(function(o){return !o.disabled&&!o.hidden;}); }
      function open(first) {
        if (button.disabled) return;
        if (activeMenu && activeMenu.picker===picker) { closeVersionMenu(true); return; }
        closeVersionMenu(false);
        var popup=el('div','cvb-version-menu'); popup.id=id; popup.dir='ltr';
        popup.setAttribute('role','listbox'); popup.setAttribute('aria-label',select.getAttribute('aria-label'));
        var items=[];
        options().forEach(function(option){
          var item=el('button','cvb-version-option'); item.type='button'; item.tabIndex=-1;
          item.setAttribute('role','option'); item.setAttribute('aria-selected',option.value===select.value?'true':'false');
          item.appendChild(icon('check','cvb-version-option-check'));
          item.appendChild(el('span','',option.text));
          item.addEventListener('click',function(){
            closeVersionMenu(false);
            // An old popup must not bypass a plan change that disabled its option.
            if (option.disabled||option.hidden||select.disabled) return;
            select.value=option.value;
            select.dispatchEvent(new doc.defaultView.Event('change',{bubbles:true}));
            button.focus();
          });
          popup.appendChild(item); items.push(item);
        });
        if (!items.length) return;
        // A body-level popup escapes card overflow and inline-size containment.
        doc.body.appendChild(popup);
        var rect=button.getBoundingClientRect(), win=doc.defaultView;
        var width=Math.min(rect.width,win.innerWidth-16), below=win.innerHeight-rect.bottom-12, above=rect.top-12;
        popup.style.width=width+'px';
        popup.style.left=Math.max(8,Math.min(rect.left,win.innerWidth-width-8))+'px';
        popup.style.maxHeight=Math.max(48,Math.min(320,Math.max(below,above)))+'px';
        if (below>=Math.min(popup.scrollHeight,320)||below>=above) popup.style.top=(rect.bottom+5)+'px';
        else { popup.style.bottom=(win.innerHeight-rect.top+5)+'px'; popup.classList.add('opens-up'); }
        activeMenu={picker:picker,popup:popup}; button.classList.add('is-open');
        button.setAttribute('aria-expanded','true');
        var current=items.findIndex(function(item){return item.getAttribute('aria-selected')==='true';});
        items[first==='last'?items.length-1:(first==='first'?0:Math.max(0,current))].focus();
        popup.addEventListener('keydown',function(event){
          var index=items.indexOf(doc.activeElement), next=index;
          if (event.key==='ArrowDown') next=(index+1)%items.length;
          else if (event.key==='ArrowUp') next=(index-1+items.length)%items.length;
          else if (event.key==='Home') next=0;
          else if (event.key==='End') next=items.length-1;
          else if (event.key==='Escape') {event.preventDefault();closeVersionMenu(true);return;}
          else if (event.key==='Tab') {closeVersionMenu(true);return;}
          else return;
          event.preventDefault(); items[next].focus();
        });
      }
      button.addEventListener('click',function(){open();});
      button.addEventListener('keydown',function(event){
        if (event.key==='ArrowDown'||event.key==='ArrowUp') {event.preventDefault();open(event.key==='ArrowUp'?'last':'first');}
        if (event.key==='Escape') closeVersionMenu(true);
      });
      picker.refresh=function(){
        if (activeMenu&&activeMenu.picker===picker) closeVersionMenu(false);
        var selected=select.options[select.selectedIndex];
        text.textContent=selected?selected.text:words.version;
        button.disabled=select.disabled;
        button.setAttribute('aria-label',select.getAttribute('aria-label')+' — '+text.textContent);
      };
      return picker;
    }
    doc.addEventListener('pointerdown',function(event){
      if (activeMenu&&!activeMenu.popup.contains(event.target)&&!activeMenu.picker.button.contains(event.target)) closeVersionMenu(false);
    });
    doc.defaultView.addEventListener('resize',function(){closeVersionMenu(false);});
    doc.addEventListener('scroll',function(event){
      if (activeMenu&&!activeMenu.popup.contains(event.target)) closeVersionMenu(false);
    },true);
    function choose(card) {
      var item = card.items.find(function (item) { return !item.source.hidden && item.input.value === card.select.value; });
      if (!item) return;
      item.input.checked = true;
      item.input.dispatchEvent(new doc.defaultView.Event('change', {bubbles:true}));
      refresh();
    }
    form.querySelectorAll('[data-pane]').forEach(function (pane) {
      // GPU has its own capacity/application contract and already uses cards.
      if (pane.querySelector('.cvb-gpu-app')) return;
      var grouped = new Map(), kind = pane.getAttribute('data-pane');
      pane.querySelectorAll('.cvb-fam').forEach(function (family) {
        family.classList.add('cvb-card-source');
        family.querySelectorAll('.cvb-img').forEach(function (source) {
          var input = source.querySelector('input[name="image"]'), image = source.querySelector('img');
          if (!input) return;
          var info = describe(family.getAttribute('data-fam'), source.querySelector('b').textContent,
            input.value, image ? image.getAttribute('src') : '/assets/os/linux.svg');
          if (!grouped.has(info.id)) grouped.set(info.id, {info:info,items:[]});
          grouped.get(info.id).items.push({source:source,input:input,info:info});
          labels[input.value] = info.label; imageLogos[input.value] = info.logo;
        });
      });
      if (!grouped.size) return;
      var grid = el('div','cvb-image-grid');
      Array.from(grouped.values()).sort(function (a,b) {
        var ai=order.indexOf(a.info.id), bi=order.indexOf(b.info.id);
        return (ai<0?99:ai)-(bi<0?99:bi) || a.info.name.localeCompare(b.info.name);
      }).forEach(function (group) {
        var box=el('div','cvb-image-card'), button=el('button','cvb-image-pick');
        button.type='button'; button.setAttribute('aria-pressed','false');
        var top=el('span','cvb-image-top'), brand=el('span','cvb-image-brand');
        var image=el('img'); image.loading='lazy'; image.src=group.info.logo; image.alt=''; image.width=30; image.height=30;
        brand.appendChild(image); top.appendChild(brand); top.appendChild(icon('check','cvb-image-check'));
        button.appendChild(top);
        var title=el('b','cvb-image-name',group.info.name); title.dir='auto'; button.appendChild(title);
        button.appendChild(el('span','cvb-image-kind',words[kind]));
        box.appendChild(button);
        var bottom=el('div','cvb-image-bottom'), select=el('select','cvb-image-version');
        select.setAttribute('aria-label',words.version+' — '+group.info.name); select.dir='ltr';
        group.items.sort(function(a,b){return b.info.version.localeCompare(a.info.version,undefined,{numeric:true});});
        group.items.forEach(function(item){
          var option=el('option','',item.info.version); option.value=item.input.value; select.appendChild(option);
        });
        var single=el('span','cvb-image-single'); single.dir='auto';
        bottom.appendChild(select); bottom.appendChild(single); bottom.appendChild(icon('chev','cvb-image-chevron'));
        box.appendChild(bottom); grid.appendChild(box);
        var card={box:box,button:button,select:select,single:single,bottom:bottom,items:group.items,picker:versionPicker(select)};
        button.addEventListener('click',function(){choose(card);});
        select.addEventListener('change',function(){choose(card);});
        single.addEventListener('click',function(){choose(card);});
        cards.push(card);
      });
      pane.insertBefore(grid,pane.firstChild); pane.classList.add('cvb-images-enhanced');
      var next=el('button','pnl-btn cvb-image-continue',words.continue);
      next.type='button'; next.setAttribute('data-image-continue',''); pane.appendChild(next);
    });
    function refresh() {
      cards.forEach(function(card){
        var available=card.items.filter(function(item){return !item.source.hidden;});
        var selected=available.find(function(item){return item.input.checked;});
        card.box.hidden=available.length===0;
        card.box.classList.toggle('is-selected',!!selected);
        card.button.setAttribute('aria-pressed',selected?'true':'false');
        card.button.setAttribute('aria-label',card.button.querySelector('b').textContent+(selected?' — '+words.selected:''));
        Array.from(card.select.options).forEach(function(option){
          var can=available.some(function(item){return item.input.value===option.value;});
          option.hidden=!can; option.disabled=!can;
        });
        var remembered=available.find(function(item){return item.input.value===card.select.value;});
        var current=selected || remembered || available[0];
        if (current) {
          card.select.value=current.input.value;
          card.single.textContent=current.info.version.toLowerCase()===current.info.name.toLowerCase() ? words.ready : current.info.version;
        }
        card.select.disabled=available.length===0;
        card.bottom.classList.toggle('is-single',available.length<2);
        card.picker.refresh();
      });
      form.querySelectorAll('[data-image-continue]').forEach(function(button){
        button.hidden=!button.parentNode.querySelector('.cvb-image-card:not([hidden])');
      });
    }
    refresh();
    return {refresh:refresh,labels:labels,logos:imageLogos};
  }
  root.ServerNetImageCards={mount:mount,describe:describe};
})(typeof window === 'object' ? window : globalThis);
