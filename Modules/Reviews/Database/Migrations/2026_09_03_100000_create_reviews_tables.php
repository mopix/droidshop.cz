<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('subject', 10);            // product | shop

            // Not a foreign key, and not nullable: `order_items.product_id`
            // takes the same stance (an order carries a snapshot and must
            // survive the product being deleted), and 0 rather than NULL is
            // what makes the unique index below actually bite — MySQL treats
            // every NULL in a unique index as distinct, so a nullable column
            // would let one order leave two shop ratings.
            $table->unsignedBigInteger('product_id')->default(0);
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('customer_id')->nullable();

            $table->string('author_name');
            $table->string('author_email');

            $table->unsignedTinyInteger('rating');    // 1–5
            $table->string('title')->nullable();
            $table->text('body')->nullable();

            $table->string('status', 12)->default('pending');   // pending | published | rejected
            $table->string('rejection_reason')->nullable();
            $table->unsignedBigInteger('moderated_by')->nullable();
            $table->timestamp('moderated_at')->nullable();

            $table->text('reply_body')->nullable();
            $table->timestamp('reply_at')->nullable();

            // Always true in this wave. The column exists so that changing the
            // rule later is a product decision, not a migration.
            $table->boolean('verified_purchase')->default(true);

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_id', 'subject', 'product_id'], 'review_once_per_order');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'product_id', 'status', 'published_at'], 'review_storefront_idx');
        });

        Schema::create('review_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->default(0);  // 0 = the shop itself

            $table->decimal('rating_avg', 2, 1)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('count_1')->default(0);
            $table->unsignedInteger('count_2')->default(0);
            $table->unsignedInteger('count_3')->default(0);
            $table->unsignedInteger('count_4')->default(0);
            $table->unsignedInteger('count_5')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'product_id']);
        });

        Schema::create('review_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');

            // The hash, never the token: a leaked database row must not be a
            // usable link. Same stance as `customer_tokens`.
            $table->string('token_hash', 64);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_id']);
            $table->unique('token_hash');
        });

        Schema::create('review_optouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_optouts');
        Schema::dropIfExists('review_invitations');
        Schema::dropIfExists('review_aggregates');
        Schema::dropIfExists('reviews');
    }
};
