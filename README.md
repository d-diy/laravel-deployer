# (Simple) Laravel Deployer

This is a [Deployer](https://deployer.org) recipe for Laravel deployments that cannot (or would prefer not to) rely on symlinks. Instead, Git is simply used to update the codebase directly on the server.

## Installation

Install the package via composer:

```sh
composer require foodkit/laravel-deployer
```

## Configuration

Define the `deploy.php` file in your project's root:

```php
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
    ->set('deploy_path', '/var/www/project');

```

You may want to run the deployment as standalone (not part of a project). This will skip certain checks against the state of the local repository. In this case, use the `standalone` flag:

```php
set('standalone', true);
```

Also, the migration step can be disable if your project doesn't require it:

```php
set('migration', false);
```

## How to deploy

Run the deploy command:

```sh
php vendor/bin/dep deploy production
```

Optionally, a tag or a branch can be specified on the command line:

```sh
php vendor/bin/dep deploy production --tag="v0.1"
php vendor/bin/dep deploy production --branch="develop"
```

To see what exactly happening you can increase verbosity of output with `--verbose` option: 

* `-v`  for normal output,
* `-vv`  for more verbose output,
* `-vvv`  for debug.

Before starting the deployment on server, the following checks will be performed:

* Check the working copy of the repo, show an error and abort if there are uncommitted changes.
* If a branch is referenced but doesn't exist, show an error.
* If a tag is referenced but it doesn't exist, ask the user if it should be created. If the user enters "N", show an error and abort. If "Y", create the tag and continue.
