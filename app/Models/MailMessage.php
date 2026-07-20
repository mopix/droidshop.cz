<?php

namespace App\Models;

use App\Core\Mail\MailKind;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempt to deliver one message on behalf of one tenant.
 *
 * Kept for two reasons: the tenant's plan caps how many e-mails a month the
 * shop may send, and a nájemce asking "did the customer get the order
 * confirmation?" needs an answer that is not a guess.
 */
class MailMessage extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'kind' => MailKind::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
