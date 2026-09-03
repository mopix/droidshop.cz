<?php

namespace Modules\Reviews\Http\Controllers;

use App\Core\Catalog\Contracts\CatalogProduct;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Html\HtmlSanitizer;
use App\Core\Settings\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Modules\Orders\Models\Order;
use Modules\Reviews\Http\Requests\StoreReviewsRequest;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Models\ReviewOptout;
use Modules\Reviews\Services\InvitationIssuer;
use Modules\Storefront\Support\Seo;

/**
 * Public review form reached from the invitation e-mail's link.
 */
class ReviewFormController extends Controller
{
    public function __construct(
        private readonly InvitationIssuer $issuer,
        private readonly ProductCatalog $catalog,
        private readonly HtmlSanitizer $sanitizer,
        private readonly SettingsService $settings,
    ) {}

    public function show(string $token): View
    {
        $invitation = $this->issuer->resolve($token) ?? abort(404);
        $order = Order::query()->findOrFail($invitation->order_id);

        return view('reviews::storefront.form', [
            'token' => $token,
            'products' => $this->purchasedProducts($order),
            // A shop that does not collect shop ratings must not be shown the
            // fieldset — and store() drops the value too, so a hand-crafted
            // POST cannot slip one past the setting.
            'shopReviewsEnabled' => (bool) $this->settings->get('reviews', 'shop_reviews_enabled', true),
            'seo' => new Seo(title: 'Vaše hodnocení', noindex: true),
        ]);
    }

    public function store(StoreReviewsRequest $request, string $token): Response
    {
        $invitation = $this->issuer->resolve($token) ?? abort(404);
        $order = Order::query()->findOrFail($invitation->order_id);

        $purchased = $this->purchasedProducts($order)->keys();
        $submitted = collect($request->input('products', []))->keys();

        // A product the order never contained is not a validation nicety: it
        // is somebody writing a review for a product they did not buy, which
        // is the one thing "verified purchase" has to mean.
        abort_if($submitted->diff($purchased)->isNotEmpty(), 422, 'Produkt nebyl součástí objednávky.');

        DB::transaction(function () use ($request, $invitation, $order, $submitted): void {
            foreach ($submitted as $productId) {
                $input = $request->input("products.{$productId}");

                $this->createReview(
                    order: $order,
                    subject: Review::SUBJECT_PRODUCT,
                    productId: (int) $productId,
                    rating: (int) $input['rating'],
                    body: $input['body'] ?? null,
                );
            }

            $shopReviewsEnabled = (bool) $this->settings->get('reviews', 'shop_reviews_enabled', true);

            if ($shopReviewsEnabled && $request->filled('shop.rating')) {
                $this->createReview(
                    order: $order,
                    subject: Review::SUBJECT_SHOP,
                    productId: Review::SUBJECT_SHOP_KEY,
                    rating: (int) $request->input('shop.rating'),
                    body: $request->input('shop.body'),
                );
            }

            // Marked used inside the same transaction: a token that survived
            // a half-failed submission would let the same order be reviewed
            // twice, and the unique index would then reject the second half
            // of an otherwise valid submission.
            $invitation->update(['used_at' => now()]);
        });

        // Not a redirect: the token is spent by the line above, so both
        // /recenze/{token} and the Task 8 shop page are wrong targets here —
        // the first now 404s, the second does not exist yet.
        return response()->view('reviews::storefront.thanks', [
            'message' => 'Děkujeme. Recenzi zveřejníme, jakmile ji projde obchod.',
        ]);
    }

    public function optout(string $token): View
    {
        // resolveAny(), not resolve(): unsubscribing must keep working after
        // the token has been used or has expired. A link that dies the moment
        // the buyer writes their review is not an unsubscribe link, and bulk
        // mail is required to carry one that works.
        $invitation = $this->issuer->resolveAny($token) ?? abort(404);
        $order = Order::query()->findOrFail($invitation->order_id);

        ReviewOptout::query()->firstOrCreate(['email' => $order->email]);

        return view('reviews::storefront.thanks', [
            'message' => 'Odhlásili jsme vás. Další výzvy k recenzi už neposíláme.',
        ]);
    }

    /**
     * @return Collection<int, CatalogProduct>
     */
    private function purchasedProducts(Order $order): Collection
    {
        return $order->items
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->mapWithKeys(fn (int $id): array => [$id => $this->catalog->findById($id)])
            // A product deleted since the order was placed cannot be
            // reviewed; the order still carries its snapshot, but there is no
            // page for the review to appear on.
            ->filter();
    }

    private function createReview(Order $order, string $subject, int $productId, int $rating, ?string $body): void
    {
        Review::query()->create([
            'subject' => $subject,
            'product_id' => $productId,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            // The order has no customer_name column — the buyer's name lives
            // in the `billing` JSON, and a guest order has nothing else.
            'author_name' => data_get($order->billing, 'name', 'Zákazník'),
            'author_email' => $order->email,
            'rating' => $rating,
            'body' => $body === null ? null : $this->sanitizer->clean($body),
            'status' => Review::STATUS_PENDING,
            'verified_purchase' => true,
        ]);
    }
}
