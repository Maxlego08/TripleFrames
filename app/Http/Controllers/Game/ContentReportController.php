<?php

namespace App\Http\Controllers\Game;

use App\Actions\ContentReport\SubmitContentReport;
use App\Enums\ContentReportReason;
use App\Enums\ContentReportScope;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\ContentReportStoreRequest;
use App\Models\ContentReport;
use App\Support\ContentReport\ContentReporter;
use App\Support\ContentReport\ContentReportTarget;
use App\Support\Game\RevealMovieBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * La page publique « Signaler » d'un film ou d'une image vue en jeu (D63 du
 * 07/10, spec 90 § 4.5 bis) : `GET /report?movie=<tmdb_id>&frame=<public_id>`
 * et son envoi. `noindex` permanent (aucun drapeau d'indexation), coquille
 * `PublicLayout`, domaine `game`.
 *
 * La page montre le titre localisé et l'année du film — **jamais l'image** :
 * aucune route ne sert une image par son `public_id` (anti-triche). Les
 * ayants droit sont renvoyés vers la page de retrait (`takedown.create`),
 * lien composé côté client.
 */
final class ContentReportController extends Controller
{
    /** Clé du flash qui porte l'issue d'un envoi : `sent` ou `already_reported`. */
    public const string FLASH_KEY = 'contentReport';

    public function create(Request $request): Response
    {
        $target = ContentReportTarget::resolve($request->query('movie'), $request->query('frame'));
        $reporter = ContentReporter::fromRequest($request);
        $locale = Locale::tryFrom(App::getLocale()) ?? Locale::cases()[0];
        // La chaîne de repli même de la révélation, `lang` compris.
        $title = RevealMovieBuilder::build($target->movie)['titles'][$locale->value];

        return Inertia::render('report/create', [
            'movie' => [
                'title' => $title['text'],
                'titleLang' => $title['lang'],
                'year' => $target->movie->release_year,
                'tmdb' => $target->movie->tmdb_id,
            ],
            'frame' => $target->frame === null ? null : ['publicId' => $target->frame->public_id],
            'scopes' => array_map(
                static fn (ContentReportScope $scope): string => $scope->value,
                $target->frame === null ? [ContentReportScope::Movie] : ContentReportScope::cases(),
            ),
            'reasons' => array_map(
                static fn (ContentReportReason $reason): array => [
                    'value' => $reason->value,
                    'frameOnly' => $reason->targetsFrameOnly(),
                ],
                ContentReportReason::cases(),
            ),
            'commentMaxLength' => ContentReport::COMMENT_MAX_LENGTH,
            'canReport' => $reporter !== null,
            'alreadyReported' => [
                'movie' => $reporter?->hasReported(ContentReport::targetKeyFor($target->movie, null)) ?? false,
                'frame' => $target->frame === null
                    ? null
                    : ($reporter?->hasReported(ContentReport::targetKeyFor($target->movie, $target->frame)) ?? false),
            ],
        ]);
    }

    /** @throws Throwable */
    public function store(ContentReportStoreRequest $request, SubmitContentReport $submit): RedirectResponse
    {
        $target = $request->target();

        $created = $submit->handle($target, $request->reporter(), $request->reason(), $request->comment());

        Inertia::flash(self::FLASH_KEY, $created ? 'sent' : 'already_reported');

        return to_route('content-report.create', array_filter([
            'movie' => $target->movie->tmdb_id,
            'frame' => $request->filled('frame') ? $request->string('frame')->value() : null,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
