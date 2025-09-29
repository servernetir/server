const DATASET_A = {
  amountCurrency: "€",
  promos: [
    { text: "from 50 € you will receive a bonus 5% to the payment," },
    { text: "from 100 € you will receive a bonus 10% to the payment," }
  ],
  coins: [
    { id:"usdt_trc20", title:"USDT (TRC20)", fee:4, min:1, max:2000, icon:"🟢", network:"TRON", selected:false },
    { id:"usdt_cryptobot", title:"USDT (CryptoBot)", fee:5, min:1, max:2000, icon:"🟢", network:"CryptoBot", selected:true },
    { id:"bnb_bsc", title:"BNB (BNB Chain)", fee:5, min:1, max:2000, icon:"🟡", network:"BSC" },
    { id:"btc", title:"Bitcoin", fee:5, min:5, max:2000, icon:"🟠", network:"BTC" },
    { id:"eth_main", title:"ETH (Mainnet)", fee:5, min:10, max:2000, icon:"🟣", network:"Ethereum" },
    { id:"usdt_bep20", title:"USDT (BEP20)", fee:5, min:1, max:2000, icon:"🟢", network:"BEP20" },
    { id:"usdt_erc20", title:"USDT (ERC20)", fee:5, min:1, max:2000, icon:"🟢", network:"ERC20" },
    { id:"sol", title:"SOLANA (SOL)", fee:5, min:1, max:2000, icon:"🟪", network:"Solana" },
    { id:"usdt_ton", title:"USDT (TON)", fee:5, min:1, max:2000, icon:"🟢", network:"TON" },
    { id:"usdc_bep20", title:"USDC (BEP20)", fee:5, min:1, max:2000, icon:"🔵", network:"BEP20" },
    { id:"usdc_eth", title:"USDC (ETH)", fee:5, min:1, max:2000, icon:"🔵", network:"Ethereum" },
    { id:"ltc", title:"Litecoin", fee:5, min:1, max:2000, icon:"⚪️", network:"LTC" },
    { id:"trx_trc20", title:"TRX (TRC20)", fee:5, min:1, max:2000, icon:"🔺", network:"TRON" },
    { id:"ton", title:"TON", fee:5, min:1, max:2000, icon:"🔷", network:"TON" },
  ]
};

const DATASET_B = {
  amountCurrency: "$",
  promos: [
    { text: "from 25 $ bonus 3% to the payment," },
    { text: "from 250 $ bonus 12% to the payment," }
  ],
  coins: [
    { id:"ada", title:"Cardano (ADA)", fee:3, min:5, max:5000, icon:"🔷", network:"ADA", selected:true },
    { id:"xrp", title:"Ripple (XRP)", fee:4, min:10, max:5000, icon:"💧", network:"XRP" },
    { id:"doge", title:"Dogecoin (DOGE)", fee:6, min:10, max:5000, icon:"🐶", network:"DOGE" },
    { id:"matic", title:"Polygon (MATIC)", fee:4, min:5, max:5000, icon:"🟪", network:"Polygon" },
  ]
};

/* ------- Renderer ------- */
let state = { data: DATASET_A, selectedId: null };

function setData(newData){
  state.data = structuredClone(newData);
  const preSel = state.data.coins.find(c=>c.selected) || state.data.coins[0];
  state.selectedId = preSel.id;
  render();
}

function render(){
  // coin grid
  const grid = document.getElementById('coinGrid');
  grid.innerHTML = '';
  state.data.coins.forEach(c=>{
    const btn = document.createElement('button');
    btn.className = 'coin' + (state.selectedId===c.id?' active':'');
    btn.innerHTML = `<span>${c.icon||''}</span><span>${c.title}</span> <span class="fee">${c.fee}%</span>`;
    btn.onclick = ()=>{ state.selectedId = c.id; render(); };
    grid.appendChild(btn);
  });

  // selected method card
  const sel = state.data.coins.find(c=>c.id===state.selectedId);
  const card = document.getElementById('selectedCard');
  card.innerHTML = `
    <div style="flex:1">
      <div class="chip">${sel.icon||''} <strong>${sel.title}</strong> <span class="fee">${sel.fee}%</span></div>
      <div class="mmeta">
        Commission: ${sel.fee}%<br>
        Minimum payment amount: ${sel.min} ${state.data.amountCurrency}<br>
        Maximum payment amount: ${sel.max.toLocaleString()} ${state.data.amountCurrency}
      </div>
    </div>
    <div class="badge">(Selected)</div>
  `;

  // promos
  const ul = document.getElementById('promoList');
  ul.innerHTML = state.data.promos.map(p=>`<li>${p.text}</li>`).join('');

  // total
  updateTotal();
}

function updateTotal(){
  const amt = Number(document.getElementById('amountInput').value || 0);
  const cur = state.data.amountCurrency;
  document.getElementById('totalValue').textContent = `${amt} ${cur}`;
}

/* ------- Events ------- */
document.getElementById('amountInput').addEventListener('input', updateTotal);
document.getElementById('loadSetA').addEventListener('click', ()=>setData(DATASET_A));
document.getElementById('loadSetB').addEventListener('click', ()=>setData(DATASET_B));

/* ------- open/close drawer (مثل قبل) ------- */
document.addEventListener('click', (e) => {
  const payBtn = e.target.closest('[data-action="pay"]');
  if (payBtn) {
    const overlay = document.querySelector(payBtn.getAttribute('data-target') || '#buyModal');
    overlay.classList.add('modal-overlay_active'); overlay.setAttribute('aria-hidden','false');
    document.body.classList.add('modal-open');
  }
  if (e.target.closest('[data-action="close-modal"]') || e.target.classList.contains('modal-overlay')) {
    closeActiveDrawer();
  }
});
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closeActiveDrawer(); });
function closeActiveDrawer(){
  const overlay = document.querySelector('.modal-overlay.modal-overlay_active');
  if(!overlay) return;
  overlay.classList.remove('modal-overlay_active'); overlay.setAttribute('aria-hidden','true');
  document.body.classList.remove('modal-open');
}

/* ------- init ------- */
setData(DATASET_A);