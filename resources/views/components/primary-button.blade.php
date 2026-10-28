<button {{ $attributes->merge(['type' => 'submit', 'class' => 'ledger-button-primary']) }}>
    {{ $slot }}
</button>
