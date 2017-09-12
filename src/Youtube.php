<?php
/*
 * changes from JoeDawson/youtube
 * 		- make chunkSize a class variable, create setter.  use in both upload and withThumbnail 
 * 		- don't read $accessToken from database, instead pass in using getter and setter
 * 		- for upload, don't check if file exists, as may be remote
 * 		- for upload, pass filesize in $data, as filesize() won't work for remote files
 */
namespace Dawson\Youtube;

use Exception;
use Google_Client;
use Google_Service_YouTube;

class Youtube
{
    /**
     * Application Container
     * 
     * @var Application
     */
    private $app;

    /**
     * Google Client
     * 
     * @var \Google_Client
     */
    protected $client;

    /**
     * Google YouTube Service
     * 
     * @var \Google_Service_YouTube
     */
    protected $youtube;

    /**
     * Video ID
     * 
     * @var string
     */
    private $videoId;

    /**
     * Video Snippet
     * 
     * @var array
     */
    private $snippet;

    /**
     * Thumbnail URL
     * 
     * @var string
     */
    private $thumbnailUrl;
    
    /**
     * Chunk size for upload
     * 
     * @var int
     */
    private $chunkSize = 1 * 1024 * 1024;
    
    public function setChunksize( $val ) {
    	$this->chunkSize = $val;
    	return $this;
    }
    
    /**
     * Access Token
     */
    
    public function setAccessToken( $accessToken) {
    	$this->client->setAccessToken($accessToken);
    	return $this;
    }
    
    public function getAccessToken() {
    	return $this->client->getAccessToken();		// may have been refreshed
    }

    /**
     * Constructor
     * 
     * @param \Google_Client $client
     */
    public function __construct($app, Google_Client $client)
    {
        $this->app = $app;

        $this->client = $this->setup($client);

        $this->youtube = new \Google_Service_YouTube($this->client);
    }
    
