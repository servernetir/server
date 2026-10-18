@push('head')
<style>
.ip {
    --ip-panel:#101925;
    --ip-soft:#0d1521;
    --ip-border:#263346;
    --ip-ink:#edf4ff;
    --ip-muted:#a5b6ca;
    --ip-accent:#6cbbff;
    --ip-teal:#4ed9cf;
    color:var(--ip-ink);
    position:relative;
    background:radial-gradient(ellipse 65% 580px at 25% 300px,rgba(33,112,190,.11),transparent),var(--bg)}

html[data-theme="light"] .ip {
    --ip-panel:#fff;
    --ip-soft:#f1f6fb;
    --ip-border:#dbe5ef;
    --ip-ink:#122a43;
    --ip-muted:#51667c;
    --ip-accent:#125eaf;
    --ip-teal:#087d80;
    background:radial-gradient(ellipse 65% 580px at 25% 300px,#e5f4fd,transparent),#f9fcff}

.ip *, .ip *:before,.ip *:after {
    box-sizing:border-box}
.ip [hidden] {
    display:none!important}
.ip-shell {
    max-width:1280px;
    padding:0 32px 88px;
    margin:auto}
.ip-nav {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:27px 0;
    border-bottom:1px solid var(--ip-border);
    font-size:12px}
.ip-nav>div {
    display:flex;
    gap:24px;
    flex-wrap:wrap}
.ip-nav a:hover,.ip-text-link:hover {
    color:var(--ip-accent)}
.ip-brand {
    display:flex;
    align-items:center;
    gap:9px;
    direction:ltr;
    font-size:16px;
    font-weight:700;
    white-space:nowrap}
.ip-brand>span:last-child {
    font-size:12px;
    font-weight:400;
    color:var(--ip-muted);
    padding-inline-start:9px;
    border-inline-start:1px solid var(--ip-border)}
.ip-brand-dot {
    width:10px;
    height:10px;
    background:linear-gradient(135deg,#3386f8,#2dd4bf);
    border-radius:3px;
    transform:rotate(15deg)}
.ip-hero {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:34px;
    align-items:center;
    padding:66px 0 62px}
.ip-eyebrow {
    font-size:12px;
    font-weight:500;
    color:var(--ip-teal);
    margin:0 0 16px;
    letter-spacing:.03em}
.ip h1 {
    font-size:clamp(30px,3.3vw,45px);
    font-weight:700;
    line-height:1.65;
    letter-spacing:-.035em;
    max-width:590px;
    margin:0 0 20px}
.ip-lead {
    max-width:550px;
    color:var(--ip-muted);
    font-size:16px;
    line-height:2.1}
.ip-actions {
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    margin:27px 0 20px}
.ip-btn {
    min-height:48px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:25px;
    border-radius:10px;
    padding:11px 21px;
    font-size:13px;
    font-weight:500;
    border:1px solid transparent;
    transition:transform .2s,box-shadow .2s;
    background:none;
    color:inherit}
.ip-btn:hover {
    transform:translateY(-2px)}
.ip-primary {
    background:#166dd7;
    color:white;
    box-shadow:0 5px 18px #1571d426;
    border-color:#3788e6}
.ip-primary:hover {
    background:#125cbb;
    box-shadow:0 8px 24px #1571d43b}
.ip-secondary {
    background:var(--ip-panel);
    border-color:var(--ip-border)}
.ip-text-link {
    font-size:12px;
    color:var(--ip-muted);
    text-underline-offset:5px}
.ip-icon {
    height:24px;
    width:24px;
    flex:none}
.ip-visual {
    position:relative;
    margin:0;
    aspect-ratio:1.25;
    min-width:0;
    direction:ltr;
    isolation:isolate}
.ip-visual:before {
    content:'';
    position:absolute;
    width:56%;
    height:65%;
    top:15%;
    left:22%;
    border-radius:50%;
    background:#1a94d81c;
    filter:blur(45px);
    z-index:-1}
.ip-orbit {
    position:absolute;
    inset:0;
    width:100%;
    height:100%}
.ip-person-card {
    position:absolute;
    width:52%;
    top:9%;
    left:4%;
    border:1px solid var(--ip-border);
    border-radius:16px;
    padding:20px;
    background:var(--ip-panel);
    box-shadow:0 20px 48px #020b1918;
    transform:rotate(-6deg)}
.ip-art-card-head {
    display:flex;
    gap:12px;
    align-items:center}
.ip-art-avatar {
    height:40px;
    width:40px;
    display:grid;
    place-items:center;
    background:#2a8cdb22;
    color:var(--ip-accent);
    border-radius:12px}
.ip-art-card-head strong {
    font-size:12px;
    display:block}
.ip-art-card-head small,.ip-result-card small {
    display:block;
    font-size:10px;
    color:var(--ip-muted);
    margin-top:2px}
.ip-art-dot {
    margin-left:auto;
    width:6px;
    height:6px;
    border-radius:50%;
    background:var(--ip-teal)}
.ip-masked {
    margin-top:18px;
    display:grid;
    gap:5px;
    color:var(--ip-muted);
    font:12px/1.7 ui-monospace,monospace;
    letter-spacing:2px}
.ip-art-bars {
    display:flex;
    height:18px;
    gap:3px;
    opacity:.25;
    margin-top:12px}
.ip-art-bars i {
    width:3px;
    background:var(--ip-accent)}
.ip-art-bars i:nth-child(3n) {
    width:7px}
.ip-art-bars i:nth-child(4n) {
    width:2px}
.ip-core {
    position:absolute;
    left:36%;
    top:39%;
    width:30%;
    aspect-ratio:1;
    border-radius:25%;
    background:linear-gradient(145deg,#1f82d9,#075bb1);
    box-shadow:0 22px 48px #0669bc3d,inset 0 0 0 1px #89cbff77,inset 0 0 35px #65d4ff22;
    transform:rotate(-8deg);
    display:flex;
    align-items:center;
    justify-content:center;
    flex-direction:column;
    color:#ddfaff;
    z-index:2}
.ip-core>svg {
    width:40%;
    height:40%;
    margin-bottom:8px}
.ip-core strong {
    font:600 13px/1.3 ui-sans-serif,system-ui}
.ip-core strong span {
    margin-left:5px;
    font-weight:400;
    opacity:.75}
.ip-core-label {
    position:absolute;
    top:10px;
    right:12px;
    font-size:9px;
    opacity:.7}
.ip-result-card {
    position:absolute;
    right:1%;
    bottom:10%;
    display:flex;
    align-items:center;
    gap:12px;
    padding:16px 18px;
    width:57%;
    background:var(--ip-panel);
    border:1px solid var(--ip-border);
    border-radius:14px;
    box-shadow:0 20px 48px #020b1918;
    transform:rotate(3deg);
    z-index:3}
.ip-result-check {
    display:grid;
    place-items:center;
    color:var(--ip-teal);
    background:#26b6ac18;
    border-radius:50%;
    width:38px;
    height:38px}
.ip-result-card strong {
    font-size:12px;
    display:block;
    margin-top:3px}
.ip-result-mark {
    margin-left:auto;
    color:var(--ip-teal);
    font-size:18px}
.ip-visual figcaption {
    position:absolute;
    bottom:0;
    width:100%;
    text-align:center;
    font-size:10px;
    color:var(--ip-muted)}
.ip-flow {
    animation:ip-flow 9s linear infinite}
.ip-benefits {
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:32px;
    border-top:1px solid var(--ip-border);
    border-bottom:1px solid var(--ip-border);
    padding:28px 0}
.ip-benefits>div {
    display:flex;
    gap:14px}
.ip-benefits .ip-icon {
    color:var(--ip-accent);
    height:23px;
    width:23px;
    margin-top:4px}
.ip .ip-benefits h2 {
    font-size:13px;
    margin:0 0 7px}
.ip-benefits p {
    font-size:11px;
    color:var(--ip-muted);
    line-height:1.9}
.ip-section {
    padding-top:88px;
    scroll-margin-top:calc(var(--header-h,112px) + 24px)}
.ip h2 {
    font-size:clamp(22px,2.1vw,30px);
    line-height:1.8;
    letter-spacing:-.02em}
.ip-section-head {
    display:flex;
    align-items:end;
    justify-content:space-between;
    gap:45px;
    margin-bottom:30px}
.ip-section-head>div {
    max-width:630px}
.ip-section-head>p {
    font-size:12px;
    line-height:2.1;
    color:var(--ip-muted);
    max-width:365px}
.ip-families {
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:16px;
    margin:26px 0 24px}
.ip-family {
    position:relative;
    display:flex;
    align-items:center;
    gap:8px;
    text-align:start;
    background:var(--ip-panel);
    border:1px solid var(--ip-border);
    border-radius:15px;
    padding:16px 16px 16px 6px;
    color:inherit;
    transition:border-color .2s,transform .2s,background .2s;
    min-width:0}
.ip-family:hover {
    border-color:var(--ip-accent);
    transform:translateY(-3px)}
.ip-family[aria-pressed="true"] {
    border-color:var(--ip-accent);
    box-shadow:0 0 0 2px #2085d91a;
    background:var(--ip-soft)}
.ip-family-art {
    width:100px;
    height:85px;
    flex:none;
    order:2}
.ip-family>span:first-of-type {
    flex:1}
.ip-family strong {
    display:block;
    font-size:14px;
    margin-bottom:7px}
.ip-family>span>span {
    display:block;
    font-size:10px;
    line-height:1.9;
    color:var(--ip-muted)}
.ip-family-arrow {
    position:absolute;
    inset-inline-end:12px;
    top:8px;
    color:var(--ip-muted);
    font-size:11px}
.ip-filter {
    display:flex;
    gap:22px;
    align-items:center;
    margin:24px 0}
.ip-search {
    position:relative;
    flex:1;
    display:flex;
    align-items:center;
    border:1px solid var(--ip-border);
    background:var(--ip-panel);
    border-radius:10px;
    padding:0 16px;
    gap:12px}
.ip-search .ip-icon {
    color:var(--ip-muted);
    width:18px;
    height:18px}
.ip-search input {
    min-width:0;
    width:100%;
    border:0;
    border-radius:10px;
    background:transparent;
    color:inherit;
    font:inherit;
    font-size:12px;
    padding:16px 0;
    outline-offset:8px}
.ip-search input::placeholder {
    color:var(--ip-muted);
    opacity:1}
.ip-toggle {
    display:flex;
    gap:10px;
    align-items:center;
    font-size:12px;
    min-height:44px;
    cursor:pointer}
.ip-toggle input {
    accent-color:#187bd7;
    width:18px;
    height:18px}
.ip-results {
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin:24px 0 18px;
    gap:15px;
    font-size:11px;
    color:var(--ip-muted)}
.ip-filter-reset {
    font:inherit;
    font-size:11px;
    padding:8px 13px;
    border:1px solid var(--ip-border);
    border-radius:20px;
    background:var(--ip-panel);
    color:inherit;
    min-height:36px}
.ip-filter-reset[aria-pressed="true"] {
    color:var(--ip-accent);
    border-color:var(--ip-accent)}
.ip-services {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:18px;
    align-items:start}
.ip-service {
    display:flex;
    flex-direction:column;
    align-self:stretch;
    min-width:0;
    padding:23px;
    background:var(--ip-panel);
    border:1px solid var(--ip-border);
    border-radius:15px;
    transition:border-color .2s,box-shadow .2s}
.ip-service:hover,.ip-service:focus-within {
    border-color:var(--ip-accent);
    box-shadow:0 10px 28px #0a37670b}
.ip-service-top {
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
    gap:10px}
.ip-service-icon {
    display:grid;
    place-items:center;
    border:1px solid var(--ip-border);
    background:var(--ip-soft);
    color:var(--ip-accent);
    width:42px;
    height:42px;
    border-radius:12px}
.ip-status {
    font-size:10px;
    color:var(--ip-muted);
    padding:4px 8px;
    border-radius:20px;
    background:var(--ip-soft);
    white-space:nowrap}
.ip-status-active {
    color:var(--ip-teal);
    background:#26b6ac14}
.ip-service h3 {
    font-size:15px;
    line-height:1.9;
    margin:0 0 9px;
    font-weight:500}
.ip-purpose {
    font-size:11px;
    line-height:2;
    color:var(--ip-muted);
    margin-bottom:26px}
.ip-service-bottom {
    margin-top:auto}
.ip-rate {
    padding-top:15px;
    border-top:1px solid var(--ip-border);
    min-height:67px;
    font-size:11px}
.ip-rate strong {
    font-size:23px;
    font-weight:500;
    font-variant-numeric:tabular-nums}
.ip-rate small {
    display:block;
    color:var(--ip-muted);
    font-size:10px;
    margin-top:2px}
.ip-rate-pending {
    color:var(--ip-muted);
    display:block;
    margin-top:6px}
.ip-service-details {
    margin-top:12px;
    font-size:11px}
.ip-service-details summary {
    display:flex;
    justify-content:space-between;
    align-items:center;
    color:var(--ip-accent);
    min-height:37px;
    list-style:none}
.ip summary::-webkit-details-marker {
    display:none}
.ip-service-details[open] summary>span,.ip-faq details[open] summary>span {
    transform:rotate(45deg)}
.ip-service-details>div {
    border-top:1px solid var(--ip-border);
    padding:15px 0 3px}
.ip-service-details h4 {
    font-size:11px;
    color:var(--ip-muted);
    font-weight:400;
    margin-bottom:10px}
.ip-service-details ul {
    list-style:none;
    text-align:left;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:16px}
.ip-service-details code {
    font:11px/1.6 ui-monospace,monospace;
    background:var(--ip-soft);
    padding:3px 6px;
    border-radius:4px;
    overflow-wrap:anywhere}
.ip-service-details a {
    color:var(--ip-accent);
    display:inline-block;
    padding:5px 0}
.ip-notice {
    font-size:10px;
    color:var(--ip-muted);
    line-height:2;
    margin-top:20px;
    padding-inline-start:12px;
    border-inline-start:2px solid var(--ip-border)}
.ip-empty {
    padding:45px 20px;
    text-align:center;
    border:1px dashed var(--ip-border);
    border-radius:15px;
    background:var(--ip-soft)}
.ip-empty>.ip-icon {
    color:var(--ip-accent);
    margin-bottom:15px}
.ip-empty h3 {
    font-size:15px;
    margin-bottom:8px}
.ip-empty p {
    font-size:12px;
    color:var(--ip-muted);
    margin-bottom:20px}
.ip-uses {
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:40px;
    margin-top:38px}
.ip-uses article {
    border-top:1px solid var(--ip-border);
    padding-top:23px}
.ip-use-icon {
    display:flex;
    align-items:center;
    gap:14px;
    color:var(--ip-accent);
    margin-bottom:22px}
.ip-use-icon>.ip-icon {
    width:26px;
    height:26px}
.ip-use-icon>.ip-icon:last-child {
    width:20px;
    color:var(--ip-teal)}
.ip-use-line {
    width:43px;
    border-top:1px dashed var(--ip-border)}
.ip h3 {
    font-size:15px;
    line-height:1.9}
.ip-uses p,.ip-steps p {
    font-size:12px;
    line-height:2;
    color:var(--ip-muted);
    margin-top:10px}
.ip-developer {
    display:grid;
    grid-template-columns:.8fr 1.2fr;
    gap:65px;
    align-items:center}
.ip-dev-copy>p:not(.ip-eyebrow) {
    font-size:13px;
    line-height:2.1;
    color:var(--ip-muted);
    margin:20px 0}
.ip-dev-copy ol {
    list-style:none;
    padding:0;
    margin:24px 0 28px;
    display:grid;
    gap:18px}
.ip-dev-copy li {
    display:flex;
    align-items:center;
    gap:12px;
    font-size:12px}
.ip-dev-copy li>span {
    border:1px solid var(--ip-border);
    color:var(--ip-accent);
    border-radius:50%;
    width:26px;
    height:26px;
    display:grid;
    place-items:center;
    flex:none;
    font-size:11px}
.ip-code-component {
    min-width:0;
    background:#0b1625;
    color:#d5e6f8;
    border:1px solid #2b3c50;
    border-radius:16px;
    box-shadow:0 18px 50px #07142615;
    overflow:hidden}
.ip-code-bar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:8px;
    border-bottom:1px solid #25394d;
    padding:0 16px;
    min-height:56px}
.ip-code-bar [role="tablist"] {
    display:flex;
    gap:8px}
.ip-code-bar [role="tab"] {
    padding:18px 10px 16px;
    font:12px ui-monospace,monospace;
    color:#99aec5;
    border:0;
    border-bottom:2px solid transparent;
    background:none;
    min-height:48px}
.ip-code-bar [aria-selected="true"] {
    color:#7bdce5;
    border-bottom-color:#5dcad9}
.ip-copy {
    background:#1a2b40;
    border:1px solid #2f455f;
    border-radius:6px;
    color:#d5e6f8;
    font:inherit;
    font-size:10px;
    padding:7px 9px;
    min-height:34px}
.ip-code-component pre {
    overflow-x:auto;
    white-space:pre;
    max-width:100%;
    font:11px/2 ui-monospace,SFMono-Regular,Consolas,monospace;
    text-align:left;
    padding:24px;
    margin:0;
    min-height:305px;
    tab-size:4;
    scrollbar-width:thin}
.ip-code-foot {
    font-size:10px;
    line-height:1.9;
    border-top:1px solid #25394d;
    padding:12px 20px;
    color:#a2b9d0}
.ip-code-foot>span {
    color:#56d3ce;
    margin-inline-end:5px}
.ip-steps {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:30px;
    list-style:none;
    padding:0;
    margin-top:38px}
.ip-steps li {
    position:relative;
    border-top:1px solid var(--ip-border);
    padding-top:24px}
.ip-step-num {
    font:28px/1 ui-monospace,monospace;
    letter-spacing:-2px;
    display:block;
    color:var(--ip-accent);
    opacity:.65;
    margin-bottom:21px}
.ip-faq {
    display:grid;
    grid-template-columns:.7fr 1.3fr;
    gap:70px}
.ip-faq .ip-text-link {
    display:inline-block;
    margin-top:18px}
.ip-faq details {
    border-bottom:1px solid var(--ip-border);
    padding:0 0 5px}
.ip-faq summary {
    list-style:none;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    min-height:69px;
    font-size:13px;
    padding:13px 0}
.ip-faq summary>span {
    color:var(--ip-accent);
    font-size:22px;
    transition:transform .2s}
.ip-faq details p {
    font-size:12px;
    color:var(--ip-muted);
    line-height:2.1;
    padding-bottom:18px}
.ip-final {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:50px;
    margin-top:88px;
    padding:40px;
    border:1px solid var(--ip-border);
    border-radius:22px;
    background:radial-gradient(ellipse at 0 0,#1684d91c,transparent 60%),var(--ip-panel)}
.ip-final>div:first-child {
    max-width:680px}
.ip-final h2 {
    font-size:25px}
.ip-final p:not(.ip-eyebrow) {
    font-size:12px;
    color:var(--ip-muted);
    margin-top:12px}
.ip-final .ip-actions {
    flex-shrink:0;
    margin:0;
    flex-direction:column}
.ip-sr {
    position:absolute;
    width:1px;
    height:1px;
    padding:0;
    margin:-1px;
    overflow:hidden;
    clip:rect(0,0,0,0);
    white-space:nowrap;
    border:0}
.ip button:focus-visible,.ip a:focus-visible,.ip summary:focus-visible,.ip input:focus-visible,.ip [tabindex="0"]:focus-visible {
    outline:2px solid var(--ip-accent);
    outline-offset:5px}
.ip details[open]>div,.ip-faq details[open]>p {
    animation:ip-reveal .18s ease-out}

.ip-response {
    margin-top:10px}
.ip-response summary {
    font-size:10px;
    min-height:34px}
.ip-response pre {
    min-height:0;
    padding:12px 0 0;
    font-size:10px}

@keyframes ip-flow {
    to {
    stroke-dashoffset:-140}
}
@keyframes ip-reveal {
    from {
    opacity:.5;
    transform:translateY(-4px)}
to {
    opacity:1;
    transform:translateY(0)}
}

@media(min-width:1000px) {
    .ip-service[data-active="true"] {
    border-top:2px solid var(--ip-teal)}
}

@media(max-width:1000px) {
    .ip-visual { aspect-ratio:1.05 }
    .ip-result-card strong { font-size:10px }
    .ip-shell {
    padding-inline:26px}
.ip-hero {
    gap:18px;
    padding-block:48px}
.ip h1 {
    font-size:32px}
.ip-person-card {
    padding:14px}
.ip-result-card {
    padding:12px;
    gap:8px}
.ip-core strong {
    font-size:11px}
.ip-family {
    flex-direction:column;
    align-items:start;
    padding:18px;
    gap:0}
.ip-family-art {
    order:0;
    width:105px;
    height:78px}
.ip-family>span>span {
    font-size:11px}
.ip-services {
    grid-template-columns:repeat(2,minmax(0,1fr))}
.ip-section-head {
    gap:25px}
.ip-developer {
    gap:30px;
    grid-template-columns:.9fr 1.1fr}
.ip-steps {
    gap:20px}
.ip-nav>div {
    gap:16px}
.ip-benefits {
    gap:22px}
.ip-final {
    gap:25px;
    padding:28px}
}

@media(max-width:700px) {
    .ip-shell {
    padding:0 20px 70px}
.ip-nav {
    align-items:start;
    flex-direction:column;
    gap:16px;
    padding:22px 0;
    font-size:10px}
.ip-nav>div {
    gap:15px}
.ip-brand {
    font-size:15px}
.ip-hero {
    grid-template-columns:1fr;
    padding:38px 0 28px;
    gap:22px}
.ip h1 {
    font-size:31px;
    line-height:1.65;
    max-width:520px}
.ip-lead {
    font-size:13px;
    line-height:2.05}
.ip-eyebrow {
    font-size:11px;
    margin-bottom:14px}
.ip-hero .ip-actions {
    margin:24px 0 17px;
    gap:10px}
.ip-btn {
    font-size:11px;
    gap:14px;
    padding:11px 15px}
.ip-visual {
    max-width:440px;
    width:100%;
    margin:auto;
    aspect-ratio:1.05}
.ip-art-card-head strong,.ip-result-card strong {
    font-size:10px}
.ip-art-card-head small,.ip-result-card small {
    font-size:9px}
.ip-art-avatar {
    width:31px;
    height:31px}
.ip-art-card-head {
    gap:8px}
.ip-masked {
    font-size:10px;
    margin-top:10px}
.ip-art-bars {
    height:12px;
    margin-top:9px}
.ip-core {
    width:28%;
    top:40%}
.ip-core>svg {
    margin-bottom:3px}
.ip-core strong {
    font-size:9px}
.ip-core-label {
    font-size:8px;
    top:6px;
    right:8px}
.ip-result-card {
    bottom:13%;
    right:0;
    padding:12px;
    gap:9px}
.ip-result-check {
    width:28px;
    height:28px}
.ip-result-check .ip-icon {
    width:17px}
.ip-result-mark {
    font-size:13px}
.ip-visual figcaption {
    font-size:9px}
.ip-person-card {
    width:54%;
    left:1%;
    top:8%;
    padding:13px}
.ip-benefits {
    grid-template-columns:1fr;
    gap:23px;
    padding:27px 0}
.ip-benefits h2 {
    font-size:12px}
.ip-benefits p {
    font-size:11px}
.ip-section {
    padding-top:58px}
.ip h2 {
    font-size:23px}
.ip-section-head {
    display:block;
    margin-bottom:24px}
.ip-section-head>p {
    margin-top:15px;
    font-size:12px;
    max-width:100%}
.ip-families {
    grid-template-columns:1fr;
    gap:10px;
    margin-top:23px}
.ip-family {
    flex-direction:row;
    align-items:center;
    padding:12px 14px;
    gap:16px}
.ip-family-art {
    width:76px;
    height:65px}
.ip-family strong {
    font-size:12px;
    margin-bottom:5px}
.ip-family>span>span {
    font-size:10px;
    line-height:1.9}
.ip-filter {
    align-items:stretch;
    flex-direction:column;
    gap:9px;
    margin:19px 0 15px}
.ip-search {
    padding-inline:12px}
.ip-search input {
    font-size:11px;
    padding-block:15px}
.ip-toggle {
    font-size:11px}
.ip-results {
    align-items:start;
    font-size:10px;
    gap:12px;
    margin:15px 0}
.ip-results p {
    text-align:end;
    max-width:60%;
    line-height:1.9}
.ip-filter-reset {
    font-size:10px;
    white-space:nowrap}
.ip-services {
    grid-template-columns:1fr;
    gap:14px}
.ip-service {
    padding:22px}
.ip-service h3 {
    font-size:15px}
.ip-purpose {
    font-size:12px;
    margin-bottom:24px}
.ip-status {
    font-size:10px}
.ip-notice {
    font-size:10px}
.ip-uses {
    grid-template-columns:1fr;
    gap:25px;
    margin-top:25px}
.ip-uses article {
    display:grid;
    grid-template-columns:86px 1fr;
    column-gap:19px;
    padding-top:23px}
.ip-use-icon {
    grid-row:span 2;
    margin:0;
    align-self:center;
    gap:9px}
.ip-use-line {
    width:18px}
.ip-uses h3 {
    font-size:13px}
.ip-uses p {
    font-size:11px;
    margin-top:8px}
.ip-developer {
    grid-template-columns:1fr;
    gap:30px}
.ip-dev-copy>p:not(.ip-eyebrow) {
    font-size:12px;
    margin-block:15px}
.ip-dev-copy ol {
    gap:15px;
    margin-block:20px}
.ip-code-component pre {
    font-size:10px;
    padding:18px;
    min-height:285px}
.ip-code-bar {
    padding-inline:10px}
.ip-code-bar [role="tab"] {
    font-size:11px;
    padding-inline:8px}
.ip-copy {
    font-size:9px;
    max-width:145px}
.ip-code-foot {
    font-size:9px;
    padding-inline:14px}
.ip-steps {
    grid-template-columns:1fr 1fr;
    gap:25px 20px;
    margin-top:26px}
.ip-step-num {
    font-size:25px;
    margin-bottom:15px}
.ip-steps h3 {
    font-size:12px}
.ip-steps p {
    font-size:11px;
    margin-top:8px}
.ip-faq {
    grid-template-columns:1fr;
    gap:24px}
.ip-faq summary {
    font-size:12px}
.ip-faq details p {
    font-size:11px}
.ip-final {
    flex-direction:column;
    align-items:stretch;
    padding:26px 23px;
    margin-top:58px;
    gap:26px}
.ip-final h2 {
    font-size:23px}
.ip-final .ip-actions {
    flex-direction:row}
.ip-final p:not(.ip-eyebrow) {
    line-height:2}
.ip-final .ip-btn {
    flex:1}
.ip-final .ip-eyebrow {
    font-size:10px}
}

@media(prefers-reduced-motion:reduce) {
    html:has([data-identity-product]) {
    scroll-behavior:auto}
.ip *,.ip *:before,.ip *:after {
    animation:none!important;
    transition:none!important;
    scroll-behavior:auto!important}
}

</style>
@endpush
