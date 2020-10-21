Installation
============

Applications that use Symfony Flex
----------------------------------

Open a command console, enter your project directory and execute:

```console
$ composer require mgwebgroup/market-survey
```

Applications that don't use Symfony Flex
----------------------------------------

### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```console
$ composer require mgwebgroup/market-survey
```

This command requires you to have Composer installed globally, as explained
in the [installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles
in the `config/bundles.php` file of your project:

```php
// config/bundles.php

return [
    // ...
    App\Studies\MGWebGroup\MarketSurvey\MarketSurveyBundle::class => ['all' => true],
];
```

### Step 3: Install and Compile Bundle Assets
Copy this bundle's assets as symlinks into *public/bundle/marketsurvey* folder 
```console
$ bin/console assets:install public --symlink --relative
```
This bundle's file *webpack.config.js* is already set to compile app's general assets (normally compiled with **npm run dev**). So you just need to compile this bundle's assets: 
```console
$ npx encore dev --config src/Studies/MGWebGroup/MarketSurvey/webpack.config.js
```