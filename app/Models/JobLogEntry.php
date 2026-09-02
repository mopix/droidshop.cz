<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A long-running job the tenant can see the state of (spec §4.4).
 *
 * The table has existed since wave 0.x with nobody writing to it. The tenant
 * data export is its first caller — a job that can take minutes and that the
 * tenant is waiting on is exactly what §4.4 described.
 */
class JobLogEntry extends Model
{
    use BelongsToTenant;

    public const TYPE_EXPORT = 'tenant_export';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_FAILED = 'failed';

    protected $table = 'jobs_log';

    public $timestamps = false;

    protected $fillable = [
        'type',
        'status',
        'progress',
        'report',
        'created_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'progress' => 'integer',
            'created_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    public function finish(array $report): void
    {
        $this->forceFill([
            'status' => self::STATUS_FINISHED,
            'progress' => 100,
            'report' => $report,
            'finished_at' => now(),
        ])->save();
    }

    public function fail(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'report' => ['error' => $message],
            'finished_at' => now(),
        ])->save();
    }
}
