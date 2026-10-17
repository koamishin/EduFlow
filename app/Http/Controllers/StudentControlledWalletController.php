<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\StudentControlledPayment;
use App\Models\StudentWallet;
use App\Services\CircleStudentWallets;
use App\Services\StudentControlledCheckout;
use App\Services\StudentControlledWallets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Yukazakiri\Lepton\Support\Amounts;

class StudentControlledWalletController extends Controller
{
    public function __construct(private readonly StudentControlledWallets $wallets, private readonly CircleStudentWallets $circle) {}

    private function student(Request $request): Student
    {
        abort_unless($request->user()?->hasRole('student'), 403);

        return $request->user()->student()->firstOrFail();
    }

    public function show(Request $request): Response
    {
        $student = $this->student($request);

        return Inertia::render('student/controlled-wallet', [
            'enabled' => (bool) config('student-wallets.enabled'),
            'circleAppId' => config('student-wallets.circle_api_key') ? config('student-wallets.circle_app_id') : null,
            'walletAuthMethod' => $student->getAttribute('circle_auth_method'),
            'socialProviders' => ['google' => config('student-wallets.google_client_id'), 'facebook' => config('student-wallets.facebook_app_id')],
            'wallet' => $student->wallet?->only(['address', 'provider', 'ownership_verified_at']),
            'pastWallets' => StudentWallet::query()->where('student_id', $student->id)->whereKeyNot($student->wallet?->getKey())->latest('id')->get()
                ->map(fn (StudentWallet $wallet): array => ['address' => $wallet->address, 'provider' => $wallet->provider, 'replaced_at' => $wallet->updated_at?->toIso8601String()]),
            'accounts' => $student->tuitionAccounts()->with('academicTerm')->get()->map(fn ($a): array => [
                'id' => $a->id, 'kind' => 'tuition', 'label' => $a->academicTerm->name, 'remaining' => (string) $a->remainingAmount(),
            ])->concat($student->bills()->where('status', '!=', 'void')->get()->map(fn ($b): array => [
                'id' => $b->id, 'kind' => 'bill', 'label' => $b->description, 'remaining' => (string) $b->remainingAmount(),
            ]))->values(),
            'payments' => StudentControlledPayment::query()->where('student_id', $student->id)->latest('id')->limit(20)->get()
                ->map(fn (StudentControlledPayment $p): array => [
                    'id' => $p->id,
                    'tx_hash' => $p->tx_hash,
                    'via_circle' => $p->circle_challenge_id !== null,
                    'approval_started' => $p->approval_started_at !== null,
                    'is_expired' => $p->expires_at->isPast(),
                    'posted' => $p->posted_at !== null,
                    'can_cancel' => $p->posted_at === null && $p->approval_started_at === null && $p->tx_hash === null && $p->circle_challenge_id === null,
                    'actual_fee_base_units' => isset($p->evidence['actual_fee_base_units']) ? (string) $p->evidence['actual_fee_base_units'] : null,
                    'amount' => (string) $p->snapshot['amount_base_units'],
                    'applied' => (string) $p->applied_base_units,
                    'overpayment' => (string) $p->overpayment_base_units,
                ]),
        ]);
    }

    public function challenge(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $this->wallets->assertTestnet();
        $data = $request->validate(['address' => ['required', 'string', 'regex:'.Student::PAYOUT_ADDRESS_PATTERN]]);
        $message = $request->getHost()." requests verification of your student wallet.\nAddress: ".strtolower($data['address'])
            ."\nChain ID: 5042002\nStudent: ".$student->id."\nNonce: ".Str::random(48)."\nExpires: ".now()->addMinutes(5)->toIso8601String();
        $request->session()->put('student_wallet_challenge', ['message' => $message, 'address' => strtolower($data['address']),
            'student_id' => $student->id, 'expires' => now()->addMinutes(5)->timestamp]);

        return response()->json(['message' => $message])->header('Cache-Control', 'no-store');
    }

    public function connect(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $this->wallets->assertTestnet();
        $data = $request->validate(['signature' => ['required', 'string', 'max:132']]);
        $challenge = $request->session()->pull('student_wallet_challenge');
        if (! is_array($challenge) || $challenge['student_id'] !== $student->id || $challenge['expires'] < now()->timestamp
            || ! $this->wallets->verifySignature($challenge['message'], $data['signature'], $challenge['address'])) {
            throw ValidationException::withMessages(['wallet' => 'Wallet signature is invalid or expired. Connect again.']);
        }
        $wallet = $this->wallets->link($student, $challenge['address'], 'external');

        return response()->json(['address' => $wallet->address]);
    }

