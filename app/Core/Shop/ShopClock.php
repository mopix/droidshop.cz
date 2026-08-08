<?php

namespace App\Core\Shop;

use App\Core\Tenancy\TenantContext;
use App\Models\ShopSettings;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Renders a moment in the shop's own time zone and format (wave 3.7).
 *
 * Wave 3.6 stored the time zone and the date/time format and then used
 * neither, so an order placed at 23:30 UTC showed as the day before to a
 * merchant in Prague. This is the one place that turns a stored instant into
 * something a person reads.
 *
 * One place rather than a `->format('d.m.Y')` at each call site, because the
 * setting exists precisely so those call sites stop deciding for themselves —
 * and the next one added would decide wrong.
 *
 * Deliberately NOT used for machine formats. ISDOC, Pohoda XML, the feeds and
 * the sitemap all print `Y-m-d` because a schema says so; running those
 * through a merchant's display preference would produce a file no importer
 * accepts.
 *
 * Nothing here changes what is stored. Timestamps stay in UTC; only the
 * reading of them moves.
 */
class ShopClock
{
    private ?array $memo = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ShopSettingsService $settings,
    ) {}

    public function formatDate(?DateTimeInterface $moment): ?string
    {
        return $this->render($moment, withTime: false);
    }

    public function formatDateTime(?DateTimeInterface $moment): ?string
    {
        return $this->render($moment, withTime: true);
    }

    /**
     * A calendar date that is already a date, not an instant.
     *
     * A DATE column (the taxable date and the due date on a document) carries
     * no time and no zone. Running it through a zone conversion could move it
     * to the day before, which on a tax document is not a display detail —
     * it is a different tax period. Formatted, never shifted.
     */
    public function formatCalendarDate(?DateTimeInterface $date): ?string
    {
        return $date === null
            ? null
            : Carbon::instance(\DateTime::createFromInterface($date))->format($this->resolved()['date_format']);
    }

    public function timezone(): string
    {
        return $this->resolved()['timezone'];
    }

    private function render(?DateTimeInterface $moment, bool $withTime): ?string
    {
        if ($moment === null) {
            return null;
        }

        $settings = $this->resolved();

        $format = $withTime
            ? $settings['date_format'].' '.$settings['time_format']
            : $settings['date_format'];

        return Carbon::instance(
            $moment instanceof \DateTime ? $moment : \DateTime::createFromInterface($moment)
        )->setTimezone(new DateTimeZone($settings['timezone']))->format($format);
    }

    /**
     * @return array{timezone: string, date_format: string, time_format: string}
     */
    private function resolved(): array
    {
        // Memoised per instance, and the instance is scoped to the request:
        // a listing of two hundred orders must not be two hundred queries.
        if ($this->memo !== null) {
            return $this->memo;
        }

        $settings = $this->context->current() === null
            ? new ShopSettings
            : $this->settings->forCurrentTenant();

        return $this->memo = [
            'timezone' => $settings->timezone,
            'date_format' => $settings->date_format,
            'time_format' => $settings->time_format,
        ];
    }
}
