<?php

namespace App\Support\Tmdb;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
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
        $request = $this->http
            ->acceptJson()
            ->baseUrl($this->config->baseUrl)
            ->timeout($this->config->timeoutSeconds)
            ->connectTimeout($this->config->connectTimeoutSeconds)
            ->retry(
                times: $this->config->retryTimes,
                sleepMilliseconds: fn (int $attempt, Throwable $exception): int => $this->backoffMilliseconds($attempt, $exception),
                when: fn (Throwable $exception): bool => $this->isRetryable($exception),
                throw: false,
            );

        return $this->config->usesToken()
            ? $request->withToken($this->config->token())
            : $request;
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
