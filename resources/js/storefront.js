/**
 * Storefront islands.
 *
 * Everything here enhances markup the server already rendered — no page
 * content is produced by JavaScript, and nothing here may compute a price
 * (binding storefront rule, spec §16.3).
 */

// Gallery: swap the main image when a thumbnail is activated. The thumbnails
// are ordinary links to the full image, so with JS off they still work.
document.querySelectorAll('[data-gallery]').forEach((gallery) => {
    const main = gallery.querySelector('[data-gallery-main]');

    if (!main) {
        return;
    }

    gallery.querySelectorAll('[data-gallery-thumb]').forEach((thumb) => {
        thumb.addEventListener('click', (event) => {
            event.preventDefault();
            main.src = thumb.dataset.galleryThumb;
            main.alt = thumb.querySelector('img')?.alt ?? main.alt;
        });
    });
});

// Listing controls submit on change once JS is available; the submit button
// stays in the markup for everyone else.
document.querySelectorAll('[data-storefront-autosubmit]').forEach((form) => {
    form.querySelectorAll('select, input[type="checkbox"]').forEach((control) => {
        control.addEventListener('change', () => form.submit());
    });
});

/**
 * Variant price island.
 *
 * Enhancement only: the form already works without this file — the server
 * renders every axis, resolves the selection on POST and computes the price
 * (.claude/rules/storefront-rendering.md). All this does is show the price of
 * the combination currently selected, before the round trip.
 */
document.querySelectorAll('[data-variant-matrix]').forEach((script) => {
    const form = script.closest('form');
    const priceEl = document.querySelector('[data-variant-price]');

    if (!form || !priceEl) {
        return;
    }

    // The net-price line ("bez DPH") sits next to the gross price; if it
    // is missing for some reason, the island still updates the gross price
    // and simply leaves the net line untouched further down.
    const netPriceEl = document.querySelector('[data-variant-net-price]');

    // The struck-through nominal price. Present only when the product was
    // rendered on sale, so switching to a variant that is not discounted has
    // to hide it rather than leave a stale figure crossed out.
    const regularPriceEl = document.querySelector('[data-variant-regular-price]');

    let variants;

    try {
        variants = JSON.parse(script.textContent);
    } catch (e) {
        return;
    }

    const selection = () =>
        Array.from(form.querySelectorAll('[data-variant-axis]'))
            .filter((el) => el.tagName === 'SELECT' || el.checked)
            .map((el) => Number(el.value))
            .sort((a, b) => a - b);

    const update = () => {
        const chosen = selection();

        const match = variants.find(
            (variant) =>
                variant.selection.length === chosen.length &&
                variant.selection
                    .slice()
                    .sort((a, b) => a - b)
                    .every((id, index) => id === chosen[index]),
        );

        if (!match) {
            return;
        }

        priceEl.textContent = match.price;

        if (netPriceEl && match.net_price) {
            netPriceEl.textContent = match.net_price;
        }

        // Only ever swaps strings the server already formatted — no price
        // arithmetic in JS (spec §16.3).
        if (regularPriceEl) {
            regularPriceEl.textContent = match.regular_price || '';
            regularPriceEl.hidden = !match.on_sale;
        }

        const submit = form.querySelector('button[type="submit"]');

        if (submit) {
            submit.disabled = !match.available;
            submit.textContent = match.available ? 'Přidat do košíku' : 'Vyprodáno';
        }
    };

    form.addEventListener('change', update);
    update();
});

/**
 * Packeta pickup point widget — enhancement over the server-rendered picker
 * at /pokladna/vydejni-misto.
 *
 * The container ships hidden and is only revealed once this runs, so a
 * shopper without JavaScript never sees a button that would do nothing. The
 * widget script is loaded on first click, not on page load: no third-party
 * request happens unless the shopper actually asks for the map.
 *
 * The widget only ever gives us a point id; the name and address it also
 * returns are deliberately ignored, because the server re-reads them from our
 * own catalogue (same policy as every other checkout input, spec §16.3).
 */
