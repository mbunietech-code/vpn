<x-mail::message>
# {{ $alert->severity === 'critical' ? '🚨' : '⚠️' }} {{ $alert->title }}

@if ($alert->node)
**Node:** {{ $alert->node->name }}
@endif

{{ $alert->ai_summary ?: $alert->body }}

@if ($alert->ai_action)
**What to do:** {{ $alert->ai_action }}
@endif

<x-mail::button :url="$url">
Open alerts
</x-mail::button>

Raised {{ $alert->created_at?->format('d M Y H:i') }} · {{ $alert->source }}

Mbunie VPN
</x-mail::message>
