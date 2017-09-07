<?php
/**
 * Deployment script helpers
 *
 * @author coreymcmahon
 * @date: 6/9/17
 */

namespace Deployer;

/**
 * @param $endpoint
 * @param $postBody
 * @return bool
 */
function http_post($endpoint, $postBody)
{
    if (empty($endpoint)) {
        return false;
    }

    $ch = curl_init();

    $options = [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-type: application/json'],
        CURLOPT_POSTFIELDS     => $postBody,
    ];

    curl_setopt_array($ch, $options);

    if (curl_exec($ch) !== false) {
        curl_close($ch);
    }

    return true;
}

/**
 * @param $unsanitized
 * @return null|string
 */
function sanitize_repository_name($unsanitized)
{
    $unsanitized = str_replace(['.git', ':'], ['', '/'], $unsanitized);
    $parts = explode('/', $unsanitized);
    $repo = array_pop($parts);
    $org  = array_pop($parts);

    if (empty($repo) || empty($org)) {
        return null;
    }

    return "{$org}/{$repo}";
}

/**
 * @param $notes
 * @param string $title
 * @param string $link
 * @return array
 */
function prepare_release_note_payload($notes, $title = '', $link = '')
{
    $payload = [
        'fallback' => $notes,
        'text' => $notes,
        'color' => '#7CD197',
    ];

    if (!empty($title)) {
        $payload['title'] = $title;
    }

    if (!empty($link)) {
        $payload['title_link'] = $link;
    }

    return $payload;
}