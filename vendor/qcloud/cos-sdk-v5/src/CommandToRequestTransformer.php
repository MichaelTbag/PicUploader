<?php

namespace Qcloud\Cos;

use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Command\CommandInterface;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

class CommandToRequestTransformer {
    private $config;
    private $operation;

    public function __construct( $config, $operation ) {
        $this->config = $config;
        $this->operation = $operation;
    }

    // format bucket style

    public function bucketStyleTransformer( CommandInterface $command, RequestInterface $request ) {
        $action = $command->getName();
        if ($action == 'ListBuckets') {
            $uri =  "service.cos.myqcloud.com";
    
            if ($this->config['endpoint'] != null) {
                $uri = $this->config['endpoint'];
            }   
            if ($this->config['domain'] != null) {
                $uri = $this->config['domain'];
            }   
            if ($this->config['ip'] != null) {
                $uri = $this->config['ip'];
                if ($this->config['port'] != null) {
                    $uri = $this->config['ip'] . ":" . $this->config['port'];
                }   
            }
            return $request->withUri(new Uri($this->config['schema']."://". $uri. "/"));
        }
        $operation = $this->operation;
        $bucketname = $command['Bucket'];

        $appId = $this->config['appId'];
        if ( $appId != null && endWith( $bucketname, '-'.$appId ) == False ) {
            $bucketname = $bucketname.'-'.$appId;
        }
        $command['Bucket'] = $bucketname;
        $path = '';

        $http_method = $operation['httpMethod'];
        $uri = $operation['uri'];

        // Hoststyle is used by default
        // Pathstyle
        if ( $this->config['pathStyle'] != true ) {
            if ( isset( $operation['parameters']['Bucket'] ) && $command->hasParam( 'Bucket' ) ) {
                $uri = str_replace( '{Bucket}', '', $uri );
            }

            if ( isset( $operation['parameters']['Key'] ) && $command->hasParam( 'Key' ) ) {
                $uri = str_replace( '{/Key*}', encodeKey( $command['Key'] ), $uri );
            }
        }

        if ($this->config['endpoint'] == null) {
            $this->config['endpoint'] = "myqcloud.com";
        }

        $domain_type = '.cos.';
        if ($action == 'PutBucketImageStyle' || $action == 'GetBucketImageStyle' || $action == 'DeleteBucketImageStyle'
            || $action == 'PutBucketGuetzli' || $action == 'GetBucketGuetzli' || $action == 'DeleteBucketGuetzli') {
            $domain_type = '.pic.';
        }

        $origin_host = $this->config['allow_accelerate'] ?
            $bucketname . $domain_type . 'accelerate' . '.' . $this->config['endpoint'] :
            $bucketname . $domain_type . $this->config['region'] . '.' . $this->config['endpoint'];

        // domain
        if ( $this->config['domain'] != null ) {
            $origin_host = $this->config['domain'];
        }
        $host = $origin_host;
        if ( $this->config['ip'] != null ) {
            $host = $this->config['ip'];
            if ( $this->config['port'] != null ) {
                $host = $this->config['ip'] . ':' . $this->config['port'];
            }
        }

        $path = $this->config['schema'].'://'. $host . $uri;
        $uri = new Uri( $path );
        $query = $request->getUri()->getQuery();
        if ( $uri->getQuery() != $query && $uri->getQuery() != '' ) {
            $query =   $uri->getQuery() . '&' . $request->getUri()->getQuery();
        }
        $uri = $uri->withQuery( $query );
        $request = $request->withUri( $uri );
        $request = $request->withHeader( 'Host', $origin_host );
        return $request;
    }

    // format upload body

