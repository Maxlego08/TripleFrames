<?php

namespace App\Http\Requests\Game;

use App\Enums\ContentReportReason;
use App\Enums\ContentReportScope;
use App\Models\ContentReport;
use App\Support\ContentReport\ContentReporter;
use App\Support\ContentReport\ContentReportTarget;
use App\Support\Identity\PublicId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use LogicException;

/**
 * Le signalement posté par la page publique `/report` (D63 du 07/10).
 *
 * - **Autorisation** : un signaleur — compte connecté, sinon siège du
 *   `player_token` ({@see ContentReporter}) ; personne → 403.
 * - `movie` (identifiant TMDB) et `frame` (`frame.public_id`, facultatif)
 *   désignent la cible, résolue par {@see ContentReportTarget} (404).
 * - `scope` : `frame` seulement si une image est désignée, sinon `movie`.
 * - `reason` : un motif de {@see ContentReportReason} admis pour la portée —
 *   `title_visible`, `wrong_level` et `poor_quality` ne visent qu'une image.
 * - `comment` : facultatif, ≤ {@see ContentReport::COMMENT_MAX_LENGTH}
 *   caractères, rogné, vide → NULL.
 */
class ContentReportStoreRequest extends FormRequest
{
    private ?ContentReporter $reporter = null;

    public function authorize(): bool
    {
        $this->reporter = ContentReporter::fromRequest($this);

        return $this->reporter !== null;
    }

    /**
     * @return array<string, array<int, ValidationRule|Closure|string|Enum|In>>
     */
    public function rules(): array
    {
        $hasFrame = $this->filled('frame');

        return [
            'movie' => ['required', 'integer', 'min:1'],
            'frame' => ['nullable', 'string', 'size:'.PublicId::LENGTH],
            'scope' => ['required', 'string', $hasFrame
                ? Rule::enum(ContentReportScope::class)
                : Rule::in([ContentReportScope::Movie->value])],
            'reason' => ['required', 'string', Rule::enum(ContentReportReason::class), $this->reasonMatchesScope(...)],
            'comment' => ['nullable', 'string', 'max:'.ContentReport::COMMENT_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scope' => __('game.report.fields.scope'),
            'reason' => __('game.report.fields.reason'),
            'comment' => __('game.report.fields.comment'),
        ];
    }

    /** Un motif propre à l'image refusé pour le film entier. */
    private function reasonMatchesScope(string $attribute, mixed $value, Closure $fail): void
    {
        $reason = is_string($value) ? ContentReportReason::tryFrom($value) : null;

        if ($reason !== null && $reason->targetsFrameOnly() && $this->scope() !== ContentReportScope::Frame) {
            $fail(__('game.report.errors.reason_needs_frame'));
        }
    }

    public function reporter(): ContentReporter
    {
        return $this->reporter ?? throw new LogicException('ContentReportStoreRequest : signaleur lu avant l\'autorisation.');
    }

    public function scope(): ContentReportScope
    {
        return ContentReportScope::tryFrom((string) $this->string('scope')) ?? ContentReportScope::Movie;
    }

    public function reason(): ContentReportReason
    {
        return ContentReportReason::from((string) $this->string('reason'));
    }

    public function comment(): ?string
    {
        $comment = trim((string) $this->string('comment'));

        return $comment === '' ? null : $comment;
    }

    /** La cible retenue : l'image seulement pour la portée `frame`. */
    public function target(): ContentReportTarget
    {
        return ContentReportTarget::resolve(
            $this->input('movie'),
            $this->scope() === ContentReportScope::Frame ? $this->input('frame') : null,
        );
    }
}
