<?php

namespace App\Support\Tmdb;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Le seul objet du projet qui parle à TMDB — et il ne connaît que HTTP.
 *
 * Ce qu'il ne fait pas, et ne fera jamais : aucun filtre métier (notoriété,
 * langue, date, contenu), aucune écriture en base, aucune décision de curation.
 * Il rend des DTO validés ; le service d'import applique les filtres, écrit
 * `movie` / `movie_title` / `alias` / `answer_key` / `movie_tmdb_tag` /
 * `movie_certification` / `movie_projection` et journalise dans `import_run`.
 *
 * **Aucun appel TMDB pendant une partie** (règle 6) : ce client est appelé par
 * une commande artisan et par le back-office de curation, jamais par un chemin
 * de jeu. Rien ici n'est atteignable depuis une route de joueur.
 *
 * **Sans clé, rien ne casse** : {@see self::isConfigured()} rend `false`, la CI
 * tourne, et le catalogue de démonstration reste jouable. Tout appel tenté dans
 * cet état lève un {@see TmdbException} de cas `NotConfigured`, donc un message
 * clair, jamais une erreur d'authentification obscure.
 *
 * **Toute erreur sort typée.** Un `RequestException` de Guzzle porte l'URL
 * complète, donc la clé v3 lorsqu'elle voyage en paramètre : il est capturé
 * ici et jamais propagé.
 */
final class TmdbClient
{
    /**
     * Forme d'un chemin de visuel téléchargeable : un fichier à la racine du
     * serveur d'images, JPEG ou PNG — la forme des `backdrops` de TMDB. Le
     * modificateur `D` refuse un saut de ligne final, que `$` laisserait
     * passer jusque dans l'URL appelée.
     */
    public const string IMAGE_FILE_PATH_PATTERN = '/^\/[A-Za-z0-9_-]+\.(?:jpg|png)$/D';

    /** Mot-clé TMDB de la taille téléchargée à l'ajout d'une image : l'original. */
    private const string ORIGINAL_SIZE = 'original';

    /** Plafond de l'original téléchargé, en kilooctets (spec 20 § 13.7). */
    private const string ORIGINAL_MAX_KILOBYTES_KEY = 'catalog.curation.tmdb_original_max_kilobytes';

    private const int BYTES_PER_KILOBYTE = 1024;

    /** Morceau de lecture du corps d'un visuel : le plafond est vérifié entre deux morceaux. */
    private const int DOWNLOAD_CHUNK_BYTES = 65_536;

    private readonly TmdbConfig $config;

    /**
     * `$config` est facultatif pour que le conteneur puisse construire ce client
     * sans liaison déclarée : il résout `Factory`, échoue à résoudre le value
     * object, et retient sa valeur par défaut. Le passer explicitement sert aux
     * tests qui veulent une configuration hors `config/services.php`.
     */
    public function __construct(private readonly Factory $http, ?TmdbConfig $config = null)
    {
        $this->config = $config ?? TmdbConfig::fromConfig();
    }

    /**
     * Vrai si une des deux variables d'environnement est posée.
     *
     * `false` est un état normal, pas une panne : c'est l'unique chose à
     * interroger avant de proposer un import à l'écran ou de lancer une
     * commande.
     */
    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Les réglages résolus, pour un écran de diagnostic. N'expose ni jeton ni
     * clé : {@see TmdbConfig} les garde privés.
     */
    public function config(): TmdbConfig
    {
        return $this->config;
    }

    /**
     * Une page de balayage. La pagination est un ARGUMENT et jamais un état :
     * c'est `import_run.tmdb_page_cursor` qui porte la reprise.
     *
     * @throws TmdbException
     */
    public function discover(TmdbDiscoverQuery $query, int $page = 1): TmdbPage
    {
        if ($page < 1 || $page > TmdbPage::MAX_PAGE) {
            throw new InvalidArgumentException(
                'Page de balayage hors bornes ['.$page.'] : TMDB plafonne `discover` à '.TmdbPage::MAX_PAGE.'.',
            );
        }

        $parameters = $query->toQueryParameters();
        $parameters['page'] = $page;

        return TmdbPage::fromArray($this->get('/discover/movie', $parameters, 'discover'));
    }

