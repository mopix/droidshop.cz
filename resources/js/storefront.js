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
