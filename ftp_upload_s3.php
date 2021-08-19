<?php
/**
 * @author Harry Tang <harry@powerkernel.com>
 * @link https://powerkernel.com
 * @copyright Copyright (c) 2018 Power Kernel
 */

/* @var $ftp_local_file string $argv[1] */

/* @var $ftp_remote_file string $argv[2] */

use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;

require __DIR__ . '/vendor/autoload.php';
$conf = require __DIR__ . '/config.php';

//$date = date('Ymd');
$bucket = $conf['bucket'];
$ftp_local_file=$argv[1];
$ftp_remote_file=$argv[2];

// S3 Client
$opts = [
    //'profile' => 'default',
    'version' => 'latest',
    'region' => $conf['region'],
    'credentials' => $conf['credentials'],
];
if (!empty($conf['endpoint'])) {
    $opts['endpoint'] = $conf['endpoint'];
}

$client = new Aws\S3\S3Client($opts);

// check bucket exist
$exist = false;
$buckets = $client->listBuckets();
if ($buckets) {
    foreach ($buckets['Buckets'] as $i => $b) {
        if ($b['Name'] == $bucket) {
            $exist = true;
        }
    }
}


// Create Bucket
if (!$exist) {
    $client->createBucket([
        'Bucket' => $bucket,
        'LocationConstraint' => $conf['region'],
    ]);

    // Poll the bucket until it is accessible
    $client->waitUntil('BucketExists', [
        'Bucket' => $bucket
    ]);

    // add Lifecycle
    $client->putBucketLifecycleConfiguration(
        [
            'Bucket' => $bucket, // REQUIRED
            'LifecycleConfiguration' => [
                'Rules' => [ // REQUIRED
                    [
                        'ID' => 'AutoDelete',
                        'AbortIncompleteMultipartUpload' => [
                            'DaysAfterInitiation' => 1,
                        ],
                        'Expiration' => [
                            'Days' => $conf['expiration'],
                        ],
                        'Status' => 'Enabled',
                        'Prefix' => ''
                    ],
                ],
            ],
        ]
    );
}

// check and apply lifecycle
$hasLifeCycle = false;
try {
    $r = $client->getBucketLifecycle(['Bucket' => $bucket]);
    $rules = $r->get('Rules');
    foreach ($rules as $rule) {
        if ($rule['ID'] == 'AutoDelete') {
            $hasLifeCycle = true;
        }
    }
} catch (\Aws\S3\Exception\S3Exception $e) {

}
if (!$hasLifeCycle) {
    // Config auto delete
    $client->putBucketLifecycleConfiguration(
        [
            'Bucket' => $bucket, // REQUIRED
            'LifecycleConfiguration' => [
                'Rules' => [ // REQUIRED
                    [
                        'ID' => 'AutoDelete',
                        'AbortIncompleteMultipartUpload' => [
                            'DaysAfterInitiation' => 1,
                        ],
                        'Expiration' => [
                            'Days' => $conf['expiration'],
                        ],
                        'Status' => 'Enabled',
                        'Prefix' => ''
                    ],
                ],
            ],
        ]
    );
    echo "adding lifecycle\n";
} else {
    echo "lifecycle already added.\n";
}

// Upload
$uploader = new MultipartUploader($client, $ftp_local_file, [
    'bucket' => $bucket,
    'key' => date('Y-m-d') . '/' . $ftp_remote_file,
]);

try {
    $result = $uploader->upload();
    echo "Upload complete: {$result['ObjectURL']}\n";
} catch (MultipartUploadException $e) {
    echo $e->getMessage() . "\n";
}
