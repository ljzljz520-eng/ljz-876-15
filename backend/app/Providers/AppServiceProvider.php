<?php

namespace App\Providers;

use App\Services\FaceMatch\Contracts\FaceMatcher;
use App\Services\FaceMatch\LocalFaceMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FaceMatcher::class, function ($app) {
            // 仅 local 驱动内置；生产环境在此根据 config('identity.driver')
            // 绑定云厂商人脸核身驱动（需含活体检测与 1:1 比对）
            return new LocalFaceMatcher(
                (int) config('identity.threshold_pass', 80),
                (int) config('identity.threshold_suspect', 55),
            );
        });
    }

    public function boot(): void
    {
        $this->app['events']->listen(Response::class, function ($response) {
            if ($response instanceof JsonResponse) {
                $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE);
            }
        });
    }
}
