<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\StudentBillPayment;
use App\Models\StudentControlledPayment;
use App\Models\StudentTuitionPayment;
use App\Models\TuitionAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class StudentControlledCheckout
{
    public function __construct(private readonly StudentControlledWallets $wallets, private readonly ArcNetworkGateway $arc,
        private readonly ArcSettlementVerifier $verifier) {}

    public function create(Student $student, string $kind, int $accountId, int $amount): StudentControlledPayment
    {
        return DB::transaction(function () use ($student, $kind, $accountId, $amount): StudentControlledPayment {
            Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

            return $this->createLocked($student->fresh(), $kind, $accountId, $amount);
        });
    }

    private function createLocked(Student $student, string $kind, int $accountId, int $amount): StudentControlledPayment
    {
        $this->wallets->assertTestnet();
        if (! in_array($kind, ['tuition', 'bill'], true)) {
            throw ValidationException::withMessages(['kind' => 'Unsupported bill type.']);
        }
        $wallet = $student->wallet;
        if (! $wallet || ! $wallet->getAttribute('ownership_verified_at') || ! in_array($wallet->provider, ['external', 'circle-user'], true)
            || $wallet->chain !== 'ARC-TESTNET' || $wallet->status !== 'active'
            || strcasecmp($wallet->address, (string) $student->payout_address) !== 0) {
            throw ValidationException::withMessages(['wallet' => 'Connect and verify your student-controlled testnet wallet first.']);
        }
        $recipient = strtolower((string) config('lepton.arc.treasury'));
        if (preg_match(Student::PAYOUT_ADDRESS_PATTERN, $recipient) !== 1 || $recipient === strtolower($wallet->address)) {
            throw ValidationException::withMessages(['recipient' => 'Institution treasury is not configured for this payment.']);
        }
        $account = $kind === 'tuition'
            ? $student->tuitionAccounts()->findOrFail($accountId)
            : StudentBill::query()->where('student_id', $student->id)->where('status', '!=', StudentBill::STATUS_VOID)->findOrFail($accountId);
        if ($amount <= 0 || $amount > $account->remainingAmount()) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive amount no greater than the outstanding balance.']);
        }
        if ($account instanceof TuitionAccount && $account->heldAmount() > 0) {
            throw ValidationException::withMessages(['account' => 'Resolve the existing operator payment hold first.']);
        }
        if (StudentControlledPayment::query()->where('student_id', $student->id)->whereNull('posted_at')
            ->where(function ($query): void {
                $query->whereNotNull('circle_challenge_id')
                    ->orWhereNotNull('tx_hash')
                    ->orWhereNotNull('approval_started_at');
            })->exists()) {
            throw ValidationException::withMessages(['payment' => 'Reconcile your existing payment before creating another.']);
        }
        StudentControlledPayment::query()
            ->where('student_id', $student->id)
            ->whereNull('posted_at')
            ->whereNull('approval_started_at')
            ->whereNull('circle_challenge_id')
            ->whereNull('tx_hash')
            ->delete();
        $id = (string) Str::uuid();
        $data = '0xa9059cbb'.str_pad(substr($recipient, 2), 64, '0', STR_PAD_LEFT)
            .str_pad(dechex($amount), 64, '0', STR_PAD_LEFT).bin2hex($id);
        $snapshot = ['purpose' => 'student_controlled_testnet_payment', 'student_id' => $student->id,
            'wallet_id' => $wallet->id, 'kind' => $kind, 'account_id' => $accountId, 'chain_id' => 5042002,
            'sender' => strtolower($wallet->address), 'recipient' => $recipient, 'amount_base_units' => $amount,
            'token' => StudentControlledWallets::TOKEN, 'data' => $data, 'reference' => $id,
            'quoted_at' => now()->timestamp, 'testnet_only' => true];
        $payment = new StudentControlledPayment;
        $payment->forceFill(['id' => $id, 'student_id' => $student->id, 'student_wallet_id' => $wallet->id,
            'tuition_account_id' => $kind === 'tuition' ? $accountId : null, 'student_bill_id' => $kind === 'bill' ? $accountId : null,
            'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot),
            'expires_at' => now()->addMinutes(10)])->save();

        return $payment;
    }

    public function confirm(Student $student, StudentControlledPayment $payment, string $hash): StudentControlledPayment
    {
        abort_unless($payment->student_id === $student->id, 404);
        $this->wallets->assertTestnet();
        $s = $payment->snapshot;
        if (! hash_equals($payment->snapshot_digest, PaymentIntent::digest($s))) {
            throw ValidationException::withMessages(['payment' => 'Payment snapshot is invalid.']);
        }
        if ($payment->posted_at !== null) {
            abort_unless($payment->tx_hash === strtolower($hash), 409);

            return $payment;
        }
        if ($payment->tx_hash !== null && $payment->tx_hash !== strtolower($hash)) {
            throw ValidationException::withMessages(['payment' => 'A different transaction is already being reconciled.']);
        }
        $tx = $this->arc->rpc('eth_getTransactionByHash', [$hash]);
        if (! is_array($tx) || strtolower((string) ($tx['to'] ?? '')) !== StudentControlledWallets::TOKEN
            || strtolower((string) ($tx['input'] ?? '')) !== strtolower($s['data'])) {
            throw ValidationException::withMessages(['payment' => 'Transaction does not match this exact payment reference and transfer.']);
        }
        $payment->forceFill(['tx_hash' => strtolower($hash)])->save();
        $evidence = $this->verifier->verify(['chain' => 'ARC-TESTNET', 'chain_id' => 5042002,
            'sender' => $s['sender'], 'recipient' => $s['recipient'], 'amount_base_units' => $s['amount_base_units'],
            'max_fee_base_units' => PHP_INT_MAX], $hash);
        if (($evidence['verdict'] ?? null) !== 'verified') {
            throw ValidationException::withMessages(['payment' => 'Transfer is not yet verified on Arc Testnet. Keep the existing transaction hash; do not pay again.']);
        }

        return DB::transaction(function () use ($payment, $student, $s, $hash, $evidence): StudentControlledPayment {
            Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            $stored = StudentControlledPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($stored->posted_at !== null) {
                abort_unless($stored->tx_hash === strtolower($hash), 409);

                return $stored;
            }
            if (StudentTuitionPayment::query()->where('tx_hash', strtolower($hash))->exists()
                || StudentBillPayment::query()->where('tx_hash', strtolower($hash))->exists()) {
                throw ValidationException::withMessages(['payment' => 'Transaction is already recorded.']);
            }
            $account = $stored->tuition_account_id !== null
                ? TuitionAccount::query()->where('student_id', $student->id)->lockForUpdate()->findOrFail($stored->tuition_account_id)
                : StudentBill::query()->where('student_id', $student->id)->lockForUpdate()->findOrFail($stored->student_bill_id);
            $applied = $account instanceof StudentBill && $account->status === StudentBill::STATUS_VOID ? 0
                : min($s['amount_base_units'], max(0, $account->remainingAmount()));
            if ($account instanceof TuitionAccount) {
                $account->paid_amount += $applied;
                $account->save();
            } else {
                $account->paid_base_units += $applied;
                $account->syncStatus();
            }
            $stored->forceFill(['tx_hash' => strtolower($hash), 'evidence' => $evidence,
                'applied_base_units' => $applied, 'overpayment_base_units' => $s['amount_base_units'] - $applied,
                'posted_at' => now()])->save();

            return $stored;
        });
    }
}
