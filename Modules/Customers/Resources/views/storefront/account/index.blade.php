@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Můj účet</h1>

    @if (session('status'))
        <p role="status" class="mt-4 rounded border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
            {{ session('status') }}
        </p>
    @endif

    <p class="mt-2 text-slate-600">Vítejte, {{ $customer->fullName() ?: $customer->email }}.</p>

    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <a href="{{ route('storefront.customers.account.profile') }}"
           class="block rounded border border-slate-200 p-4 hover:border-slate-400">
            <h2 class="font-semibold">Moje údaje</h2>
            <p class="mt-1 text-sm text-slate-600">Jméno, telefon a heslo.</p>
        </a>

        <a href="{{ route('storefront.customers.account.addresses') }}"
           class="block rounded border border-slate-200 p-4 hover:border-slate-400">
            <h2 class="font-semibold">Moje adresy</h2>
            <p class="mt-1 text-sm text-slate-600">Fakturační a doručovací adresy.</p>
        </a>

        <a href="{{ route('storefront.customers.account.orders') }}"
           class="block rounded border border-slate-200 p-4 hover:border-slate-400">
            <h2 class="font-semibold">Moje objednávky</h2>
            <p class="mt-1 text-sm text-slate-600">Historie a stav vašich objednávek.</p>
        </a>
    </div>

    <form method="POST" action="{{ route('storefront.customers.logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="rounded border border-slate-300 px-4 py-2 text-sm">Odhlásit se</button>
    </form>
@endsection
