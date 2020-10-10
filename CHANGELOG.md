# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2020-10-10

## Changed
- `Transformer` allows returning no message (i.e. to indicate the pipeline stops for a given message)
- `Piper` deals with no message by transformer by directly responding with NACK & no requeue

## [0.2.0] - 2020-10-06

### Added
- Add CHANGELOG.md

### Changed
- Allow specifying whether pre-fetch count should be global and default to global

## [0.1.2] - 2020-09-16

### Changed
- Use [forked rabbitmq-management-api](https://github.com/tsterker/php-rabbitmq-management-api) for Guzzle 7.0 support

## [0.1.1] - 2020-07-28

### Added
- Support idle callback for Piper

## [0.1.0] - 2020-07-25

### Added
- Initial release

[Unreleased]: https://github.com/tsterker/hopper/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/tsterker/hopper/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/tsterker/hopper/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/tsterker/hopper/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/tsterker/hopper/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/tsterker/hopper/releases/tag/v0.1.0