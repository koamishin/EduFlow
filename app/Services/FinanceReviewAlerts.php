<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Pages\Collections;
use App\Filament\Pages\FinanceSupervisor;
use App\Models\CollectionBatchReview;
use App\Models\FinanceWorkflowRun;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Ramsey\Uuid\Uuid;

/** Database delivery is atomic and deduplicated inside queued workflow processing. */
final readonly class FinanceReviewAlerts
{
    public function __construct(private InstallationInstitution $institutions) {}

    public function deliver(int $runId): void
    {
        if (! config('eduflow.background_finance.enabled', false)) {
            return;
        }
        /** @var FinanceWorkflowRun|null $run */
        $run = FinanceWorkflowRun::query()->where('organization_id', $this->institutions->require()->id)->find($runId);
        if ($run === null || ! in_array($run->state, ['waiting_for_review', 'blocked', 'failed'], true)) {
            return;
        }
        if ($run->kind === 'collection_review' && $run->collectionBatch !== null) {
            /** @var CollectionBatchReview|null $review */
            $review = CollectionBatchReview::query()->where('collection_batch_id', $run->collection_batch_id)->first();
            if ($review !== null) {
                $valid = $run->hasValidSource() && $review->hasValidEvidence($run->collectionBatch);
                $run->update(['state' => $valid ? 'completed' : 'blocked', 'last_error' => $valid ? null : 'Collection review integrity failed; independent investigation required.']);
                if ($valid) {
                    return;
                }
            }
        }
        $sent = 0;
        foreach (User::query()->whereNotNull('email_verified_at')->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin']))->get() as $user) {
            $allowed = $run->kind === 'collection_review' && $run->state === 'waiting_for_review'
                ? $run->collectionBatch !== null && Gate::forUser($user)->allows('review', $run->collectionBatch)
                : ($run->state === 'waiting_for_review' ? Gate::forUser($user)->allows('review', $run) : Gate::forUser($user)->allows('view', $run));
            if (! $allowed) {
                continue;
            }
            $id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'finance-run:'.$run->id.':'.$run->state.':'.$user->id);
            $message = Notification::make()->title($run->state === 'waiting_for_review' ? 'Finance review required' : 'Finance workflow needs investigation')
                ->body('Run #'.$run->id.' requires attention in the finance workspace. No payment authorized.')->warning()
                ->actions([Action::make('view')->label('Open finance workspace')->url($run->kind === 'collection_review'
                    ? Collections::getUrl(panel: 'finance') : FinanceSupervisor::getUrl(panel: 'finance'))])->getDatabaseMessage();
            DB::table('notifications')->insertOrIgnore(['id' => $id, 'type' => 'finance_workflow', 'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id, 'data' => json_encode($message, JSON_THROW_ON_ERROR), 'read_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            $sent++;
        }
        if ($sent === 0 && $run->state === 'waiting_for_review') {
            $run->update(['last_error' => 'No eligible independent supervisor found; review remains pending.']);
        }
    }
}
