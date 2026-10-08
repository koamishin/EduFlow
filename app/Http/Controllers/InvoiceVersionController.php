<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CaptureInvoiceVersion;
use App\Actions\ReviewInvoiceVersion;
use App\Http\Requests\ReviewInvoiceVersionRequest;
use App\Http\Requests\StoreInvoiceVersionRequest;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Services\InstallationInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvoiceVersionController extends Controller
{
    public function store(StoreInvoiceVersionRequest $request, CaptureInvoiceVersion $capture, InstallationInstitution $institutions): JsonResponse
    {
        $data = $request->validated();
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('organization_id', $institutions->require()->id)->whereKey($data['invoice_id'])->firstOrFail();
        unset($data['invoice_id']);
        $bill = $capture->handle($request->user(), $invoice, $data);

        return response()->json(['data' => $bill->evidence()]);
    }

    public function show(Request $request, InvoiceVersion $invoiceVersion): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $invoiceVersion);

        return response()->json(['data' => $invoiceVersion->evidence()]);
    }

    public function review(ReviewInvoiceVersionRequest $request, InvoiceVersion $invoiceVersion, ReviewInvoiceVersion $review): JsonResponse
    {
        $data = $request->validated();
        $result = $review->handle($request->user(), $invoiceVersion, $data['expected_digest'], $data['decision'], $data['reason']);

        return response()->json(['data' => ['review_id' => $result->id, 'review_digest' => $result->review_digest,
            'decision' => $result->decision, 'bill' => $invoiceVersion->evidence(), 'can_execute' => false]]);
    }
}
