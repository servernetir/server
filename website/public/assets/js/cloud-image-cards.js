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
      logo:logos.indexOf(id) !== -1 ? '/assets/os/' + id + '.svg' : logo};
  }

  function mount(form, words) {
    var doc = form.ownerDocument, cards = [], labels = {}, imageLogos = {};
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
        var image=el('img'); image.src=group.info.logo; image.alt=''; image.width=30; image.height=30;
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
        var card={box:box,button:button,select:select,single:single,bottom:bottom,items:group.items};
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
