<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDPA consent: who recorded it, against which notice, and whether it was withdrawn.
 *
 * `employees.consent_at` and `consent_note` already existed, and a single timestamp is not
 * a consent record. Under the PDPA the burden is on the employer to SHOW consent when asked,
 * and "there is a date in a column" answers none of the questions that actually get asked:
 *
 *   - WHO took the consent? A date with no name cannot be defended when the person whose
 *     consent it was says they never gave it.
 *   - WHAT did they agree to? A consent is consent to a particular notice at a particular
 *     version. Reusing the same column across a rewritten notice would claim agreement to
 *     terms that had not been written yet.
 *   - HOW? Verbal consent witnessed by a manager and a signature are different qualities of
 *     evidence, and both are legitimate.
 *
 * And a consent that cannot be WITHDRAWN is not consent under the Act, so a withdrawal has to
 * be recordable too — while keeping the fact that consent was once given, because the
 * photographs taken under it were taken lawfully.
 *
 * The old columns are deliberately NOT dropped. Existing rows keep whatever they hold, and
 * the resource falls back to them, so this migration cannot silently erase a record someone
 * already relies on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            /*
             * The manager who recorded the consent. Nullable because consent may predate
             * this column, and a NOT NULL would force a fabricated backfill — inventing an
             * attribution is worse than admitting one is unknown.
             */
            $table->foreignId('consent_recorded_by')
                ->nullable()
                ->after('consent_note')
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Which notice version was agreed to. A short string rather than a foreign key
             * to a notices table: the business has one notice, and a version stamp is enough
             * to answer "what did they agree to" without inventing a table to manage a
             * document that changes once a year.
             */
            $table->string('consent_version', 20)->nullable()->after('consent_recorded_by');

            /** verbal | written | signed_form — how strong the evidence is. */
            $table->string('consent_method', 20)->nullable()->after('consent_version');

            /*
             * Withdrawal is a timestamp, not a nulling of consent_at. Erasing the original
             * would suggest consent was never given, which would call into question the
             * lawfulness of everything done while it was.
             */
            $table->timestamp('consent_withdrawn_at')->nullable()->after('consent_method');
            $table->string('consent_withdrawal_note', 255)->nullable()->after('consent_withdrawn_at');

            // "Who still has not consented" is the console's question, so index for it.
            $table->index('consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['consent_at']);

            // Dropping the foreign key by its column name, since Laravel derives it and
            // naming it explicitly here would break if the table were ever recreated.
            $table->dropConstrainedForeignId('consent_recorded_by');

            $table->dropColumn([
                'consent_version',
                'consent_method',
                'consent_withdrawn_at',
                'consent_withdrawal_note',
            ]);
        });
    }
};
