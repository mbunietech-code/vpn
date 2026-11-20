<?php

namespace App\Filament\Resources\Nodes\Pages;

use App\Filament\Resources\Nodes\NodeResource;
use App\Models\AuditLog;
use App\Models\Node;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNode extends EditRecord
{
    protected static string $resource = NodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A node that still serves customers is set to disabled/draining
            // first, so nobody's VPN drops by a mis-click.
            DeleteAction::make()
                ->visible(fn (Node $record) => ! $record->peers()->where('status', 'active')->exists())
                ->before(fn (Node $record) => AuditLog::create([
                    'actor' => 'admin:'.auth()->id(),
                    'action' => 'node.deleted',
                    'target' => "node:{$record->id}",
                    'meta' => ['name' => $record->name, 'host' => $record->public_host],
                ])),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->data['remove_openvpn'] ?? false) {
            $data = array_merge($data, ['openvpn_config' => null, 'openvpn_username' => null, 'openvpn_password' => null]);
        }

        return $data;
    }
}
