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

desc('Execute artisan migrate');
task('artisan:migrate', function () {
    if (get('migration', true) === false) {
        return;
    }
    run('{{bin/php}} {{release_path}}/artisan migrate --force');
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
        $tag = input()->getOption('tag');
    }

    run("cd {{release_path}} && $git fetch");
    run("cd {{release_path}} && $git fetch --tags");

    if (!empty($tag)) {
        // Tags shouldn't change over time, so no need to `git pull` here.
        run("cd {{release_path}} && $git checkout $tag");
    } elseif (!empty($branch)) {
        // We need to `git pull` from origin in case the branch has been updated:
        run("cd {{release_path}} && $git checkout $branch && git pull origin $branch");
    }

});

desc('Check clean working directory');
task('deploy:clean_working_dir', function () {

    if (get('standalone', false)) {
        return;
    }

    $output = runLocally('git status');

    if (strpos($output, 'working directory clean') === false && strpos($output, 'working tree clean') === false) {
        if (!askConfirmation("The local working path is not clean.\n\nThis means there may be uncommitted changes that will NOT be deployed. Continue anyway?", true)) {
            throw new \RuntimeException('Working directory is not clean, please commit your changes before deploying.');
        }
    }

});

desc('Fetch git references');
task('deploy:git_fetch', function () {

    if (get('standalone', false)) {
        return;
    }

    runLocally('git fetch');
    runLocally('git fetch --tags');

});

desc('Check parameters');
task('deploy:check_parameters', function () {

    if (empty(input()->getOption('tag')) && empty(input()->getOption('branch'))) {
        throw new \RuntimeException("No branch or tag was supplied.\n\nPlease provide either --tag={tag} or --branch={branch} so I know what to deploy.");
    }

});

desc('Check branch existence');
task('deploy:check_branch', function () {

    $branch = get('branch');

    // If option `branch` is set.
    if (input()->hasOption('branch')) {
        $inputBranch = input()->getOption('branch');
        if (!empty($inputBranch)) {
            $branch = $inputBranch;
        }
    }

    if (empty($branch) || get('standalone', false)) {
        return;
    }

    $output = runLocally('git branch -a');

    if (strpos($output, "remotes/origin/$branch") === false) {
        throw new \RuntimeException("The referenced branch `$branch` doesn't exist on origin.");
    }

});

desc('Check tag existence');
task('deploy:check_tag', function () {

    $tag = null;

    // If option `tag` is set
    if (input()->hasOption('tag')) {
        $tag = input()->getOption('tag');
    }

    if (empty($tag) || get('standalone', false)) {
        return;
    }

    $output = runLocally('git tag');

    if (strpos($output, $tag) === false) {
        if (!askConfirmation("The referenced tag `$tag` doesn't exist. Would you like to create it?", true)) {
            throw new \RuntimeException("The referenced tag `$tag` doesn't exist on origin.");
        }
        runLocally("git tag $tag");
        runLocally("git push origin --tags");
    }

});

desc('Send release note to slack');
task('slack:send-release-notes', function () {

    if (!input()->hasOption('start') && !input()->hasOption('end')) {
        return;
    }

    $start = input()->getOption('start');
    $end   = input()->getOption('end');

    $output = runLocally("release-notes generate --start=$start --end=$end --format=slack");

    $attachment = [
        'title' => get('slack_title'),
        'color' => get('slack_color'),
        'text'  => $output,
    ];

    $postString = json_encode([
        'icon_emoji'  => get('slack_emoji'),
        'username'    => get('slack_name'),
        'attachments' => [$attachment],
        "mrkdwn"      => true,
    ]);

    $ch = curl_init();

    $options = [
        CURLOPT_URL            => get('slack_webhook'),
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-type: application/json'],
        CURLOPT_POSTFIELDS     => $postString,
    ];

    curl_setopt_array($ch, $options);

    if (curl_exec($ch) !== false) {
        curl_close($ch);
    }

})->onlyOn(['production']);

desc('Send release note to API');
task('slack:send-release-notes-api', function () {

    if (!input()->hasOption('start') && !input()->hasOption('end')) {
        return;
    }

    $start = input()->getOption('start');
    $end   = input()->getOption('end');

    $endpoint = get('api_endpoint');

    if (empty($endpoint)) {
        return;
    }

    $output = runLocally("release-notes generate --start=$start --end=$end --format=json");

    $ch = curl_init();

    $options = [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-type: application/json'],
        CURLOPT_POSTFIELDS     => $output,
    ];

    curl_setopt_array($ch, $options);

    if (curl_exec($ch) !== false) {
        curl_close($ch);
    }

})->onlyOn(['production']);


task('deploy', [
    'deploy:check_parameters',
    'deploy:clean_working_dir',
    'deploy:git_fetch',
    'deploy:check_branch',
    'deploy:check_tag',
    'deploy:prepare',
    'deploy:checkout_code',
    'artisan:down',
    'deploy:update_code',
    'deploy:vendors',
    'artisan:migrate',
    'artisan:queue:restart',
    'artisan:up',
]);
