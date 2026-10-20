<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\MaxMind\Api\Authentication\AccountKey;
use Hampel\MaxMind\Api\Authentication\Authentication;
use Hampel\MaxMind\Api\Client;
use Hampel\MaxMind\Api\Config;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class TestCase extends BaseTestCase
{
    public const ACCOUNT_ID = 42;

    public const LICENSE_KEY = 'TestKey_for_the_suite_only_0000000000_mmk';

    public const R2_HOST = 'mm-prod-geoip-databases.a2649acb697e2c09b632799562c076f2.r2.cloudflarestorage.com';

    protected StubClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new StubClient();
    }

    protected function maxmind(
        ?Config $config = null,
        ?Authentication $authentication = null,
        ?LoggerInterface $logger = null,
    ): Client {
        $factory = new HttpFactory();

        return new Client(
            $config ?? Config::live(),
            $authentication ?? new AccountKey(self::ACCOUNT_ID, self::LICENSE_KEY),
            $this->client,
            $factory,
            $factory,
            $logger ?? new NullLogger(),
        );
    }

    protected static function basicAuth(): string
    {
        return 'Basic ' . base64_encode(self::ACCOUNT_ID . ':' . self::LICENSE_KEY);
    }

    /**
     * A sample response from maxmind/openapi's examples, as a decoded array.
     *
     * @return array<string, mixed>
     */
    protected static function fixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/fixtures/web/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \LogicException(sprintf('Fixture %s is not an object.', $name));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    protected static function database(string $name): string
    {
        return __DIR__ . '/fixtures/mmdb/' . $name . '-Test.mmdb';
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function pushMaxMindJson(int $status, array $body, string $type = 'insights'): void
    {
        $this->client->pushJson($status, $body, [
            'Content-Type' => sprintf('application/vnd.maxmind.com-%s+json; charset=UTF-8; version=2.1', $type),
        ]);
    }

    protected function pushError(int $status, string $code, string $error = 'An error.'): void
    {
        $this->pushMaxMindJson($status, ['code' => $code, 'error' => $error], 'error');
    }

    /**
     * A fresh temporary directory, removed with everything in it at tearDown.
     */
    protected function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/maxmind-api-test-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $this->directories[] = $directory;

        return $directory;
    }

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob($directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            @rmdir($directory);
        }

        parent::tearDown();
    }
}
