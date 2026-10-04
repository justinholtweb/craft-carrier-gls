<?php

namespace justinholtweb\carriergls\auth;

use justinholtweb\carrier\auth\BaseAuth;

/**
 * MyGLS authentication: credentials in the body of every request, not in a header.
 *
 * MyGLS has no tokens and no Authorization header. Every method's JSON body starts with
 * `Username` (the MyGLS portal login) and `Password`, where `Password` is the SHA-512 digest of the
 * plaintext password as **raw bytes serialised as a JSON array of 64 integers** (0–255). The SOAP
 * flavour of the same API takes those bytes base64-encoded; the JSON flavour does not, and sending
 * a hex string or base64 there gets an "Unauthorized" error list back on an HTTP 200.
 *
 * The strategy contributes no headers and no query; the carrier merges `body()` into each payload.
 * It is still a strategy so that `Carrier::test()` can tell an incomplete form from a bad login.
 */
class MyGlsAuth extends BaseAuth
{
    public function headers(): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        return (string)$this->setting('username') !== ''
            && (string)$this->setting('password') !== ''
            && (string)$this->setting('clientNumber') !== '';
    }

    /**
     * The authentication members of an `APIRequestBase`.
     *
     * `ClientNumberList` is omitted on purpose: the MyGLS manual says "do not use" it, and the
     * client number goes on each parcel instead.
     */
    public function body(): array
    {
        return [
            'Username' => (string)$this->setting('username'),
            'Password' => self::hashPassword((string)$this->setting('password')),
        ];
    }

    /**
     * SHA-512 of the password as 64 unsigned bytes, the way MyGLS's JSON binding expects a
     * `byte[]`. Public so the carrier can redact the digest from its log: it is not the password,
     * but MyGLS accepts it in place of one.
     *
     * @return int[]
     */
    public static function hashPassword(string $plain): array
    {
        return array_values(unpack('C*', hash('sha512', $plain, true)));
    }
}
