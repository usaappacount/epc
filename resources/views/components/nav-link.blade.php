@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white transition'
            : 'inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
