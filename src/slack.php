<?php
/**
 * Slack helpers
 * 
 * @author coreymcmahon
 * @date: 6/9/17
 */

namespace Deployer;

/**
 * @param $endpoint
 * @param $postBody
 */
function http_post($endpoint, $postBody)
{
    if (empty($endpoint) || empty($postBody)) {
        return;
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
}