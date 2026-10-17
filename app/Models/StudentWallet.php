<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\StudentWalletFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A wallet minted for a student by the Circle CLI.
 *
 * Provenance, not the live destination: students.payout_address holds the
 * address the disbursement path pays. This row records who minted it and when.
 *
 * @property int $id
 * @property int $student_id
 * @property string $address
 * @property string $provider
 * @property string $network
 * @property string $chain
 * @property string $status
 * @property CarbonInterface $generated_at
 * @property CarbonInterface|null $ownership_verified_at
 * @property string|null $circle_user_id
 * @property string|null $circle_wallet_id
 * @property CarbonInterface|null $updated_at
 * @property-read Student $student
 * @property-read Collection<int, StudentWalletFunding> $fundings
 */
class StudentWallet extends Model
{
    /** The wallet a student currently receives on and pays from. */
    public const string STATUS_ACTIVE = 'active';

    /** Kept for history and receipts after a deliberate switch; never payable. */
    public const string STATUS_REPLACED = 'replaced';

    /** @use HasFactory<StudentWalletFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id', 'address', 'provider', 'network', 'chain', 'status', 'generated_at',
        'ownership_verified_at', 'circle_user_id', 'circle_wallet_id',
    ];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'ownership_verified_at' => 'datetime',
        ];
    }

    /** @param  Builder<StudentWallet>  $query */
    /** @return Builder<StudentWallet> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return HasMany<StudentWalletFunding, $this> */
    public function fundings(): HasMany
    {
        return $this->hasMany(StudentWalletFunding::class);
    }

    public function latestFunding(): ?StudentWalletFunding
    {
        return $this->fundings()->latest('funded_at')->first();
    }
}
