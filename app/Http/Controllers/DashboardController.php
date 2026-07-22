<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $shops = $request->user()->tenants()->with('primaryDomain')->get()
            ->map(fn ($t) => [
                'uuid' => $t->uuid,
                'name' => $t->name,
                'status' => $t->status->value,
                'host' => $t->primaryDomain?->domain,
            ]);

        return Inertia::render('Dashboard', ['shops' => $shops]);
    }
}
