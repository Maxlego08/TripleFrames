type Props = {
    /** Message DÉJÀ composé par Laravel, dans la langue forcée du back-office. */
    message?: string;
    /** Rattache l'erreur au champ par `aria-describedby`. */
    id?: string;
};

/**
 * L'erreur d'un champ du back-office.
 *
 * Ce n'est PAS `<InputError>` du site joueur : celui-ci peint son texte avec
 * deux utilitaires de couleur littérale et une variante de thème sombre, que
 * le back-office s'interdit — il est forcé en clair, et un re-skin ne doit
 * toucher que les tokens (règle 5). Ici, `text-destructive`, et rien d'autre.
 *
 * `role="alert"` : une erreur de validation arrive APRÈS un aller-retour
 * serveur, hors du flux de lecture. Sans ce rôle, elle reste invisible à qui
 * navigue au lecteur d'écran et croit son envoi parti.
 */
export function AdminInputError({ message, id }: Props) {
    if (message === undefined || message === '') {
        return null;
    }

    return (
        <p id={id} role="alert" className="text-sm text-destructive">
            {message}
        </p>
    );
}
