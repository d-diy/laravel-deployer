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

### Slack integration

If you'd like to integrate with Foodkit's release note generator, add the following:

```php

option('start', null, InputOption::VALUE_OPTIONAL, 'The start tag/branch');
option('end', null, InputOption::VALUE_OPTIONAL, 'The end tag/branch');

set('slack_title', 'Release notes');
set('slack_color', '#4d91f7');
set('slack_emoji', ':ghost:');
set('slack_name', 'Laravel Deployer');
set('slack_webhook', 'https://hooks.slack.com/services/ABCDEFGH/IJLMNOPQ/OJI7OA9IU1BAJgGj4ge3YD9A');
set('release_notes_command', 'vendor/bin/release-notes generate');
```

then run the deployment with the `start` and `end` command line parameters.

### API integration

If you'd like to send the release note to an API endpoint, add the following:

```php

option('start', null, InputOption::VALUE_OPTIONAL, 'The start tag/branch');
option('end', null, InputOption::VALUE_OPTIONAL, 'The end tag/branch');

set('api_endpoint', 'https://api.product.com');

after('deploy', 'slack:send-release-notes-api');
```

then run the deployment with the `start` and `end` command line parameters.

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

Optionally, if you're integrating with the release note generator:

```sh
php vendor/bin/dep deploy production --tag="v1.0.8" --start="v1.0.7" --end="v1.0.8"
```

To see what exactly happening you can increase verbosity of output with `--verbose` option:

* `-v`  for normal output,
* `-vv`  for more verbose output,
* `-vvv`  for debug.

## For semver

If you use semantic versioning, the repo has "hotfix" and "release" tasks built-in.

### Hotfixes

```sh
php vendor/bin/dep hotfix production
```

This will take the latest tag, increment it by 0.0.1, create a new tag and deploy that.

### Releases

```sh
php vendor/bin/dep release production
```

Same as for the hotfix command, but it will increment latest tag by 0.1

## Contributing

See the list of [issues](issues).

Submit a pull request against `master`.