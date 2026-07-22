<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A credit note names the invoice it corrects (spec §16.6, wave 1.6 Stage
     * 3) and carries a negative total/line amounts (CreditNoteSnapshot negates
     * the original invoice). Pulled forward from the wave's own Task 8 plan
     * (`corrects_document_id`/`corrects_number`, deliberately not a foreign
     * key — "the reference is to another documents row and must survive even
     * if that lookup path changes; the number is the human/legal anchor")
     * because Task 7's own gate tests already issue a real credit note
     * end-to-end and cannot pass without these columns existing.
     *
     * Also re-scopes the printed-number uniqueness to (tenant, type, number):
     * each document type numbers from its own SequenceService series
     * (`invoices:2026` vs `credit_notes:2026`), so with no distinguishing
     * tenant prefix an invoice and a credit note both format their first
     * document of the year as the identical string "20260001" — the original
     * (tenant_id, number) unique index rejected the second type outright.
     * Different document types are allowed to share a printed number; the
     * same type never may.
     *
     * Added separately from the original create_documents_table migration
     * (wave 1.5, already merged to main) rather than editing it in place —
     * that migration has shipped, so every change this stage needs arrives
     * as an alter, not a rewrite of history.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('corrects_document_id')->nullable()->after('series');
            $table->string('corrects_number')->nullable()->after('corrects_document_id');
        });

        // unsignedBigInteger cannot hold a credit note's negative total
        // (haléře, Money-cast); a credit note must be able to go negative.
        DB::statement('ALTER TABLE documents MODIFY total BIGINT NOT NULL');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'type', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'type', 'number']);
            $table->unique(['tenant_id', 'number']);
        });

        // Caveat: this fails if any credit note with a negative total exists
        // (UNSIGNED cannot hold it) — rolling back this migration on a
        // database that has already issued a credit note requires deleting
        // or re-signing those rows first.
        DB::statement('ALTER TABLE documents MODIFY total BIGINT UNSIGNED NOT NULL');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['corrects_document_id', 'corrects_number']);
        });
    }
};
