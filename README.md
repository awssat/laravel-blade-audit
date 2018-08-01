# Laravel Blade Audit

![laravel-blade-audit](https://i.imgur.com/i0Xj0ZL.jpg)


[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)


## Introduction
Laravel's artisan command to show extensive information about any blade view in laravel's project.


## Features
- General information about the view (size, lines, longest line, blade's directives number ...)
- Blade directives information (repetitions, type: custom or built-in)
- Blade directives nesting level.
- Audit notes (recommendations and best practice notes)


## Install

Via Composer
``` bash
composer require awssat/laravel-blade-audit --dev
```

### Before Laravel 5.5
You'll need to manually register `Awssat\BladeAudit\BladeAuditServiceProvider::class` service provider in `config/app.php`.


## Usage
```console
php artisan blade:audit view.name
```



## License

This package is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).

## Credits
- [All Contributors][link-contributors]


[ico-version]: https://img.shields.io/packagist/v/awssat/laravel-blade-audit.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[link-packagist]: https://packagist.org/packages/awssat/laravel-blade-audit
[link-contributors]: ../../contributors

