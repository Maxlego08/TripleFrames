import { Link } from '@inertiajs/react';
import { useTranslations } from '@/hooks/use-translations';
import { ACCOUNT_ACTION_KEYS, USER_ROLE_KEYS } from '@/lib/admin-enum-keys';
import { formatMoment } from '@/lib/admin-format';
import { show as usersShow } from '@/routes/admin/users';
import type { AdminAccountHistoryLine } from '@/types/admin';
import type { TranslationKey } from '@/types/translations';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

/**
 * Les deux acteurs RÉSERVÉS du journal (`AdminAction::SYSTEM_ACTOR`,
 * `AdminAction::CONSOLE_ACTOR`) : jamais un nom réel — la garde du modèle
 * refuse qu'un compte les porte —, donc sans ambiguïté possible. Ils se
 * nomment à l'écran par leur sens, jamais par leur valeur technique.
 *
 * Une `Map` et non un objet littéral : `actor_name` est un texte libre, et un
 * nom réel « __proto__ » ou « constructor » lu dans un objet rendrait un
 * prototype ou une fonction au lieu de `undefined` — et l'écran entier
 * tomberait au rendu.
 */
const RESERVED_ACTOR_KEYS = new Map<string, TranslationKey>([
    ['console', 'admin.access.history.actor_reserved.console'],
    ['system', 'admin.access.history.actor_reserved.system'],
]);

function actorLabel(
    actorName: string,
    t: (key: TranslationKey) => string,
): string {
    const key = RESERVED_ACTOR_KEYS.get(actorName);

    return key === undefined ? actorName : t(key);
}

type Props = {
    lines: AdminAccountHistoryLine[];
    /**
     * Ajoute la colonne du compte visé : l'écran des accès mêle plusieurs
     * comptes, la fiche d'un compte n'en montre qu'un.
     */
    showSubject?: boolean;
};

/**
 * L'historique daté des accès, lu dans le journal `admin_action` — il
 * n'existe aucune table `role_history` (spec 10 A15).
 *
 * L'auteur est rendu par son **instantané signé** (`actor_name`) : le nom
 * sous lequel le geste a été fait, que la correction ultérieure d'un nom réel
 * ne réécrit jamais. Un changement de rôle se lit « avant → après » ; les
 * rôles passent par la table typée des libellés, jamais par une valeur brute.
 */
export function AccountHistoryTable({ lines, showSubject = false }: Props) {
    const { t, locale } = useTranslations();

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>{t('admin.access.history.column.at')}</TableHead>
                    {showSubject && (
                        <TableHead>
                            {t('admin.access.history.column.subject')}
                        </TableHead>
                    )}
                    <TableHead>
                        {t('admin.access.history.column.action')}
                    </TableHead>
                    <TableHead>
                        {t('admin.access.history.column.change')}
                    </TableHead>
                    <TableHead>
                        {t('admin.access.history.column.actor')}
                    </TableHead>
                    <TableHead>
                        {t('admin.access.history.column.reason')}
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {lines.map((line) => (
                    <TableRow key={line.id}>
                        <TableCell className="align-top whitespace-nowrap">
                            {formatMoment(line.created_at, locale) ??
                                t('admin.common.unknown')}
                        </TableCell>
                        {showSubject && (
                            <TableCell className="align-top">
                                {line.subject === null ? (
                                    t('admin.common.deleted_account')
                                ) : (
                                    <Link
                                        href={usersShow(line.subject.id)}
                                        className="font-medium text-foreground underline-offset-4 hover:underline"
                                    >
                                        {line.subject.real_name ??
                                            line.subject.name}
                                    </Link>
                                )}
                            </TableCell>
                        )}
                        <TableCell className="align-top">
                            {t(ACCOUNT_ACTION_KEYS[line.action])}
                        </TableCell>
                        <TableCell className="align-top">
                            {line.role_before !== null &&
                            line.role_after !== null
                                ? t('admin.access.history.role_change', {
                                      before: t(
                                          USER_ROLE_KEYS[line.role_before],
                                      ),
                                      after: t(USER_ROLE_KEYS[line.role_after]),
                                  })
                                : t('admin.common.none')}
                        </TableCell>
                        <TableCell className="align-top">
                            {actorLabel(line.actor_name, t)}
                        </TableCell>
                        <TableCell className="align-top break-words">
                            {line.reason ?? t('admin.common.none')}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
