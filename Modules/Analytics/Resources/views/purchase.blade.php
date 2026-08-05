{{--
    Purchase conversion.

    Unlike the site-wide snippet, this page carries a single customer's order
    value — which is exactly why it may. The thank-you page is served with
    `Cache-Control: private, no-store` and is never a page-cache entry, so
    nothing here can reach a second visitor. On a cached page the same markup
    would be a leak between customers.

    Still gated on consent, and on the same categories as the site-wide
    snippet: a conversion is measurement like any other.
--}}
<script type="application/json" id="purchase-config">{!! json_encode($purchase, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

<script>
    (function () {
        var node = document.getElementById('purchase-config');
        if (!node) return;

        var order;
        try {
            order = JSON.parse(node.textContent);
        } catch (e) {
            return;
        }

        var sent = false;

        function allows(category) {
            return window.droidshopConsent && window.droidshopConsent.allows(category);
        }

        function report() {
            // Once per page. The site-wide loader may fire consent:changed
            // while this page is open, and a double-counted purchase is worse
            // than a missed one — it silently inflates every conversion
            // report the tenant makes decisions from.
            if (sent) return;

            var reported = false;

            if (allows('analytics') && order.ga4 && window.gtag) {
                window.gtag('event', 'purchase', {
                    transaction_id: order.number,
                    value: order.value,
                    currency: order.currency,
                });
                reported = true;
            }

            if (allows('marketing')) {
                if (order.sklikConversion && window.rc && window.rc.conversionHit) {
                    window.rc.conversionHit({
                        id: Number(order.sklikConversion),
                        value: order.value,
                        orderId: order.number,
                        consent: 1,
                    });
                    reported = true;
                }

                if (order.metaPixel && window.fbq) {
                    window.fbq('track', 'Purchase', {
                        value: order.value,
                        currency: order.currency,
                    });
                    reported = true;
                }
            }

            sent = reported;
        }

        // The vendor libraries are injected by the site-wide loader, which
        // runs on DOMContentLoaded and again on consent:changed. Both moments
        // are watched, plus a short retry, because gtag.js and fbevents.js
        // arrive over the network some time after that.
        document.addEventListener('consent:changed', function () {
            setTimeout(report, 300);
        });

        function attempt(remaining) {
            report();

            if (!sent && remaining > 0) {
                setTimeout(function () { attempt(remaining - 1); }, 400);
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { attempt(5); });
        } else {
            attempt(5);
        }
    })();
</script>
