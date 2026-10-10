<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Maps an MbunieEduHub identity onto a VPN user (SSO + partner activations).
 */
class EduHubAccounts
{
    /**
     * @param  array{id:int,name:?string,email:string,email_verified_at:?string}  $edu
     *
     * @throws RuntimeException 'email_unverified' | 'email_conflict'
     */
    public function resolve(array $edu, string $via): User
    {
        return DB::transaction(function () use ($edu, $via) {
            $linked = User::where('eduhub_user_id', $edu['id'])->lockForUpdate()->first();
            if ($linked) {
                if (! $linked->name && $edu['name']) {
                    $linked->update(['name' => $edu['name']]);
                }

                return $linked;
            }

            $verified = ! empty($edu['email_verified_at']);
            $byEmail = User::where('email', $edu['email'])->lockForUpdate()->first();

            if ($byEmail) {
                if ($byEmail->eduhub_user_id !== null) {
                    throw new RuntimeException('email_conflict');
                }
                // Only an EduHub-verified email may claim an existing VPN account,
                // otherwise anyone could sign up on EduHub with someone else's email.
                if (! $verified) {
                    throw new RuntimeException('email_unverified');
                }
                $byEmail->forceFill([
                    'eduhub_user_id' => $edu['id'],
                    'name' => $byEmail->name ?: $edu['name'],
                    'email_verified_at' => $byEmail->email_verified_at ?? now(),
                ])->save();
                $this->audit($byEmail, 'eduhub.linked', $via);

                return $byEmail;
            }

            $user = new User(['email' => $edu['email'], 'name' => $edu['name']]);
            $user->forceFill([
                'eduhub_user_id' => $edu['id'],
                'email_verified_at' => $verified ? now() : null,
            ])->save();
            $this->audit($user, 'eduhub.created', $via);

            return $user->refresh(); // pick up DB defaults (status, locale, ...)
        });
    }

    private function audit(User $user, string $action, string $via): void
    {
        AuditLog::create([
            'actor' => "eduhub:$via",
            'action' => $action,
            'target' => "user:{$user->id}",
            'meta' => ['eduhub_user_id' => $user->eduhub_user_id],
        ]);
    }
}
