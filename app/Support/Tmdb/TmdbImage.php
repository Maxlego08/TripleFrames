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
     * C'est le critère d'exclusion de D39 du 28/09 : un visuel auquel TMDB
     * attache une langue porte, le plus souvent, un titre ou un texte
     * incrusté, et rien d'autre ne permet de l'écarter avant qu'un humain
     * l'ait sous les yeux. L'éditeur de la banque ne le propose donc pas (il
     * le compte) et l'ajout le refuse. Le critère ne vaut pas verdict dans
     * l'autre sens : un visuel sans langue peut encore porter un texte qui
     * nomme le film, et l'item `no_identifying_text` de la grille d'exclusion
     * continue de s'appliquer, sur l'image réelle, à ces visuels comme aux
     * captures.
     *
     * Le filtre n'est appliqué ni ici ni par `TmdbClient` : il vit là où il se
     * compte et se refuse, dans `App\Http\Controllers\Admin`.
     */
    public function isLanguageNeutral(): bool
    {
        return $this->languageCode === null;
    }
}
