@extends('storefront::layouts.shop')

@section('content')
    <div class="mx-auto max-w-2xl">
        <h1 class="text-2xl font-semibold text-slate-900">Nastavení cookies</h1>

        <p class="mt-3 text-slate-700">
            Zvolte, co smíme na tomto e-shopu spustit. Rozhodnutí můžete kdykoli změnit
            — na tuhle stránku vede odkaz v patičce.
        </p>

        @if (session('status'))
            <p class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('consent.store') }}" class="mt-6 space-y-4">
            @csrf

            @foreach ($categories as $category)
                <div class="rounded-md border border-slate-200 p-4">
                    <label class="flex items-start gap-3">
                        <input type="checkbox"
                               name="kategorie[]"
                               value="{{ $category->value }}"
                               @checked(! $category->isRefusable() || ($consent?->allows($category) ?? false))
                               @disabled(! $category->isRefusable())
                               class="mt-1 rounded border-slate-300 text-brand focus:ring-brand">
                        <span>
                            <span class="font-medium text-slate-900">{{ $category->label() }}</span>
                            <span class="mt-1 block text-sm text-slate-600">{{ $category->description() }}</span>
                        </span>
                    </label>
                </div>
            @endforeach

            <div class="flex flex-wrap gap-3 pt-2">
                <button type="submit" class="btn btn-primary">Uložit volbu</button>

                {{-- Same classes as each other, for the same reason as in the banner. --}}
                <button type="submit" name="volba" value="vse"
                        class="rounded-md border border-slate-300 bg-slate-100 px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200">
                    Přijmout vše
                </button>
                <button type="submit" name="volba" value="nic"
                        class="rounded-md border border-slate-300 bg-slate-100 px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200">
                    Odmítnout vše
                </button>
            </div>
        </form>
    </div>
@endsection
