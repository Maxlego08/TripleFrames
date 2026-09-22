<?php

namespace App\Support\Frames;

use InvalidArgumentException;
use Random\RandomException;

/**
 * Les deux préfixes du disque privé `frames`, et l'éclatement des chemins.
 *
 * | Préfixe   | Contenu                     | Plafonds de SORTIE | Servable |
 * |-----------|-----------------------------|--------------------|----------|
 * | `game/`   | Dérivé servi, WebP          | 1280 px, 150 Ko    | oui      |
 * | `master/` | Source de re-cadrage, WebP  | 1920 px            | jamais   |
 *
 * La route de service refuse STRUCTURELLEMENT tout chemin ne commençant pas par
 * `game/` : `master/` est non servable par construction et non par convention.
 *
 * Le plafond d'ENTRÉE d'un téléversement est distinct de ces deux plafonds de
 * sortie — il vit dans `PlatformLimits::frameUploadMaxKilobytes()`.
 *
 * Un nom de fichier est tiré de `bin2hex(random_bytes(16))` et JAMAIS d'un ULID :
 * un ULID est trié dans le temps, ses dix premiers caractères encodent l'instant
 * de création et regrouperaient visiblement les images curées dans la même séance,
 * c'est-à-dire celles d'un même film. `game_path` et `master_path` portent deux
 * noms SANS AUCUN LIEN entre eux ({@see self::newPathPair()}) : un nom partagé
 * ferait qu'un service de jeu légitime révélerait le chemin de la source de
 * re-cadrage. Aucun répertoire par film, aucun titre, aucun `tmdb_id`, aucune
 * année dans un chemin ; chemins RELATIFS en base, jamais une URL.
 *
 * Ce n'est PAS un enum de schéma : il ne porte aucune colonne.
 */
enum FrameStoragePrefix: string
{
    /** Dérivé servi au joueur, et le seul. */
    case Game = 'game/';

    /** Source de re-cadrage, jamais servie. */
    case Master = 'master/';

    /** Disque privé, racine venue de `FRAMES_DISK_ROOT`, `'serve' => false`. */
    public const string DISK = 'frames';

    /** Les deux préfixes ne portent que du WebP. */
    public const string EXTENSION = 'webp';

    /** Longueur d'un nom de fichier : 16 octets de CSPRNG en hexadécimal. */
    public const int NAME_BYTES = 16;

    /** Profondeur de l'éclatement en répertoires, calculé sur un condensat du nom. */
    public const int FANOUT_DEPTH = 2;

    /** Largeur d'un segment de répertoire, en caractères hexadécimaux. */
    public const int FANOUT_WIDTH = 2;

    /**
     * Seul préfixe servable à un joueur.
     */
    public function isServable(): bool
    {
        return $this === self::Game;
    }

    /**
     * Plafond de SORTIE, en pixels sur le plus grand côté.
     */
    public function maxPixels(): int
    {
        return match ($this) {
            self::Game => 1280,
            self::Master => 1920,
        };
    }

    /**
     * Plafond de SORTIE en kilooctets, quand il en existe un.
     *
     * Le master n'en porte pas : il n'est jamais servi, il est re-cadré.
     */
    public function maxKilobytes(): ?int
    {
        return match ($this) {
            self::Game => 150,
            self::Master => null,
        };
    }

    /**
     * Chemin relatif complet pour un nom déjà tiré.
     */
    public function pathFor(string $name): string
    {
        if (preg_match('/^[0-9a-f]{'.(self::NAME_BYTES * 2).'}$/', $name) !== 1) {
            throw new InvalidArgumentException('Nom de fichier d\'image hors format.');
        }

        $digest = hash('sha256', $name);
        $path = $this->value;

        for ($level = 0; $level < self::FANOUT_DEPTH; $level++) {
            $path .= substr($digest, $level * self::FANOUT_WIDTH, self::FANOUT_WIDTH).'/';
        }

        return $path.$name.'.'.self::EXTENSION;
    }

    /**
     * Nouveau chemin sous ce préfixe.
     *
     * @throws RandomException
     */
    public function newPath(): string
    {
        return $this->pathFor(self::newName());
    }

    /**
     * Vrai si le chemin appartient à ce préfixe ET respecte la disposition
     * attendue — répertoires compris, recalculés depuis le nom.
     *
     * C'est la garde de la route de service : elle ferme d'un seul geste le
     * préfixe interdit, la traversée de répertoire et le chemin fabriqué.
     */
    public function owns(string $path): bool
    {
        if (! str_starts_with($path, $this->value)) {
            return false;
        }

        $name = basename($path, '.'.self::EXTENSION);

        if ($name === $path || $name === '') {
            return false;
        }

        return $path === $this->pathForOrNull($name);
    }

    /**
     * Préfixe d'un chemin relatif, ou `null` s'il n'en respecte aucun.
     */
    public static function fromPath(string $path): ?self
    {
        foreach (self::cases() as $prefix) {
            if ($prefix->owns($path)) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * Nom de fichier : 16 octets de CSPRNG en hexadécimal, jamais un ULID.
     *
     * @throws RandomException
     */
    public static function newName(): string
    {
        return bin2hex(random_bytes(self::NAME_BYTES));
    }

    /**
     * Les deux chemins d'une même image, tirés indépendamment.
     *
     * @return array{game: string, master: string}
     *
     * @throws RandomException
     */
    public static function newPathPair(): array
    {
        return [
            'game' => self::Game->newPath(),
            'master' => self::Master->newPath(),
        ];
    }

    private function pathForOrNull(string $name): ?string
    {
        try {
            return $this->pathFor($name);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
