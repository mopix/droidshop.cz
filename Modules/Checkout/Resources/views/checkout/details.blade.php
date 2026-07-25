@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Údaje a rekapitulace</h1>

    @if (session('status'))
        <p role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            {{ session('status') }}
        </p>
    @endif

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
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

            <fieldset class="card space-y-4 p-4">
                <legend class="px-1 text-lg font-medium text-slate-900">Kontaktní údaje</legend>

                <div>
                    <label for="email" class="field-label">E-mail</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                           class="field-input">
                </div>

                <div>
                    <label for="phone" class="field-label">Telefon</label>
                    <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required autocomplete="tel"
                           class="field-input">
                </div>
            </fieldset>

            <fieldset class="card space-y-4 p-4">
                <legend class="px-1 text-lg font-medium text-slate-900">Fakturační adresa</legend>

                <div>
                    <label for="name" class="field-label">Jméno a příjmení</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required autocomplete="name"
                           class="field-input">
                </div>

                <div>
                    <label for="street" class="field-label">Ulice a číslo popisné</label>
                    <input id="street" type="text" name="street" value="{{ old('street') }}" required autocomplete="street-address"
                           class="field-input">
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="city" class="field-label">Město</label>
                        <input id="city" type="text" name="city" value="{{ old('city') }}" required autocomplete="address-level2"
                               class="field-input">
                    </div>
                    <div>
                        <label for="zip" class="field-label">PSČ</label>
                        <input id="zip" type="text" name="zip" value="{{ old('zip') }}" required autocomplete="postal-code"
                               class="field-input">
                    </div>
                </div>

                <div>
                    <label for="country" class="field-label">Země (kód, např. CZ)</label>
                    <input id="country" type="text" name="country" value="{{ old('country', 'CZ') }}" required maxlength="2"
                           class="field-input w-24 uppercase">
                </div>
            </fieldset>

            <fieldset class="card space-y-4 p-4">
                <legend class="px-1 text-lg font-medium text-slate-900">Nákup na firmu <span class="font-normal text-slate-500">(nepovinné)</span></legend>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-3">
                        <label for="company" class="field-label">Firma</label>
                        <input id="company" type="text" name="company" value="{{ old('company') }}" autocomplete="organization"
                               class="field-input">
                    </div>
                    <div>
                        <label for="ico" class="field-label">IČO</label>
                        <input id="ico" type="text" name="ico" value="{{ old('ico') }}" inputmode="numeric"
                               class="field-input">
                    </div>
                    <div>
                        <label for="dic" class="field-label">DIČ</label>
                        <input id="dic" type="text" name="dic" value="{{ old('dic') }}"
                               class="field-input">
                    </div>
                </div>
            </fieldset>

            <fieldset class="card space-y-4 p-4">
                <legend class="px-1 text-lg font-medium text-slate-900">Doručovací adresa</legend>

                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="ship_to_different" value="1" @checked(old('ship_to_different'))
                           class="rounded border-slate-300 text-brand focus:ring-brand">
                    <span>Doručit na jinou adresu než fakturační</span>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="delivery_name" class="field-label">Jméno pro doručení</label>
                        <input id="delivery_name" type="text" name="delivery_name" value="{{ old('delivery_name') }}"
                               class="field-input">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="delivery_street" class="field-label">Ulice a číslo popisné</label>
                        <input id="delivery_street" type="text" name="delivery_street" value="{{ old('delivery_street') }}"
                               class="field-input">
                    </div>
                    <div>
                        <label for="delivery_city" class="field-label">Město</label>
                        <input id="delivery_city" type="text" name="delivery_city" value="{{ old('delivery_city') }}"
                               class="field-input">
                    </div>
                    <div>
                        <label for="delivery_zip" class="field-label">PSČ</label>
                        <input id="delivery_zip" type="text" name="delivery_zip" value="{{ old('delivery_zip') }}"
                               class="field-input">
                    </div>
                    <div>
                        <label for="delivery_country" class="field-label">Země (kód)</label>
                        <input id="delivery_country" type="text" name="delivery_country" value="{{ old('delivery_country', 'CZ') }}" maxlength="2"
                               class="field-input w-24 uppercase">
                    </div>
                </div>
            </fieldset>

            <div>
                <label for="note" class="field-label">Poznámka k objednávce <span class="font-normal text-slate-500">(nepovinné)</span></label>
                <textarea id="note" name="note" rows="3"
                          class="field-input">{{ old('note') }}</textarea>
            </div>

            <label class="flex items-start gap-2 text-sm text-slate-700">
                <input type="checkbox" name="terms" value="1" required @checked(old('terms'))
                       class="mt-1 rounded border-slate-300 text-brand focus:ring-brand">
                <span>Souhlasím s obchodními podmínkami a zpracováním osobních údajů.</span>
            </label>

            <button type="submit" class="btn btn-primary w-full sm:w-auto">
                Objednat s povinností platby
            </button>
        </form>

        <aside aria-label="Rekapitulace objednávky" class="card h-fit space-y-4 p-4 lg:sticky lg:top-8">
            <h2 class="text-lg font-medium text-slate-900">Rekapitulace</h2>

            <ul class="divide-y divide-slate-100 text-sm text-slate-700">
                @foreach ($cart->lines as $line)
                    <li class="flex justify-between gap-2 py-2">
                        <span>{{ $line->quantity }}× {{ $line->name }}</span>
                        <span class="whitespace-nowrap font-medium text-slate-900">{{ $line->lineTotal->format() }}</span>
                    </li>
                @endforeach
            </ul>

            <dl class="space-y-1 border-t border-slate-200 pt-3 text-sm text-slate-700">
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
                <table class="w-full border-t border-slate-200 pt-3 text-sm text-slate-700">
                    <caption class="pt-3 text-left font-medium text-slate-900">Rozpis DPH</caption>
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

            <p class="flex justify-between border-t border-slate-200 pt-3 text-lg font-semibold text-slate-900">
                <span>Celkem</span>
                <span>{{ $total->format() }}</span>
            </p>
        </aside>
    </div>
@endsection
