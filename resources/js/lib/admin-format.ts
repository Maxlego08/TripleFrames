/**
 * Mise en forme des nombres, des instants et des durées du back-office.
 *
 * **Tout est fait côté client, et ce n'est pas un choix esthétique** :
 * `Number::format` lève une `RuntimeException` dans cet environnement —
 * ni `intl`, ni `gd`, ni `exif` — et la règle 4 interdit de toute façon qu'une
 * chaîne pré-formatée parte du serveur. Les contrôleurs expédient donc des
 * entiers et des instants ISO-8601 ; ce module les rend lisibles.
 *
 * Aucune fonction n'écrit de texte : ce qui manque revient `null`, et c'est à
 * l'écran de choisir entre `admin.common.none` et `admin.common.unknown`.
 */

/** Un entier lisible : séparateurs de milliers de la locale active. */
export function formatInteger(value: number, locale: string): string {
    return new Intl.NumberFormat(locale).format(value);
}

/**
 * Un pourcentage reçu sur l'échelle 0-100, au dixième, avec le signe et
 * l'espacement de la locale active (« 64 % ») : jamais un « % » écrit dans
 * un texte.
 */
export function formatPercent(value: number, locale: string): string {
    return new Intl.NumberFormat(locale, {
        style: 'percent',
        maximumFractionDigits: 1,
    }).format(value / 100);
}

/** Un instant ISO-8601 en date et heure courtes, ou `null` s'il manque. */
export function formatMoment(
    iso: string | null | undefined,
    locale: string,
): string | null {
    const date = parseMoment(iso);

    if (date === null) {
        return null;
    }

    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(date);
}

/** Un jour nu `YYYY-MM-DD` — une date de sortie n'a pas d'heure. */
export function formatDay(
    day: string | null | undefined,
    locale: string,
): string | null {
    const date = parseMoment(day);

    if (date === null) {
        return null;
    }

    return new Intl.DateTimeFormat(locale, { dateStyle: 'short' }).format(date);
}

/** Un mois `YYYY-MM-01`, sans suggérer que le jour exact est conservé. */
export function formatMonth(
    month: string | null | undefined,
    locale: string,
): string | null {
    const date = parseMoment(month);

    if (date === null) {
        return null;
    }

    return new Intl.DateTimeFormat(locale, {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(date);
}

/**
 * Une durée entre deux instants, en unités de la locale — jamais en chaîne
 * composée à la main, qui serait un texte en dur déguisé.
 *
 * Sous quatre-vingt-dix secondes on lit des secondes : c'est l'ordre de
 * grandeur d'un collage court, et l'arrondir à « 1 min » effacerait l'écart
 * qu'un curateur cherche justement à mesurer.
 */
export function formatDuration(
    fromIso: string | null | undefined,
    toIso: string | null | undefined,
    locale: string,
): string | null {
    const from = parseMoment(fromIso);
    const to = parseMoment(toIso);

    if (from === null || to === null) {
        return null;
    }

    return formatSeconds(
        Math.round((to.getTime() - from.getTime()) / 1000),
        locale,
    );
}

/**
 * Une durée donnée en secondes, en unités de la locale : secondes sous
 * quatre-vingt-dix secondes, minutes sous quatre-vingt-dix minutes, heures
 * au dixième au-delà. Sert aussi au tableau du débit (spec 20 § 10.2), où
 * médiane et p90 arrivent en secondes entières.
 */
export function formatSeconds(value: number, locale: string): string {
    const seconds = Math.max(0, Math.round(value));

    if (seconds < 90) {
        return formatUnit(seconds, 'second', locale);
    }

    if (seconds < 5400) {
        return formatUnit(Math.round(seconds / 60), 'minute', locale);
    }

    return formatUnit(Math.round(seconds / 360) / 10, 'hour', locale);
}

/**
 * Une durée exacte à la seconde : heures, minutes et secondes de la locale,
 * les unités nulles omises, jointes par la liste étroite de la locale.
 *
 * Sert au verdict du pilote (spec 20 § 10.3), recopié tel quel dans son
 * compte rendu : le temps actif total et le p90 s'y comparent à des seuils
 * ronds, et un arrondi au dixième d'heure ou à la minute afficherait « 10 h »
 * au-dessus comme au-dessous d'un seuil de 10 h.
 */
export function formatPreciseDuration(value: number, locale: string): string {
    const total = Math.max(0, Math.round(value));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;
    const parts: string[] = [];

    if (hours > 0) {
        parts.push(formatUnit(hours, 'hour', locale));
    }

    if (minutes > 0) {
        parts.push(formatUnit(minutes, 'minute', locale));
    }

    if (seconds > 0 || parts.length === 0) {
        parts.push(formatUnit(seconds, 'second', locale));
    }

    return new Intl.ListFormat(locale, {
        style: 'narrow',
        type: 'unit',
    }).format(parts);
}

/** Une durée en heures, au dixième : seuils et projections du débit. */
export function formatHours(seconds: number, locale: string): string {
    return formatUnit(Math.round(seconds / 360) / 10, 'hour', locale);
}

/** Un nombre de semaines, au dixième : projection du débit. */
export function formatWeeks(weeks: number, locale: string): string {
    return formatUnit(Math.round(weeks * 10) / 10, 'week', locale);
}

/**
 * Un instant est-il plus vieux que `seconds` ?
 *
 * Sert au seul diagnostic « aucun worker ne prend le travail » : un balayage
 * en file depuis plus d'une minute est le symptôme quotidien en développement,
 * et il doit rester lisible par un non-technicien.
 */
export function isOlderThan(
    iso: string | null | undefined,
    seconds: number,
): boolean {
    const date = parseMoment(iso);

    if (date === null) {
        return false;
    }

    return Date.now() - date.getTime() > seconds * 1000;
}

/** Un masque de bits en cinq booléens, du niveau 1 au niveau 5. */
export function levelsFromMask(mask: number): boolean[] {
    return [0, 1, 2, 3, 4].map((offset) => (mask & (1 << offset)) !== 0);
}

function formatUnit(
    value: number,
    unit: 'second' | 'minute' | 'hour' | 'week',
    locale: string,
): string {
    return new Intl.NumberFormat(locale, {
        style: 'unit',
        unit,
        unitDisplay: 'short',
        maximumFractionDigits: 1,
    }).format(value);
}

function parseMoment(iso: string | null | undefined): Date | null {
    if (iso === null || iso === undefined || iso === '') {
        return null;
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? null : date;
}
