import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { RoundInput } from '@/components/game/round-input';
import type { RoundInputProps } from '@/components/game/round-input';
import type { AnswerSubmission } from '@/hooks/game/use-answer-submission';

/*
 * D54 du 02/10 (70 § 10.7, 90 § 10) : le cas terminal du QCM en Normal se
 * dit à la place de la grille, sur la seule information du serveur
 * (`RoundState.choicesUnavailable`, que le magasin tient de `tier.opened` ou
 * du paquet — `store.test.ts`). Rendu serveur sans DOM : la clé traduite
 * apparaît telle quelle, le dictionnaire étant remplacé par l'identité.
 */

vi.mock('@/hooks/use-translations', () => ({
    useTranslations: () => ({
        locale: 'fr',
        locales: [],
        t: (key: string) => key,
        tChoice: (key: string) => key,
    }),
}));

const UNAVAILABLE = 'game.choices.unavailable';

const submission = {
    pending: false,
    error: null,
    last: null,
    submitText: () => undefined,
    submitChoice: () => undefined,
} as unknown as AnswerSubmission;

function render(overrides: Partial<RoundInputProps>): string {
    const props: RoundInputProps = {
        roundKey: 'partie:2',
        difficulty: 'normal',
        input: null,
        choices: null,
        choicesUnavailable: false,
        attemptsLeft: 3,
        maxLength: 60,
        submission,
        disabled: false,
        lockRank: null,
        ...overrides,
    };

    return renderToStaticMarkup(createElement(RoundInput, props));
}

describe('QCM indisponible (D54 du 02/10)', () => {
    it('affiche le message à la place de la grille quand le serveur dit le QCM indisponible', () => {
        expect(render({ choicesUnavailable: true })).toContain(UNAVAILABLE);
    });

    it("l'affiche aussi au siège dont la saisie a été fermée par la composition terminale", () => {
        const html = render({
            choicesUnavailable: true,
            attemptsLeft: 0,
            input: {
                inputState: 'attempts_exhausted',
                attemptsLeft: 0,
                choices: null,
                locked: null,
            },
        });

        expect(html).toContain(UNAVAILABLE);
        expect(html).toContain('game.answer.exhausted');
    });

    it("n'affiche rien tant que le serveur ne l'a pas dit, aucun minuteur client n'en décidant", () => {
        expect(render({ choicesUnavailable: false })).not.toContain(
            UNAVAILABLE,
        );
    });

    it('ne montre rien au joueur verrouillé, dont le panneau ne porte jamais de grille', () => {
        expect(render({ choicesUnavailable: true, lockRank: 1 })).not.toContain(
            UNAVAILABLE,
        );
    });

    it('laisse la Facile inchangée : le chargement, jamais ce message', () => {
        expect(
            render({ choicesUnavailable: true, difficulty: 'easy' }),
        ).not.toContain(UNAVAILABLE);
    });
});
