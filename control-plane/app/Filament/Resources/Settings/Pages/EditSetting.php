<?php

namespace App\Filament\Resources\Settings\Pages;

use App\Filament\Resources\Settings\SettingResource;
use App\Models\AuditLog;
use Filament\Resources\Pages\EditRecord;

class EditSetting extends EditRecord
{
    protected static string $resource = SettingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->getRecord()->is_secret) {
            $data['value'] = null;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $clear = (bool) ($data['clear'] ?? false);
        unset($data['clear']);

        if ($this->getRecord()->is_secret) {
            if ($clear) {
                $data['value'] = null;
            } elseif (blank($data['value'] ?? null)) {
                unset($data['value']);
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // Which key changed and by whom - never the value.
        if ($this->getRecord()->wasChanged('value')) {
            AuditLog::create([
                'actor' => 'admin:'.auth()->id(),
                'action' => 'setting.updated',
                'target' => 'setting:'.$this->getRecord()->key,
                'meta' => ['secret' => (bool) $this->getRecord()->is_secret],
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
