# Developer tool suite

[![Latest Version on Packagist](https://img.shields.io/packagist/v/webregulate/dev-companion.svg?style=flat-square)](https://packagist.org/packages/webregulate/dev-companion)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/webregulate/dev-companion/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/webregulate/dev-companion/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/webregulate/dev-companion/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/webregulate/dev-companion/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/webregulate/dev-companion.svg?style=flat-square)](https://packagist.org/packages/webregulate/dev-companion)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/dev-companion.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/dev-companion)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer (BE AWARE THAT THIS PACKAGE IS IN  ACTIVE DEVELOPMENT AND NOT YET STABLE):

```bash
composer require webregulate/dev-companion
```

Install assets (this is needed for the `php artisan dev-companion` command to work):

```bash
php artisan dev-companion:install
```

Next, modify the dev-companion configuration file to your needs:

```bash
/config/dev-companion.php
```

## Usage

Simply run the following command to open the developer companion interface:

```bash
php artisan dev-companion
```

## Credits

- [Craig Dennis](https://github.com/Zephni)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
