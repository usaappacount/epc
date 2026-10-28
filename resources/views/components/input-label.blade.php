@props(['value'])

<label {{ $attributes->merge(['class' => 'mb-1 block text-xs font-bold text-slate-600']) }}>
    {{ $value ?? $slot }}
</label>
