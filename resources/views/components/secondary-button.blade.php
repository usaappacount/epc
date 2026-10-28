<button {{ $attributes->merge(['type' => 'button', 'class' => 'ledger-button-secondary']) }}>
    {{ $slot }}
</button>
