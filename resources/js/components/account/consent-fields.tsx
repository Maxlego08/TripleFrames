import { useId } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { terms } from '@/routes/legal';

type Props = {
    /** Les erreurs du formulaire parent, lues sous `terms` et `age`. */
    errors: Partial<Record<string, string>>;
    /** Afficher la case d'âge : toujours à la création, une fois en ré-acceptation. */
    withAge?: boolean;
    tabIndex?: number;
};

/**
 * Les deux cases de consentement (spec 40 § 13.1) : CGU et âge, DISTINCTES
 * et jamais pré-cochées, chacune avec son erreur. Le lien des CGU s'ouvre
 * dans un nouvel onglet, pour ne pas perdre la saisie. Le serveur date les
 * consentements ; la case ne porte qu'une intention.
 */
export function ConsentFields({ errors, withAge = true, tabIndex }: Props) {
    const { t } = useTranslations();
    const id = useId();

    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <div className="flex items-start gap-3">
                    <Checkbox
                        id={`${id}-terms`}
                        name="terms"
                        value="1"
                        tabIndex={tabIndex}
                        aria-invalid={errors.terms !== undefined}
                        aria-describedby={
                            errors.terms !== undefined
                                ? `${id}-terms-error`
                                : undefined
                        }
                    />
                    <Label htmlFor={`${id}-terms`}>
                        {t('account.consent.terms')}
                    </Label>
                </div>
                <a
                    href={terms().url}
                    target="_blank"
                    rel="noopener"
                    tabIndex={tabIndex}
                    className="text-sm underline underline-offset-4"
                >
                    {t('account.consent.terms_link')}
                    <span className="sr-only"> {t('legal.new_tab')}</span>
                </a>
                <InputError id={`${id}-terms-error`} message={errors.terms} />
            </div>

            {withAge && (
                <div className="grid gap-2">
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id={`${id}-age`}
                            name="age"
                            value="1"
                            tabIndex={tabIndex}
                            aria-invalid={errors.age !== undefined}
                            aria-describedby={
                                errors.age !== undefined
                                    ? `${id}-age-error`
                                    : undefined
                            }
                        />
                        <Label htmlFor={`${id}-age`}>
                            {t('account.consent.age')}
                        </Label>
                    </div>
                    <InputError id={`${id}-age-error`} message={errors.age} />
                </div>
            )}
        </div>
    );
}
