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
        button.setAttribute('aria-busy', 'true');

        loadLibrary()
            .then(() => {
                window.Packeta.Widget.pick(apiKey, (point) => {
                    button.disabled = false;
                    button.removeAttribute('aria-busy');

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
                button.disabled = false;
                button.removeAttribute('aria-busy');
                button.textContent = 'Mapa se nenačetla — vyhledejte místo níže';
            });
    });
}

initPacketaWidget();
