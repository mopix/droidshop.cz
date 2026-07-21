@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Údaje a rekapitulace</h1>

    @if (session('status'))
        <p role="status" class="mt-4 rounded border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
            {{ session('status') }}
        </p>
    @endif

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-6 grid gap-8 lg:grid-cols-3">
        <form method="POST" action="{{ route('storefront.checkout.place') }}" class="space-y-6 lg:col-span-2">
            @csrf
            {{-- The idempotency key: a double submit of this same form returns the one order already placed (AK 2). --}}
            <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">

            <fieldset class="space-y-4">
                <legend class="text-lg font-medium">Kontaktní údaje</legend>

                <div>
                    <label for="email" class="block text-sm font-medium">E-mail</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium">Telefon</label>
                    <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required autocomplete="tel"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>
            </fieldset>

            <fieldset class="space-y-4">
                <legend class="text-lg font-medium">Fakturační adresa</legend>

                <div>
                    <label for="name" class="block text-sm font-medium">Jméno a příjmení</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required autocomplete="name"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>

                <div>
                    <label for="street" class="block text-sm font-medium">Ulice a číslo popisné</label>
                    <input id="street" type="text" name="street" value="{{ old('street') }}" required autocomplete="street-address"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="city" class="block text-sm font-medium">Město</label>
                        <input id="city" type="text" name="city" value="{{ old('city') }}" required autocomplete="address-level2"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="zip" class="block text-sm font-medium">PSČ</label>
                        <input id="zip" type="text" name="zip" value="{{ old('zip') }}" required autocomplete="postal-code"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                </div>

                <div>
                    <label for="country" class="block text-sm font-medium">Země (kód, např. CZ)</label>
                    <input id="country" type="text" name="country" value="{{ old('country', 'CZ') }}" required maxlength="2"
                           class="mt-1 w-24 rounded border border-slate-300 px-3 py-2 uppercase">
                </div>
            </fieldset>

            <fieldset class="space-y-4">
                <legend class="text-lg font-medium">Nákup na firmu (nepovinné)</legend>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-3">
                        <label for="company" class="block text-sm font-medium">Firma</label>
                        <input id="company" type="text" name="company" value="{{ old('company') }}" autocomplete="organization"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="ico" class="block text-sm font-medium">IČO</label>
                        <input id="ico" type="text" name="ico" value="{{ old('ico') }}" inputmode="numeric"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="dic" class="block text-sm font-medium">DIČ</label>
                        <input id="dic" type="text" name="dic" value="{{ old('dic') }}"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                </div>
            </fieldset>

            <fieldset class="space-y-4">
                <legend class="text-lg font-medium">Doručovací adresa</legend>

                <label class="flex items-center gap-2">
                    <input type="checkbox" name="ship_to_different" value="1" @checked(old('ship_to_different'))>
                    <span>Doručit na jinou adresu než fakturační</span>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="delivery_name" class="block text-sm font-medium">Jméno pro doručení</label>
                        <input id="delivery_name" type="text" name="delivery_name" value="{{ old('delivery_name') }}"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="delivery_street" class="block text-sm font-medium">Ulice a číslo popisné</label>
                        <input id="delivery_street" type="text" name="delivery_street" value="{{ old('delivery_street') }}"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="delivery_city" class="block text-sm font-medium">Město</label>
                        <input id="delivery_city" type="text" name="delivery_city" value="{{ old('delivery_city') }}"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="delivery_zip" class="block text-sm font-medium">PSČ</label>
                        <input id="delivery_zip" type="text" name="delivery_zip" value="{{ old('delivery_zip') }}"
                               class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label for="delivery_country" class="block text-sm font-medium">Země (kód)</label>
                        <input id="delivery_country" type="text" name="delivery_country" value="{{ old('delivery_country', 'CZ') }}" maxlength="2"
                               class="mt-1 w-24 rounded border border-slate-300 px-3 py-2 uppercase">
                    </div>
                </div>
            </fieldset>

            <div>
                <label for="note" class="block text-sm font-medium">Poznámka k objednávce (nepovinné)</label>
                <textarea id="note" name="note" rows="3"
                          class="mt-1 w-full rounded border border-slate-300 px-3 py-2">{{ old('note') }}</textarea>
            </div>

            <label class="flex items-start gap-2">
                <input type="checkbox" name="terms" value="1" required @checked(old('terms')) class="mt-1">
                <span>Souhlasím s obchodními podmínkami a zpracováním osobních údajů.</span>
            </label>

            <button type="submit" class="rounded bg-slate-900 px-5 py-3 text-white">
                Objednat s povinností platby
            </button>
        </form>

        <aside aria-label="Rekapitulace objednávky" class="space-y-4 rounded border border-slate-200 p-4">
            <h2 class="text-lg font-medium">Rekapitulace</h2>

            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($cart->lines as $line)
                    <li class="flex justify-between gap-2 py-2">
                        <span>{{ $line->quantity }}× {{ $line->name }}</span>
                        <span class="whitespace-nowrap">{{ $line->lineTotal->format() }}</span>
                    </li>
                @endforeach
            </ul>

            <dl class="space-y-1 border-t border-slate-200 pt-3 text-sm">
                <div class="flex justify-between">
                    <dt>Mezisoučet</dt>
                    <dd>{{ $cart->itemsTotal->format() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Doprava @if ($usingFallback)(osobní odběr)@elseif ($shipping){{ ' — '.$shipping->name() }}@endif</dt>
                    <dd>{{ $shippingCost->isZero() ? 'zdarma' : $shippingCost->format() }}</dd>
                </div>
                @if ($payment)
                    <div class="flex justify-between">
                        <dt>Platba — {{ $payment->name() }}</dt>
                        <dd>{{ $paymentFee->isZero() ? 'zdarma' : $paymentFee->format() }}</dd>
                    </div>
                @endif
            </dl>

            @if ($vatBreakdown !== [])
                <table class="w-full border-t border-slate-200 pt-3 text-sm">
                    <caption class="pt-3 text-left font-medium">Rozpis DPH</caption>
                    <thead>
                        <tr class="text-left text-slate-500">
                            <th scope="col" class="font-normal">Sazba</th>
                            <th scope="col" class="text-right font-normal">Základ</th>
                            <th scope="col" class="text-right font-normal">DPH</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($vatBreakdown as $row)
                            <tr>
                                <td>{{ rtrim(rtrim(number_format($row['rate'], 2, ',', ' '), '0'), ',') }} %</td>
                                <td class="text-right">{{ (new \App\Core\Money\Money($row['base'], $cart->itemsTotal->currency))->format() }}</td>
                                <td class="text-right">{{ (new \App\Core\Money\Money($row['vat'], $cart->itemsTotal->currency))->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <p class="flex justify-between border-t border-slate-200 pt-3 text-lg font-semibold">
                <span>Celkem</span>
                <span>{{ $total->format() }}</span>
            </p>
        </aside>
    </div>
@endsection
