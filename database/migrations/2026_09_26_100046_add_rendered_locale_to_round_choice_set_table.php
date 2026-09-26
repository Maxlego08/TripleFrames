<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `round_choice_set.rendered_locale` — locale EFFECTIVE atteinte par les
 * quatre chaînes d'une ligne du QCM (E10-03, spec 10 § 7.8, spec 70 § 10.5),
 * migration additive n° 46 du lot 9 de § 13.2, livrée par le lot de `70` qui
 * compose le QCM (L70-8).
 *
 * **Uniquement un ajout**, jamais une réécriture de la migration de création
 * déjà jouée : la production naît dès le jalon 1 et `migrate` ne rejoue jamais
 * ce qu'il a déjà joué. `round_choice_set` est une table de faits purgeable,
 * hors du périmètre de l'instantané bloquant (règle 12) : aucun
 * `backup:snapshot` n'est requis.
 *
 * `string(5)` comme toute locale d'interface (§ 1.3), placée après `locale`,
 * **nullable et sans défaut** : NULL quand les quatre chaînes sortent de
 * `title_original` (troisième rang de la chaîne de repli, ou mode dégradé).
 * C'est elle qui fournit l'attribut `lang` de la charge du QCM et le rejoue à
 * l'identique à chaque renvoi (règle 3). **Aucun index** : la colonne n'est
 * lue que sur la ligne déjà trouvée par `round_choice_set_round_locale_uq`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('round_choice_set', function (Blueprint $table) {
            $table->string('rendered_locale', 5)->nullable()->after('locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('round_choice_set', function (Blueprint $table) {
            $table->dropColumn('rendered_locale');
        });
    }
};
