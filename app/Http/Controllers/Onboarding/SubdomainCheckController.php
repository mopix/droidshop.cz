<?php

namespace App\Http\Controllers\Onboarding;

use App\Core\Tenancy\SubdomainName;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubdomainCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $slug = SubdomainName::normalise((string) $request->query('slug', ''));

        $reason = match (true) {
            ! SubdomainName::isValidFormat($slug) => 'invalid',
            SubdomainName::isReserved($slug) => 'reserved',
            Domain::where('domain', SubdomainName::host($slug))->exists() => 'taken',
            default => 'ok',
        };

        return response()
            ->json(['available' => $reason === 'ok', 'reason' => $reason])
            ->header('Cache-Control', 'no-store, private');
    }
}
