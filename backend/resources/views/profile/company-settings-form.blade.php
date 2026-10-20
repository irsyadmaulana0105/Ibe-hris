<x-form-section submit="updateCompanySettings">
  <x-slot name="title">
    Pengaturan Perusahaan
  </x-slot>

  <x-slot name="description">
    Atur email keuangan dan tanda tangan untuk slip gaji.
  </x-slot>

  <x-slot name="form">
    <div class="col-span-6 sm:col-span-4">
      <x-label for="finance_email" value="Email Keuangan" />
      <x-input id="finance_email" type="email" class="mt-1 block w-full" wire:model="finance_email" />
      <x-input-error for="finance_email" class="mt-2" />
    </div>

    <div class="col-span-6 sm:col-span-4">
      <x-label for="finance_signature" value="TTD Keuangan (Upload)" />
      <input id="finance_signature" type="file" class="mt-1 block w-full" wire:model="finance_signature"
        accept="image/*" />
      <x-input-error for="finance_signature" class="mt-2" />
      @if ($current_signature_url)
        <div class="mt-3">
          <div class="text-xs text-gray-500">Preview TTD Keuangan</div>
          <img src="{{ $current_signature_url }}" alt="TTD Keuangan" class="mt-2 h-16 w-auto" />
        </div>
      @endif
    </div>

    <div class="col-span-6 sm:col-span-4">
      <x-label for="hrd_signature" value="TTD HRD (Upload)" />
      <input id="hrd_signature" type="file" class="mt-1 block w-full" wire:model="hrd_signature"
        accept="image/*" />
      <x-input-error for="hrd_signature" class="mt-2" />
      @if ($current_hrd_signature_url)
        <div class="mt-3">
          <div class="text-xs text-gray-500">Preview TTD HRD</div>
          <img src="{{ $current_hrd_signature_url }}" alt="TTD HRD" class="mt-2 h-16 w-auto" />
        </div>
      @endif
    </div>
  </x-slot>

  <x-slot name="actions">
    <x-action-message class="me-3" on="saved">
      {{ __('Saved.') }}
    </x-action-message>

    <x-button wire:loading.attr="disabled" wire:target="finance_signature">
      {{ __('Save') }}
    </x-button>
  </x-slot>
</x-form-section>
