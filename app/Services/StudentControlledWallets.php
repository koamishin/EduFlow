<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Student;
use App\Models\StudentControlledPayment;
use App\Models\StudentWallet;
use Elliptic\EC;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use kornrunner\Keccak;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

class StudentControlledWallets
{
    public const CHAIN_ID = 5042002;

    public const TOKEN = '0x3600000000000000000000000000000000000000';

    public function __construct(private readonly ArcNetworkGateway $arc) {}

    public function assertTestnet(): void
    {
        if (! config('student-wallets.enabled') || config('lepton.arc.chain') !== 'ARC-TESTNET'
            || (int) config('lepton.arc.chain_id') !== self::CHAIN_ID) {
            throw ValidationException::withMessages(['wallet' => 'Student-controlled payments require enabled Arc Testnet chain 5042002.']);
        }
        if ($this->arc instanceof FakeLeptonGateway) {
            throw ValidationException::withMessages(['wallet' => 'Fake driver cannot create a real student-controlled payment.']);
        }
        if (ChainQuantity::toInt($this->arc->rpc('eth_chainId')) !== self::CHAIN_ID) {
            throw ValidationException::withMessages(['wallet' => 'RPC endpoint is not Arc Testnet 5042002.']);
        }
    }

    public function verifySignature(string $message, string $signature, string $address): bool
    {
        if (preg_match('/^0x[0-9a-fA-F]{130}$/D', $signature) !== 1) {
            return false;
        }
        try {
            $hex = substr($signature, 2);
            $recovery = hexdec(substr($hex, 128, 2));
            $recovery = $recovery >= 27 ? $recovery - 27 : $recovery;
            if (! in_array($recovery, [0, 1], true)) {
                return false;
            }
            $digest = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);
            $key = (new EC('secp256k1'))->recoverPubKey($digest, [
                'r' => substr($hex, 0, 64), 's' => substr($hex, 64, 64),
            ], $recovery);
            $public = hex2bin(substr($key->encode('hex', false), 2));

            return is_string($public) && hash_equals(strtolower($address), '0x'.substr(Keccak::hash($public, 256), -40));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Link a verified wallet as the student's active destination.
     *
     * A switch keeps the previous wallet as a replaced row: past receipts and
     * funding history must still resolve to the address that received the
     * money. Nothing is transferred here — funds stay with the wallet the
     * student still controls.
     *
     * @throws ValidationException when a payment is unresolved, the address
     *                             already belongs to another student, or the
     *                             claimed Circle identity does not match.
     */
    public function link(Student $student, string $address, string $provider, ?string $circleUser = null, ?string $circleWallet = null): StudentWallet
    {
        return DB::transaction(function () use ($student, $address, $provider, $circleUser, $circleWallet): StudentWallet {
            $locked = Student::query()->lockForUpdate()->findOrFail($student->id);
            $active = StudentWallet::query()->where('student_id', $locked->id)->active()->lockForUpdate()->first();
            $address = strtolower($address);

            // Only an approval that never started is safe to discard.
            StudentControlledPayment::query()
                ->where('student_id', $student->id)
                ->whereNull('posted_at')
                ->whereNull('circle_challenge_id')
                ->whereNull('tx_hash')
                ->whereNull('approval_started_at')
                ->delete();

            if (StudentControlledPayment::query()->where('student_id', $student->id)->whereNull('posted_at')
                ->where(function ($query): void {
                    $query->whereNotNull('circle_challenge_id')
                        ->orWhereNotNull('tx_hash')
                        ->orWhereNotNull('approval_started_at');
                })->exists()) {
                throw ValidationException::withMessages(['wallet' => 'Reconcile or cancel your pending payment before changing wallets.']);
            }

            if ($active instanceof StudentWallet && strcasecmp($active->address, $address) === 0) {
                if ($active->provider !== $provider) {
                    throw ValidationException::withMessages(['wallet' => 'This address is already linked through another wallet type. Sign in with that wallet to continue.']);
                }

                return $active;
            }

            $claimed = StudentWallet::query()->whereRaw('lower(address) = ?', [$address])->lockForUpdate()->first();
            if ($claimed instanceof StudentWallet) {
                throw ValidationException::withMessages(['wallet' => 'That wallet is already linked. Reconnect it instead of relinking.']);
            }
            if (Student::query()->whereRaw('lower(payout_address) = ?', [$address])->whereKeyNot($locked->id)->exists()) {
                throw ValidationException::withMessages(['wallet' => 'Wallet is already linked to another student.']);
            }
            if ($circleUser !== null && Student::query()->where('circle_user_id', $circleUser)->whereKeyNot($locked->id)->exists()) {
                throw ValidationException::withMessages(['wallet' => 'That Circle identity is already linked to another student.']);
            }

            if ($active instanceof StudentWallet) {
                $active->update(['status' => StudentWallet::STATUS_REPLACED]);
            }

            $wallet = new StudentWallet([
                'student_id' => $locked->id,
                'address' => $address,
                'provider' => $provider,
                'network' => 'arc',
                'chain' => 'ARC-TESTNET',
                'status' => StudentWallet::STATUS_ACTIVE,
                'generated_at' => now(),
                'ownership_verified_at' => now(),
                'circle_user_id' => $circleUser,
                'circle_wallet_id' => $circleWallet,
            ]);
            $wallet->save();

            if ($circleUser !== null) {
                $locked->forceFill(['circle_user_id' => $circleUser, 'circle_auth_method' => $provider === 'circle-user'
                    ? ($locked->getAttribute('circle_auth_method') ?? 'pin')
                    : $locked->getAttribute('circle_auth_method')])->save();
            }

            app(StudentWalletService::class)->setAddress($locked, $address);

            return $wallet;
        });
    }
}
