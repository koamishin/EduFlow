<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Organization;
use App\Services\InstitutionFinancePreview;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final readonly class InspectInstitutionFinance implements Tool
{
    public function __construct(
        private InstitutionFinancePreview $preview,
        private Organization $organization,
    ) {}

    public function description(): string
    {
        return 'Read an indicative institution treasury and vendor-policy review. No payments, approvals or reservations; no student setup required.';
    }

    public function handle(Request $request): string
    {
        try {
            return json_encode($this->preview->handle($this->organization), JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            report($exception);

            return 'Preview unavailable. Institution identity, treasury configuration or stored precision could not be verified. No payment was submitted.';
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
