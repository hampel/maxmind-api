<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Authentication;

use Hampel\MaxMind\Api\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * A MaxMind account id and licence key, sent as HTTP basic authentication.
 *
 * Both come from the account portal, under Manage License Keys; the key is shown once, when it is
 * generated. The same pair serves the GeoIP web services, the database downloads and minFraud.
 *
 * THE ACCOUNT ID IS AN INTEGER, and is taken as one. MaxMind's own client accepts a string and
 * fails with a TypeError on an empty one; here a non-positive id is refused at construction, with
 * a message, and fromStrings() reads the form a settings page hands over.
 *
 * THE SANDBOX IS A SEPARATE ACCOUNT, with its own id and key. A production key is refused there,
 * and the other way round.
 *
 * NEITHER VALUE IS PRINTABLE FROM HERE: __toString(), var_dump(), print_r() and json_encode() all
 * show the description or nothing. The account id is not a secret on its own and is shown; the
 * key is not. What this class cannot protect is the request once built: its `Authorization`
 * header is the key in base64, which is encoding, not encryption.
 */
final class AccountKey implements Authentication
{
    private readonly string $licenseKey;

    public function __construct(
        public readonly int $accountId,
        #[\SensitiveParameter] string $licenseKey,
    ) {
        if ($accountId < 1) {
            throw new InvalidArgumentException(sprintf('A MaxMind account id is a positive integer; %d was given.', $accountId));
        }

        $licenseKey = trim($licenseKey);

        if ($licenseKey === '') {
            throw new InvalidArgumentException('A MaxMind licence key is required.');
        }

        $this->licenseKey = $licenseKey;
    }

    /**
     * Both values as strings - the form a settings page or an environment variable gives them in.
     *
     * An account id that is not entirely digits is refused rather than cast, so `'12a'` does not
     * quietly become account 12.
     */
    public static function fromStrings(string $accountId, #[\SensitiveParameter] string $licenseKey): self
    {
        $accountId = trim($accountId);

        if (preg_match('/^\d{1,18}$/', $accountId) !== 1) {
            throw new InvalidArgumentException(sprintf('A MaxMind account id is a positive integer; "%s" is not one.', $accountId));
        }

        return new self((int) $accountId, $licenseKey);
    }

    public function applyTo(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', 'Basic ' . base64_encode($this->accountId . ':' . $this->licenseKey));
    }

    /**
     * The account id, and the key's length - enough to tell two credentials apart in a log
     * without being enough to use either.
     */
    public function describe(): string
    {
        return sprintf('MaxMind account %d with a licence key of %d characters', $this->accountId, strlen($this->licenseKey));
    }

    public function __toString(): string
    {
        return $this->describe();
    }

    /**
     * @return array<string, int|string>
     */
    public function __debugInfo(): array
    {
        return ['accountId' => $this->accountId, 'licenseKey' => '[withheld]'];
    }
}