    /**
     * La fiche d'un film, `append_to_response` compris — un seul appel, donc un
     * seul jeton de quota.
     *
     * Rend `null` sur un 404 : un identifiant inconnu de TMDB est une donnée
     * ordinaire d'une voie de collage, pas une panne. Tous les autres échecs
     * lèvent.
     *
     * @throws TmdbException
     */
    public function movie(int $tmdbId): ?TmdbMovie
    {
        $this->assertIdentifier($tmdbId);

        try {
            $payload = $this->get('/movie/'.$tmdbId, [
                'language' => $this->config->language,
                'append_to_response' => TmdbMovie::APPEND_TO_RESPONSE,
            ], 'movie/'.$tmdbId);
        } catch (TmdbException $exception) {
            if ($exception->kind === TmdbErrorKind::NotFound) {
                return null;
            }

            throw $exception;
        }

        return TmdbMovie::fromArray($payload);
    }

    /**
     * Les visuels d'un film. Aucun paramètre `language` n'est envoyé : TMDB
     * filtrerait alors les visuels et déciderait à la place du curateur.
     *
     * Un film sans aucun visuel rend un ensemble VIDE — ce n'est pas une
     * erreur. Un identifiant inconnu, lui, lève : demander les images d'un film
     * qu'on n'a pas lu est un défaut d'appel.
     *
     * @throws TmdbException
     */
    public function images(int $tmdbId): TmdbImageSet
    {
        $this->assertIdentifier($tmdbId);

        return TmdbImageSet::fromArray(
            $this->get('/movie/'.$tmdbId.'/images', [], 'movie/'.$tmdbId.'/images'),
            $tmdbId,
        );
    }

    /**
     * URL publique d'un visuel, `$size` étant un mot-clé TMDB (`original`,
     * `w1280`…). Seul endroit du projet qui connaisse `image_base_url`.
     */
    public function imageUrl(string $filePath, string $size = 'original'): string
    {
        return $this->config->imageUrl($filePath, $size);
    }

    /**
     * Les octets `original` d'un visuel, pour l'ajout d'une image (spec 20
     * § 5.3, contrat C9) : c'est le SERVEUR qui télécharge, et `source_hash`
     * est l'empreinte de ces octets-là, jamais une déclaration du navigateur.
     *
     * **Plafonné** par `catalog.curation.tmdb_original_max_kilobytes` :
     * l'original transite par la mémoire de la requête HTTP qui l'ajoute. Un
     * `Content-Length` déclaré au-delà refuse sans lire le corps ; un corps qui
     * dépasse en cours de lecture est abandonné au plafond. Les deux cas lèvent
     * un {@see TmdbException} de cas `TooLarge`.
     *
     * **Borné dans le temps** : le corps doit arriver en entier dans les
     * `timeout` secondes qui suivent ses en-têtes, sinon la lecture est
     * abandonnée en cas `Transport` (voir {@see self::imageRequest()}).
     *
     * Le serveur d'images de TMDB est public : ni jeton ni clé ne lui sont
     * envoyés — un en-tête `Authorization` n'a rien à faire chez un tiers qui
     * n'en demande pas. L'appel reste refusé sans configuration, comme tout
     * appel de ce client : une intégration désactivée ne parle pas à TMDB.
     *
     * Rend des OCTETS, jamais un DTO : l'action d'ajout et le job ne
     * connaissent aucun type de `App\Support\Tmdb` (`TmdbBoundaryTest`).
     *
     * @throws TmdbException
     */
    public function downloadImage(string $filePath): string
    {
        if (preg_match(self::IMAGE_FILE_PATH_PATTERN, $filePath) !== 1) {
            throw new InvalidArgumentException('Chemin de visuel TMDB hors format.');
        }

        if (! $this->config->isConfigured()) {
            throw TmdbException::notConfigured();
        }

        $maxKilobytes = Config::integer(self::ORIGINAL_MAX_KILOBYTES_KEY);
        $maxBytes = $maxKilobytes * self::BYTES_PER_KILOBYTE;

        try {
            $response = $this->imageRequest()->get($this->config->imageUrl($filePath, self::ORIGINAL_SIZE));
        } catch (ConnectionException $exception) {
            throw TmdbException::transport(self::transportReason($exception), $exception);
        } catch (RequestException $exception) {
            $response = $exception->response;
        }

        $this->guard($response, 'image');

        $declared = trim($response->header('Content-Length'));

        if (preg_match('/^\d+$/', $declared) === 1 && (int) $declared > $maxBytes) {
            throw TmdbException::tooLarge($maxKilobytes);
        }

        // L'échéance part de la réception des en-têtes : chaque tentative,
        // reprise comprise, est déjà bornée par ses délais de connexion et de
        // lecture. Seul le corps, lu en flux après la tentative, ne l'est pas —
        // et partir d'avant l'appel ferait d'une reprise réussie un refus.
        $deadline = Date::now()->addSeconds($this->config->timeoutSeconds);

        $bytes = self::readCapped($response->toPsrResponse()->getBody(), $maxBytes, $maxKilobytes, $deadline);

        if ($bytes === '') {
            throw TmdbException::malformed('image', 'corps vide');
        }

        return $bytes;
    }

