<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ApproveVendorDestination;
use App\Actions\PrepareVendorDestination;
use App\Http\Requests\ApproveVendorDestinationRequest;
use App\Http\Requests\StoreVendorDestinationRequest;
use App\Models\Vendor;
use App\Models\VendorDestinationVersion;
use App\Services\InstallationInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VendorDestinationController extends Controller
{
    public function store(StoreVendorDestinationRequest $request, PrepareVendorDestination $prepare, InstallationInstitution $institutions): JsonResponse
    {
        $data = $request->validated();
        /** @var Vendor $vendor */
        $vendor = Vendor::query()->where('organization_id', $institutions->require()->id)->whereKey($data['vendor_id'])->firstOrFail();
        $destination = $prepare->handle($request->user(), $vendor, $data['version'], $data['address'], $data['chain'], $data['control_evidence']);

        return response()->json(['data' => ['id' => $destination->id, 'content' => $destination->content(), 'content_digest' => $destination->content_digest, 'can_execute' => false]]);
    }

    public function show(Request $request, VendorDestinationVersion $vendorDestinationVersion): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $vendorDestinationVersion);

        return response()->json(['data' => ['id' => $vendorDestinationVersion->id, 'content' => $vendorDestinationVersion->content(),
            'content_digest' => $vendorDestinationVersion->content_digest, 'content_valid' => $vendorDestinationVersion->hasValidContent(), 'can_execute' => false]]);
    }

    public function approve(ApproveVendorDestinationRequest $request, VendorDestinationVersion $vendorDestinationVersion, ApproveVendorDestination $approve): JsonResponse
    {
        $data = $request->validated();
        $expected = $data['expected_approval'] === 'none' ? null : filter_var($data['expected_approval'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($expected === false) {
            throw ValidationException::withMessages(['expected_approval' => 'A supported approval ID or explicit none is required.']);
        }
        $approval = $approve->handle($request->user(), $vendorDestinationVersion, $data['expected_digest'], $expected, $data['verification_reference']);

        return response()->json(['data' => ['id' => $approval->id, 'approval_digest' => $approval->approval_digest,
            'destination_version_id' => $approval->vendor_destination_version_id, 'payment_approved' => false, 'can_execute' => false]]);
    }
}
