# WPCS for TSF files via Composer and phpcs

This package helps you validate your PHP files against WordPress coding standards, as used for The SEO Framework product family.

This is a fork of [WordPress/WordPress-Coding-Standards](https://github.com/WordPress/WordPress-Coding-Standards)'s `composer.json` file.

## Installation

1. Import this Git's files to a private folder locaiton.
1. Open Terminal.
1. `cd` to folder.
1. Enter: `composer install`

## Updating

1. Open Terminal.
1. `cd` to folder.
1. Enter: `composer update`.

## Usage (VScode)

1. Download/install PHP on your drive.
1. Install the [phpcs VScode extension by Ioannis Kappas](https://marketplace.visualstudio.com/items?itemName=ikappas.phpcs) (more instructions in link).

### Required VScode config (example for Windows)

_Unlisted settings are optional._

```JSON
{
    "php.validate.executablePath": "C:\\php-folder-set-in-step-1-at-usage\\php.exe",
    "phpcs.executablePath": "C:\\the-folder-you-ran-composer-for-this-project\\vendor\\bin\\phpcs",
    "phpcs.standard": "WordPress-Extra",
    "phpcs.errorSeverity": 1,
    "phpcs.warningSeverity": 1,
}
```

## Issues

On Windows, PHP might not be recognized as an internal or external command.

For this, you need to [set PHP to your PATH environmental variable](https://stackoverflow.com/questions/31291317/php-is-not-recognized-as-an-internal-or-external-command-in-command-prompt/31291404#31291404) at:
- (enter this in the address bar at <kbd>WLK+E</kbd>) `Control Panel\System and Security\System`
   - `Advanced system settings`.

But, it's probably easier to [install XAMPP](https://www.apachefriends.org/index.html), which should take care of this for you.

Be sure to restart VScode when you're done setting the `PATH` variable.
