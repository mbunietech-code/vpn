<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Peer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'secret' => 'encrypted',
        'openvpn_profile' => 'encrypted', // holds the client private key
    ];

    protected $hidden = ['openvpn_profile'];

    /** @return BelongsTo<Subscription, Peer> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Node, Peer> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
