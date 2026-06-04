<?php

namespace Database\Factories;

use App\Models\Link;
use App\Models\LinkDeletionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory para LinkDeletionRequest (Fase 4).
 *
 * Estados disponibles:
 *  - default: pending (sin revisar)
 *  - ->approved($reviewer): aprobada por el $reviewer dado
 *  - ->rejected($reviewer, $note): rechazada con nota
 *
 * @extends Factory<LinkDeletionRequest>
 */
class LinkDeletionRequestFactory extends Factory
{
    protected $model = LinkDeletionRequest::class;

    public function definition(): array
    {
        return [
            'link_id'      => Link::factory(),
            'requested_by' => User::factory(),
            'reason'       => fake()->sentence(8),
            'status'       => LinkDeletionRequest::STATUS_PENDING,
            'reviewed_by'  => null,
            'reviewed_at'  => null,
            'review_note'  => null,
        ];
    }

    /**
     * Estado: solicitud pendiente (default, alias explícito para legibilidad en tests).
     */
    public function pending(): static
    {
        return $this->state([
            'status'      => LinkDeletionRequest::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
        ]);
    }

    /**
     * Estado: solicitud aprobada.
     *
     * @param User|null $reviewer  Si null, crea un User con super_admin.
     */
    public function approved(?User $reviewer = null): static
    {
        return $this->state(function () use ($reviewer) {
            $reviewerId = $reviewer ? $reviewer->id : User::factory()->create(['role' => 'super_admin'])->id;
            return [
                'status'      => LinkDeletionRequest::STATUS_APPROVED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => fake()->optional(0.5)->sentence(5),
            ];
        });
    }

    /**
     * Estado: solicitud rechazada con nota explicativa.
     *
     * @param User|null   $reviewer  Si null, crea un User con super_admin.
     * @param string|null $note      Si null, genera una frase aleatoria.
     */
    public function rejected(?User $reviewer = null, ?string $note = null): static
    {
        return $this->state(function () use ($reviewer, $note) {
            $reviewerId = $reviewer ? $reviewer->id : User::factory()->create(['role' => 'super_admin'])->id;
            return [
                'status'      => LinkDeletionRequest::STATUS_REJECTED,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note ?? fake()->sentence(6),
            ];
        });
    }
}
