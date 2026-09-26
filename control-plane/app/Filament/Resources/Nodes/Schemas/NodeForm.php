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
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Node')
                    ->description('Unda node hapa, nakili token, kisha endesha install.sh kwenye VPS kwa --node-token hiyo. Vigezo vya protocol vinajazwa na agent yenyewe.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->placeholder('Kuala Lumpur 1'),
                        TextInput::make('region')->required()->placeholder('my'),
                        TextInput::make('public_host')
                            ->required()
                            ->placeholder('n1.mbuniehub.com')
                            ->helperText('DNS A record → IP ya VPS, Cloudflare DNS only (grey).'),
                        TextInput::make('cdn_host')->placeholder('(hiari)'),
                        TextInput::make('api_secret')
                            ->label('Node token')
                            // The token is $hidden on the model, so the edit form
                            // starts empty: blank = keep the current token.
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText(fn (string $operation) => $operation === 'edit' ? 'Acha tupu kubaki na token ya sasa.' : null)
                            ->password()
                            ->revealable()
                            ->copyable()
                            ->default(fn () => bin2hex(random_bytes(24)))
                            ->suffixAction(
                                Action::make('regenerate')
                                    ->icon('heroicon-m-arrow-path')
                                    ->requiresConfirmation()
                                    ->modalDescription('Token mpya itafanya agent ya sasa ikataliwe hadi install.sh iendeshwe tena kwa token hii.')
                                    ->action(fn (Set $set) => $set('api_secret', bin2hex(random_bytes(24))))
                            ),
                        Select::make('status')
                            ->required()
                            ->default('provisioning')
                            ->options(array_combine(Node::STATUSES, Node::STATUSES))
                            ->helperText('draining/disabled: agent haitabadilisha. Nyingine zinasasishwa na health.'),
                        TextInput::make('capacity')->required()->numeric()->default(500),
                    ]),

                Section::make('Protocol (inajazwa na agent)')
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

                Section::make('Hali')
                    ->columns(3)
                    ->visibleOn('edit')
                    ->schema([
                        Placeholder::make('last_health_at')
                            ->label('Health ya mwisho')
                            ->content(fn (?Node $record) => $record?->last_health_at?->diffForHumans() ?? 'kamwe'),
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
