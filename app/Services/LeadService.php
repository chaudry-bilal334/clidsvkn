<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\User;

class LeadService
{
    /**
     * The legal CRM stage moves (DESIGN §15).
     *
     * The positive path is strict and forward-only; "closed_lost" is the
     * terminal state and may be reached from any active engagement stage.
     *
     * @return array<string, array<int, string>>
     */
    public function transitions(): array
    {
        return [
            Lead::STATUS_NEW => [Lead::STATUS_CONTACTED],
            Lead::STATUS_CONTACTED => [Lead::STATUS_QUALIFIED, Lead::STATUS_CONVERTED, Lead::STATUS_CLOSED_LOST],
            Lead::STATUS_QUALIFIED => [Lead::STATUS_CONVERTED, Lead::STATUS_CLOSED_LOST],
            Lead::STATUS_CONVERTED => [Lead::STATUS_CLOSED_LOST],
            Lead::STATUS_CLOSED_LOST => [],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function availableTransitions(Lead $lead): array
    {
        return $this->transitions()[$lead->status] ?? [];
    }

    /**
     * Abort the request when the requested transition is not permitted.
     */
    public function assertTransitionAllowed(Lead $lead, string $nextStatus): void
    {
        if (! in_array($nextStatus, $this->availableTransitions($lead), true)) {
            abort(422, "A {$lead->status} lead cannot be moved to {$nextStatus}.");
        }
    }

    /**
     * Orchestrate a stage change, capturing the actor and notes in the audit trail.
     */
    public function transition(Lead $lead, string $nextStatus, User $actor, ?string $notes = null): Lead
    {
        $this->assertTransitionAllowed($lead, $nextStatus);

        $previous = $lead->status;

        $lead->status = $nextStatus;

        if ($notes !== null && $notes !== '') {
            $lead->notes = $notes;
        }

        $lead->save();

        AuditLog::record(
            $actor,
            'lead.transitioned',
            $lead,
            oldValues: ['status' => $previous],
            newValues: ['status' => $nextStatus, 'notes' => $lead->notes],
        );

        return $lead;
    }
}
