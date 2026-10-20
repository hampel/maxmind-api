<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Database;

use Hampel\MaxMind\Api\Exception\AddressNotFoundException;
use Hampel\MaxMind\Api\Exception\DatabaseException;
use Hampel\MaxMind\Api\Exception\DatabaseMismatchException;
use Hampel\MaxMind\Api\Model\AnonymousIp;
use Hampel\MaxMind\Api\Model\Asn;
use Hampel\MaxMind\Api\Model\City;
use Hampel\MaxMind\Api\Model\ConnectionType;
use Hampel\MaxMind\Api\Model\Country;
use Hampel\MaxMind\Api\Model\Domain;
use Hampel\MaxMind\Api\Model\Isp;
use Hampel\MaxMind\Api\Support\Address;
use Hampel\MaxMind\Api\Support\Cast;
use MaxMind\Db\Reader as DbReader;
use MaxMind\Db\Reader\InvalidDatabaseException;
use MaxMind\Db\Reader\Metadata;

/**
 * A downloaded GeoIP or GeoLite database, answering with the same models as the web services.
 *
 *     $reader = Reader::open('/path/to/GeoLite2-City.mmdb');
 *
 *     $reader->city('81.2.69.160')->country->isoCode;          // a Model\City, as from City Plus
 *     $reader->anonymizer('1.2.0.1')->anonymizer->confidence;  // as from Insights
 *
 * THE MODELS ARE THE POINT. A consumer that lets an administrator choose between the web service
 * and a downloaded file holds a Model\Country or a Record\Anonymizer either way, and writes its
 * rules once. MaxMind keeps the record layouts of its web services and its databases in step, so
 * the mapping is direct; the one rename - `anonymizer_confidence` in the files, `confidence` on
 * the web - is absorbed in Record\Anonymizer.
 *
 * WHICH METHOD READS WHICH FILE is decided from the file's own `database_type`, never its name.
 * A file that cannot answer a lookup raises DatabaseMismatchException rather than returning an
 * empty model, which would read as "MaxMind knows nothing about this address". This is wider than
 * MaxMind's own reader in two places, both supersets: country() reads a City or Enterprise file,
 * and asn() reads an ISP one.
 *
 * OPEN ONCE AND KEEP IT. Opening reads the file's metadata; a lookup reads a few hundred bytes
 * and builds a handful of small objects. A page listing a hundred addresses should hold one
 * Reader, not open one per row. The reader holds a file handle until close() or destruction, so
 * replace a file by renaming a new one over it - Downloads::database() does - rather than writing
 * into it, and reopen.
 *
 * Not safe for nested use from a stream wrapper - maxmind-db/reader refuses a lookup started
 * during another one on the same reader.
 */
final class Reader
{
    /**
     * The `database_type` values each lookup reads, by substring - which is how MaxMind's own
     * reader matches, and what lets `GeoIP2-City-Shield` and `GeoIP2-Precision-Enterprise` through.
     */
    public const READS = [
        'country' => ['Country', 'City', 'Enterprise'],
        'city' => ['City', 'Enterprise'],
        'asn' => ['GeoLite2-ASN', 'GeoIP2-ISP'],
        'isp' => ['GeoIP2-ISP'],
        'connectionType' => ['GeoIP2-Connection-Type'],
        'domain' => ['GeoIP2-Domain'],
        'anonymizer' => ['GeoIP2-Anonymous-IP', 'GeoIP-Anonymous-Plus', 'GeoIP-Residential-Proxy'],
    ];

    private readonly string $databaseType;

    public function __construct(private readonly DbReader $reader)
    {
        $this->databaseType = (string) $reader->metadata()->databaseType;
    }

