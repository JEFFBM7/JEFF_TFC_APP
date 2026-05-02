<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip().'|'.$request->input('email'));
        });

        // Swagger / OpenAPI : déclare le schéma de sécurité Bearer (Sanctum)
        // dans `components.securitySchemes`, puis l'applique uniquement aux
        // opérations dont la route porte le middleware `auth:sanctum` (pas
        // au niveau global, pour ne pas faire apparaître /health ou /auth/login
        // comme protégés). Le bouton "Authorize" de Swagger UI permet ensuite
        // de coller le token retourné par POST /api/v1/auth/login.
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->components->addSecurityScheme(
                    'bearerAuth',
                    SecurityScheme::http('bearer')
                        ->as('bearerAuth')
                        ->setDescription('Token Sanctum obtenu via POST /api/v1/auth/login. À placer dans l\'en-tête : Authorization: Bearer <token>.')
                );
            })
            ->withOperationTransformers(function (Operation $operation, RouteInfo $routeInfo): void {
                $middlewares = $routeInfo->route->gatherMiddleware();
                $needsAuth = collect($middlewares)->contains(
                    fn ($m) => is_string($m) && str_starts_with($m, 'auth:')
                );

                if ($needsAuth) {
                    $operation->addSecurity(new SecurityRequirement(['bearerAuth' => []]));
                }
            });
    }
}