    public function circleSession(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $this->wallets->assertTestnet();
        if (in_array($student->getAttribute('circle_auth_method'), ['google', 'facebook'], true)) {
            throw ValidationException::withMessages(['circle' => 'Reconnect using your original social wallet login, not PIN.']);
        }
        $student->forceFill(['circle_auth_method' => 'pin'])->save();
        $userId = $student->getAttribute('circle_user_id');
        if (! is_string($userId)) {
            $userId = (string) Str::uuid();
            $student->forceFill(['circle_user_id' => $userId])->save();
        }
        $this->circle->ensureUser($userId);
        $auth = $this->circle->session($userId);
        $request->session()->put('circle_student_auth', ['user_id' => $userId, 'token' => encrypt($auth['userToken']),
            'student_id' => $student->id, 'expires' => now()->addMinutes(50)->timestamp]);
        $existing = $this->circle->wallets($auth['userToken']);
        $challenge = null;
        if ($existing === []) {
            $key = $request->session()->get('circle_student_initialize') ?? (string) Str::uuid();
            $request->session()->put('circle_student_initialize', $key);
            $challenge = $this->circle->request('POST', 'user/initialize', ['idempotencyKey' => $key,
                'blockchains' => ['ARC-TESTNET'], 'accountType' => 'EOA'], $auth['userToken'])['challengeId'];
        }

        return response()->json([...$auth, 'challengeId' => $challenge])->header('Cache-Control', 'no-store');
    }

    public function socialDevice(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $this->wallets->assertTestnet();
        $data = $request->validate(['provider' => ['required', 'in:google,facebook'], 'deviceId' => ['nullable', 'string', 'max:200'], 'resume' => ['sometimes', 'boolean']]);
        $method = $student->getAttribute('circle_auth_method');
        if (($method !== null && $method !== $data['provider'])
            || ($method === null && ($student->getAttribute('circle_user_id') !== null || $student->wallet !== null))) {
            throw ValidationException::withMessages(['circle' => 'Use the original wallet login. Switching custody or social identity requires review.']);
        }
        $configKey = $data['provider'] === 'google' ? 'google_client_id' : 'facebook_app_id';
        abort_unless(config('student-wallets.'.$configKey), 422);
        $context = $request->session()->get('circle_social_context');
        if ($request->boolean('resume')) {
            abort_unless(is_array($context) && $context['student_id'] === $student->id
                && $context['provider'] === $data['provider'] && $context['expires'] > now()->timestamp, 403);
        } else {
            if (! is_string($data['deviceId'] ?? null) || $data['deviceId'] === '') {
                throw ValidationException::withMessages(['deviceId' => 'A wallet SDK device ID is required.']);
            }
            $auth = $this->circle->request('POST', 'users/social/token', ['deviceId' => $data['deviceId'], 'idempotencyKey' => (string) Str::uuid()]);
            $context = ['student_id' => $student->id, 'provider' => $data['provider'], 'expires' => now()->addMinutes(10)->timestamp,
                'credentials' => encrypt($auth), 'nonce' => Str::random(48)];
            $request->session()->put('circle_social_context', $context);
        }

        return response()->json([...decrypt($context['credentials']), 'nonce' => $context['nonce']])->header('Cache-Control', 'no-store');
    }

    public function socialComplete(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $this->wallets->assertTestnet();
        $data = $request->validate(['userToken' => ['required', 'string', 'max:10000'], 'encryptionKey' => ['required', 'string', 'max:10000'],
            'nonce' => ['required', 'string'], 'provider' => ['required', 'in:google,facebook']]);
        $context = $request->session()->pull('circle_social_context');
        abort_unless(is_array($context) && $context['student_id'] === $student->id && $context['expires'] > now()->timestamp
            && $context['provider'] === $data['provider'] && hash_equals($context['nonce'], $data['nonce']), 403);
        $identity = $this->circle->request('GET', 'user', token: $data['userToken']);
        $userId = $identity['id'] ?? null;
        if (! is_string($userId) || ! Str::isUuid($userId) || ($identity['status'] ?? null) !== 'ENABLED') {
            throw ValidationException::withMessages(['circle' => 'Circle did not validate this wallet session.']);
        }
        DB::transaction(function () use ($student, $userId, $data): void {
            $stored = Student::query()->lockForUpdate()->findOrFail($student->id);
            if (($stored->getAttribute('circle_user_id') !== null && $stored->getAttribute('circle_user_id') !== $userId)
                || ($stored->getAttribute('circle_auth_method') !== null && $stored->getAttribute('circle_auth_method') !== $data['provider'])
                || Student::query()->where('circle_user_id', $userId)->whereKeyNot($student->id)->exists()
                || ($stored->wallet !== null && $stored->wallet->getAttribute('circle_user_id') !== $userId)) {
                throw ValidationException::withMessages(['circle' => 'This wallet identity cannot replace the existing student wallet. Use your original login.']);
            }
            $stored->forceFill(['circle_user_id' => $userId, 'circle_auth_method' => $data['provider']])->save();
        });
        $request->session()->put('circle_student_auth', ['user_id' => $userId, 'token' => encrypt($data['userToken']),
            'student_id' => $student->id, 'expires' => now()->addMinutes(50)->timestamp]);
        $challenge = null;
        if ($this->circle->wallets($data['userToken']) === []) {
            $initializeKey = $request->session()->get('circle_social_initialize.'.$userId) ?? (string) Str::uuid();
            $request->session()->put('circle_social_initialize.'.$userId, $initializeKey);
            $challenge = $this->circle->request('POST', 'user/initialize', ['idempotencyKey' => $initializeKey,
                'blockchains' => ['ARC-TESTNET'], 'accountType' => 'EOA'], $data['userToken'])['challengeId'];
        }

        return response()->json(['challengeId' => $challenge])->header('Cache-Control', 'no-store');
    }

