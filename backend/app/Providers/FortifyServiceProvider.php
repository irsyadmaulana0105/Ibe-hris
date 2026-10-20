<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    private function redirectToPortalSso(): RedirectResponse
    {
        // WAJIB target portal.ibereality.com - kalau pakai erp.base_url
        // (app-erp.ibereality.com) akan redirect ke diri sendiri = infinite loop.
        $portalUrl = rtrim((string) env('SSO_PORTAL_URL', 'https://portal.ibereality.com'), '/');
        $callback = '/login?target_app=erp&next=/launch/erp';

        return redirect()->away($portalUrl . $callback);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->instance(LoginResponse::class, new class implements LoginResponse
        {
            public function toResponse($request)
            {
                if (Auth::user()) {
                    return redirect('/admin');
                }

                return redirect('/');
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView(fn () => $this->redirectToPortalSso());
        Fortify::registerView(fn () => $this->redirectToPortalSso());
        Fortify::requestPasswordResetLinkView(fn () => $this->redirectToPortalSso());

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())) . '|' . $request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('livewire', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
