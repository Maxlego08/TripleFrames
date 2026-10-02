export type LegalSection = {
    id: string;
    label: string;
};

export type PreparedLegalDocument = {
    html: string;
    sections: LegalSection[];
};

const H2_PATTERN = /<h2\b([^>]*)>([\s\S]*?)<\/h2>/gi;
const ID_PATTERN = /\bid\s*=\s*(["'])(.*?)\1/i;

function plainText(html: string): string {
    return html
        .replace(/<[^>]*>/g, '')
        .replaceAll('&amp;', '&')
        .replaceAll('&quot;', '"')
        .replaceAll('&lt;', '<')
        .replaceAll('&gt;', '>')
        .trim();
}

/**
 * Ajoute un identifiant stable aux titres du corps legal et produit le
 * sommaire correspondant. Le HTML vient exclusivement des partiels Blade du
 * depot ; cette fonction ne rend aucun contenu externe plus fiable.
 */
export function prepareLegalDocument(body: string): PreparedLegalDocument {
    const sections: LegalSection[] = [];
    let index = 0;

    const html = body.replace(
        H2_PATTERN,
        (heading, attributes: string, content: string) => {
            index += 1;
            const existingId = attributes.match(ID_PATTERN)?.[2];
            const id = existingId ?? `legal-section-${index}`;

            sections.push({ id, label: plainText(content) });

            if (existingId !== undefined) {
                return heading;
            }

            return `<h2${attributes} id="${id}">${content}</h2>`;
        },
    );

    return { html, sections };
}
