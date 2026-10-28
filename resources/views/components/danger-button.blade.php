<button {{ $attributes->merge(['type' => 'submit', 'class' => 'ledger-button-danger']) }}>
    {{ $slot }}
</button>
