import { describe, expect, it } from 'vite-plus/test';
import { prepareLegalDocument } from '@/lib/legal-document';

describe('prepareLegalDocument', () => {
    it('construit le sommaire et ajoute des ancres stables', () => {
        const document = prepareLegalDocument(
            '<h2>Objet</h2><p>Texte</p><h2>Données personnelles</h2>',
        );

        expect(document.sections).toEqual([
            { id: 'legal-section-1', label: 'Objet' },
            { id: 'legal-section-2', label: 'Données personnelles' },
        ]);
        expect(document.html).toContain('<h2 id="legal-section-1">Objet</h2>');
        expect(document.html).toContain(
            '<h2 id="legal-section-2">Données personnelles</h2>',
        );
    });

    it('conserve les identifiants deja portes par le corps', () => {
        const document = prepareLegalDocument(
            '<h2 id="legal-cookies-heading">Cookies &amp; stockage</h2>',
        );

        expect(document.sections).toEqual([
            {
                id: 'legal-cookies-heading',
                label: 'Cookies & stockage',
            },
        ]);
        expect(document.html).toBe(
            '<h2 id="legal-cookies-heading">Cookies &amp; stockage</h2>',
        );
    });
});
