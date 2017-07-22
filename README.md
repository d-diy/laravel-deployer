## Laravel Deployer

This is a [Deployer](https://deployer.org) recipe for Laravel projects that doesn't rely on symlinks
and instead pulls latest code from git repository. 

## Installation

Install the package via composer:

``` sh
composer require foodkit/laravel-deployer
```

## Configuration

Define the `deploy.php` file in your project root:

```
<?php

namespace Deployer;

require __DIR__.'/vendor/foodkit/laravel-deployer/src/laravel-norevision.php';

// Configuration
set('ssh_type', 'native');
set('ssh_multiplexing', true);
set('branch', 'production');
set('repository', 'git@github.com:company/project.git');

// Servers
server('production', '1.2.3.4')
    ->user('root')
    ->identityFile()
    ->set('deploy_path', '/var/www/project')
    ->pty(true);

```

You may want to run the deployment as standalone (not part of a project), in which case specify it:

```
set('standalone', true);
```

## How to deploy

Run the deploy command:

``` sh
php vendor/bin/dep deploy
```

Optionally, a tag or a branch can be specified on the command line:

``` sh
php vendor/bin/dep deploy --tag="v0.1"
php vendor/bin/dep deploy --branch="develop"
```

To see what exactly happening you can increase verbosity of output with `--verbose` option: 

* `-v`  for normal output,
* `-vv`  for more verbose output,
* `-vvv`  for debug.