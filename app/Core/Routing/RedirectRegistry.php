<?php

namespace App\Core\Routing;

use App\Models\Redirect;

/**
 * Keeps old URLs answering after a rename (spec §15.3).
 *
 * Lives in the kernel because more than one module needs it — categories,
 * products and, later, the blog all rename things — and because a shop that
 * switched a module off must not lose the redirect history it produced.
 *
 * Chains are collapsed on write rather than followed on read. A visitor to a
 * twice-renamed path gets one hop instead of two, and there is no recursion
 * to bound at request time.
 */
class RedirectRegistry
{
    public function record(string $from, string $to, ?string $reason = null, int $status = 301): void
    {
        $from = $this->normalise($from);
        $to = $this->normalise($to);

        if ($from === $to) {
            return;
        }

        // Everything that pointed at the old path now points at the new one,
        // so /a -> /b -> /c resolves in a single hop.
        Redirect::query()->where('to_path', $from)->update(['to_path' => $to]);

        Redirect::query()->updateOrCreate(
            ['from_path' => $from],
            ['to_path' => $to, 'status' => $status, 'reason' => $reason],
        );

        // A rename back to a path we were redirecting away from would leave a
        // row pointing at itself — a loop for the visitor and a crawl trap.
        Redirect::query()->whereColumn('from_path', 'to_path')->delete();
    }

    public function resolve(string $path): ?string
    {
        return Redirect::query()
            ->where('from_path', $this->normalise($path))
            ->value('to_path');
    }

    public function statusFor(string $path): ?int
    {
        return Redirect::query()
            ->where('from_path', $this->normalise($path))
            ->value('status');
    }

    /**
     * One canonical spelling per URL. Without this, `/a`, `a` and `/a/` are
     * three different rows and two of them silently miss.
     */
    private function normalise(string $path): string
    {
        $path = '/'.trim(parse_url($path, PHP_URL_PATH) ?? $path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