    private function circleToken(Request $request, Student $student): string
    {
        $auth = $request->session()->get('circle_student_auth');
        if (! is_array($auth) || $auth['student_id'] !== $student->id || $auth['expires'] <= now()->timestamp) {
            throw ValidationException::withMessages(['circle' => 'Reconnect your Circle wallet session.']);
        }

        return decrypt($auth['token']);
    }

    public function circleConnect(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $this->wallets->assertTestnet();
        $token = $this->circleToken($request, $student);
        $auth = $request->session()->get('circle_student_auth');
        $wallets = $this->circle->wallets($token);
        $wallet = collect($wallets)->first(fn (array $w): bool => ($w['userId'] ?? null) === $auth['user_id']
            && ($w['accountType'] ?? null) === 'EOA' && ($w['state'] ?? null) === 'LIVE');
        if (! is_array($wallet) || preg_match(Student::PAYOUT_ADDRESS_PATTERN, $wallet['address'] ?? '') !== 1) {
            return response()->json(['state' => 'awaiting_wallet', 'message' => 'Circle approval received. Waiting for an active Arc Testnet wallet to become available.'], 202)->header('Cache-Control', 'no-store');
        }
        $this->wallets->link($student, $wallet['address'], 'circle-user', $auth['user_id'], $wallet['id']);

        return response()->json(['state' => 'connected', 'address' => $wallet['address']]);
    }

    public function estimate(Request $request, StudentControlledCheckout $checkout): JsonResponse
    {
        $student = $this->student($request);
        $data = $request->validate(['kind' => ['required', 'in:tuition,bill'], 'account_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,6})?$/']]);

