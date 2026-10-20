# Test fixtures

MaxMind's own sample data, copied rather than invented, so the suite reads what MaxMind says it
sends. Both sources are dual-licensed Apache-2.0 OR MIT; this package takes the MIT option, and
each directory carries the MIT licence it came with.

## web/

`country.json`, `city.json` and `insights.json` are the `value` of each example in
[maxmind/openapi](https://github.com/maxmind/openapi) `examples/geoip/*.yaml`, converted from YAML
to JSON unchanged. Copied on 2026-10-20 from commit `1f718005dc5890919133b0480ba92828274f28c7`.

`ModelTest` checks that every field in them with a value arrives in a property with a value. When
MaxMind adds a field, refreshing these files is what makes that test notice.

## mmdb/

Twelve test databases from [maxmind/MaxMind-DB](https://github.com/maxmind/MaxMind-DB)
`test-data/`, copied on 2026-10-20 from commit `f4847f28e5d12cd495ad5c1ea297cef9cc45bf4f`. Their
source records are in that repository's `source-data/<name>-Test.json`, which is where to look up
what an address should answer:

| database | addresses with data |
|---|---|
| City, Country, GeoLite2 City and Country, Enterprise | `2.125.160.216/29`, `81.2.69.160/27`, `2001:218::/32` |
| Anonymous IP | `1.2.0.0/16`, `81.2.69.0/24`, `71.160.223.0/24`, `186.30.236.0/24` |
| Anonymous Plus | `1.2.0.0/32` flags only; `1.2.0.1/32` with confidence 30, provider `foo` |
| Residential Proxy | `1.2.0.4/32` — `quickshift`, confidence 82 |
| ISP, GeoLite2 ASN | `1.128.0.0/11` |
| Domain | `1.2.0.0/16` |
| Connection Type | `1.0.0.0/24`, `80.214.0.0/20` and others |

**Do not copy that repository's `bad-data/` or its generic `MaxMind-DB-test-*` files here without
reading its README first.** Some are deliberately hostile to a decoder.
