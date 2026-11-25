<?php

namespace Laravel\Doctor\Diagnostics;

use Exception;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Routing\Middleware\ValidateSignature;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\EnvironmentMode;
use Laravel\Doctor\Results\DiagnosticResult;
use Laravel\Doctor\Results\Link;
use Laravel\Doctor\Results\Message;
use ReflectionClass;

class ApplicationTrustedHostIsConfigured extends Diagnostic
{
    public string $name = 'Trusted host is configured';

    public string $group = 'security';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, string|Message>
     */
    protected function messages(): array
    {
        return [
            'configured' => 'Trusted host is configured for security-sensitive features.',
            'not-required' => 'Trusted host configuration is not required as no security-sensitive features are enabled.',
            'not-configured-local' => Message::make(
                summary: 'Trusted host is not configured while using password reset, email verification, or temporary signed URLs.',
                remediation: 'Configure trusted hosts in middleware.php or disable security-sensitive features if not needed.',
            )->link(Link::docs('middleware', 'trust-hosts')),
            'not-configured-production' => Message::make(
                summary: 'Trusted host must be configured in production while using password reset, email verification, or temporary signed URLs.',
                remediation: 'Configure trusted hosts in middleware.php to prevent host header attacks.',
            )->link(Link::docs('middleware', 'trust-hosts')),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (! $this->usesSecuritySensitiveFeatures()) {
            return $this->skip('not-required');
        }

        if ($this->trustedHostIsConfigured()) {
            return $this->pass('configured');
        }

        if (EnvironmentMode::current()->isProduction()) {
            return $this->fail('not-configured-production');
        }

        return $this->warn('not-configured-local');
    }

    /**
     * Determine if trusted host is configured.
     */
    private function trustedHostIsConfigured(): bool
    {
        try {
            $reflection = new ReflectionClass(TrustHosts::class);
            $property = $reflection->getProperty('alwaysTrust');
            $property->setAccessible(true);

            return $property->getValue() !== null;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Determine if the application uses security-sensitive features.
     */
    private function usesSecuritySensitiveFeatures(): bool
    {
        return $this->usesPasswordReset() || $this->usesEmailVerification() || $this->usesSignedUrls();
    }

    /**
     * Determine if password reset is enabled by checking for configured password brokers.
     */
    private function usesPasswordReset(): bool
    {
        try {
            $brokers = config('auth.passwords');

            if (! is_array($brokers) || empty($brokers)) {
                return false;
            }

            foreach ($brokers as $broker) {
                if (is_array($broker) && isset($broker['provider'])) {
                    $provider = $broker['provider'];
                    if (is_string($provider) && config("auth.providers.{$provider}") !== null) {
                        return true;
                    }
                }
            }

            return false;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Determine if email verification is enabled by checking User model's MustVerifyEmail trait.
     */
    private function usesEmailVerification(): bool
    {
        try {
            $model = config('auth.providers.users.model');

            if (! is_string($model) || $model === '' || ! class_exists($model)) {
                return false;
            }

            $traits = class_uses($model) ?: [];

            return isset($traits['Illuminate\\Contracts\\Auth\\MustVerifyEmail']) ||
                   isset($traits['Illuminate\\Foundation\\Auth\\User\\MustVerifyEmail']);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Determine if temporary signed URLs are used by inspecting routes.
     */
    private function usesSignedUrls(): bool
    {
        try {
            $router = app('router');
            if (! is_object($router) || ! method_exists($router, 'getRoutes')) {
                return false;
            }

            $routes = $router->getRoutes();
            if (! is_iterable($routes)) {
                return false;
            }

            foreach ($routes as $route) {
                if (! is_object($route) || ! method_exists($route, 'middleware') || ! method_exists($route, 'gatherMiddleware')) {
                    continue;
                }

                $middleware = array_merge(
                    is_array($route->middleware()) ? $route->middleware() : [],
                    is_array($route->gatherMiddleware()) ? $route->gatherMiddleware() : []
                );

                foreach ($middleware as $m) {
                    if ($this->isValidateSignatureMiddleware($m)) {
                        return true;
                    }
                }
            }

            return false;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Check if middleware matches ValidateSignature.
     */
    private function isValidateSignatureMiddleware(mixed $middleware): bool
    {
        if ($middleware instanceof ValidateSignature) {
            return true;
        }

        if (! is_string($middleware) && ! is_object($middleware)) {
            return false;
        }

        $middlewareClass = is_string($middleware) ? $middleware : $middleware::class;

        return $middlewareClass === ValidateSignature::class ||
               $middlewareClass === 'signed' ||
               str_ends_with((string) $middlewareClass, 'ValidateSignature');
    }
}