        return response()->json($checkout->previewFee($student, $data['kind'], (int) $data['account_id'], Amounts::fromDecimalString($data['amount'])));
    }

    public function quote(Request $request, StudentControlledCheckout $checkout): JsonResponse
    {
        $student = $this->student($request);
        $data = $request->validate(['kind' => ['required', 'in:tuition,bill'], 'account_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,6})?$/']]);
        $payment = $checkout->create($student, $data['kind'], (int) $data['account_id'],
            Amounts::fromDecimalString($data['amount']));

        return response()->json(['id' => $payment->id, 'snapshot' => $payment->snapshot, ...$checkout->feeEstimate($payment)]);
    }

    public function start(Request $request, StudentControlledPayment $payment): JsonResponse
    {
        $student = $this->student($request);
        abort_unless($payment->student_id === $student->id, 404);
        $this->wallets->assertTestnet();
        $request->validate(['consent' => ['required', 'accepted']]);
        DB::transaction(function () use ($payment, $student): void {
            $stored = StudentControlledPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($stored->expires_at->isPast() || $stored->getAttribute('approval_started_at') !== null
                || $stored->posted_at !== null || $student->wallet?->provider !== 'external'
                || ! hash_equals($stored->snapshot_digest, PaymentIntent::digest($stored->snapshot))
                || strtolower((string) $student->payout_address) !== $stored->snapshot['sender']) {
                throw ValidationException::withMessages(['payment' => 'Payment expired, changed or already authorized. Reconcile the existing transfer; do not resend.']);
            }
            $stored->forceFill(['approval_started_at' => now()])->save();
        });

        return response()->json(['approved' => true]);
    }

    public function circlePay(Request $request, StudentControlledPayment $payment): JsonResponse
    {
        $student = $this->student($request);
        abort_unless($payment->student_id === $student->id, 404);
        $this->wallets->assertTestnet();
        $request->validate(['consent' => ['required', 'accepted']]);
        if (($payment->expires_at->isPast() && $payment->approval_started_at === null) || $payment->posted_at !== null || $student->wallet?->provider !== 'circle-user'
            || $student->wallet->id !== $payment->student_wallet_id
            || ! hash_equals($payment->snapshot_digest, PaymentIntent::digest($payment->snapshot))) {
            throw ValidationException::withMessages(['payment' => 'Payment expired or wallet changed.']);
        }
        $token = $this->circleToken($request, $student);
        if ($payment->circle_challenge_id !== null) {
            return response()->json(['challengeId' => $payment->circle_challenge_id]);
        }
        DB::transaction(function () use ($payment): void {
            $stored = StudentControlledPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($stored->getAttribute('approval_started_at') === null) {
                $stored->forceFill(['approval_started_at' => now()])->save();
            }
        });
        $s = $payment->snapshot;
        $data = $this->circle->request('POST', 'user/transactions/contractExecution', [
            'idempotencyKey' => $payment->id, 'walletId' => $student->wallet->getAttribute('circle_wallet_id'),
            'contractAddress' => StudentControlledWallets::TOKEN, 'callData' => $s['data'],
            'feeLevel' => 'MEDIUM', 'refId' => $payment->id,
        ], $token);
        if (! is_string($data['challengeId'] ?? null) || ! Str::isUuid($data['challengeId'])) {
            throw ValidationException::withMessages(['circle' => 'Circle did not return a valid approval challenge. Resume this existing payment to recover the same request; do not create another payment.']);
        }
        $payment->forceFill(['circle_challenge_id' => $data['challengeId']])->save();

        return response()->json(['challengeId' => $data['challengeId']]);
    }

    public function circleStatus(Request $request, StudentControlledPayment $payment, StudentControlledCheckout $checkout): JsonResponse
    {
        $student = $this->student($request);
        abort_unless($payment->student_id === $student->id, 404);
        $this->wallets->assertTestnet();
        $token = $this->circleToken($request, $student);
        if ($payment->posted_at !== null) {
            return response()->json(['posted' => true, 'tx_hash' => $payment->tx_hash]);
        }
        if ($payment->circle_challenge_id === null) {
            throw ValidationException::withMessages(['circle' => 'No approval challenge is saved for this request. If you use Circle, resume the existing Circle approval to recover it. If you used a browser wallet, verify its transaction hash.']);
        }
        $challenge = $this->circle->request('GET', 'user/challenges/'.$payment->circle_challenge_id, token: $token)['challenge'];
        $ids = $challenge['correlationIds'] ?? [];
        if (count($ids) !== 1 || ! Str::isUuid($ids[0])) {
            return response()->json(['posted' => false, 'state' => $challenge['status'] ?? 'PENDING']);
        }
        $transaction = $this->circle->request('GET', 'transactions/'.$ids[0], token: $token)['transaction'];
        if (($transaction['walletId'] ?? null) !== $student->wallet?->getAttribute('circle_wallet_id')
            || ($transaction['blockchain'] ?? null) !== 'ARC-TESTNET') {
            throw ValidationException::withMessages(['circle' => 'Circle transaction does not match the student wallet and testnet.']);
        }
        $hash = $transaction['txHash'] ?? null;
        if (($transaction['state'] ?? null) !== 'COMPLETE' || ! is_string($hash)) {
            return response()->json(['posted' => false, 'state' => $transaction['state'] ?? 'PENDING']);
        }
        $stored = $checkout->confirm($student, $payment, $hash);

        return response()->json(['posted' => $stored->posted_at !== null, 'tx_hash' => $stored->tx_hash]);
    }

    public function cancel(Request $request, StudentControlledPayment $payment): JsonResponse
    {
        $student = $this->student($request);
        abort_unless($payment->student_id === $student->id, 403);

        DB::transaction(function () use ($student, $payment): void {
            Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            $stored = StudentControlledPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($stored->posted_at !== null || $stored->approval_started_at !== null
                || $stored->tx_hash !== null || $stored->circle_challenge_id !== null) {
                throw ValidationException::withMessages(['payment' => 'Approval already started. Its outcome may be unknown: verify the existing transaction rather than clearing it or paying again.']);
            }
            $stored->delete();
        });

        return response()->json(['cancelled' => true]);
    }

    public function confirm(Request $request, StudentControlledPayment $payment, StudentControlledCheckout $checkout): JsonResponse
    {
        $student = $this->student($request);
        $data = $request->validate(['tx_hash' => ['required', 'regex:/^0x[0-9a-fA-F]{64}$/D']]);
        $stored = $checkout->confirm($student, $payment, $data['tx_hash']);

        return response()->json(['posted' => $stored->posted_at !== null, 'applied' => (string) $stored->applied_base_units]);
    }
}
