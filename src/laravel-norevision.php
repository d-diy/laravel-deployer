<?php

namespace Deployer;

require 'recipe/laravel.php';
require __DIR__.'/helpers.php';

use Symfony\Component\Console\Input\InputOption;

/**
 *
 */
function check_stage()
{
    // If the stage is not provided we want to prevent the script from deploying to ALL environments (default behaviour).
    if (is_null(input()->getArgument('stage'))) {
        $environments = array_keys(Deployer::get()->environments->toArray());
        throw new \RuntimeException("No environment was specified. Run the command again with one of the available environments as a parameter: " . implode(', ', $environments));
    }
}

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

/**
 *
 */
desc('Preparing server for deploy');
task('deploy:prepare', function () {
    check_stage();

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

    set('release_path', parse('{{deploy_path}}'));
});

/**
 *
 */
desc('Check that a tag or branch has been supplied as input parameter. Not relevant for hotfixes or releases.');
task('deploy:check_parameters', function () {
    check_stage();
    if (empty(input()->getOption('tag')) && empty(input()->getOption('branch'))) {
        throw new \RuntimeException("No branch or tag was supplied.\n\nPlease provide either --tag={tag} or --branch={branch} so I know what to deploy.");
    }
});

/**
 *
 */
desc('Checks that the local and remote working paths are both clean (no uncommitted changes).');
task('deploy:clean_working_dir', function () {

    check_stage();
    $git = get('bin/git', null);

    if (get('standalone', false)) {
        return;
    }

    // Check the local environment, maybe the user forgot to commit some changes in their local working copy
    $output = runLocally('git status');
    if (strpos($output, 'working directory clean') === false && strpos($output, 'working tree clean') === false) {
        if (!askConfirmation("The local working path is not clean. This means are uncommitted changes on your local repository that will NOT be deployed. Continue anyway?", true)) {
            throw new \RuntimeException('Working directory is not clean, user aborted.');
        }
    }

    // Check the remote machine, we don't want to unintentionally wipe out code changes on the server
    $output = run("cd {{release_path}} && $git status");
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
desc('Fetches git references locally.');
task('deploy:git_fetch', function () {
    check_stage();

    if (get('standalone', false)) {
        return;
    }

    runLocally('git fetch');
    runLocally('git fetch --tags');

});

/**
 *
 */
desc('When deploying a branch, ensures that the branch exists on the remote.');
task('deploy:check_branch', function () {
    check_stage();

    $branch = get('branch', null);

    // If option `branch` is set.
    if (!empty(input()->getOption('branch'))) {
        $branch = input()->getOption('branch');
    }

    // No local repo in standalone mode, so we can't check this
    if (get('standalone', false)) {
        return;
    }

    if (empty($branch)) {
        writeln("   Not deploying branch, skipping 'deploy:check_branch'");
        return;
    }

    $output = runLocally('git branch -a');
    if (strpos($output, "remotes/origin/$branch") === false) {
        throw new \RuntimeException("The referenced branch `$branch` doesn't exist on origin.");
    }

});

desc('When deploying a tag ensures that the tag exists on the remote.');
task('deploy:check_tag', function () {
    check_stage();

    $tag = null;

    // If option `tag` is set
    if (!empty(input()->getOption('tag'))) {
        $tag = input()->getOption('tag');
    } elseif (get('tag', false)) {
        $tag = get('tag');
    }

    // No local repo in standalone mode, so we can't check this
    if (get('standalone', false)) {
        return;
    }

    if (empty($tag)) {
        writeln("   Not deploying tag, skipping 'deploy:check_tag'");
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
desc('Puts the selected stage in maintenance mode');
task('artisan:down', function () {
    check_stage();
    $output = run('{{bin/php}} {{release_path}}/artisan down');
    writeln('   <info>'.$output.'</info>');
});


/**
 *
 */
desc('Pulls down all branches/tags to the target server and then checks out the relevant one (based on arguments).');
task('deploy:update_code', function () {
    check_stage();

    $branch = get('branch', null);
    $git    = get('bin/git', null);

    // If option `branch` is set.
    if (!empty(input()->getOption('branch'))) {
        $branch = input()->getOption('branch');
    }

    // If option `tag` is set
    if (!empty(input()->getOption('tag'))) {
        $tag = input()->getOption('tag');
    } elseif (get('tag', false)) {
        $tag = get('tag');
    }

    run("cd {{release_path}} && $git fetch");
    run("cd {{release_path}} && $git fetch --tags");

    if (!empty($tag)) {
        writeln("   <info>Checking out 'refs/tags/{$tag}'...</info>");
        // Tags shouldn't change over time, so no need to `git pull` here.
        run("cd {{release_path}} && $git checkout $tag");
        set('after', $tag);
    } elseif (!empty($branch)) {
        writeln("   <info>Checking out and pulling '{$branch}'...</info>");
        // We need to `git pull` from origin in case the branch has been updated:
        run("cd {{release_path}} && $git checkout $branch && git pull origin $branch");
        set('after', $branch);
    } else {
        throw new \RuntimeException("No tag or branch set. Should not be possible to get here :S");
    }

});

/**
 *
 */
desc('Run database migrations.');
task('artisan:migrate', function () {
    check_stage();
    if (get('migration', true) === false) {
        return;
    }
    run('{{bin/php}} {{release_path}}/artisan migrate --force');
});

desc('Takes the selected stage out of maintenance mode.');
task('artisan:up', function () {
    check_stage();
    $output = run('{{bin/php}} {{release_path}}/artisan up');
    writeln('   <info>'.$output.'</info>');
});

/**
 *
 */
desc('Send deployment message to Slack.');
task('notify:send-deployment-message', function () {
    check_stage();

    $repo = sanitize_repository_name(get('repository', ''));

    $stage = input()->getArgument('stage');

    $user = runLocally('whoami');

    $deployed = '';
    $after = get('after', false);
    if (!empty($after)) {
        $deployed = "at `{$after}` ";
    }

    $message = "*{$user}* deployed *{$repo}* {$deployed}to *{$stage}*!";

    $postString = \json_encode([
        'icon_emoji'  => get('slack_emoji', ':robot_face:'),
        'username'    => get('slack_name', 'Deployment Bot'),
        'text'        => $message,
    ]);

    if (!http_post(get('slack_webhook', false), $postString)) {
        writeln("   <error>Attempted to send Slack message, but no 'slack_webhook' set!</error>");
        writeln("   <info>{$message}</info>");
    }
});

function get_start_end_tags()
{
    // These might be set via command line arguments:
    $start = input()->hasOption('start') ? input()->getOption('start') : false;
    $end   = input()->hasOption('end') ? input()->getOption('end') : false;

    // Or via hotfix/release tasks:
    $start = !$start ? get('before', false) : $start;
    $end   = !$end   ? get('after', false)  : $end;

    return [$start, $end];
}

/**
 *
 */
desc('Generate release notes given a start and end tag and send to Slack.');
option('start', null, InputOption::VALUE_OPTIONAL, 'Start tag for the release notes.');
option('end', null, InputOption::VALUE_OPTIONAL, 'End tag for the release notes.');
task('notify:send-release-notes', function () {
    check_stage();

    list($start, $end) = get_start_end_tags();

    if (!$start || !$end) {
        writeln("   <info>Could not determine start and end tags for release notes. Exiting.</info>");
        return;
    }

    if (!get('release_notes_command', false)) {
        writeln("   <info>'release_notes_command' config is not set. Exiting.</info>");
        return;
    }

    writeln("   <info>Generating release notes for {$start}...{$end}</info>");

    $output = "" . runLocally(get('release_notes_command') . " --start={$start} --end={$end} --format=slack");

    if (empty(trim($output))) {
        writeln("    No release notes generated.");
    }

    $repo = get('repository');
    $link = str_replace([':', 'git@', '.git'], ['/', 'https://', ''], $repo) . "/compare/{$start}...{$end}";

    $repoName = sanitize_repository_name($repo);
    $title = "Release Notes - $repoName @ {$end}";

    $postString = json_encode([
        'icon_emoji'  => get('slack_emoji', ':robot_face:'),
        'username'    => get('slack_name', 'Deployment Bot'),
        'attachments' => [prepare_release_note_payload($output, $title, $link)],
    ]);

    if (!http_post(get('slack_webhook', false), $postString)) {
        writeln("   <error>Attempted to send Slack message, but no 'slack_webhook' set!</error>");
        writeln("   <info>{$output}</info>");
    }
});

/**
 *
 */
desc('Generate release notes given a start and end tag and send to an API endpoint via a POST request.');
option('start', null, InputOption::VALUE_OPTIONAL, 'Start tag for the release notes.');
option('end', null, InputOption::VALUE_OPTIONAL, 'End tag for the release notes.');
task('notify:send-release-notes-api', function () {
    check_stage();

    list($start, $end) = get_start_end_tags();

    if (!$start || !$end) {
        writeln("   <info>Could not determine start and end tags for release notes. Exiting.</info>");
        return;
    }

    if (!get('release_notes_command', false)) {
        writeln("   <info>'release_notes_command' config is not set. Exiting.</info>");
        return;
    }

    writeln("   <info>Generating release notes for {$start}...{$end}</info>");

    $output = runLocally(get('release_notes_command') . " --start=$start --end=$end --format=json");

    if (!http_post(get('api_endpoint', false), ['text' => $output])) {
        writeln("   <error>Attempted to send release notes, but no 'api_endpoint' set!</error>");
        writeln("   <info>{$output}</info>");
    }
});

/**
 *
 */
desc('Creates and pushes a release tag.');
task('deploy:release', function(){
    check_stage();

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
    $numbers[2] = 0;

    $newTag = $prefix.implode('.', $numbers);

    if (!askConfirmation("Latest tag before release is {$lastTag}. Continuing will tag current HEAD at {$newTag} and deploy to production. Proceed?", true)) {
        throw new \RuntimeException("User aborted.");
    }

    writeln("   <info>Tagging at {$newTag}...</info>");

    runLocally("git tag $newTag");
    runLocally("git push origin --tags");

    set('tag', $newTag);

    // Set this for the release notes:
    set('before', $lastTag);
});

/**
 *
 */
desc('Creates and pushes a hotfix tag.');
task('deploy:hotfix', function(){
    check_stage();

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

    writeln("   <info>Tagging at {$newTag}...</info>");

    runLocally("git tag $newTag");
    runLocally("git push origin --tags");

    set('tag', $newTag);

    // Set this for the release notes:
    set('before', $lastTag);
});

/**
 *
 */
task('deploy', [
    'deploy:prepare',
    'deploy:check_parameters',
    'deploy:clean_working_dir',
    'deploy:git_fetch',
    'deploy:check_branch',
    'deploy:check_tag',
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
    'deploy:prepare',
    'deploy:clean_working_dir',
    'deploy:git_fetch',
    'deploy:release',
    'artisan:down',
    'deploy:update_code',
    'deploy:vendors',
    'artisan:migrate',
    'artisan:queue:restart',
    'artisan:up',
    'notify:send-deployment-message',
    'notify:send-release-notes',
    //'notify:send-release-notes-api',
]);

/**
 *
 */
task('hotfix', [
    'deploy:prepare',
    'deploy:clean_working_dir',
    'deploy:git_fetch',
    'deploy:hotfix',
    'artisan:down',
    'deploy:update_code',
    'deploy:vendors',
    'artisan:migrate',
    'artisan:queue:restart',
    'artisan:up',
    'notify:send-deployment-message',
    'notify:send-release-notes',
    //'notify:send-release-notes-api',
]);