function initPacketaWidget() {
    const mount = document.querySelector('[data-packeta-widget]');

    if (!mount) {
        return;
    }

    const apiKey = mount.dataset.apiKey;
    const button = mount.querySelector('[data-packeta-open]');
    const tokenField = mount.querySelector('input[name="_token"]');

    if (!apiKey || !button || !tokenField) {
        return;
    }

    mount.hidden = false;

    const idleText = button.textContent;
    let loading = null;

    const loadLibrary = () => {
        if (window.Packeta) {
            return Promise.resolve();
        }

        if (loading) {
            return loading;
        }

        loading = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://widget.packeta.com/v6/www/js/library.js';
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('widget unavailable'));
            document.head.appendChild(script);
        });

        return loading;
    };

    button.addEventListener('click', () => {
        button.disabled = true;
        // A bare aria-busy toggle is not reliably announced by screen
        // readers; the mount carries aria-live="polite", so changing the
        // button's own text is what actually gets read out.
        button.textContent = 'Načítání mapy…';

        loadLibrary()
            .then(() => {
                window.Packeta.Widget.pick(apiKey, (point) => {
                    button.disabled = false;
                    button.textContent = idleText;

                    if (!point || !point.id) {
                        return;
                    }

                    // Submit the same form the no-JS path posts, with the
                    // same single field. One server code path, one set of
                    // validation rules.
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = mount.dataset.action;

                    const fields = {
                        _token: tokenField.value,
                        pickup_point_code: String(point.id),
                    };

                    Object.entries(fields).forEach(([name, value]) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = value;
                        form.appendChild(input);
                    });

                    document.body.appendChild(form);
                    form.submit();
                });
            })
            .catch(() => {
                // Adblock, offline, CSP — fail silently and leave the
                // no-JS search below fully usable. No alert, no broken page.
                // Reset the cached promise too, so a later click (network
                // back, adblock toggled off) retries the request instead of
                // replaying the same rejected promise forever.
                loading = null;
                button.disabled = false;
                button.textContent = 'Mapa se nenačetla — vyhledejte místo níže';
            });
    });
}

initPacketaWidget();

/**
 * Cookie consent.
 *
 * The banner is baked into every cached page — page-cache entries must not
 * vary by cookie (the same reason wave 3.0 dropped `has_cart`), so the HTML
 * is identical for everyone and the decision is applied here. An inline
 * script in the layout head has already hidden the banner before paint for
 * anyone who decided; this adds the submit-without-reload path and exposes
 * the decision to the tracking snippets.
 *
 * Everything degrades: with JS off the banner stays visible and its plain
 * form POST still records the decision.
 */
function readConsent() {
    const match = document.cookie.match(/(?:^|;\s*)cookie_consent=([^;]*)/);

    if (!match) {
        return null;
    }

    try {
        const parsed = JSON.parse(decodeURIComponent(match[1]));

        if (String(parsed.v) !== document.documentElement.dataset.consentVersion) {
            return null;
        }

        return Array.isArray(parsed.c) ? parsed.c : [];
    } catch (error) {
        // A tampered cookie means "ask again", never "assume yes".
        return null;
    }
}

function consentAllows(category) {
    const decided = readConsent();

    return decided !== null && decided.includes(category);
}

function initConsentBanner() {
    const banner = document.getElementById('cookie-banner');

    if (!banner) {
        return;
    }

    const form = banner.querySelector('form');

    if (!form) {
        return;
    }

    form.addEventListener('submit', (event) => {
        const choice = event.submitter?.value;

        if (!choice) {
            return; // Let the browser submit normally.
        }

        event.preventDefault();

        const body = new FormData(form);
        body.set('volba', choice);

        fetch(form.action, {
            method: 'POST',
            body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(() => {
                banner.remove();
                document.documentElement.classList.add('consent-decided');
                // Tracking snippets listen for this rather than polling, so a
                // visitor who accepts is measured on the very page where they
                // accepted, not only from the next one.
                document.dispatchEvent(new CustomEvent('consent:changed', {
                    detail: { categories: readConsent() ?? [] },
                }));
            })
            .catch(() => {
                // Network down, adblock, CSP — fall back to the plain form
                // submit so the decision is still recorded.
                form.submit();
            });
    });
}

initConsentBanner();

// Exposed for the tracking snippets rendered by the analytics module. They
// are separate <script> tags in the page, not part of this bundle, because a
// tenant may run none of them.
window.droidshopConsent = { allows: consentAllows, read: readConsent };
