<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentTuitionPaymentAttempt;
use App\Services\StudentWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StudentWalletController extends Controller
{
    public function __construct(
        private readonly StudentWalletService $wallets,
    ) {}

    /**
     * The student's own wallet screen: live balance, receive details, and the
     * chain state the number came from.
     */
    public function show(Request $request): Response
    {
        $student = $this->studentFor($request);

        return Inertia::render('student/wallet', [
            'walletLocked' => $student->wallet?->getAttribute('ownership_verified_at') !== null,
            'snapshot' => $this->wallets->snapshot($student)->toArray(),
            'tuitionAttempts' => StudentTuitionPaymentAttempt::query()
                ->where('student_id', $student->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (StudentTuitionPaymentAttempt $attempt): array => StudentTuitionPaymentController::statusData($attempt)),
        ]);
    }

    /**
     * Re-read the chain, bypassing the short cache, so the refresh action shows
     * current state rather than the copy cached moments ago.
     */
    public function refresh(Request $request): JsonResponse
    {
        $student = $this->studentFor($request);

        $this->wallets->forget($student);

        return response()->json([
            'snapshot' => $this->wallets->snapshot($student)->toArray(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $student = $this->studentFor($request);

        $validated = $request->validate([
            // Same pattern the disbursement path enforces. A wallet address is
            // a destination money is sent to, so a shape that Circle would
            // reject must not be accepted here.
            'wallet_address' => [
                'required',
                'string',
                'regex:'.Student::PAYOUT_ADDRESS_PATTERN,
                Rule::unique('students', 'payout_address')->ignore($student->id),
            ],
        ], [
            'wallet_address.regex' => 'Enter a wallet address: 0x followed by exactly 40 hexadecimal characters.',
        ]);

        if ($student->wallet?->getAttribute('ownership_verified_at') !== null
            && strcasecmp($student->wallet->address, $validated['wallet_address']) !== 0) {
            throw ValidationException::withMessages(['wallet_address' => 'Verified payment wallet cannot be replaced here. Reconnect your existing wallet; balances remain at their original address.']);
        }

        $this->wallets->setAddress($student, (string) $validated['wallet_address']);

        return back()->with('status', 'wallet-updated');
    }

    /**
     * The wallet belongs to the authenticated student's own record. No student
     * id is ever taken from the request, so there is no id to forge.
     */
    private function studentFor(Request $request): Student
    {
        abort_unless($request->user()?->hasRole('student'), 403);

        return $request->user()->student()->firstOrFail();
    }
}
