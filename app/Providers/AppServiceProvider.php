<?php

namespace App\Providers;

use App\Repositories\ApiUser\ApiUserRepository;
use App\Repositories\Auth\OtpRepository;
use App\Repositories\Contracts\ApiUser\ApiUserRepositoryInterface;
use App\Repositories\Contracts\Auth\OtpRepositoryInterface;
use App\Repositories\Contracts\Theme\ThemeRepositoryInterface;
use App\Repositories\Contracts\User\UserRepositoryInterface;
use App\Repositories\Theme\ThemeRepository;
use App\Repositories\User\UserRepository;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(OtpRepositoryInterface::class, OtpRepository::class);
        $this->app->bind(ApiUserRepositoryInterface::class, ApiUserRepository::class);
        $this->app->bind(ThemeRepositoryInterface::class, ThemeRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        ResetPassword::createUrlUsing(function ($user, string $token) {
            return config('app.frontend_url')."/reset-password?token={$token}&email=".urlencode($user->email);
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('customer-login', function (Request $request) {
            return Limit::perMinute(5)->by($this->loginThrottleKey($request));
        });

        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(5)->by($this->loginThrottleKey($request));
        });

        RateLimiter::for('customer-otp-send', function (Request $request) {
            $window = max(1, (int) config('security.otp_minutes', 5));
            $max = max(1, (int) config('security.otp_send_max_per_window', 3));

            return Limit::perMinutes($window, $max)->by($this->otpThrottleKey($request));
        });

        RateLimiter::for('customer-otp-verify', function (Request $request) {
            $window = max(1, (int) config('security.otp_minutes', 5));
            $max = max(1, (int) config('security.otp_verify_max_per_window', 20));

            return Limit::perMinutes($window, $max)->by($this->otpThrottleKey($request));
        });

        RateLimiter::for('admin-otp-send', function (Request $request) {
            $window = max(1, (int) config('security.otp_minutes', 5));
            $max = max(1, (int) config('security.otp_send_max_per_window', 3));

            return Limit::perMinutes($window, $max)->by($this->otpThrottleKey($request));
        });

        RateLimiter::for('admin-otp-verify', function (Request $request) {
            $window = max(1, (int) config('security.otp_minutes', 5));
            $max = max(1, (int) config('security.otp_verify_max_per_window', 20));

            return Limit::perMinutes($window, $max)->by($this->otpThrottleKey($request));
        });

        RateLimiter::for('api-user-dev-registration', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        RateLimiter::for('customer-register', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        RateLimiter::for('public-leaderboard', function (Request $request) {
            return Limit::perMinute(60)->by((string) $request->ip());
        });

        RateLimiter::for('public-receipt', function (Request $request) {
            return Limit::perMinute(30)->by((string) $request->ip());
        });
    }

    private function loginThrottleKey(Request $request): string
    {
        $ip = (string) $request->ip();
        $email = strtolower(trim((string) $request->input('email')));

        return $email !== '' ? $ip.'|'.$email : $ip;
    }

    private function otpThrottleKey(Request $request): string
    {
        $ip = (string) $request->ip();
        $token = (string) $request->input('challenge_token');

        return $token !== '' ? $ip.'|'.hash('sha256', $token) : $ip;
    }
}
