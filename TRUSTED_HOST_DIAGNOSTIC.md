# Application Trusted Host Configuration Diagnostic

## Overview

The `ApplicationTrustedHostIsConfigured` diagnostic ensures that Laravel applications using security-sensitive features (password reset, email verification, or temporary signed URLs) are configured with trusted hosts to prevent host header attacks.

## Purpose

This diagnostic detects when an application uses features that rely on the request host being verified, and warns or fails if the application hasn't configured the `TrustHosts` middleware properly.

## Behavior

### Skip
The diagnostic is skipped if the application doesn't use any of the following features:
- Password reset authentication (configured password brokers)
- Email verification (User model with MustVerifyEmail trait)
- Temporary signed URLs (routes using ValidateSignature middleware)

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

The diagnostic detects security-sensitive features by:

1. **Password Reset**: Checks if password brokers are configured in `auth.passwords` with valid providers
2. **Email Verification**: Checks if the User model has the `Illuminate\Contracts\Auth\MustVerifyEmail` or `Illuminate\Foundation\Auth\User\MustVerifyEmail` trait
3. **Temporary Signed URLs**: Inspects all registered routes for the `Illuminate\Routing\Middleware\ValidateSignature` middleware

## Security Implications

Host header attacks can be exploited when:
- An attacker sends a request with a spoofed Host header
- The application uses that Host header to generate URLs (e.g., password reset links, email verification links)
- The user receives a URL pointing to an attacker-controlled domain

By configuring trusted hosts, you ensure that Laravel will reject requests with invalid Host headers, protecting against such attacks.

## Example Scenarios

### Scenario 1: Warn in Development
An application uses password reset but doesn't configure trusted hosts. The diagnostic will warn in development to remind developers to set up the configuration before deploying to production.

### Scenario 2: Fail in Production
An application uses email verification (detected via User model trait) and deploys to production without configuring trusted hosts. The diagnostic will fail, indicating a security vulnerability.

### Scenario 3: Detect Signed Routes
An application has routes using the `signed` middleware for temporary URLs. Even if no password reset or email verification is configured, the diagnostic will detect this and enforce trusted host configuration in production.

## See Also

- [Laravel Middleware - Trust Hosts](https://laravel.com/docs/middleware#trust-hosts)
- [OWASP - Host Header Attacks](https://owasp.org/www-project-web-security-testing-guide/stable/4-Web_Application_Security_Testing/07-Input_Validation_Testing/17-Testing_for_Host_Header_Injection)

