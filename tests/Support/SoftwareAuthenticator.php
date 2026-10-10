<?php

declare(strict_types=1);

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

/**
 * A software WebAuthn authenticator (ES256, attestation "none") for feature tests. It answers the
 * browser options of the Laravel Passkeys endpoints, so the package's real validators run, including
 * the origin and relying-party checks.
 */
final class SoftwareAuthenticator
{
    private readonly OpenSSLAsymmetricKey $key;

    private readonly string $credentialId;

    private string $userHandle = '';

    private int $counter = 0;

    public function __construct()
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        $this->key = $key instanceof OpenSSLAsymmetricKey ? $key : throw new RuntimeException('Could not create an EC key pair.');
        $this->credentialId = random_bytes(16);
    }

    /**
     * @param  array<string, mixed>  $options  the "options" returned by passkey.registration-options
     * @return array<string, mixed> the "credential" to post to passkey.store
     */
    public function attest(array $options, string $origin): array
    {
        $this->userHandle = $this->decode($this->string($options, 'user', 'id'));

        $authenticatorData = hash('sha256', $this->string($options, 'rp', 'id'), true)
            .chr(0x45)
            .pack('N', $this->counter)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->publicKey();

        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authenticatorData));

        return [
            'id' => $this->encode($this->credentialId),
            'rawId' => $this->encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->encode($this->clientData('webauthn.create', $this->string($options, 'challenge'), $origin)),
                'attestationObject' => $this->encode((string) $attestation),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options  the "options" returned by passkey.login-options
     * @return array<string, mixed> the "credential" to post to passkey.login
     */
    public function assert(array $options, string $origin): array
    {
        $this->counter++;

        $authenticatorData = hash('sha256', $this->string($options, 'rpId'), true).chr(0x05).pack('N', $this->counter);
        $clientData = $this->clientData('webauthn.get', $this->string($options, 'challenge'), $origin);
        openssl_sign($authenticatorData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => $this->encode($this->credentialId),
            'rawId' => $this->encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->encode($clientData),
                'authenticatorData' => $this->encode($authenticatorData),
                'signature' => $this->encode((string) $signature),
                'userHandle' => $this->encode($this->userHandle),
            ],
        ];
    }

    private function publicKey(): MapObject
    {
        $details = openssl_pkey_get_details($this->key);
        $curve = is_array($details) && is_array($details['ec'] ?? null) ? $details['ec'] : throw new RuntimeException('Not an EC key.');

        return MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad((string) $curve['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad((string) $curve['y'], 32, "\0", STR_PAD_LEFT)));
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $data */
    private function string(array $data, string ...$path): string
    {
        $value = $data;

        foreach ($path as $key) {
            $value = is_array($value) && array_key_exists($key, $value)
                ? $value[$key]
                : throw new RuntimeException('Missing passkey option ['.implode('.', $path).'].');
        }

        return is_string($value) ? $value : throw new RuntimeException('Passkey option ['.implode('.', $path).'] is not a string.');
    }

    private function encode(string $binary): string
    {
        return Base64UrlSafe::encodeUnpadded($binary);
    }

    private function decode(string $encoded): string
    {
        return Base64UrlSafe::decodeNoPadding(rtrim($encoded, '='));
    }
}
