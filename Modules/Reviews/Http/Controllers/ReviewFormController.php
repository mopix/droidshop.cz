<?php

namespace Modules\Reviews\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Modules\Reviews\Services\InvitationIssuer;

/**
 * Public review form reached from the invitation e-mail's link.
 *
 * `show()` only proves the token out for this task — a review cannot be
 * written before Task 3 (invitations) exists, and every route the mailable
 * links to must resolve or the whole sweep dies on RouteNotFoundException.
 * `store()` and `optout()` get their real bodies in Task 4; they are
 * registered now purely so the two links in the e-mail have a destination.
 */
class ReviewFormController
{
    public function __construct(
        private readonly InvitationIssuer $issuer,
    ) {}

    public function show(string $token): View
    {
        $this->issuer->resolve($token) ?? abort(404);

        // Formulář dodá Task 4; tady jde jen o to, aby routa existovala a aby
        // neplatný token nikdy nevedl na stránku.
        return view('reviews::storefront.thanks', ['message' => 'Formulář se připravuje.']);
    }

    public function store(string $token): RedirectResponse
    {
        abort(404);
    }

    public function optout(string $token): RedirectResponse
    {
        abort(404);
    }
}
