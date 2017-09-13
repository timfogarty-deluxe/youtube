<?php
/*
 * changes from JoeDawson/youtube
 *         - make chunkSize a class variable, create setter.  use in both upload and withThumbnail
 *         - don't read $accessToken from database, instead pass in using getter and setter
 *         - for upload, don't check if file exists, as may be remote
 *         - for upload, pass filesize in $data, as filesize() won't work for remote files
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
        return json_encode( $this->client->getAccessToken() );
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
     *         - remove check for video as it may be remote
     *         - require filesize be passed in via $data as it may be remote
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
            
            if (array_key_exists('title', $data))        $snippet->setTitle($data['title']);
            if (array_key_exists('description', $data))  $snippet->setDescription($data['description']);
            if (array_key_exists('default_language', $data)) $snippet->setDefaultLanguage($data['default_language']);
            if (array_key_exists('tags', $data))         $snippet->setTags($data['tags']);
            if (array_key_exists('category_id', $data))  $snippet->setCategoryId($data['category_id']);
            if (array_key_exists('published_at', $data)) $snippet->setPublishedAt($data['published_at']);
            
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
        $accessToken = $this->client->getAccessToken();
        
        $uri = $this->client->getRedirectUri();
        
        // hack for artistan command, which has no uri
        if( strncmp( $uri, "http://:/", 9 ) == 0 ) $this->client->setRedirectUri( null );
        
        if (is_null($accessToken = $this->client->getAccessToken())) {
            throw new \Exception('An access token is required.');
        }
        
        if($this->client->isAccessTokenExpired())
        {
            if(is_string($accessToken)) $accessToken = json_decode($accessToken);
            
            // If we have a "refresh_token"
            if( array_key_exists('refresh_token',$accessToken) ) {
                // Refresh the access token
                $this->client->refreshToken($accessToken['refresh_token']);
            }
        }
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
    
    /**
     *
     */
    public function getStatus($id) {
        $part = 'snippet,contentDetails,statistics';
        $params = [ 'id' => $id ];
        
        $this->handleAccessToken();
        return $this->youtube->videos->listVideos($part, $params);
    }
    
    /**
     * List all videos
     *
     */
    public function listVideos() {
        $this->handleAccessToken();
        $err = '';
        try {
            // Call the channels.list method to retrieve information about the
            // currently authenticated user's channel.
            $channelsResponse = $this->youtube->channels->listChannels('contentDetails', array('mine' => 'true'));
            
            foreach ($channelsResponse['items'] as $channel) {
                // Extract the unique playlist ID that identifies the list of videos
                // uploaded to the channel, and then call the playlistItems.list method
                // to retrieve that list.
                $uploadsListId = $channel['contentDetails']['relatedPlaylists']['uploads'];
                
                $playlistItemsResponse = $this->youtube->playlistItems->listPlaylistItems('snippet', array(
                        'playlistId' => $uploadsListId,
                        'maxResults' => 50
                ));
                $list = [];
                foreach ($playlistItemsResponse['items'] as $playlistItem) {
                    $list[ $playlistItem['snippet']['resourceId']['videoId'] ] = $playlistItem['snippet']['title'];
                }
                return $list;
            }
        } catch (Google_ServiceException $e) {
            $err = sprintf('<p>A service error occurred: <code>%s</code></p>',
                    htmlspecialchars($e->getMessage()));
        } catch (Google_Exception $e) {
            $err = sprintf('<p>An client error occurred: <code>%s</code></p>',
                    htmlspecialchars($e->getMessage()));
        }
        return $err;
    }
    
    
    /**
     * update video meta data
     */
    
    public function updateVideo( array $data = [] ) {
        $this->handleAccessToken();
        
        try {
            
            $video = new \Google_Service_YouTube_Video();
            $video->setId($data['id']);
            
            // Setup the Snippet
            $snippet = new \Google_Service_YouTube_VideoSnippet();
            
            if (array_key_exists('title', $data))        $snippet->setTitle($data['title']);
            if (array_key_exists('description', $data))  $snippet->setDescription($data['description']);
            if (array_key_exists('default_language', $data)) $snippet->setDefaultLanguage($data['default_language']);
            if (array_key_exists('tags', $data))         $snippet->setTags($data['tags']);
            if (array_key_exists('category_id', $data))  $snippet->setCategoryId($data['category_id']);
            if (array_key_exists('published_at', $data)) $snippet->setPublishedAt($data['published_at']);
            
            $part = "snippet";
            $video->setSnippet($snippet);
            
            // Set the Privacy Status
            if( array_key_exists('status',$data) ) {
                $status = new \Google_Service_YouTube_VideoStatus();
                $status->privacyStatus = $data['status'];
                $video->setStatus($status);
                $part = "snippet,status";
            }
            
            $response = $this->youtube->videos->update($part, $video);
            return $response;
            
            
        } catch (Google_ServiceException $e) {
            $err = sprintf('<p>A service error occurred: <code>%s</code></p>',
                    htmlspecialchars($e->getMessage()));
        } catch (Google_Exception $e) {
            $err = sprintf('<p>An client error occurred: <code>%s</code></p>',
                    htmlspecialchars($e->getMessage()));
        }
        return $err;
    }
}