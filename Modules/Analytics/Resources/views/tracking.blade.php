{{--
    Measurement snippets.

    The hard rule of this whole wave: nothing here may contact a third party
    before the visitor consents. No <script src>, no pixel <img>, no
    preconnect — those are all requests, and a request that leaves the browser
    before consent is the violation, whether or not a cookie comes back.

    So the server renders only CONFIGURATION (ids the tenant typed, per
    tenant, identical for every visitor and therefore safe in a cached page).
    The loader below reads the consent cookie and injects the vendor scripts
    itself, which is also why this cannot be done with a Blade @if on the
    consent: that would make cached HTML differ per visitor and hand one
    visitor's decision to the next.
--}}
@if ($trackingCodes !== [])
    <script type="application/json" id="tracking-config">{!! json_encode($trackingCodes, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

    <script>
        (function () {
            var configNode = document.getElementById('tracking-config');
            if (!configNode) return;

            var config;
            try {
                config = JSON.parse(configNode.textContent);
            } catch (e) {
                return;
            }

            var loaded = {};

            function allows(category) {
                return window.droidshopConsent && window.droidshopConsent.allows(category);
            }

            function inject(src) {
                if (loaded[src]) return;
                loaded[src] = true;

                var s = document.createElement('script');
                s.async = true;
                s.src = src;
                document.head.appendChild(s);
            }

            // GA4 speaks Consent Mode v2. Its script may load with everything
            // denied — that is the documented way to run it in the EU, and
            // since 2024 a GA4 property without Consent Mode v2 no longer
            // pairs with Google Ads here. Nothing is stored until 'update'
            // grants it, which only happens below.
            function startGa4(id) {
                if (loaded.ga4) return;
                loaded.ga4 = true;

                window.dataLayer = window.dataLayer || [];
                window.gtag = function () { window.dataLayer.push(arguments); };

                window.gtag('consent', 'default', {
                    ad_storage: 'denied',
                    ad_user_data: 'denied',
                    ad_personalization: 'denied',
                    analytics_storage: 'denied',
                    wait_for_update: 500,
                });

                inject('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id));

                window.gtag('js', new Date());
                window.gtag('config', id, { anonymize_ip: true });
            }

            // Sklik and Meta have no consent mode: their scripts start
            // measuring the moment they load, so they are injected only after
            // a decision that allows them.
            function startSklik(id) {
                if (loaded.sklik) return;
                loaded.sklik = true;

                window.rc = window.rc || {};
                window.rc.retargetingConf = { rtgId: Number(id), consent: 1 };
                inject('https://c.seznam.cz/js/rc.js');
            }

            function startMeta(id) {
                if (loaded.meta) return;
                loaded.meta = true;

                /* eslint-disable */
                !function (f, b, e, v, n, t, s) {
                    if (f.fbq) return; n = f.fbq = function () {
                        n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
                    };
                    if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
                    t = b.createElement(e); t.async = !0; t.src = v;
                    s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
                }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
                /* eslint-enable */

                window.fbq('init', id);
                window.fbq('track', 'PageView');
            }

            function apply() {
                // Consent Mode v2 would technically allow loading gtag.js
                // with everything denied, and Google recommends exactly that.
                // We do not: the request itself still reaches Google and
                // carries the visitor's IP address, and a request made before
                // consent is the thing ePrivacy objects to — whether or not a
                // cookie comes back. So gtag.js loads only once analytics is
                // allowed. The default-denied call still runs first, in
                // startGa4(), because that is what makes the property pair
                // with Google Ads in the EU.
                if (config.ga4 && allows('analytics')) {
                    startGa4(config.ga4);

                    window.gtag('consent', 'update', {
                        analytics_storage: 'granted',
                        ad_storage: allows('marketing') ? 'granted' : 'denied',
                        ad_user_data: allows('marketing') ? 'granted' : 'denied',
                        ad_personalization: allows('marketing') ? 'granted' : 'denied',
                    });
                }

                if (allows('marketing')) {
                    if (config.sklikRetargeting) startSklik(config.sklikRetargeting);
                    if (config.metaPixel) startMeta(config.metaPixel);
                }
            }

            // A visitor who accepts is measured on the page where they
            // accepted, not only from the next one.
            document.addEventListener('consent:changed', apply);

            // Nothing runs for a visitor who has not decided: read() returns
            // null for no cookie, an unreadable one, and one recorded against
            // older wording alike.
            function applyIfDecided() {
                if (window.droidshopConsent && window.droidshopConsent.read() !== null) {
                    apply();
                }
            }

            // window.droidshopConsent is defined by storefront.js, which Vite
            // loads as a module and therefore defers — it has not run yet
            // while this inline script is being parsed. DOMContentLoaded is
            // the first point where it is guaranteed to exist.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', applyIfDecided);
            } else {
                applyIfDecided();
            }
        })();
    </script>
@endif
