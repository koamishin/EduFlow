<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $title
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChatSession extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'title',
        'source',
    ];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (ChatSession $session): void {
            if (! $session->uuid) {
                $session->uuid = (string) Str::uuid();
            }
        });
    }

    #[\Override]
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Retrieve the model for a bound value.
     * Supports resolution by both UUID string and numeric ID.
     *
     * @param  Builder<ChatSession>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Builder<ChatSession>
     */
    #[\Override]
    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        if ($field) {
            return parent::resolveRouteBindingQuery($query, $value, $field);
        }

        if (is_numeric($value)) {
            return $query->where('id', (int) $value);
        }

        if (is_string($value) && Str::isUuid($value)) {
            return $query->where('uuid', $value);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id')->orderBy('id');
    }
}
