<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Shop\ShopSettingsService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateContactsRequest;
use App\Http\Requests\Tenant\UpdateShopRequest;
use App\Models\ShopSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop's own settings: how it is named, who a customer writes to
 * (wave 3.6).
 *
 * Everything is read and written through the tenant resolved for this request
 * (TenantContext), never through an id in the URL — there is no identifier a
 * merchant could swap to reach another shop's settings. Same shape as
 * AppearanceController, which these screens sit next to in the menu.
 */
class ShopSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ShopSettingsService $settings,
    ) {}

    public function editShop(): Response
    {
        $settings = $this->settings->forCurrentTenant();

        return Inertia::render('Tenant/Settings/Shop', [
            'shop' => [
                'name' => $this->context->current()->name,
                'tagline' => $settings->tagline,
                'timezone' => $settings->timezone,
                'date_format' => $settings->date_format,
                'time_format' => $settings->time_format,
            ],
            'timezones' => $this->timezones(),
            'dateFormats' => $this->formatOptions(ShopSettings::DATE_FORMATS),
            'timeFormats' => $this->formatOptions(ShopSettings::TIME_FORMATS),
        ]);
    }

    public function updateShop(UpdateShopRequest $request): RedirectResponse
    {
        $tenant = $this->context->current();

        // The name lives on `tenants` — it is the shop's identity, not a
        // presentation setting, and the platform's own screens read it.
        $tenant->name = $request->validated('name');
        $tenant->save();

        // Explicit whitelist into a $guarded=[] model — never $request->all().
        $this->settings->update([
            'tagline' => $request->validated('tagline'),
            'timezone' => $request->validated('timezone'),
            'date_format' => $request->validated('date_format'),
            'time_format' => $request->validated('time_format'),
        ]);

        return back()->with('success', 'Nastavení obchodu bylo uloženo.');
    }

    public function editContacts(): Response
    {
        $settings = $this->settings->forCurrentTenant();

        return Inertia::render('Tenant/Settings/Contacts', [
            'contacts' => [
                'contact_email' => $settings->contact_email,
                'contact_phone' => $settings->contact_phone,
                'contact_street' => $settings->contact_street,
                'contact_city' => $settings->contact_city,
                'contact_zip' => $settings->contact_zip,
                'contact_country' => $settings->contact_country,
                'opening_hours' => $settings->opening_hours,
                'facebook_url' => $settings->facebook_url,
                'instagram_url' => $settings->instagram_url,
                'x_url' => $settings->x_url,
                'youtube_url' => $settings->youtube_url,
                'tiktok_url' => $settings->tiktok_url,
            ],
        ]);
    }

    public function updateContacts(UpdateContactsRequest $request): RedirectResponse
    {
        $this->settings->update($request->safe()->only([
            'contact_email',
            'contact_phone',
            'contact_street',
            'contact_city',
            'contact_zip',
            'contact_country',
            'opening_hours',
            'facebook_url',
            'instagram_url',
            'x_url',
            'youtube_url',
            'tiktok_url',
        ]));

        return back()->with('success', 'Kontaktní údaje byly uloženy.');
    }

    /**
     * The full IANA list would be nine hundred entries in a select. These are
     * the ones a shop billing in CZK plausibly runs in; anything else is still
     * accepted on write, so nothing here shuts a merchant out.
     *
     * @return list<array{value: string, label: string}>
     */
    private function timezones(): array
    {
        $zones = [
            'Europe/Prague' => 'Praha (Europe/Prague)',
            'Europe/Bratislava' => 'Bratislava (Europe/Bratislava)',
            'Europe/Vienna' => 'Vídeň (Europe/Vienna)',
            'Europe/Berlin' => 'Berlín (Europe/Berlin)',
            'Europe/Warsaw' => 'Varšava (Europe/Warsaw)',
            'Europe/Budapest' => 'Budapešť (Europe/Budapest)',
            'Europe/London' => 'Londýn (Europe/London)',
            'UTC' => 'UTC',
        ];

        $current = $this->settings->forCurrentTenant()->timezone;

        // A shop already running on something outside the shortlist must not
        // have it silently swapped by opening the form.
        if (! array_key_exists($current, $zones)) {
            $zones[$current] = $current;
        }

        return array_map(
            static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($zones),
            array_values($zones),
        );
    }

    /**
     * @param  list<string>  $formats
     * @return list<array{value: string, label: string}>
     */
    private function formatOptions(array $formats): array
    {
        // A fixed instant, so the preview reads the same on every request and
        // nobody has to decode "j. n. Y" to know what they are choosing.
        $moment = new \DateTimeImmutable('2026-03-09 14:05:00');

        return array_map(
            static fn (string $format): array => [
                'value' => $format,
                'label' => $moment->format($format),
            ],
            $formats,
        );
    }
}
