<div style="text-align:center">
    <img src="{{ $url }}" alt="Payment proof"
         style="max-width:100%;border-radius:10px;border:1px solid #e6ebf3">
    @if ($note)
        <p style="margin-top:12px;font-size:13px;opacity:.75">
            <strong>Note ya mtumiaji:</strong> {{ $note }}
        </p>
    @endif
</div>
