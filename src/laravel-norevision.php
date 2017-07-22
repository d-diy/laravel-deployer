<?php

namespace Deployer;

require 'recipe/laravel.php';

// Variables
set('bin/composer', function () {

    if (commandExist('composer')) {
        $composer = run('which composer')->toString();
    }

    if (empty($composer)) {
        run("cd {{release_path}} && curl -sS https://getcomposer.org/installer | {{bin/php}}");
        run("mkdir -p /usr/local/bin");
        run("mv {{release_path}}/composer.phar /usr/local/bin/composer");
        $composer = '/usr/local/bin/composer';
    }

    return '{{bin/php}} '.$composer;

});

// Tasks
desc('Disable maintenance mode');
task('artisan:up', function () {
    $output = run('{{bin/php}} {{release_path}}/artisan up');
    writeln('<info>'.$output.'</info>');
});

desc('Enable maintenance mode');
task('artisan:down', function () {
    $output = run('{{bin/php}} {{release_path}}/artisan down');
    writeln('<info>'.$output.'</info>');
});

desc('Preparing server for deploy');
task('deploy:prepare', function () {

    // Check if shell is POSIX-compliant
    try {
        $result = run('echo $0')->toString();
        if ($result == 'stdin: is not a tty') {
            throw new \RuntimeException(
                "Looks like ssh inside another ssh.\n".
                "Help: http://goo.gl/gsdLt9"
            );
        }
    } catch (\RuntimeException $e) {
        $formatter = Deployer::get()->getHelper('formatter');

        $errorMessage = [
            "Shell on your server is not POSIX-compliant. Please change to sh, bash or similar.",
            "Usually, you can change your shell to bash by running: chsh -s /bin/bash",
        ];
        write($formatter->formatBlock($errorMessage, 'error', true));

        throw $e;
    }

    run('if [ ! -d {{deploy_path}} ]; then mkdir -p {{deploy_path}}; fi');

    set('release_path', parse('{{deploy_path}}'));

});

desc('Checkout repository');
task('deploy:checkout_code', function () {

    $repo = trim(get('repository'));
    $git  = get('bin/git');

    $cloneExists = run("if [ -d {{release_path}}/.git ]; then echo 'true'; fi")->toBool();

    if (!$cloneExists) {
        run("$git clone $repo {{release_path}} 2>&1");
    }

});

desc('Update code');
task('deploy:update_code', function () {

    $branch = get('branch');
    $git    = get('bin/git');

    // If option `branch` is set.
    if (input()->hasOption('branch')) {
        $inputBranch = input()->getOption('branch');
        if (!empty($inputBranch)) {
            $branch = $inputBranch;
        }
    }

    // If option `tag` is set
    if (input()->hasOption('tag')) {
        $inputTag = input()->getOption('tag');
        if (!empty($inputTag)) {
            $tag = $inputTag;
        }
    }

    run("cd {{release_path}} && $git fetch");
    run("cd {{release_path}} && $git fetch --tags");

    if (!empty($tag)) {
        run("cd {{release_path}} && $git checkout $tag");
    } elseif (!empty($branch)) {
        run("cd {{release_path}} && $git checkout $branch");
    } else {
        run("cd {{release_path}} && $git pull");
    }

});

task('deploy', [
    'deploy:prepare',
    'deploy:checkout_code',
    'artisan:down',
    'deploy:update_code',
    'deploy:vendors',
    'deploy:writable',
    'artisan:migrate',
    'artisan:queue:restart',
    'artisan:up',
]);