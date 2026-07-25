@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Můj účet</h1>

    @if (session('status'))
        <p role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            {{ session('status') }}
        </p>
    @endif

    <p class="mt-2 text-slate-600">Vítejte, {{ $customer->fullName() ?: $customer->email }}.</p>

    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <a href="{{ route('storefront.customers.account.profile') }}"
           class="card block p-4 transition hover:border-brand hover:shadow-md">
            <h2 class="font-semibold text-slate-900">Moje údaje</h2>
            <p class="mt-1 text-sm text-slate-600">Jméno, telefon a heslo.</p>
        </a>

        <a href="{{ route('storefront.customers.account.addresses') }}"
           class="card block p-4 transition hover:border-brand hover:shadow-md">
            <h2 class="font-semibold text-slate-900">Moje adresy</h2>
            <p class="mt-1 text-sm text-slate-600">Fakturační a doručovací adresy.</p>
        </a>

        <a href="{{ route('storefront.customers.account.orders') }}"
           class="card block p-4 transition hover:border-brand hover:shadow-md">
            <h2 class="font-semibold text-slate-900">Moje objednávky</h2>
            <p class="mt-1 text-sm text-slate-600">Historie a stav vašich objednávek.</p>
        </a>
    </div>

    <form method="POST" action="{{ route('storefront.customers.logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="btn btn-outline">Odhlásit se</button>
    </form>
@endsection
