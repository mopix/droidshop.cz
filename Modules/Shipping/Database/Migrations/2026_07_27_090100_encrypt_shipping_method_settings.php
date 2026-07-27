<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypts shipping_methods.settings (wave 2.5).
 *
 * The wave 1.3 decision "delivery settings are not secret" held only while the
 * column carried a pickup address and opening hours. Packeta's apiPassword is
 * a credential (spec §16.5), so the column joins payment_methods.settings in
 * being encrypted at rest — and the existing plaintext rows must be rewritten,
 * or the cast would fail to decrypt them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            // json -> text: ciphertext is not valid JSON.
            $table->text('settings')->nullable()->change();
        });

        DB::table('shipping_methods')
            ->whereNotNull('settings')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->settings, true);

                    if (! is_array($decoded)) {
                        // Already ciphertext (re-run), or unreadable: leave it.
                        // This is what makes the migration idempotent — a
                        // second run finds no plaintext JSON left to encrypt.
                        continue;
                    }

                    DB::table('shipping_methods')
                        ->where('id', $row->id)
                        ->update(['settings' => Crypt::encryptString(json_encode($decoded))]);
                }
            });
    }

    public function down(): void
    {
        $undecryptable = 0;

        DB::table('shipping_methods')
            ->whereNotNull('settings')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$undecryptable) {
                foreach ($rows as $row) {
                    try {
                        $plain = Crypt::decryptString((string) $row->settings);
                    } catch (Throwable) {
                        // Not ciphertext this app instance can decrypt (wrong
                        // APP_KEY, or already plaintext from a previous partial
                        // rollback). Left as-is rather than destroyed — but the
                        // rollback made the column readable as JSON again for
                        // the rest, so this row is worth flagging, not silently
                        // dropping.
                        $undecryptable++;

                        continue;
                    }

                    DB::table('shipping_methods')->where('id', $row->id)->update(['settings' => $plain]);
                }
            });

        if ($undecryptable > 0) {
            Log::warning('shipping_methods rollback: some settings rows could not be decrypted and were left as ciphertext.', [
                'count' => $undecryptable,
            ]);
        }

        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->json('settings')->nullable()->change();
        });
    }
};
