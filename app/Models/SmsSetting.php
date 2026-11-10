<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The SMS gateway settings (System > SMS). Always one row (id = 1). The API key is stored encrypted. */
class SmsSetting extends Model
{
    public const DRIVERS = [
        'log' => 'Log only (no SMS is sent)',
        'bulksmsbd' => 'BulkSMSBD (send real SMS)',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'rate' => 'float',
            'bulk_chunk' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }

    public function sends(): bool
    {
        return $this->driver !== 'log';
    }

    /** The balance API on the same host as the SMS API (bulksmsbd.net/api/getBalanceApi). */
    public function balanceUrl(): string
    {
        $parts = parse_url($this->url) ?: [];

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'bulksmsbd.net') . '/api/getBalanceApi';
    }

    /** The one row. Created with the defaults if it is ever missing. */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }
}
