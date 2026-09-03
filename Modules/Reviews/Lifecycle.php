<?php

namespace Modules\Reviews;

use App\Core\Modules\Contracts\ModuleUninstall;

/**
 * Reviews are content, not a legal record — a tenant may delete the lot.
 *
 * Unlike `orders` and `docs`, where a tax document has to survive ten years
 * and `documents.order_id` is a real foreign key, nothing points at a review:
 * `reviews.order_id` is a plain integer with no FK, precisely so that orders
 * and products stay independently deletable.
 *
 * @see App\Core\Modules\Contracts\ModuleUninstall
 */
class Lifecycle implements ModuleUninstall
{
    /**
     * Children before parents. None of these tables holds a foreign key into
     * another, so the order is for readability rather than correctness.
     *
     * `review_optouts` is deliberately absent: it is a consent record, not
     * review content, and it must outlive the feature that collected it.
     * Uninstalling and reinstalling the module (or a tenant just switching
     * it off and back on) must not wipe the unsubscribe list and re-mail
     * everyone who asked to be left alone.
     *
     * @return list<string>
     */
    public function tablesToPurge(): array
    {
        return ['review_invitations', 'review_aggregates', 'reviews'];
    }
}
