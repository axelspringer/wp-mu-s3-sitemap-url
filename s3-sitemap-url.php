<?php

// @codingStandardsIgnoreFile

/*
 * Handle S3 Sitemap routes
 */
function handleSitemapRoutes() {
  global $_SERVER;
  $parts = parse_url($_SERVER['REQUEST_URI']);
  $path = $parts['path'];
  $pattern = '/^\/data\/sitemap\/((sitemap)(-page|-news)?(-section|-index|(-\d{4}-\d{2}(-\d{2})?)-no\d{1,2}?)?.xml(\.gz)?)$/';

  preg_match($pattern, $path, $matches);

  if (count($matches) > 1) {

      /**
        * @see web/wp-content/plugins/amazon-s3-and-cloudfront/wordpress-s3.php#L60
        * @var \Amazon_S3_And_CloudFront $as3cf
        */
      global $as3cf;

      try {
          /**
            * @var \Aws\S3\S3Client $s3Client
            */
            $s3Client = $as3cf->get_s3client($as3cf->get_setting('region'));

            $command = $s3Client->getCommand('GetObject', [
              'Bucket' => $as3cf->get_setting('bucket'),
              'Key'    => $path,
            ]);
            // Get the JSON response data and extract the log records
            $command->execute();

            /**
            * @var \Guzzle\Http\Message\Response $response
            */

            $response = $command->getResponse();
            $body = $response->getBody(true);
            header('Content-Type: ' . $response->getContentType() . '; charset=UTF-8');
            header('Content-Length: ' . $response->getContentLength());
            header('Cache-Control: '. $response->getCacheControl());
            header('Last-Modified: '. $response->getLastModified());
            header('Expires: '. $response->getExpires());

            if ($response->getContentEncoding()) {
              header('Content-Encoding: ' . $response->getContentEncoding());
            }
            echo $body;
            exit();
      } catch (\Exception $e) {
          exit();
      }
  }
}
// filter to handle sitemap routes
add_action('parse_request', 'handleSitemapRoutes');

/**
 * Images that are not uploaded over the wordpress backend don't have a 'amazonS3_info' entry in the database.
 * We have to make sure, this info exists. Otherwise we are running into unexpected side effects.
 * (e.g. images are not displayed properly in the text-editor)
 */
add_filter('as3cf_get_attachment_s3_info', function($meta, $postId) {
    if (is_array($meta)) {
       return $meta;
    }

    /**
     * @var $as3cf \Amazon_S3_And_CloudFront
     */
    global $as3cf;

    $file = get_post_meta($postId, '_wp_attached_file', true);

    $s3object = [
        'bucket' => $as3cf->get_setting('bucket'),
        'key'    => $as3cf->get_object_prefix() . $file,
        'region' => $as3cf->get_setting('region'),
        'acl'    => \Amazon_S3_And_CloudFront::DEFAULT_ACL
    ];

    if (!get_s3_client()->doesObjectExist($s3object['bucket'], $s3object['key'])) {
        return $meta;
    }

    $result = update_post_meta($postId, 'amazonS3_info', $s3object);

    if ($result === false) {
        return $meta;
    }

    return $s3object;
}, 10, 2);

/**
 * Paths to images are saved with relative paths in the database.
 * For that reason, images are not visible in the text-editor.
 * Therefore we have to prepend the S3 url to every image source that matches a specific pattern.
 */
add_filter('content_edit_pre', function($content) {
    if (empty($content)) {
        return $content;
    }

    /**
     * @var $as3cf \Amazon_S3_And_CloudFront
     */
    global $as3cf;

    $s3Client = get_s3_client();
    $bucket = $as3cf->get_setting('bucket');
    return preg_replace_callback('/(["\'])\/?(data\/uploads[^\\1]*?)\\1/i', function($match) use ($s3Client, $bucket) {
        $path = $match[2];

        if (!$s3Client->doesObjectExist($bucket, $path)) {
            return '/' . $path;
        }

        $replaced = $s3Client->getObjectUrl($bucket, $path);

        return $replaced;
    }, $content);
}, 10, 2);
