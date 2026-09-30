<?php

namespace App\Support\Ops;

use DateTimeInterface;
use JsonSerializable;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Processeur de journal qui retire toute donnée personnelle de la liste
 * close — spec 100 § 10.9.
 *
 * Aucune IP en table de domaine, et l'IP ne vit qu'en journaux purgés tôt
 * (principe 12) : les journaux de l'application ne doivent pas devenir un
 * second dépôt d'adresses, de pseudos ou de réponses. Le processeur retire
 * du contexte ET des données supplémentaires (où `Context` dépose les
 * siennes) toute clé de {@see self::KEYS}, à toute profondeur.
 *
 * Un objet du contexte y passe aussi : le formateur de Monolog en écrit les
 * clés — `jsonSerialize()` d'un modèle Eloquent ou d'une collection, sinon
 * ses propriétés publiques. Le processeur le remplace donc par ce tableau,
 * expurgé. Une exception, une date, une énumération ou un objet convertible
 * en chaîne restent tels quels : le formateur n'en écrit aucune clé.
 *
 * Une clé se compare sous une forme repliée — minuscules, sans `_` ni `-` —,
 * pour que `ipAddress`, `IP_ADDRESS` et `ip-address` désignent la même
 * donnée que `ip_address` : la liste reste close, ses graphies non.
 *
 * Branché sur les canaux de l'application (`single`, `daily`, `game`, et
 * `stack` qui hérite des processeurs de ses canaux) par
 * {@see RedactPersonalDataTap}, et directement sur `stderr`. Il s'exécute
 * avant le remplacement des marqueurs du message : un `{nickname}` écrit dans
 * un message reste le marqueur, jamais le pseudo.
 */
final class RedactPersonalData implements ProcessorInterface
{
    /**
     * La liste close des clés retirées.
     *
     * @var list<string>
     */
    public const array KEYS = ['ip', 'ip_address', 'nickname', 'email', 'player_token', 'answer', 'submitted'];

    /**
     * Profondeur au-delà de laquelle le retrait s'arrête, pour qu'un objet
     * qui se référence lui-même ne fasse pas boucler le processeur. Le
     * formateur de Monolog n'écrit rien au-delà de 9 niveaux : ce qui dépasse
     * est remplacé par {@see self::DEPTH_MARKER}, jamais écrit tel quel.
     */
    private const int MAX_DEPTH = 16;

    public const string DEPTH_MARKER = '[retrait interrompu : trop profond]';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: self::redact($record->context),
            extra: self::redact($record->extra),
        );
    }

    /**
     * Retire récursivement toute clé de la liste close, objets compris.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isPersonal($key)) {
                unset($data[$key]);

                continue;
            }

            $value = self::writtenData($value);

            if (is_array($value)) {
                $data[$key] = $depth >= self::MAX_DEPTH ? self::DEPTH_MARKER : self::redact($value, $depth + 1);
            }
        }

        return $data;
    }

    /**
     * Ce que le formateur de Monolog écrira d'un objet, sous forme de tableau
     * quand il en écrit des clés ; toute autre valeur, inchangée.
     */
    private static function writtenData(mixed $value): mixed
    {
        if (! is_object($value)
            || $value instanceof Throwable
            || $value instanceof DateTimeInterface
            || $value instanceof UnitEnum) {
            return $value;
        }

        if ($value instanceof JsonSerializable) {
            $serialized = $value->jsonSerialize();

            return is_array($serialized) ? $serialized : $value;
        }

        if ($value instanceof Stringable) {
            return $value;
        }

        // Hors de la classe de l'objet : les seules propriétés publiques,
        // exactement celles que l'encodage JSON du formateur écrirait.
        return get_object_vars($value);
    }

    private static function isPersonal(string $key): bool
    {
        static $folded = null;

        $folded ??= array_map(self::fold(...), self::KEYS);

        return in_array(self::fold($key), $folded, true);
    }

    private static function fold(string $key): string
    {
        return str_replace(['_', '-'], '', strtolower($key));
    }
}