    public function uploadBodyTransformer( CommandInterface $command, $request, $bodyParameter = 'Body', $sourceParameter = 'SourceFile' ) {

        $operation = $this->operation;
        if ( !isset( $operation['parameters']['Body'] ) ) {
            return $request;
        }
        $source = isset( $command[$sourceParameter] ) ? $command[$sourceParameter] : null;
        $body = isset( $command[$bodyParameter] ) ? $command[$bodyParameter] : null;
        // If a file path is passed in then get the file handle
        if ( is_string( $source ) && file_exists( $source ) ) {
            $body = fopen( $source, 'rb' );
        }
        // Prepare the body parameter and remove the source file parameter
        if ( null !== $body ) {
            return $request;
        } else {
            throw new InvalidArgumentException(
                "You must specify a non-null value for the {$bodyParameter} or {$sourceParameter} parameters." );
            }
        }

        // update md5

        public function md5Transformer( CommandInterface $command, $request ) {
            $operation = $this->operation;
            if ( isset( $operation['data']['contentMd5'] ) ) {
                $request = $this->addMd5( $request );
            }
            if ( isset( $operation['parameters']['ContentMD5'] ) &&
            isset( $command['ContentMD5'] ) ) {
                $value = $command['ContentMD5'];
                if ( $value != false ) {
                    $request = $this->addMd5( $request );
                }
            }

            return $request;
        }

        // add Query string

        public function queryStringTransformer( CommandInterface $command, $request ) {
            $operation = $this->operation;
            if ( isset( $command['Params'] ) ) {
                $params = $command['Params'];
                foreach ( $params as $key => $value ) {
                    $uri = $request->getUri();
                    $query = $uri->getQuery();
                    $uri = $uri->withQuery($query. "&" . urlencode($key) . "=" . $value );
                    $request = $request->withUri( $uri );
                }
            }

            return $request;
        }

        // add Header string

        public function headerTransformer( CommandInterface $command, $request ) {
            $operation = $this->operation;
            if ( isset( $command['Headers'] ) ) {
                $headers = $command['Headers'];
                foreach ( $headers as $key => $value ) {
                    $request = $request->withHeader( $key, $value);
                }
            }
            return $request;
        }

        // add meta

        public function metadataTransformer( CommandInterface $command, $request ) {
            $operation = $this->operation;
            if ( isset( $command['Metadata'] ) ) {
                $meta = $command['Metadata'];
                foreach ( $meta as $key => $value ) {
                    $request = $request->withHeader( 'x-cos-meta-' . $key, $value );
                }
            }
            $request = headersMap( $command, $request );

            return $request;
        }

        // count md5

        private function addMd5( $request ) {
            $body = $request->getBody();
            if ( $body && $body->getSize() > 0 ) {
                $md5 = base64_encode( md5( $body, true ) );
                return $request->withHeader( 'Content-MD5', $md5 );
            }
            return $request;
        }

        // inventoryId

        public function specialParamTransformer( CommandInterface $command, $request ) {
            $action = $command->getName();
            if ( $action == 'PutBucketInventory' ) {
                $id = $command['Id'];
                $uri = $request->getUri();
                $query = $uri->getQuery();
                $uri = $uri->withQuery( $query . '&Id='.$id );
                return $request->withUri( $uri );
            }
            return $request;
        }

        public function ciParamTransformer( CommandInterface $command, $request ) {
            $action = $command->getName();
            if ( $action == 'GetObject' ) {
                if(str_contains($uri = $request->getUri(), '%21') ) {
                    $uri = new Uri( str_replace('%21', '!', $uri) );
                    $request = $request->withUri( $uri );
                }
                if(isset($command['ImageHandleParam']) && $command['ImageHandleParam']){
                    $uri = $request->getUri();
                    $query = $uri->getQuery();
                    if($query){
                        $query .= "&" . urlencode($command['ImageHandleParam']);
                    }else{
                        $query .= urlencode($command['ImageHandleParam']);
                    }
                    $uri = $uri->withQuery($query);
                    $request = $request->withUri( $uri );
                }
            }
            return $request;
        }

