<?php

namespace App\Console\Commands;

use App\Services\ProvisioningService;
use Illuminate\Console\Command;

/**
 * Make sure every active subscription has peers on every eligible node.
 * Normally automatic (a node coming online triggers it); this is the manual
 * lever after bulk edits or restoring a node.
 */
class SyncPeers extends Command
{
    protected $signature = 'mvpn:sync-peers';
    protected $description = 'Provision peers for all active subscriptions on all eligible nodes';

    public function handle(ProvisioningService $provisioning): int
    {
        $count = $provisioning->syncAllActive();
        $this->info("Synced peers for {$count} active subscription(s).");

        return self::SUCCESS;
    }
}
