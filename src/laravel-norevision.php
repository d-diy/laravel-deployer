<?php

namespace Deployer;

require 'recipe/laravel.php';
require __DIR__.'/slack.php';

/**
 * Variables
 */
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

/**
 * Tasks
 */
desc('Disable maintenance mode');
task('artisan:up', function () {
    $output = run('{{bin/php}} {{release_path}}/artisan up');
    writeln('<info>'.$output.'</info>');
});

/**
 *
 */
desc('Enable maintenance mode');
task('artisan:down', function () {
    $output = run('{{bin/php}} {{release_path}}/artisan down');
    writeln('<info>'.$output.'</info>');
});

/**
 *
 */
desc('Execute artisan migrate');
task('artisan:migrate', function () {
    if (get('migration', true) === false) {
        return;
    }
    run('{{bin/php}} {{release_path}}/artisan migrate --force');
});

/**
 *
 */
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

/**
 * Pulls down all branches/tags and then checks out the relevant one (based on command line arguments).
 */
desc('Update code');
task('deploy:update_code', function () {

    $branch = get('branch');
    $git    = get('bin/git');

    // If option `branch` is set.
    if (!empty(input()->getOption('branch'))) {
        $branch = input()->getOption('branch');
    }

    // If option `tag` is set
    if (!empty(input()->getOption('tag'))) {
        $tag = input()->getOption('tag');
    } elseif (get('tag')) {
        $tag = get('tag');
    }

    run("cd {{release_path}} && $git fetch");
    run("cd {{release_path}} && $git fetch --tags");

    if (!empty($tag)) {
        // Tags shouldn't change over time, so no need to `git pull` here.
        run("cd {{release_path}} && $git checkout $tag");
        input()->setArgument('end', $tag);
    } elseif (!empty($branch)) {
        // We need to `git pull` from origin in case the branch has been updated:
        run("cd {{release_path}} && $git checkout $branch && git pull origin $branch");
        input()->setArgument('end', $branch);
    }

});

/**
 * Checks the working path of local folder and remote folder to ensure no uncommitted changes would be lost.
 */
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

    $output = run('git status');

    if (strpos($output, 'working directory clean') === false && strpos($output, 'working tree clean') === false) {
        $message = 'Remote working directory is not clean! ';
        $message .= '\nThis means there are uncommitted changes on the server that would be lost if we deployed now. ';
        $message .= 'Please resolve this manually and try deploying again.';
        throw new \RuntimeException($message);
    }
});

/**
 *
 */
desc('Fetch git references');
task('deploy:git_fetch', function () {

    if (get('standalone', false)) {
        return;
    }

    runLocally('git fetch');
    runLocally('git fetch --tags');

});

/**
 * Check all the required parameters have been supplied.
 */
desc('Check parameters');
task('deploy:check_parameters', function () {

    // If the stage is not provided we want to prevent the script from deploying to ALL environments (default behaviour).
    if (is_null(input()->getArgument('stage'))) {
        $environments = array_keys(Deployer::get()->environments->toArray());
        throw new \RuntimeException("No environment was specified. Run the command again with one of the available environments as a parameter: " . implode(', ', $environments));
    }

    if (empty(input()->getOption('tag')) && empty(input()->getOption('branch'))) {
        throw new \RuntimeException("No branch or tag was supplied.\n\nPlease provide either --tag={tag} or --branch={branch} so I know what to deploy.");
    }
});

/**
 *
 */
