<?php

namespace App\Models;

use App\Core\Enums\DomainType;
use App\Core\Enums\SslStatus;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'ssl_status' => SslStatus::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Hosts are compared lowercase: DNS is case-insensitive, so storing
     * mixed case would let the same host resolve to no tenant at all.
     */
    public function setDomainAttribute(string $value): void
    {
        $this->attributes['domain'] = mb_strtolower(trim($value));
    }
}
