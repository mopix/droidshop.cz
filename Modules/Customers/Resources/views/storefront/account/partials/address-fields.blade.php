{{--
    Shared fields for the "add address" and "edit address" forms.
    $address is null when adding, the owned CustomerAddress when editing —
    every value falls back through old() first so a failed submission does
    not blank the form.
--}}
@php
    $address ??= null;
@endphp

<div>
    <span class="block text-sm font-medium">Typ adresy</span>
    <div class="mt-1 flex gap-4">
        <label class="flex items-center gap-2 text-sm">
            <input type="radio" name="kind" value="shipping"
                   @checked(old('kind', $address->kind ?? 'shipping') === 'shipping') required>
            Doručovací
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input type="radio" name="kind" value="billing"
                   @checked(old('kind', $address->kind ?? 'shipping') === 'billing')>
            Fakturační
        </label>
    </div>
    @error('kind') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
</div>

<div>
    <label for="company" class="block text-sm font-medium">Firma <span class="font-normal text-slate-500">(nepovinné)</span></label>
    <input id="company" name="company" type="text" value="{{ old('company', $address->company ?? '') }}"
           class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
    @error('company') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <label for="reg_no" class="block text-sm font-medium">IČO <span class="font-normal text-slate-500">(nepovinné)</span></label>
        <input id="reg_no" name="reg_no" type="text" value="{{ old('reg_no', $address->reg_no ?? '') }}"
               class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        @error('reg_no') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="vat_no" class="block text-sm font-medium">DIČ <span class="font-normal text-slate-500">(nepovinné)</span></label>
        <input id="vat_no" name="vat_no" type="text" value="{{ old('vat_no', $address->vat_no ?? '') }}"
               class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        @error('vat_no') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
    </div>
</div>

<div>
    <label for="street" class="block text-sm font-medium">Ulice a číslo popisné</label>
    <input id="street" name="street" type="text" value="{{ old('street', $address->street ?? '') }}" required
           autocomplete="address-line1" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
    @error('street') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
</div>

<div class="grid grid-cols-2 gap-4">
    <div>
        <label for="city" class="block text-sm font-medium">Město</label>
        <input id="city" name="city" type="text" value="{{ old('city', $address->city ?? '') }}" required
               autocomplete="address-level2" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        @error('city') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="zip" class="block text-sm font-medium">PSČ</label>
        <input id="zip" name="zip" type="text" value="{{ old('zip', $address->zip ?? '') }}" required
               autocomplete="postal-code" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        @error('zip') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
    </div>
</div>

<div>
    <label for="country" class="block text-sm font-medium">Země (kód, např. CZ)</label>
    <input id="country" name="country" type="text" maxlength="2" value="{{ old('country', $address->country ?? 'CZ') }}"
           required autocomplete="country" class="mt-1 w-24 rounded border border-slate-300 px-3 py-2 uppercase">
    @error('country') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
</div>

<div>
    <label for="is_default" class="flex items-center gap-2 text-sm">
        <input id="is_default" name="is_default" type="checkbox" value="1"
               @checked(old('is_default', $address->is_default ?? false))>
        Nastavit jako výchozí adresu tohoto typu
    </label>
</div>