desc('Check branch existence');
task('deploy:check_branch', function () {

    $branch = get('branch');

    // If option `branch` is set.
    if (!empty(input()->getOption('branch'))) {
        $branch = input()->getOption('branch');
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
    if (!empty(input()->getOption('tag'))) {
        $tag = input()->getOption('tag');
    } elseif (get('tag')) {
        $tag = get('tag');
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

/**
 *
 */
desc('Send deployment message to slack');
task('notify:send-deployment-message', function () {

    if (empty(get('slack_webhook', ''))) {
        return;
    }

    $repo = str_replace(['https://', 'http://', 'git@', 'github.com:'], '', get('repository'));
    $stage = input()->getArgument('stage');
    $deployed = input()->getArgument('end');

    $output = "Deployed {$repo} at {$deployed} to {$stage}!";

    $attachment = [
        'title' => get('slack_title', null),
        'color' => get('slack_color', null),
        'text'  => $output,
    ];

    $postString = json_encode([
        'icon_emoji'  => get('slack_emoji', ':robot_face:'),
        'username'    => get('slack_name', 'Deployment Bot'),
        'attachments' => [$attachment],
        'mrkdwn'      => true,
    ]);

    http_post(get('slack_webhook', null), $postString);

})->onlyOn(['production']);

/**
 *
 */
desc('Send release note to slack');
task('notify:send-release-notes', function () {

    if (empty(get('slack_webhook', ''))) {
        return;
    }
    
    if (!input()->hasOption('start') && !input()->hasOption('end')) {
        return;
    }

    if (empty(get('release_notes_command', ''))) {
        return;
    }

    $start = input()->getOption('start');
    $end   = input()->getOption('end');

    $output = runLocally(get('release_notes_command') . " --start=$start --end=$end --format=slack");
    // @todo: format release notes as a "post" (instead of just a message)

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

    http_post(get('slack_webhook'), $postString);

})->onlyOn(['production']);

/**
 *
 */
desc('Send release note to API');
task('notify:send-release-notes-api', function () {

    if (!input()->hasOption('start') && !input()->hasOption('end')) {
        return;
    }

    if (empty(get('release_notes_command', ''))) {
        return;
    }

    $start = input()->getOption('start');
    $end   = input()->getOption('end');

    $endpoint = get('api_endpoint');

    if (empty($endpoint)) {
        return;
    }

    // @todo: determine which `release-notes` script to use
    $output = runLocally(get('release_notes_command') . " --start=$start --end=$end --format=json");

    http_post($endpoint, $output);

})->onlyOn(['production']);

/**
 *
 */
desc('Run release process');
task('deploy:release', function(){

    $prefix  = get('tag-prefix');

    $lastTag = runLocally("git describe --tag --match '{$prefix}[0-9]*' --abbrev=0 HEAD");

    if (empty($lastTag)) {
        throw new \RuntimeException("Could not determine last tag.");
    }

    $numbers = explode('.', str_replace($prefix, '', trim($lastTag)));
    $numbers = array_map('intval', $numbers);

    if (count($numbers) < 3) {
        throw new \RuntimeException("Tag name does not follow semver standard.");
    }

    $numbers[1]++;

    $newTag = $prefix.implode('.', $numbers);

    if (!askConfirmation("Latest tag before release is {$lastTag}. Continuing will tag current HEAD at {$newTag} and deploy to production. Proceed?", true)) {
        throw new \RuntimeException("User aborted.");
    }

    runLocally("git tag $newTag");
    runLocally("git push origin --tags");

    set('tag', $newTag);

    // Set these for the release notes:
    input()->setArgument('start', $lastTag);
    input()->setArgument('end', $newTag);
});

/**
 *
 */
desc('Run hotfix process');
task('deploy:hotfix', function(){

    $prefix  = get('tag-prefix');
    $lastTag = runLocally("git describe --tag --match '{$prefix}[0-9]*' --abbrev=0 HEAD");

    if (empty($lastTag)) {
        throw new \RuntimeException("Could not determine last tag.");
    }

    $numbers = explode('.', str_replace($prefix, '', trim($lastTag)));
    $numbers = array_map('intval', $numbers);

    if (count($numbers) !== 3) {
        throw new \RuntimeException("Tag name does not follow semver standard.");
    }

    $numbers[2]++;

    $newTag = $prefix.implode('.', $numbers);

    if (!askConfirmation("Latest tag before hotfixing is {$lastTag}. Continuing will tag current HEAD at {$newTag} and deploy to production. Proceed?", true)) {
        throw new \RuntimeException("User aborted.");
    }

    writeln("<info>Latest tag is {$lastTag}. Hotfix tag will be {$newTag}</info>");

    runLocally("git tag $newTag");
    runLocally("git push origin --tags");

    set('tag', $newTag);

    // Set these for the release notes:
    input()->setArgument('start', $lastTag);
    input()->setArgument('end', $newTag);
});

/**
 *
 */
task('deploy', [
    'deploy:check_parameters',
    'deploy:clean_working_dir',
    'deploy:git_fetch',
    'deploy:check_branch',
    'deploy:check_tag',
    'deploy:prepare',
    'artisan:down',
    'deploy:update_code',
    'deploy:vendors',
    'artisan:migrate',
    'artisan:queue:restart',
    'artisan:up',
    'notify:send-deployment-message',
]);

/**
 *
 */
task('release', [
    'deploy:clean_working_dir',
    'deploy:git_fetch',
    'deploy:release',
    'deploy:prepare',
    'artisan:down',
    'deploy:update_code',
    'deploy:vendors',
    'artisan:migrate',
    'artisan:queue:restart',
    'artisan:up',
    'notify:send-deployment-message',
    'notify:send-release-notes',
    'notify:send-release-notes-api',
]);

/**
 *
 */
task('hotfix', [
    'deploy:clean_working_dir',
    'deploy:git_fetch',
    'deploy:hotfix',
    'deploy:prepare',
    'artisan:down',
    'deploy:update_code',
    'deploy:vendors',
    'artisan:migrate',
    'artisan:queue:restart',
    'artisan:up',
    'notify:send-deployment-message',
    'notify:send-release-notes',
    'notify:send-release-notes-api',
]);
