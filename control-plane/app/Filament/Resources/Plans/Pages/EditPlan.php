<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\PlanResource;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Subscriptions and payments refer to the plan by its code: a plan
            // in use is switched off (Active), never deleted.
            DeleteAction::make()
                ->visible(fn (Plan $record) => ! Subscription::where('plan_code', $record->code)->exists()
                    && ! Invoice::where('plan_code', $record->code)->exists()),
        ];
    }
}