    /**
     * Open a file. Absent, unreadable, not a MaxMind DB file, or corrupt: DatabaseException.
     */
    public static function open(string $path): self
    {
        try {
            return new self(new DbReader($path));
        } catch (InvalidDatabaseException | \InvalidArgumentException | \UnexpectedValueException $e) {
            throw new DatabaseException(sprintf('Could not open the MaxMind database "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    /**
     * The file's `database_type` - `GeoLite2-City`, `GeoIP-Anonymous-Plus`.
     */
    public function databaseType(): string
    {
        return $this->databaseType;
    }

    public function metadata(): Metadata
    {
        return $this->reader->metadata();
    }

    /**
     * When MaxMind built the file - what to show an administrator beside "database last updated".
     */
    public function buildDate(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . (int) $this->reader->metadata()->buildEpoch))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Whether this file can answer a lookup method - `supports('city')`.
     */
    public function supports(string $method): bool
    {
        foreach (self::READS[$method] ?? [] as $type) {
            if (str_contains($this->databaseType, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The country of an address - from a Country, City or Enterprise database.
     */
    public function country(string $ipAddress): Country
    {
        return Country::fromArray($this->place('country', $ipAddress));
    }

    /**
     * The city of an address - from a City or Enterprise database. Enterprise fills the
     * `confidence` fields too.
     */
    public function city(string $ipAddress): City
    {
        return City::fromArray($this->place('city', $ipAddress));
    }

    /**
     * Whether an address is anonymous - from an Anonymous IP, Anonymous Plus or Residential Proxy
     * database, as the Record\Anonymizer Insights answers with.
     */
    public function anonymizer(string $ipAddress): AnonymousIp
    {
        [$record, $ipAddress, $network] = $this->lookup('anonymizer', $ipAddress);

        return str_contains($this->databaseType, 'Residential-Proxy')
            ? AnonymousIp::fromResidentialDatabase($record, $ipAddress, $network)
            : AnonymousIp::fromDatabase($record, $ipAddress, $network);
    }

    public function asn(string $ipAddress): Asn
    {
        return Asn::fromDatabase(...$this->lookup('asn', $ipAddress));
    }

    public function isp(string $ipAddress): Isp
    {
        return Isp::fromDatabase(...$this->lookup('isp', $ipAddress));
    }

    public function connectionType(string $ipAddress): ConnectionType
    {
        return ConnectionType::fromDatabase(...$this->lookup('connectionType', $ipAddress));
    }

    public function domain(string $ipAddress): Domain
    {
        return Domain::fromDatabase(...$this->lookup('domain', $ipAddress));
    }

    /**
     * The record for an address exactly as the file holds it, and its prefix length - for a
     * database this package has no model for. No type check; null when there is no record.
     *
     * @return array{mixed, int}
     */
    public function raw(string $ipAddress): array
    {
        return $this->read(Address::check($ipAddress));
    }

    public function close(): void
    {
        $this->reader->close();
    }

    /**
     * A place record, with `traits.ip_address` and `traits.network` added - the two keys the web
     * services send and a file does not hold.
     *
     * @return array<string, mixed>
     */
    private function place(string $method, string $ipAddress): array
    {
        [$record, $ipAddress, $network] = $this->lookup($method, $ipAddress);

        $record['traits'] = ['ip_address' => $ipAddress, 'network' => $network] + Cast::object($record['traits'] ?? null);

        return $record;
    }

    /**
     * @return array{array<string, mixed>, string, ?string}
     */
    private function lookup(string $method, string $ipAddress): array
    {
        if (!$this->supports($method)) {
            throw DatabaseMismatchException::for($method, $this->databaseType, self::READS[$method] ?? []);
        }

        $ipAddress = Address::check($ipAddress);

        [$record, $prefixLength] = $this->read($ipAddress);

        if (!is_array($record)) {
            throw AddressNotFoundException::inDatabase($ipAddress, $this->databaseType);
        }

        return [Cast::object($record), $ipAddress, Address::network($ipAddress, $prefixLength)];
    }

    /**
     * @return array{mixed, int}
     */
    private function read(string $ipAddress): array
    {
        try {
            return $this->reader->getWithPrefixLen($ipAddress);
        } catch (InvalidDatabaseException | \BadMethodCallException $e) {
            throw new DatabaseException(sprintf('Could not read %s from the %s database: %s', $ipAddress, $this->databaseType, $e->getMessage()), 0, $e);
        } catch (\InvalidArgumentException $e) {
            // an IPv6 address asked of an IPv4-only file
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }
}