    /**
     * Upload the video to YouTube
     * 
     * changes from original:
     * 		- remove check for video as it may be remote
     * 		- require filesize be passed in via $data as it may be remote
     * 
     * @param  string $path
     * @param  array  $data
     * @param  string $privacyStatus
     * @return string
     */
    public function upload($path, array $data = [], $privacyStatus = 'public')
    {
        $this->handleAccessToken();

        try {
            // Setup the Snippet
            $snippet = new \Google_Service_YouTube_VideoSnippet();

            if (array_key_exists('title', $data))       $snippet->setTitle($data['title']);
            if (array_key_exists('description', $data)) $snippet->setDescription($data['description']);
            if (array_key_exists('tags', $data))        $snippet->setTags($data['tags']);
            if (array_key_exists('category_id', $data)) $snippet->setCategoryId($data['category_id']);
            
            $filesize = $data['filesize'] ?? filesize($path);

            // Set the Privacy Status
            $status = new \Google_Service_YouTube_VideoStatus();
            $status->privacyStatus = $privacyStatus;

            // Set the Snippet & Status
            $video = new \Google_Service_YouTube_Video();
            $video->setSnippet($snippet);
            $video->setStatus($status);

            // Set the defer to true
            $this->client->setDefer(true);

            // Build the request
            $insert = $this->youtube->videos->insert('status,snippet', $video);

            // Upload
            $media = new \Google_Http_MediaFileUpload(
                $this->client,
                $insert,
                'video/*',
                null,
                true,
                $this->chunkSize
            );

            // Set the Filesize
            $media->setFileSize($filesize);

            // Read the file and upload in chunks
            $status = false;
            if( !$handle = fopen($path, "rb") ) {
            	throw new Exception('Error opening video file "$path"');
            }
            
            
            while (!$status && !feof($handle)) {
                $chunk = fread($handle, $this->chunkSize);
                $status = $media->nextChunk($chunk);
            }

            fclose($handle);

            $this->client->setDefer(false);

            // Set ID of the Uploaded Video
            $this->videoId = $status['id'];

            // Set the Snippet from Uploaded Video
            $this->snippet = $status['snippet'];

        }  catch (\Google_Service_Exception $e) {
            throw new Exception($e->getMessage());
        } catch (\Google_Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $this;
    }

    /**
     * Set a Custom Thumbnail for the Upload
     *
     * @param  string  $imagePath
     *
     * @return self
     */
    public function withThumbnail($imagePath)
    {
        try {
            $videoId = $this->getVideoId();

            $this->client->setDefer(true);

            $setRequest = $this->youtube->thumbnails->set($videoId);

            $media = new \Google_Http_MediaFileUpload(
                $this->client,
                $setRequest,
                'image/png',
                null,
                true,
                $this->chunkSize
            );
            
            $filesize = $data['filesize'] ?? filesize($imagePath);
            $media->setFileSize($filesize);

            $status = false;
            $handle = fopen($imagePath, "rb");

            while (!$status && !feof($handle)) {
            	$chunk  = fread($handle, $this->chunkSize);
                $status = $media->nextChunk($chunk);
            }

            fclose($handle);

            $this->client->setDefer(false);
            $this->thumbnailUrl = $status['items'][0]['default']['url'];

        } catch (\Google_Service_Exception $e) {
            throw new Exception($e->getMessage());
        } catch (\Google_Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $this;
    }

    /**
     * Delete a YouTube video by it's ID.
     *
     * @param  int  $id
     *
     * @return bool
     */
    public function delete($id)
    {
        $this->handleAccessToken();

        if (!$this->exists($id)) {
            throw new Exception('A video matching id "'. $id .'" could not be found.');
        }

        return $this->youtube->videos->delete($id);
    }

    /**
     * Check if a YouTube video exists by it's ID.
     *
     * @param  int  $id
     *
     * @return bool
     */
    public function exists($id)
    {
        $this->handleAccessToken();

        $response = $this->youtube->videos->listVideos('status', ['id' => $id]);

        if (empty($response->items)) return false;

        return true;
    }

    /**
     * Return the Video ID
     *
     * @return string
     */
    public function getVideoId()
    {
        return $this->videoId;
    }

    /**
     * Return the snippet of the uploaded Video
     *
     * @return array
     */
    public function getSnippet()
    {
        return $this->snippet;
    }

    /**
     * Return the URL for the Custom Thumbnail
     *
     * @return string
     */
    public function getThumbnailUrl()
    {
        return $this->thumbnailUrl;
    }

    /**
     * Setup the Google Client
     *
     * @param \Google_Client $client
     * @return \Google_Client $client
     */
    private function setup(Google_Client $client)
    {
        if(
            !$this->app->config->get('youtube.client_id') ||
            !$this->app->config->get('youtube.client_secret')
        ) {
            throw new Exception('A Google "client_id" and "client_secret" must be configured.');
        }

        $client->setClientId($this->app->config->get('youtube.client_id'));
        $client->setClientSecret($this->app->config->get('youtube.client_secret'));
        $client->setScopes($this->app->config->get('youtube.scopes'));
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');
        $client->setRedirectUri(url(
            $this->app->config->get('youtube.routes.prefix') 
            . '/' .
            $this->app->config->get('youtube.routes.redirect_uri')
        ));

        return $this->client = $client;
    }

    /**
     * Handle the Access Token
     * 
     * @return void
     */
    public function handleAccessToken()
    {
        if (is_null($accessToken = $this->client->getAccessToken())) {
            throw new \Exception('An access token is required.');
        }

        if($this->client->isAccessTokenExpired())
        {
            $accessToken = json_decode($accessToken);

            // If we have a "refresh_token"
            if(property_exists($accessToken, 'refresh_token'))
            {
                // Refresh the access token
                $this->client->refreshToken($accessToken->refresh_token);
            }
        }
        return $this->client->getAccessToken();
    }

    /**
     * Pass method calls to the Google Client.
     *
     * @param  string  $method
     * @param  array   $args
     *
     * @return mixed
     */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->client, $method], $args);
    }
}
