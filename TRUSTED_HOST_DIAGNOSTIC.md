# Application Trusted Host Configuration Diagnostic

## Overview

The `ApplicationTrustedHostIsConfigured` diagnostic ensures that Laravel applications using security-sensitive features (password reset, email verification, or temporary signed URLs) are configured with trusted hosts to prevent host header attacks.

## Purpose

This diagnostic detects when an application uses features that rely on the request host being verified, and warns or fails if the application hasn't configured the `TrustHosts` middleware properly.

## Behavior

### Skip
The diagnostic is skipped if the application doesn't use any of the following features:
- Password reset authentication
- Email verification
- Temporary signed URLs

### Pass
The diagnostic passes when:
- Trusted hosts are configured AND
- The application uses at least one of the security-sensitive features

### Warn (Local Environments)
The diagnostic warns in local environments when:
- Trusted hosts are NOT configured AND
- The application uses at least one of the security-sensitive features

### Fail (Production Environments)
The diagnostic fails in production environments when:
- Trusted hosts are NOT configured AND
- The application uses at least one of the security-sensitive features

## Configuration

To configure trusted hosts in your Laravel application, update your `bootstrap/app.php` file:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustHosts(at: [
        'example.com',
        'www.example.com',
        // Add other trusted hosts...
    ]);
})
```

Or use subdomains:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustHosts();  // Trusts subdomains of APP_URL
})
```

## Feature Detection

The diagnostic detects security-sensitive features by checking:

1. **Password Reset**: Checks if a default auth guard is configured with a provider
2. **Email Verification**: Checks if a User model is configured in auth
3. **Temporary Signed URLs**: Checks if `config('features.signed_urls')` is enabled

## Security Implications

Host header attacks can be exploited when:
- An attacker sends a request with a spoofed Host header
- The application uses that Host header to generate URLs (e.g., password reset links, email verification links)
- The user receives a URL pointing to an attacker-controlled domain

By configuring trusted hosts, you ensure that Laravel will reject requests with invalid Host headers, protecting against such attacks.

## See Also

- [Laravel Middleware - Trust Hosts](https://laravel.com/docs/middleware#trust-hosts)
- [OWASP - Host Header Attacks](https://owasp.org/www-project-web-security-testing-guide/stable/4-Web_Application_Security_Testing/07-Input_Validation_Testing/17-Testing_for_Host_Header_Injection)
