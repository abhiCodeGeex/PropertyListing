<?php

namespace App\Providers;

use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Policies\ChatPolicy;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Maintenance\Policies\MaintenanceRequestPolicy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Gate::policy(Chat::class, ChatPolicy::class);
        Gate::policy(MaintenanceRequest::class, MaintenanceRequestPolicy::class);
        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));

        $this->warnOnInsufficientUploadLimits();
    }

    private function warnOnInsufficientUploadLimits(): void
    {
        $requiredMegabytes = 10;
        $uploadMax = $this->toMegabytes(ini_get('upload_max_filesize'));
        $postMax = $this->toMegabytes(ini_get('post_max_size'));

        if ($uploadMax < $requiredMegabytes || $postMax < $requiredMegabytes) {
            Log::warning('PHP upload limits are below application requirements.', [
                'required_mb' => $requiredMegabytes,
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'upload_tmp_dir' => ini_get('upload_tmp_dir'),
                'sys_temp_dir' => ini_get('sys_temp_dir'),
            ]);
        }
    }

    private function toMegabytes(string|false $value): float
    {
        if ($value === false || $value === '') {
            return 0;
        }

        $normalized = trim($value);
        $unit = strtolower(substr($normalized, -1));
        $size = (float) $normalized;

        return match ($unit) {
            'g' => $size * 1024,
            'm' => $size,
            'k' => $size / 1024,
            default => $size / 1048576,
        };
    }
}
