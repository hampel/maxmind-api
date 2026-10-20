# Changelog

## Unreleased

First version.

- `Client`, over any PSR-18 client, with `Config::live()` and `Config::sandbox()` and one
  `AccountKey` credential for every MaxMind service
- the GeoIP2 Country, City Plus and Insights web services, as `Model\Country`, `Model\City` and
  `Model\Insights`, each extending the last, with the Insights `anonymizer` section and its
  `residential` feed; an anonymizer flag is `null` where the source cannot say, and `false` where
  it checked
- `Database\Reader`, reading GeoIP and GeoLite `.mmdb` files into the same models, and the
  Anonymous IP, Anonymous Plus and Residential Proxy databases into the Insights `Anonymizer`
- `Downloads`: `lastModified()` by HEAD, `checksum()`, `download()` verified against the published
  SHA-256, and `database()`, which also extracts the `.mmdb` without Phar
- exceptions by status and then by MaxMind's error code, with `AddressNotFoundException` raised by
  the web services and the database reader alike
