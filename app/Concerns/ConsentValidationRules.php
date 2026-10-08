<?php

namespace App\Concerns;

/**
 * Les deux cases de consentement d'une création de compte et de la
 * ré-acceptation (spec 40 § 13.1) : `terms` et `age`, distinctes, chacune
 * `accepted`. Partagées entre `CreateNewUser`, `OAuthFinishRequest` et
 * `TermsAcceptanceRequest`, comme {@see ProfileValidationRules}.
 */
trait ConsentValidationRules
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function consentRules(bool $age = true): array
    {
        $rules = ['terms' => ['accepted']];

        if ($age) {
            $rules['age'] = ['accepted'];
        }

        return $rules;
    }

    /**
     * Les messages traduits des deux cases.
     *
     * @return array<string, string>
     */
    protected function consentMessages(): array
    {
        return [
            'terms.accepted' => (string) __('account.consent.errors.terms_required'),
            'age.accepted' => (string) __('account.consent.errors.age_required'),
        ];
    }
}
