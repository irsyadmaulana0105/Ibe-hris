<x-guest-layout>
  @php($frontendUrl = \App\Helpers::getFrontendUrl())

  <x-authentication-card>
    <x-slot name="logo">
      <x-authentication-card-logo />
    </x-slot>

    <div class="mb-5 space-y-2">
      <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Portal Admin</h1>
      <p class="text-sm text-gray-600 dark:text-gray-300">
        Halaman ini hanya untuk akun admin dan superadmin.
      </p>
      <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
        Jika Anda login sebagai employee, gunakan halaman login frontend.
        <a href="{{ $frontendUrl }}" class="font-semibold underline hover:no-underline">
          Buka login employee
        </a>
      </div>
    </div>

    <x-validation-errors class="mb-4" />

    @session('status')
      <div class="mb-4 text-sm font-medium text-green-600 dark:text-green-400">
        {{ $value }}
      </div>
    @endsession

    <form method="POST" action="{{ route('login') }}">
      @csrf

      <div>
        <x-label for="email" value="{{ __('Email or Phone Admin') }}" />
        <x-input id="email" class="mt-1 block w-full" type="text" name="email" :value="old('email')" required
          autofocus autocomplete="username" />
      </div>

      <div class="mt-4">
        <x-label for="password" value="{{ __('Password') }}" />
        <x-input id="password" class="mt-1 block w-full" type="password" name="password" required
          autocomplete="current-password" />
      </div>

      <div class="mt-4 block">
        <label for="remember_me" class="flex items-center">
          <x-checkbox id="remember_me" name="remember" checked />
          <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Remember me') }}</span>
        </label>
      </div>

      @if (config('services.recaptcha.enabled') && config('services.recaptcha.site'))
        <div class="mt-4">
          <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site') }}"></div>
        </div>
      @endif

      <div class="mb-3 mt-4 flex items-center justify-end gap-3">
        <a
          href="{{ $frontendUrl }}"
          class="inline-flex items-center justify-center rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
        >
          Login Employee
        </a>

        <x-button>
          {{ __('Log in') }}
        </x-button>
      </div>
    </form>

    @if (Route::has('password.request'))
      <a class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:text-gray-400 dark:hover:text-gray-100 dark:focus:ring-offset-gray-800"
        href="{{ route('password.request') }}">
        {{ __('Forgot your password?') }}
      </a>
    @endif
  </x-authentication-card>

  @if (config('services.recaptcha.enabled') && config('services.recaptcha.site'))
    @push('scripts')
      <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endpush
  @endif
</x-guest-layout>