    /**
     * Appel GET, statut trié, corps validé en objet.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     *
     * @throws TmdbException
     */
    private function get(string $path, array $query, string $context): array
    {
        if (! $this->config->isConfigured()) {
            throw TmdbException::notConfigured();
        }

        if (! $this->config->usesToken()) {
            $query['api_key'] = $this->config->apiKey();
        }

        try {
            $response = $this->pendingRequest()->get($path, $query);
        } catch (ConnectionException $exception) {
            throw TmdbException::transport(self::transportReason($exception), $exception);
        } catch (RequestException $exception) {
            $response = $exception->response;
        }

        $this->guard($response, $context);

        return TmdbData::object($response->json(), $context);
    }

    /**
     * Requête préparée : authentification, délais explicites, reprise bornée.
     *
     * Le jeton v4 prime sur la clé v3 — un en-tête `Authorization` ne fuite ni
     * dans un journal d'accès, ni dans un `Referer`, ni dans le message d'une
     * exception Guzzle. La clé v3, quand elle est seule, est ajoutée aux
     * paramètres d'appel par {@see self::get()} et non par
     * `withQueryParameters()` : un `query` passé à `get()` remplace celui des
     * options, et la clé disparaîtrait de la moitié des appels.
     */
    private function pendingRequest(): PendingRequest
    {
        $request = $this->resilientRequest()
            ->acceptJson()
            ->baseUrl($this->config->baseUrl);

        return $this->config->usesToken()
            ? $request->withToken($this->config->token())
            : $request;
    }

    /**
     * Requête vers le serveur d'images : même reprise bornée que l'API, sans
     * authentification, et **en flux**, pour que le plafond de taille
     * s'applique pendant la lecture et non après avoir tout reçu.
     *
     * Les délais ne sont PAS ceux de l'API. En flux, Guzzle confie la requête
     * à son gestionnaire de flux PHP, où `timeout` borne chaque lecture de
     * socket et non le transfert entier comme le fait cURL : un serveur qui
     * livre ses octets au goutte-à-goutte tiendrait la requête — et son worker
     * PHP-FPM, voisin de palier sur le VPS — sans limite de temps.
     * `read_timeout` rend explicite la borne par lecture ; la borne TOTALE du
     * corps est l'échéance de {@see self::readCapped()}.
     */
    private function imageRequest(): PendingRequest
    {
        return $this->resilientRequest()->withOptions([
            'stream' => true,
            'read_timeout' => $this->config->timeoutSeconds,
        ]);
    }

    /**
     * Délais explicites et reprise bornée, communs aux deux serveurs.
     */
    private function resilientRequest(): PendingRequest
    {
        return $this->http
            ->timeout($this->config->timeoutSeconds)
            ->connectTimeout($this->config->connectTimeoutSeconds)
            ->retry(
                times: $this->config->retryTimes,
                sleepMilliseconds: fn (int $attempt, Throwable $exception): int => $this->backoffMilliseconds($attempt, $exception),
                when: fn (Throwable $exception): bool => $this->isRetryable($exception),
                throw: false,
            );
    }

    /**
     * Le corps d'un visuel, lu par morceaux, **jamais au-delà du plafond ni
     * de l'échéance** : un serveur qui ment sur `Content-Length`, ou qui n'en
     * envoie pas, ne fait pas grossir la mémoire de la requête au-delà d'un
     * morceau de plus ; un serveur qui livre au goutte-à-goutte est coupé à
     * l'échéance, en panne de transport, avec au plus une lecture de retard.
     *
     * L'échéance est testée AVANT chaque lecture : un corps entièrement reçu
     * n'est jamais refusé pour sa dernière seconde.
     *
     * @throws TmdbException
     */
    private static function readCapped(StreamInterface $body, int $maxBytes, int $maxKilobytes, CarbonInterface $deadline): string
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $bytes = '';

