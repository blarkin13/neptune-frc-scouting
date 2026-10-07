# Neptune Public 1.0 self-host first run

A clean Neptune installation is initialized from `/Neptune/register.php`.

## First organization

When the `organizations` table is empty, registration is always allowed even if
`allow_public_registration` is false. The first organization should receive ID 1
on a clean database and is the default platform organization.

The first registration creates the organization, its unique sign-in slug, the
first Owner user, and (when provided) the FRC team and primary-team assignment.

## Additional organization registration

`config.php` controls whether later organizations may self-register:

```php
'allow_public_registration' => false,
```

Keep this false for a private team installation. Set it true only when the
installation is intentionally operating as a multi-tenant hosted Neptune service.

## Platform-owner authentication

Platform Owners require strong authentication for high-value tools such as
File Manager, Maintenance Console, and Platform Administration.

The Public 1.0 policy is:

- Google sign-in, or
- Neptune password + TOTP authenticator code.

A self-hosted school does not need Gmail or Google Workspace. On the first
Platform Owner password login, Neptune guides the owner through Google Authenticator enrollment with a QR
code and then displays one-time recovery codes.

The configuration setting is:

```php
'platform_owner_auth' => 'strong',
```

Use `google` only when an installation deliberately wants Google-only platform
administration.

For new installations, configure a stable TOTP encryption key with either
`app.mfa_encryption_key` in the private configuration or
`NEPTUNE_MFA_ENCRYPTION_KEY` in `/etc/scout/neptune.env`.


## QR-code generation

The enrollment QR code is generated in the browser from the `otpauth://` value.
Neptune does not send the TOTP setup secret to an online QR-code generation
service. The QR rendering library is pinned to QRCode.js 1.0.0 with Subresource
Integrity (SRI); if it cannot load, the manual Google Authenticator setup key
remains available.
