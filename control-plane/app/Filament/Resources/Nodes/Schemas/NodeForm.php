<?php

namespace App\Filament\Resources\Nodes\Schemas;

use App\Models\Node;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class NodeForm
{
    /** Hostname or IPv4 — no scheme, path, or "@". */
    private const HOST_REGEX = '/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Node')
                    ->description('Create the node here, copy its token, then run install.sh on the VPS with --node-token. Protocol settings are filled in by the agent.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->placeholder('Kuala Lumpur 1'),
                        TextInput::make('region')->required()->placeholder('my'),
                        TextInput::make('public_host')
                            ->required()
                            ->autocomplete('off')
                            ->regex(self::HOST_REGEX)
                            ->placeholder('n1.mbuniehub.com')
                            ->helperText('DNS A record → the VPS IP, Cloudflare DNS only (grey cloud).'),
                        // China university networks (CERNET) often reach abroad far
                        // better over IPv6; clients get an extra "<node> (IPv6)" server.
                        TextInput::make('public_host_v6')
                            ->label('IPv6 host (optional)')
                            ->autocomplete('off')
                            ->regex(self::HOST_REGEX)
                            ->placeholder('n1v6.mbuniehub.com')
                            ->helperText('DNS AAAA record only → the VPS IPv6, Cloudflare DNS only (grey cloud). Adds a "(IPv6)" server for REALITY + Hysteria2.'),
                        // Sits right above the password-type token field, so
                        // browsers autofill the login email here — reject it.
                        TextInput::make('cdn_host')
                            ->autocomplete('off')
                            ->regex(self::HOST_REGEX)
                            ->placeholder('(optional)'),
                        TextInput::make('api_secret')
                            ->label('Node token')
                            // The token is $hidden on the model, so the edit form
                            // starts empty: blank = keep the current token.
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave empty to keep the current token.' : null)
                            ->password()
                            // Stop browsers autofilling the admin password here
                            // (that silently replaced the token and 401'd the agent).
                            ->autocomplete('new-password')
                            ->revealable()
                            ->copyable()
                            ->default(fn () => bin2hex(random_bytes(24)))
                            ->suffixAction(
                                Action::make('regenerate')
                                    ->icon('heroicon-m-arrow-path')
                                    ->requiresConfirmation()
                                    ->modalDescription('A new token locks out the current agent until its agent.env is updated with the new token.')
                                    ->action(fn (Set $set) => $set('api_secret', bin2hex(random_bytes(24))))
                            ),
                        Select::make('status')
                            ->required()
                            ->default('provisioning')
                            ->options(array_combine(Node::STATUSES, Node::STATUSES))
                            ->helperText('draining/disabled are kept as set; other statuses follow the agent health reports.'),
                        TextInput::make('capacity')->required()->numeric()->default(500),
                        TextInput::make('monthly_quota_gb')
                            ->label('Monthly data quota (GB)')
                            ->numeric()->minValue(1)
                            ->placeholder('8000')
                            ->helperText('The hosting plan\'s data allowance (Hostinger KVM 2: 8 TB = 8000). Alert at '.config('services.mvpn.quota_alert_percent', 80).'%.'),
                        TextInput::make('quota_reset_day')
                            ->label('Quota resets on day')
                            ->numeric()->minValue(1)->maxValue(28)->default(1)
                            ->helperText('Day of the month the host\'s billing cycle starts.'),
                        TextInput::make('priority')
                            ->required()
                            ->numeric()
                            ->default(100)
                            ->helperText('Lower numbers are tried first by the app. Example: 10 = primary, 100 = backup.'),
                    ]),

                Section::make('Protocol (filled in by the agent)')
                    ->columns(2)
                    ->collapsed(fn (?Node $record) => filled($record?->reality_pubkey))
                    ->schema([
                        TextInput::make('reality_pubkey'),
                        TextInput::make('reality_short_id'),
                        TextInput::make('reality_sni'),
                        TextInput::make('reality_port')->numeric()->default(443)->label('REALITY port (tcp)'),
                        TextInput::make('hysteria_port')->numeric()->default(443)->label('Hysteria2 port (udp)'),
                        TextInput::make('hysteria_port_range'),
                        TextInput::make('hysteria_cert_sha256')->columnSpanFull(),
                        Textarea::make('hysteria_cert_pem')->rows(4)->columnSpanFull(),
                    ]),

                Section::make('OpenVPN fallback')
                    ->description('Optional: paste an .ovpn profile so OpenVPN can be used as a fallback.')
                    ->columns(2)
                    ->collapsed(fn (?Node $record) => blank($record?->openvpn_config))
                    ->schema([
                        Textarea::make('openvpn_config')
                            ->label('.ovpn profile')
                            ->rows(10)
                            ->columnSpanFull()
                            ->helperText('Supports remote/proto with inline <ca>, <cert>, <key>, <tls-auth> or <tls-crypt>.'),
                        TextInput::make('openvpn_username')->label('Username')->password()->revealable(),
                        TextInput::make('openvpn_password')->label('Password')->password()->revealable(),
                        TextInput::make('openvpn_server_name')
                            ->label('TLS/SNI name')
                            ->placeholder('asia8.libaivps.pw')
                            ->helperText('Leave empty to use public_host.'),
                    ]),

                Section::make('Status')
                    ->columns(3)
                    ->visibleOn('edit')
                    ->schema([
                        Placeholder::make('last_health_at')
                            ->label('Last health report')
                            ->content(fn (?Node $record) => $record?->last_health_at?->diffForHumans() ?? 'never'),
                        Placeholder::make('agent_version')
                            ->label('Agent')
                            ->content(fn (?Node $record) => $record?->agent_version ?? '—'),
                        Placeholder::make('peer_version')
                            ->label('Peer version (applied / desired)')
                            ->content(fn (?Node $record) => ($record?->health['applied_version'] ?? '—') . ' / ' . ($record?->peer_version ?? 0)),
                        Placeholder::make('health')
                            ->label('Health')
                            ->columnSpanFull()
                            ->content(fn (?Node $record) => $record?->health
                                ? json_encode($record->health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                : '—'),
                    ]),
            ]);
    }
}
