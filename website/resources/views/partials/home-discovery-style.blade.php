@push('head')
<style>
/* Lightweight, text-first service guide; deliberately independent of carousel JS. */
.sn-discovery{position:relative;padding-block:clamp(42px,5vw,80px)}
.sn-discovery-head{max-width:830px;margin-block-end:28px}
.sn-discovery-head .sn-discovery-kicker{color:var(--cyan);font-weight:700;font-size:.8rem;letter-spacing:.04em;display:flex;align-items:center;gap:8px;margin-bottom:10px}
.sn-discovery-kicker::before{content:"";width:22px;height:2px;background:var(--cyan);border-radius:2px}
.sn-discovery-head h2,.sn-discovery-head h1{font-size:clamp(1.65rem,3vw,2.65rem);line-height:1.5;margin-bottom:12px}
.sn-discovery-head p{max-width:75ch;color:var(--muted);line-height:1.9}
.sn-discovery-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.sn-discovery-card{display:flex;flex-direction:column;gap:12px;min-width:0;padding:24px;border:1px solid var(--line-2);border-radius:20px;background:linear-gradient(145deg,var(--surface-2),var(--surface));position:relative;overflow:hidden}
.sn-discovery-card::before{content:"";position:absolute;inset-inline:24px;top:0;height:1px;background:linear-gradient(90deg,transparent,rgba(34,211,238,.5),transparent);pointer-events:none}
.sn-discovery-card h3{font-size:1.1rem;line-height:1.7;margin:0}
.sn-discovery-card p{color:var(--muted);font-size:.9rem;line-height:1.9;margin:0}
.sn-discovery-card .sn-discovery-detail{border-top:1px solid var(--line);padding-top:12px;margin-top:auto;font-size:.8rem;line-height:1.85}
.sn-discovery-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;color:var(--cyan);border:1px solid rgba(34,211,238,.25);background:rgba(34,211,238,.07)}
.sn-discovery-icon .icon{width:21px;height:21px}
.sn-discovery-link{display:inline-flex;align-items:center;gap:8px;color:var(--cyan);font-weight:700;font-size:.86rem;align-self:flex-start;min-height:36px}
.sn-discovery-link:hover{text-decoration:underline;text-underline-offset:4px}
.sn-discovery-trust{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;align-items:center;border:1px solid var(--line);background:var(--surface);border-radius:18px;margin-top:22px;padding:22px 24px}
.sn-discovery-trust h3{font-size:1rem;margin-bottom:5px}.sn-discovery-trust p{font-size:.85rem;color:var(--muted);line-height:1.8}
.sn-discovery-trust-links{display:flex;flex-wrap:wrap;gap:8px 18px;justify-content:flex-end}
.sn-discovery-hero{padding-block:clamp(72px,10vw,145px) 40px;background:radial-gradient(ellipse at 20% 20%,rgba(45,140,224,.1),transparent 56%)}
.sn-discovery-hero .sn-discovery-head{margin-bottom:0;max-width:850px}
.sn-discovery-hero h1{font-size:clamp(2rem,4.4vw,4rem);line-height:1.35}
.sn-discovery-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:25px}
.sn-discovery-q{padding:18px 0;border-top:1px solid var(--line)}.sn-discovery-q h3{font-size:1rem;margin-bottom:7px}.sn-discovery-q p{color:var(--muted);max-width:90ch;line-height:1.9}
@media(max-width:900px){.sn-discovery-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.sn-discovery-trust{grid-template-columns:1fr}.sn-discovery-trust-links{justify-content:flex-start}}
@media(max-width:560px){.sn-discovery-grid{grid-template-columns:1fr;gap:12px}.sn-discovery-card{padding:19px}.sn-discovery-trust{padding:18px}.sn-discovery-head{margin-bottom:22px}}
</style>
@endpush
