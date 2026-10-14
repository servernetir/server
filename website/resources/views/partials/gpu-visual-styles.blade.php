/* Scoped to the GPU landing page; shared product styling remains intact. */
.gpu-experience{--gpu-violet:#a491f7;--gpu-amber:#f2ae66}
.gpu-experience .gpu-art{display:block;width:76px;flex:0 0 76px;color:var(--cyan)}
.gpu-experience .gpu-art svg{display:block;width:100%;height:auto;overflow:visible}
.gpu-experience .gpu-art-comfyui{color:var(--gpu-violet)}
.gpu-experience .gpu-art-jupyter{color:var(--gpu-amber)}
.gpu-experience .gpu-art-flow{animation:gpu-signal 1.4s ease-out 3}
.gpu-experience .gpu-art-float{animation:gpu-float 2s ease-in-out 2;transform-box:fill-box;transform-origin:center}
.gpu-experience .gpu-app-option:hover .gpu-art-float,.gpu-experience .gpu-app-option:focus-within .gpu-art-float{animation:gpu-lift .6s ease-out 1}
@keyframes gpu-signal{from{stroke-dashoffset:26;opacity:.2}to{stroke-dashoffset:0;opacity:.7}}
@keyframes gpu-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-4px)}}
@keyframes gpu-lift{50%{transform:translateY(-5px)}}
.gpu-experience .gpu-compute-art{position:relative;min-width:0;aspect-ratio:1.4;border:1px solid var(--line);border-radius:28px;background:radial-gradient(ellipse at 50% 54%,rgba(34,211,238,.09),transparent 65%);color:var(--cyan);isolation:isolate;overflow:hidden}
.gpu-experience .gpu-compute-svg{position:absolute;width:100%;height:100%;inset:0}
.gpu-experience .gpu-art-caption{position:absolute;inset:22px 24px auto;display:flex;justify-content:space-between;gap:10px;font:9px/1.5 ui-monospace,monospace;letter-spacing:1.3px;color:var(--dim);direction:ltr}
.gpu-experience .gpu-art-caption-bottom{inset:auto 24px 22px}
.gpu-experience .gpu-art-satellite{position:absolute;display:grid;justify-items:center;gap:3px;font:8px/1.4 ui-monospace,monospace;letter-spacing:1.8px;color:var(--muted);direction:ltr}
.gpu-experience .gpu-art-satellite-a{left:5%;top:18%}.gpu-experience .gpu-art-satellite-b{right:5%;top:18%}.gpu-experience .gpu-art-satellite-c{left:calc(50% - 38px);bottom:9%}
.gpu-experience .gpu-hero-links{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px;align-items:center}
.gpu-experience .gpu-hero-links a{font-size:13px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:10px;text-decoration:none}
.gpu-experience .gpu-hero-links .gpu-secondary{color:var(--muted);border:1px solid var(--line)}
.gpu-experience #gpu-configure,.gpu-experience #gpu-app-guide{scroll-margin-top:calc(var(--header-h) + 24px)}
.sn-public #main .gpu-experience .gpu-cfg{grid-template-columns:minmax(0,1.15fr) minmax(370px,.85fr);gap:24px}
.sn-public #main .gpu-experience .gpu-panel,.sn-public #main .gpu-experience .gpu-side{border-radius:20px}
.sn-public #main .gpu-experience .gpu-panel-h{padding:24px}
.sn-public #main .gpu-experience .gpu-panel-h h2{font-size:20px;line-height:1.8;margin:5px 0 8px}
.gpu-experience .gpu-section-kicker{display:flex;align-items:center;gap:9px;color:var(--cyan);font-size:11px;letter-spacing:.02em;margin-bottom:7px}
.gpu-experience .gpu-section-kicker span{width:25px;height:25px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line-2);border-radius:8px;font:11px ui-monospace,monospace}
.gpu-experience .gpu-search{display:flex;align-items:center;gap:10px;border:1px solid var(--line);background:var(--surface-2);border-radius:12px;padding:0 12px;margin-top:20px}
.gpu-experience .gpu-search svg{width:18px;height:18px;flex:none;color:var(--muted)}
.gpu-experience .gpu-search input{min-width:0;width:100%;border:0;outline:0;background:transparent;color:var(--text);font:inherit;font-size:13px;padding:13px 0;min-height:44px}
.gpu-experience .gpu-search:focus-within{outline:2px solid var(--cyan);outline-offset:2px}
.sn-public #main .gpu-experience .gpu-search input:focus{outline:none;box-shadow:none;border:0}
.gpu-experience .gpu-search-empty{padding:24px;text-align:center;color:var(--muted);font-size:13px;line-height:2}
.gpu-experience .gpu-card[hidden]{display:none}
.gpu-experience .gpu-cards{max-height:620px;scrollbar-width:thin;scrollbar-color:var(--line-2) transparent}
.sn-public #main .gpu-experience .gpu-card{padding:19px 22px;border-radius:0;gap:12px}
.gpu-experience .gpu-card:focus-within{outline:2px solid var(--cyan);outline-offset:-3px}
.gpu-experience .gpu-card b{font-size:14px;line-height:1.6;overflow-wrap:anywhere}
.gpu-experience .gpu-card .gpu-spec{font-size:11px;line-height:1.9;display:block;margin-top:4px}
.gpu-experience .gpu-card .gpu-rate{font-size:13px}
.gpu-experience .gpu-side{padding:24px;position:static;min-width:0}
.gpu-experience .gpu-side h2{font-size:18px;line-height:1.8}
.gpu-experience .gpu-selected-card{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--muted);padding:10px 12px;border:1px solid var(--line);border-radius:10px;margin:14px 0}
.gpu-experience .gpu-selected-card b{color:var(--text);font:600 12px/1.6 system-ui,sans-serif;overflow-wrap:anywhere}
.gpu-experience .gpu-app-option{display:grid;grid-template-columns:60px minmax(0,1fr) 15px;gap:12px;align-items:center;padding:14px 12px;border-radius:14px;position:relative;transition:background .2s,border-color .2s}
.gpu-experience .gpu-app-option .gpu-art{width:60px;grid-column:1;grid-row:1;direction:ltr}
.gpu-experience .gpu-app-option input{grid-column:3;grid-row:1;margin:0;width:15px;height:15px}
.gpu-experience .gpu-app-option>span:last-child{grid-column:2;grid-row:1;min-width:0}
.gpu-experience .gpu-app-option b{font-size:13px;line-height:1.8}
.gpu-experience .gpu-app-option small{font-size:12px;line-height:1.9}
.gpu-experience .gpu-app-option:hover{background:var(--surface-2);border-color:var(--line-2)}
.gpu-experience .gpu-app-option:has(input:checked){background:rgba(34,211,238,.06);border-color:var(--cyan)}
.gpu-experience .gpu-app-option:focus-within{outline:2px solid var(--cyan);outline-offset:3px}
.gpu-experience .gpu-app-option[hidden],.gpu-experience .gpu-search[hidden]{display:none}
.gpu-experience .gpu-name{border-top:1px solid var(--line);padding-top:20px;margin-top:24px}
.gpu-experience .gpu-sum{padding:18px;background:var(--surface-2);border:1px solid var(--line);border-radius:12px;margin-bottom:18px}
.gpu-experience .gpu-sum-row:last-child{margin-bottom:0}
.gpu-experience .gpu-side #gpu-start-credit{padding-inline-start:12px;border-inline-start:2px solid var(--cyan)}
.gpu-experience .gpu-side .btn{min-height:48px;font-size:14px;gap:12px}
.gpu-experience .gpu-apps{text-align:start}
.gpu-experience .gpu-apps h2,.gpu-experience .gpu-how h2,.gpu-experience .gpu-faq h2{font-size:clamp(22px,2.4vw,30px);line-height:1.8}
.gpu-experience .gpu-apps>p{max-width:650px;line-height:2}
.gpu-experience .gpu-apps-grid{gap:20px}
.sn-public #main .gpu-experience .gpu-app{padding:24px;border-radius:20px;min-width:0;overflow:hidden}
.gpu-experience .gpu-app-visual{height:170px;display:grid;place-items:center;margin:-24px -24px 24px;background:radial-gradient(ellipse at center,rgba(34,211,238,.07),transparent 72%);border-bottom:1px solid var(--line)}
.gpu-experience .gpu-app-visual .gpu-art{width:176px}
.gpu-experience .gpu-app[data-art="comfyui"] .gpu-app-visual{background:radial-gradient(ellipse at center,rgba(164,145,247,.1),transparent 72%)}
.gpu-experience .gpu-app[data-art="jupyter"] .gpu-app-visual{background:radial-gradient(ellipse at center,rgba(242,174,102,.09),transparent 72%)}
.gpu-experience .gpu-app-tag{font:10px/1.6 ui-monospace,monospace;letter-spacing:1px;color:var(--dim);display:block;margin-bottom:12px}
.gpu-experience .gpu-app b{font-size:17px}.gpu-experience .gpu-app p{line-height:2.1}
.gpu-experience .gpu-how-grid{gap:18px}.gpu-experience .gpu-step-card{padding:25px 22px}
.gpu-experience .gpu-step-n{position:static;width:34px;height:34px;margin-bottom:15px;border-radius:10px;background:var(--surface-2);color:var(--cyan);border:1px solid var(--line-2)}
.gpu-experience .gpu-faq{max-width:980px;margin-inline:auto}.gpu-experience .gpu-faq details{padding:0 22px;border-radius:14px}
.gpu-experience .gpu-faq summary{padding:20px 0;font-size:14px}.gpu-experience .gpu-faq details[open]{border-color:var(--line-2)}
.gpu-experience .gpu-faq details[open] summary{color:var(--cyan)}.gpu-experience .gpu-faq p{padding:0 0 22px;margin:0}
@media(max-width:960px){.sn-public #main .gpu-experience .gpu-cfg{grid-template-columns:minmax(0,1fr)}.gpu-experience .gpu-compute-art{width:100%;max-width:570px;margin-inline:auto}.gpu-experience .gpu-cards{max-height:390px}.gpu-experience .gpu-app-option{grid-template-columns:70px minmax(0,1fr) 16px}.gpu-experience .gpu-app-option .gpu-art{width:70px}}
@media(max-width:540px){.gpu-experience .gpu-compute-art{border-radius:18px;aspect-ratio:1.35}.gpu-experience .gpu-art-caption{font-size:7px;letter-spacing:.5px;inset:15px 16px auto}.gpu-experience .gpu-art-caption-bottom{inset:auto 16px 15px}.gpu-experience .gpu-art-satellite .gpu-art{width:58px}.gpu-experience .gpu-art-satellite{font-size:6px}.gpu-experience .gpu-art-satellite-c{left:calc(50% - 29px);bottom:8%}.gpu-experience .gpu-hero-links{gap:8px}.gpu-experience .gpu-hero-links a{font-size:12px;padding-inline:13px}.sn-public #main .gpu-experience .gpu-panel-h,.gpu-experience .gpu-side{padding:18px}.sn-public #main .gpu-experience .gpu-card{padding:16px 14px;grid-template-columns:20px minmax(0,1fr);gap:5px 9px}.gpu-experience .gpu-card .gpu-rate{grid-column:2;display:flex;gap:8px;align-items:center;text-align:start}.gpu-experience .gpu-card .gpu-rate small{font-size:10px}.gpu-experience .gpu-app-option{grid-template-columns:49px minmax(0,1fr) 15px;gap:9px;padding:12px 10px}.gpu-experience .gpu-app-option .gpu-art{width:49px}.gpu-experience .gpu-app-option b{font-size:11px}.gpu-experience .gpu-app-option small{font-size:10.5px}.gpu-experience .gpu-apps-grid{grid-template-columns:minmax(0,1fr)}.gpu-experience .gpu-faq details{padding-inline:16px}.gpu-experience .gpu-selected-card{flex-wrap:wrap}.gpu-experience .gpu-name input{font-size:16px}}
@media(prefers-reduced-motion:reduce){.gpu-experience .gpu-art-flow,.gpu-experience .gpu-art-float,.gpu-experience .gpu-app-option:hover .gpu-art-float,.gpu-experience .gpu-app-option:focus-within .gpu-art-float{animation:none}.gpu-experience .gpu-app-option{transition:none}}
@media(max-width:540px){.gpu-experience .gpu-app-option b,.gpu-experience .gpu-app-option small{font-size:12px}}
