<?php

namespace App\Support\Tmdb;

/**
 * Un visuel TMDB — une référence, jamais des octets.
 *
 * `filePath` est la référence conservée POUR TOUJOURS dans
 * `frame.tmdb_file_path` : c'est elle qui rend un master retéléchargeable au
 * lieu d'être sauvegardé en octets, et c'est pourquoi elle n'est jamais effacée
 * après traitement (§ 10). Elle ne quitte jamais le serveur vers un joueur : ce
 * que le client de jeu reçoit est un `round_tier.serve_token`.
 *
 * L'URL de téléchargement se construit par {@see TmdbClient::imageUrl()}, qui
 * est le seul endroit connaissant `image_base_url` et les mots-clés de taille.
 */
final readonly class TmdbImage
{
    public function __construct(
        public string $filePath,
        public int $width,
        public int $height,
        public float $aspectRatio,
        public ?string $languageCode,
        public float $voteAverage,
        public int $voteCount,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $context): self
    {
        return new self(
            filePath: TmdbData::text($data, 'file_path', $context),
            width: TmdbData::counter($data, 'width', $context),
            height: TmdbData::counter($data, 'height', $context),
            aspectRatio: TmdbData::decimal($data, 'aspect_ratio', $context),
            languageCode: TmdbData::optionalText($data, 'iso_639_1', $context),
            voteAverage: TmdbData::decimal($data, 'vote_average', $context),
            voteCount: TmdbData::counter($data, 'vote_count', $context),
        );
    }

    /**
     * Vrai si TMDB n'attache aucune langue au visuel — `iso_639_1` nul ou vide.
     *
     * Lecture d'une propriété, jamais un verdict : qu'un visuel sans langue soit
     * plus souvent dépourvu de texte est une régularité utile à la curation,
     * mais la règle « aucun texte qui nomme le film » est un item de la grille
     * d'exclusion, tranché par un humain sur l'image réelle.
     */
    public function isLanguageNeutral(): bool
    {
        return $this->languageCode === null;
    }
}
