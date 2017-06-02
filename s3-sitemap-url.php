<?php

defined( 'ABSPATH' ) || exit;

Class Asse_Sitemap {

    public function __construct() {
        // filter to handle sitemap routes
        add_action( 'parse_request', array( $this, 'handle_sitemap_routes' ) );
    }

    public function handle_sitemap_routes() {
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
}

$asse_sitemap = new Asse_Sitemap();
