<?php

namespace App\Http\Controllers;

use App\Core\Billing\StripeWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe server-to-server webhook. No session, no CSRF — authenticity is the
 * Stripe-Signature header verified against the signing secret (Comgate pattern,
 * wave 1.4). Always 2xx once past verification so Stripe stops retrying;
 * only a bad/missing signature is a 4xx.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookHandler $handler): Response
    {
        $secret = (string) config('billing.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $secret,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('invalid signature', 400);
        }

        $handler->handle($event);

        return response('ok', 200);
    }
}
