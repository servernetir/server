<script>
(() => {
    'use strict';
    const root = document.querySelector('[data-identity-product]');
    if (!root) return;
    const labels = {{ Illuminate\Support\Js::from(['all'=>__('identity_product.all'), 'active'=>__('identity_product.active_only'), 'copy'=>__('identity_product.copy'), 'copied'=>__('identity_product.copied'), 'failed'=>__('identity_product.copy_failed')]) }};
    const cards = Array.from(root.querySelectorAll('[data-service]'));
    const categories = Array.from(root.querySelectorAll('button[data-category]'));
    const search = root.querySelector('[data-search]');
    const showAll = root.querySelector('[data-show-all]');
    const all = root.querySelector('[data-category-all]');
    let selected = '';
    const normalize = value => value.normalize('NFKC').toLocaleLowerCase(document.documentElement.lang).replace(/[يى]/g,'ی').replace(/ك/g,'ک').replace(/[\u200c\u200e\u200f]/g,' ').replace(/\s+/g,' ').trim();
    const update = () => {
        const terms = normalize(search.value).split(' ').filter(Boolean);
        let count = 0;
        cards.forEach(card => {
            const visible = (!selected || card.dataset.category === selected) && (showAll.checked || card.dataset.active === 'true') && terms.every(term => normalize(card.dataset.searchText).includes(term));
            card.hidden = !visible;
            if (visible) count++;
        });
        categories.forEach(button => button.setAttribute('aria-pressed',String(button.dataset.category === selected)));
        all.setAttribute('aria-pressed',String(!selected));
        root.querySelector('[data-category-label]').textContent = selected ? categories.find(button => button.dataset.category === selected).querySelector('strong').textContent : labels.all;
        root.querySelector('[data-result-count]').textContent = new Intl.NumberFormat(document.documentElement.lang).format(count);
        root.querySelector('[data-availability-label]').textContent = showAll.checked ? '' : ' · '+labels.active;
        root.querySelector('[data-empty]').hidden = count !== 0;
    };
    root.querySelector('[data-filter]').hidden = false;
    search.addEventListener('input',update);
    showAll.addEventListener('change',update);
    categories.forEach(button => button.addEventListener('click',() => {selected = selected === button.dataset.category ? '' : button.dataset.category; update();}));
    all.addEventListener('click',() => {selected = '';update();});
    root.querySelector('[data-reset]').addEventListener('click',() => {selected='';search.value='';showAll.checked=true;update();search.focus();});
    update();
    const tabs = Array.from(root.querySelectorAll('[data-code-tab]'));
    let current = 'curl';
    const selectTab = tab => {
        current = tab.dataset.codeTab;
        tabs.forEach(button => {const active=button===tab;button.setAttribute('aria-selected',String(active));button.tabIndex=active?0:-1;});
        root.querySelectorAll('[data-code-panel]').forEach(panel => {panel.hidden=panel.dataset.codePanel!==current;});
        root.querySelector('[data-copy]').textContent=labels.copy;
        root.querySelector('[data-copy-status]').textContent='';
    };
    tabs.forEach((tab,index) => {
        tab.addEventListener('click',() => selectTab(tab));
        tab.addEventListener('keydown',event => {
            let next;
            if(event.key==='ArrowRight') next=(index+1)%tabs.length;
            if(event.key==='ArrowLeft') next=(index+tabs.length-1)%tabs.length;
            if(event.key==='Home') next=0;
            if(event.key==='End') next=tabs.length-1;
            if(next!==undefined){event.preventDefault();selectTab(tabs[next]);tabs[next].focus();}
        });
    });
    const copy=root.querySelector('[data-copy]');
    copy.hidden=false;
    copy.addEventListener('click',async () => {
        const code=root.querySelector('[data-code-panel="'+current+'"] code');
        try {
            await navigator.clipboard.writeText(code.textContent);
            copy.textContent=labels.copied;
            root.querySelector('[data-copy-status]').textContent=labels.copied;
        } catch (_) {
            copy.textContent=labels.failed;
            root.querySelector('[data-copy-status]').textContent=labels.failed;
            code.closest('[role="tabpanel"]').focus();
        }
    });
})();
</script>
