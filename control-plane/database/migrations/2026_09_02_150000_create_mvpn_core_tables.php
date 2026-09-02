<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MVPN control-plane core schema.
 * See 05-Addendum-MVPN.md §A5.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- one-time passwords (phone/email verification + login) --------
        Schema::create('otp_codes', function (Blueprint $t) {
            $t->id();
            $t->string('identifier');            // phone or email
            $t->string('channel');               // sms | email
            $t->string('code_hash');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->string('request_ip', 45)->nullable();
            $t->timestamps();
            $t->index(['identifier', 'channel']);
        });

        // ---- plans -------------------------------------------------------
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();        // e.g. "m1", "m3", "y1"
            $t->string('name');
            $t->unsignedInteger('days');         // subscription length
            $t->unsignedTinyInteger('max_devices')->default(2);
            $t->unsignedBigInteger('data_cap_mb')->nullable(); // null = unlimited
            $t->json('node_scope')->nullable();  // null = all nodes, or ["region:hk", ...]
            $t->unsignedInteger('price_usd_cents');
            $t->unsignedInteger('price_cny_cents');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ---- nodes -----------------------------------------------------
        Schema::create('nodes', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('region');                // "hk", "jp", "sg", ...
            $t->string('public_host');           // node1.mbunievpn.com
            $t->string('cdn_host')->nullable();
            $t->string('api_base');              // https://node1...:PORT  (agent callbacks not needed; CP pulls)
            $t->string('api_secret');            // per-node bearer token (encrypted cast)
            $t->string('reality_pubkey')->nullable();
            $t->string('reality_short_id')->nullable();
            $t->string('reality_sni')->nullable();
            $t->string('hysteria_port_range')->nullable();
            $t->string('hysteria_cert_sha256')->nullable();
            $t->unsignedInteger('capacity')->default(500);
            $t->string('status')->default('provisioning'); // provisioning|online|degraded|offline|draining
            $t->unsignedInteger('peer_version')->default(0); // bumped on every peer change
            $t->json('health')->nullable();
            $t->timestamp('last_health_at')->nullable();
            $t->timestamps();
        });

        // ---- invoices -------------------------------------------------
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('plan_code');
            $t->string('provider');              // stripe | cryptomus
            $t->string('currency', 3);           // usd | cny
            $t->unsignedInteger('amount_cents');
            $t->string('status')->default('pending'); // pending|paid|failed|expired|refunded
            $t->string('provider_ref')->nullable();   // provider session/order id
            $t->string('idempotency_key')->unique();
            $t->json('meta')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('expires_at')->nullable();  // checkout window
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });

        // ---- webhook events (idempotency + audit) --------------------
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('event_id')->nullable();
            $t->string('type')->nullable();
            $t->string('signature_status');      // ok | bad
            $t->json('payload');
            $t->boolean('processed')->default(false);
            $t->string('result')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'event_id']);
        });

        // ---- subscriptions ------------------------------------------
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('plan_code');
            $t->string('status')->default('pending'); // pending|active|expired|suspended
            $t->string('sub_token', 64)->unique();
            $t->unsignedTinyInteger('max_devices')->default(2);
            $t->unsignedBigInteger('data_used_mb')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'expires_at']);
        });

        // ---- devices ----------------------------------------------
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $t->string('fingerprint');
            $t->string('platform')->nullable();  // android|windows|macos|linux
            $t->string('name')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['subscription_id', 'fingerprint']);
        });

        // ---- peers (subscription x node) --------------------------
        Schema::create('peers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $t->foreignId('node_id')->constrained()->cascadeOnDelete();
            $t->string('protocol');              // vless-reality | hysteria2
            $t->string('remote_id');             // UUID (vless) / username (hy2)
            $t->text('secret')->nullable();      // hy2 password (encrypted cast)
            $t->string('status')->default('active'); // active | disabled
            $t->unsignedBigInteger('bytes_up')->default(0);
            $t->unsignedBigInteger('bytes_down')->default(0);
            $t->timestamps();
            $t->unique(['subscription_id', 'node_id', 'protocol']);
        });

        // ---- alerts (AI anomaly feed) ----------------------------
        Schema::create('alerts', function (Blueprint $t) {
            $t->id();
            $t->string('severity');              // info | warn | critical
            $t->string('source');                // node.health | webhook | provisioning | ...
            $t->string('title');
            $t->text('body')->nullable();
            $t->text('ai_summary')->nullable();
            $t->text('ai_action')->nullable();
            $t->json('context')->nullable();
            $t->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->string('acknowledged_by')->nullable();
            $t->timestamps();
            $t->index(['severity', 'acknowledged_at']);
        });

        // ---- audit log ------------------------------------------
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('actor')->nullable();     // "admin:1" | "system" | "webhook:stripe"
            $t->string('action');
            $t->string('target')->nullable();
            $t->json('meta')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_logs', 'alerts', 'peers', 'devices', 'subscriptions',
            'webhook_events', 'invoices', 'nodes', 'plans', 'otp_codes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
