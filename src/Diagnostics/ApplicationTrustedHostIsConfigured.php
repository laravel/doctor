<?php

namespace Laravel\Doctor\Diagnostics;

use Exception;
use Illuminate\Http\Middleware\TrustHosts;
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
     * Determine if password reset is enabled.
     */
    private function usesPasswordReset(): bool
    {
        try {
            $guard = config('auth.defaults.guard');
            if (! is_string($guard)) {
                return false;
            }

            $provider = config("auth.guards.{$guard}.provider");

            return is_string($provider) && config("auth.providers.{$provider}") !== null;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Determine if email verification is enabled.
     */
    private function usesEmailVerification(): bool
    {
        try {
            $model = config('auth.providers.users.model');

            return is_string($model) && $model !== '' && class_exists($model);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Determine if temporary signed URLs are used.
     */
    private function usesSignedUrls(): bool
    {
        $value = config('features.signed_urls', false);

        return is_bool($value) ? $value : false;
    }
}
