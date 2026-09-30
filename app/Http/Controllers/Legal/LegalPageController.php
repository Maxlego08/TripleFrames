<?php

namespace App\Http\Controllers\Legal;

use App\Enums\LegalPage;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Les pages publiques du jalon 1, en squelette (spec 90 § 4) : mentions
 * légales, CGU, confidentialité et « signaler un contenu ». Une méthode par
 * page, toutes rendant la page Inertia unique `legal/show` pour un cas de
 * {@see LegalPage}.
 *
 * **Habillage traduit, corps en français.** Le corps est un partiel Blade du
 * dépôt, rendu ici en HTML et transmis en prop `body` ; la page l'injecte dans
 * un `<div lang="fr">`, quelle que soit la locale du visiteur, pendant que
 * l'habillage (titre, bandeau provisoire, bloc de contact, pied de page) suit
 * sa langue, `<html lang>` compris (spec 90 § 4.2, spec 05 § Contenus
 * traduits).
 *
 * **Contenu venu du dépôt seulement, jamais de la base** : c'est ce qui rend
 * sûre l'injection de ce HTML. Un outil d'édition qui déplacerait ces textes en
 * base ouvrirait une faille XSS et exigerait un assainissement ; il n'est pas
 * prévu en v1.
 *
 * Lecture seule : « signaler un contenu » n'a **aucun formulaire** au jalon 1
 * (spec 90 § 4.5). Le formulaire, `takedown.store`, arrive au jalon 2 avec le
 * SMTP et la file de retrait du back-office : branché avant, il créerait des
 * demandes qu'aucun écran ne traite.
 */
class LegalPageController extends Controller
{
    /** `legal.notice` — mentions légales. */
    public function notice(): Response
    {
        return $this->render(LegalPage::Notice);
    }

    /** `legal.terms` — conditions générales d'utilisation. */
    public function terms(): Response
    {
        return $this->render(LegalPage::Terms);
    }

    /** `legal.privacy` — politique de confidentialité. */
    public function privacy(): Response
    {
        return $this->render(LegalPage::Privacy);
    }

    /** `takedown.create` — « signaler un contenu », page statique au jalon 1. */
    public function report(): Response
    {
        return $this->render(LegalPage::Report);
    }

    /**
     * Props de `legal/show`, type `LegalPageProps` de
     * `resources/js/types/legal.ts`.
     */
    private function render(LegalPage $page): Response
    {
        return Inertia::render('legal/show', [
            'page' => $page->value,
            'body' => view()->file($page->viewPath())->render(),
            'provisional' => $this->provisional($page),
            'updatedAt' => $this->updatedAt($page),
            'contactEmail' => $this->contactEmail(),
        ]);
    }

    /**
     * Le bandeau provisoire tombe sur un `false` explicite, et seulement sur
     * lui : une entrée absente ou d'un autre type garde le texte marqué comme
     * provisoire, ce qui est l'erreur sans danger.
     */
    private function provisional(LegalPage $page): bool
    {
        return config("legal.pages.{$page->value}.provisional") !== false;
    }

    /**
     * Jour de dernière mise à jour, `AAAA-MM-JJ`, ou `null`. Le client le
     * formate en UTC : lu comme minuit UTC, il s'afficherait sinon la veille à
     * l'ouest de Greenwich.
     */
    private function updatedAt(LegalPage $page): ?string
    {
        $day = config("legal.pages.{$page->value}.updated_at");

        return is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : null;
    }

    /**
     * Adresse de contact publiée, ou `null`. `LEGAL_CONTACT_EMAIL=` écrit vide
     * rend une CHAÎNE VIDE, pas `null` (`Env::get` ne rend `null` que pour une
     * variable absente ou écrite `null`) : sans ce repli, la mention
     * `legal.contact.unavailable` ne s'afficherait jamais et la page dirait
     * « écrivez à . ».
     */
    private function contactEmail(): ?string
    {
        $email = config('legal.contact_email');

        if (! is_string($email)) {
            return null;
        }

        $email = trim($email);

        return $email !== '' ? $email : null;
    }
}
