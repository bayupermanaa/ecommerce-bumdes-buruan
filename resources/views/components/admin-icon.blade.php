@props(['name'])

<svg {{ $attributes->class(['h-5 w-5 shrink-0']) }} xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('layout-dashboard')
            <rect x="3" y="3" width="7" height="9" rx="1.5" /><rect x="14" y="3" width="7" height="5" rx="1.5" /><rect x="14" y="12" width="7" height="9" rx="1.5" /><rect x="3" y="16" width="7" height="5" rx="1.5" />
            @break
        @case('credit-card')
            <rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20M6 15h4" />
            @break
        @case('store')
            <path d="M3 10h18l-1.5-6h-15L3 10Z" /><path d="M3 10v9a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-9M9 20v-6h6v6M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0" />
            @break
        @case('users')
            <path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 4.5a4 4 0 0 1 0 7.5M22 20v-2a4 4 0 0 0-3-3.87" /><circle cx="9" cy="7" r="4" />
            @break
        @case('package-check')
            <path d="m12 2 9 5-9 5-9-5 9-5ZM3 7v10l9 5 9-5V7M12 12v10m4-5 2 2 3-3" />
            @break
        @case('file-pen')
            <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h9M13 2v5h5M13 2l5 5v4M8 12h6M8 16h3m5.5 3.5 4-4a1.5 1.5 0 0 0-2-2l-4 4-.5 2.5 2.5-.5Z" />
            @break
        @case('tags')
            <path d="M2 7V4a2 2 0 0 1 2-2h3M7 7 20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h6" /><circle cx="17" cy="11" r="1" />
            @break
        @case('landmark')
            <path d="m3 9 9-6 9 6H3ZM4 21h16M6 9v9m4-9v9m4-9v9m4-9v9M3 18h18" />
            @break
        @case('handshake')
            <path d="m2 12 4-4 4 2 3-2 5 2 4 4-5 5-3-2-3 2-3-2-2 1-4-6ZM6 8l3-3 4 1 3-1 2 3M8 17l3-3m0 5 3-3m0 1 2-2" />
            @break
        @case('wallet')
            <rect x="2" y="5" width="20" height="16" rx="2" /><path d="M2 9h20M6 5V3h12v2M16 15h4" />
            @break
        @case('badge-check')
            <path d="m12 2 2.5 2 3.2-.1.9 3.1 2.6 1.9-1 3.1 1 3.1-2.6 1.9-.9 3.1-3.2-.1L12 22l-2.5-2-3.2.1-.9-3.1-2.6-1.9 1-3.1-1-3.1 2.6-1.9.9-3.1 3.2.1L12 2Z" /><path d="m8.5 12 2.3 2.3 4.7-4.7" />
            @break
        @case('banknote')
            <rect x="2" y="5" width="20" height="14" rx="2" /><circle cx="12" cy="12" r="3" /><path d="M5 9h.01M19 15h.01" />
            @break
        @case('scan-search')
            <path d="M4 8V5a1 1 0 0 1 1-1h3m8 0h3a1 1 0 0 1 1 1v3M4 16v3a1 1 0 0 0 1 1h3m8 0h3a1 1 0 0 0 1-1v-3" /><circle cx="11" cy="11" r="4" /><path d="m14 14 3 3" />
            @break
        @case('eye')
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" /><circle cx="12" cy="12" r="3" />
            @break
    @endswitch
</svg>