        public function cosDomain2CiTransformer(CommandInterface $command, $request) {
            $action = $command->getName();
            if(key_exists($action, array( 'DescribeMediaBuckets' => 1, ))) {
                $origin_host = "ci.{$this->config['region']}.myqcloud.com";
                $host = $origin_host;
                if ($this->config['ip'] != null) {
                    $host = $this->config['ip'];
                    if ($this->config['port'] != null) {
                        $host = $this->config['ip'] . ":" . $this->config['port'];
                    }
                }

                $path = $this->config['schema'].'://'. $host . $request->getUri()->getPath();
                $uri = new Uri( $path );
                $query = $request->getUri()->getQuery();
                $uri = $uri->withQuery( $query );
                $request = $request->withUri( $uri );
                $request = $request->withHeader( 'Host', $origin_host );
                return $request;
            }
            $ciActions = array(
                'DetectText' => 1,
                'CreateMediaTranscodeJobs' => 1,
                'CreateMediaJobs' => 1,
                'DescribeMediaJob' => 1,
                'DescribeMediaJobs' => 1,
                'CreateMediaSnapshotJobs' => 1,
                'CreateMediaConcatJobs' => 1,
                'DetectAudio' => 1,
                'GetDetectAudioResult' => 1,
                'GetDetectTextResult' => 1,
                'DetectVideo' => 1,
                'GetDetectVideoResult' => 1,
                'DetectDocument' => 1,
                'GetDetectDocumentResult' => 1,
                'CreateDocProcessJobs' => 1,
                'DescribeDocProcessQueues' => 1,
                'DescribeDocProcessJob' => 1,
                'GetDescribeDocProcessJobs' => 1,
                'DetectImages' => 1,
                'GetDetectImageResult' => 1,
                'DetectVirus' => 1,
                'GetDetectVirusResult' => 1,
                'CreateMediaVoiceSeparateJobs' => 1,
                'DescribeMediaVoiceSeparateJob' => 1,
                'DetectWebpage' => 1,
                'GetDetectWebpageResult' => 1,
                'DescribeMediaQueues' => 1,
                'UpdateMediaQueue' => 1,
                'CreateMediaSmartCoverJobs' => 1,
                'CreateMediaVideoProcessJobs' => 1,
                'CreateMediaVideoMontageJobs' => 1,
                'CreateMediaAnimationJobs' => 1,
                'CreateMediaPicProcessJobs' => 1,
                'CreateMediaSegmentJobs' => 1,
                'CreateMediaVideoTagJobs' => 1,
                'CreateMediaSuperResolutionJobs' => 1,
                'CreateMediaSDRtoHDRJobs' => 1,
                'CreateMediaDigitalWatermarkJobs' => 1,
                'CreateMediaExtractDigitalWatermarkJobs' => 1,
                'DetectLiveVideo' => 1,
                'CancelLiveVideoAuditing' => 1,
            );
            if (key_exists($action, $ciActions)) {
                $bucketname = $command['Bucket'];
                $appId = $this->config['appId'];
                if ( $appId != null && endWith( $bucketname, '-'.$appId ) == False ) {
                    $bucketname = $bucketname.'-'.$appId;
                }
                $command['Bucket'] = $bucketname;
                $domain_type = '.ci.';
                $origin_host = $bucketname . $domain_type . $this->config['region'] . '.' . $this->config['endpoint'];
                $host = $origin_host;
                if ( $this->config['ip'] != null ) {
                    $host = $this->config['ip'];
                    if ( $this->config['port'] != null ) {
                        $host = $this->config['ip'] . ':' . $this->config['port'];
                    }
                }
                $path = $this->config['schema'].'://'. $host . $request->getUri()->getPath();
                $uri = new Uri( $path );
                $query = $request->getUri()->getQuery();
                $uri = $uri->withQuery( $query );
                $request = $request->withUri( $uri );
                $request = $request->withHeader( 'Host', $origin_host );
            }
            return $request;
        }

        public function __destruct() {
        }

    }