        while (! $body->eof()) {
            if (Date::now()->greaterThan($deadline)) {
                $body->close();

                throw TmdbException::transport('délai de téléchargement du visuel dépassé');
            }

            try {
                $bytes .= $body->read(self::DOWNLOAD_CHUNK_BYTES);
            } catch (RuntimeException $exception) {
                throw TmdbException::transport('lecture du visuel interrompue', $exception);
            }

            if (strlen($bytes) > $maxBytes) {
                $body->close();

                throw TmdbException::tooLarge($maxKilobytes);
            }
        }

        return $bytes;
    }

    /**
     * Les cas d'échec, nommés un par un. Aucun `default` muet : un statut non
     * prévu sort en `UnexpectedStatus` plutôt que de passer pour un succès.
     *
     * @throws TmdbException
     */
    private function guard(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        throw match (true) {
            $status === 401, $status === 403 => TmdbException::unauthorized($status),
            $status === 404 => TmdbException::notFound($context),
            $status === 429 => TmdbException::rateLimited($this->cappedRetryAfterSeconds($response)),
            $status >= 500 => TmdbException::serverError($status),
            default => TmdbException::unexpectedStatus($status),
        };
    }

    /**
     * On ne retente que ce qu'une attente peut résoudre : quota, panne serveur,
     * transport. Jamais un 401 — retenter trois fois une clé invalide ne fait
     * que tripler le temps avant le message utile.
     */
    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }

    /**
     * Raison d'une panne de transport, **assainie**.
     *
     * Le message d'une `ConnectionException` est construit par Guzzle et porte
     * l'URL appelée, donc la clé v3 quand elle voyage en paramètre. Que la
     * version installée efface ou non la chaîne de requête est un accident de
     * résolution Composer, pas une propriété de ce code : on ne repropage
     * jamais ce message. Le code cURL suffit au diagnostic, et l'original
     * reste disponible en `$previous`.
     */
    private static function transportReason(ConnectionException $exception): string
    {
        return preg_match('/\bcURL error (\d+)/', $exception->getMessage(), $matches) === 1
            ? 'cURL error '.$matches[1]
            : $exception::class;
    }

    /**
     * `Retry-After` **borné à la source**.
     *
     * La valeur voyage jusqu'à l'appelant par `TmdbException::$retryAfterSeconds`
     * et invite au câblage d'une mise en sommeil : un `Retry-After: 86400`
     * envoyé par TMDB ou par un intermédiaire mal configuré immobiliserait un
     * worker 24 h, alors que tout le reste du client est soigneusement borné.
     * Le plafond est celui du retrait exponentiel, et c'est le même.
     */
    private function cappedRetryAfterSeconds(Response $response): ?int
    {
        $seconds = self::retryAfterSeconds($response);

        return $seconds === null ? null : min($seconds, $this->config->maxRetryAfterSeconds);
    }

    /**
     * Retrait exponentiel, plafonné. Un `Retry-After` envoyé par TMDB fait
     * autorité sur le calcul : c'est la seule valeur qui connaisse la fenêtre
     * de quota réellement appliquée.
     */
    private function backoffMilliseconds(int $attempt, Throwable $exception): int
    {
        if ($exception instanceof RequestException && $exception->response->status() === 429) {
            $retryAfter = self::retryAfterSeconds($exception->response);

            if ($retryAfter !== null) {
                return min($retryAfter, $this->config->maxRetryAfterSeconds) * 1000;
            }
        }

        $delay = $this->config->retryBaseDelayMs * (2 ** max(0, $attempt - 1));

        return (int) min($delay, $this->config->maxBackoffMs);
    }

    /**
     * En-tête `Retry-After` en secondes, ou `null` s'il est absent ou exprimé
     * en date HTTP — forme que TMDB n'emploie pas et qu'il serait plus risqué
     * d'interpréter que d'ignorer.
     */
    private static function retryAfterSeconds(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        return preg_match('/^\d+$/', $header) === 1 ? (int) $header : null;
    }

    private function assertIdentifier(int $tmdbId): void
    {
        if ($tmdbId < 1) {
            throw new InvalidArgumentException('Identifiant TMDB invalide : ['.$tmdbId.'].');
        }
    }
}
