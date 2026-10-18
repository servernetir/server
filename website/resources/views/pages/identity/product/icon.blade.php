<svg class="ip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($kind)
@case('user')<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>@break
@case('phone')<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10 18h4M10 5h4"/>@break
@case('bank')<path d="m3 8 9-5 9 5M3 21h18M5 10v8m7-8v8m7-8v8"/>@break
@case('utility')<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>@break
@case('key')<circle cx="8" cy="8" r="5"/><path d="m12 12 9 9m-4-4 3-3m-6 0 3-3"/>@break
@case('wallet')<path d="M3 8V6a2 2 0 0 1 2-2h14v4M3 8h17v13H5a2 2 0 0 1-2-2ZM20 12h-5v5h5"/>@break
@case('layers')<path d="m2 7 10-5 10 5-10 5Zm0 5 10 5 10-5M2 17l10 5 10-5"/>@break
@case('search')<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>@break
@default<path d="m5 12 4 4L19 6"/>
@endswitch
</svg>
