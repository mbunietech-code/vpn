<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** Admin access is not mass-assignable (no API input may set it): set it here. */
    protected function handleRecordCreation(array $data): Model
    {
        $user = new User;
        $user->forceFill(['is_admin' => (bool) ($data['is_admin'] ?? false)]);
        unset($data['is_admin']);
        $user->fill($data)->save();

        return $user;
    }
}
