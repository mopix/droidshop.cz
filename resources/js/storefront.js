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
