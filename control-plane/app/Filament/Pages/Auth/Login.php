<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Admin sign-in: "Keep me signed in" is ticked by default (the owner works
 * from a phone and kept being asked to sign in again).
 */
class Login extends BaseLogin
{
    public function getHeading(): string|Htmlable|null
    {
        return 'Admin sign in';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Sales, customers and the VPN network.';
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()
            ->label('Keep me signed in on this device')
            ->default(true);
    }
}
