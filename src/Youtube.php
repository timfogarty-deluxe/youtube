<?php
/*
 * changes from JoeDawson/youtube
 *         - make chunkSize a class variable, create setter.  use in both upload and withThumbnail
 *         - don't read $accessToken from database, instead pass in using getter and setter
 *         - for upload, don't check if file exists, as may be remote
 *         - for upload, pass filesize in $data, as filesize() won't work for remote files
 *         - add publishAt and embedabble to upload and update
 *         - add methods for manipulating playlists
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
			
			$filesize = $data['filesize'] ?? filesize($path);
			
			// Set the Privacy Status
			$status = new \Google_Service_YouTube_VideoStatus();
			if (array_key_exists('publish_at', $data)) {
				$status->setPublishAt($data['publish_at']);
				$privacyStatus = 'private';
			}
			if (array_key_exists('embeddable', $data)) {
				$status->setEmbeddable($data['embeddable']);
			}
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
				throw new YoutubeException('Error opening video file "$path"');
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
	public function withThumbnail($imagePath, $filesize=0, $mime_type='image/png')
	{
		try {
			if( $filesize == 0 ) $filesize = filesize($imagePath);
			
			$videoId = $this->getVideoId();
			
			$this->client->setDefer(true);
			
			$setRequest = $this->youtube->thumbnails->set($videoId);
			
			$media = new \Google_Http_MediaFileUpload(
					$this->client,
					$setRequest,
					$mime_type,
					null,
					true,
					$this->chunkSize
					);
			
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
			throw new YoutubeException('A video matching id "'. $id .'" could not be found.');
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
					throw new YoutubeException('A Google "client_id" and "client_secret" must be configured.');
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
			throw new YoutubeException('An access token is required.');
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
	 * Get info on a speicific video
	 *
	 * @param string $id
	 * @param comma delimited string $part
	 * @return json object
	 */
	public function getStatus($id, $part=null) {
		if( empty($part) ) $part = 'snippet,status,contentDetails,statistics';
		$params = [ 'id' => $id ];
		
		$this->handleAccessToken();
		return $this->youtube->videos->listVideos($part, $params);
	}
	
	
	public function getStatistics($id) {
		$part = 'statistics';
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
			
			$part = "snippet";
			$video->setSnippet($snippet);
			
			// Set the Privacy Status
			if (array_key_exists('publish_at', $data)) $data['status'] = 'private';
			if (array_key_exists('status',$data)) {
				$status = new \Google_Service_YouTube_VideoStatus();
				$status->privacyStatus = $data['status'];
				if (array_key_exists('publish_at', $data)) {
					$status->setPublishAt($data['publish_at']);
				}
				if (array_key_exists('embeddable', $data)) {
					$status->setEmbeddable($data['embeddable']);
				}
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
	
	/**
	 * Analytics Channel Reports
	 *
	 * @return unknown
	 */
	public function analyticsList( $params ) {
		$channel = "channel==".($params['channel_id'] ?? "MINE");
		$start = $params['start_date'] ?? date("Y-m-d", strtotime( date( "Y-m-d", strtotime( date("Y-m-d") ) ) . "-1 month" ) );
		$end = $params['end_date'] ?? date("Y-m-d");
		$metrics = $params['metrics'] ;
		$opt_params = [ 'dimensions' => $params['dimensions'] ?? 'video' ];
		if( !empty($params['sort']) ) $opt_params['sort'] = $params['sort'];
		if( !empty($params['filter']) ) $opt_params['filter'] = $params['filter'];
		
		$this->handleAccessToken();
		try {
			$youtubeAnalytics = new \Google_Service_YouTubeAnalytics($this->client);
			return $youtubeAnalytics->reports->query( $channel, $start, $end, $metrics, $opt_params );
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
	 * Bulk Reports - list report types
	 * @return Google_Service_YouTubeReporting_ListReportTypesResponse|unknown
	 */
	public function bulkReportsListTypes() {
		$this->handleAccessToken();
		try {
			$youtubeReporting = new \Google_Service_YouTubeReporting($this->client);
			return $youtubeReporting->reportTypes->listReportTypes();
		} catch (Google_ServiceException $e) {
			$err = sprintf('<p>A service error occurred: <code>%s</code></p>',
					htmlspecialchars($e->getMessage()));
		} catch (Google_Exception $e) {
			$err = sprintf('<p>An client error occurred: <code>%s</code></p>',
					htmlspecialchars($e->getMessage()));
		}
		return $err;
	}
	
	public function bulkReportsListJobs() {
		$this->handleAccessToken();
		try {
			$youtubeReporting = new \Google_Service_YouTubeReporting($this->client);
			return $youtubeReporting->jobs->listJobs();
		} catch (Google_ServiceException $e) {
			$err = sprintf('<p>A service error occurred: <code>%s</code></p>',
					htmlspecialchars($e->getMessage()));
		} catch (Google_Exception $e) {
			$err = sprintf('<p>An client error occurred: <code>%s</code></p>',
					htmlspecialchars($e->getMessage()));
		}
		return $err;
	}
	
	public function bulkReportsFetchJobs($jobId) {
		$this->handleAccessToken();
		try {
			$youtubeReporting = new \Google_Service_YouTubeReporting($this->client);
			return $youtubeReporting->jobs_reports->listJobsReports($jobId);
			
			$this->client->setDefer(true);
			
			// Call the YouTube Reporting API's media.download method to download a report.
			$request = $youtubeReporting->media->download("");
			$request->setUrl($reportUrl);
			$response = $this->client->execute($request);
			
			file_put_contents("reportFile", $response->getResponseBody());
			$client->setDefer(false);
			
			
		} catch (Google_ServiceException $e) {
			$err = sprintf('<p>A service error occurred: <code>%s</code></p>',
					htmlspecialchars($e->getMessage()));
		} catch (Google_Exception $e) {
			$err = sprintf('<p>An client error occurred: <code>%s</code></p>',
					htmlspecialchars($e->getMessage()));
		}
		return $err;
	}
	
	public function bulkReportsFetchJob( $reportUrl ) {
		$this->handleAccessToken();
		try {
			$youtubeReporting = new \Google_Service_YouTubeReporting($this->client);
			$this->client->setDefer(true);
			$request = $youtubeReporting->media->download("");
			$request->setUrl($reportUrl);
			$response = $this->client->execute($request);
			$client->setDefer(false);
			return $response->getResponseBody();
			
		} catch (Google_ServiceException $e) {
			$err = sprintf('<p>A service error occurred: <code>%s</code></p>',
					htmlspecialchars($e->getMessage()));
		} catch (Google_Exception $e) {
			$err = sprintf('<p>An client error occurred: <code>%s</code></p>',
					htmlspecialchars($e->getMessage()));
		}
		return $err;
	}
	
	public function bulkReportsAddJob($job) {
		
	}
	
	public function bulkReportsRemoveJob($job) {
		
	}
	
	/**
	 * Create Playlist
	 *
	 * @param array $parameters
	 * @return array
	 */
	public function createPlaylist( $data ) {
		$this->handleAccessToken();
		
		$part = 'snippet,status';
		$properties = [
				'snippet.title' => $data['title'] ?? '',
				'snippet.description' => $data['description'] ?? '',
				'snippet.tags[]' => $data['tags'] ?? '',
				'snippet.defaultLanguage' => $data['language'] ?? '',
				'status.privacyStatus' => $data['privacy'] ?? ''
		];
		$propertyObject = $this->createResource($properties);
		
		//		$params = array('onBehalfOfContentOwner' => '');
		//		$params = array_filter($params);
		$params = [];
		
		$resource = new \Google_Service_YouTube_Playlist($propertyObject);
		// TODO: allow upload of thumbnail
		return $this->youtube->playlists->insert($part, $resource, $params);
	}
	
	public function modifyPlaylist( $data ) {
		$this->handleAccessToken();
		$part = 'snippet,status';
		
		$properties = [ 'id' => $data['id'] ];
		if( isset($data['title']) ) $properties['snippet.title'] = $data['title'];
		if( isset($data['description']) ) $properties['snippet.description'] = $data['description'];
		if( isset($data['tags']) ) $properties['snippet.tags[]'] = $data['tags'];
		if( isset($data['language']) ) $properties['snippet.defaultLanguage'] = $data['language'];
		if( isset($data['privacy']) ) $properties['status.privacyStatus'] = $data['privacy'];
		
		$propertyObject = $this->createResource($properties);
		$params = [];
		$resource = new \Google_Service_YouTube_Playlist($propertyObject);
		return $this->youtube->playlists->update($part, $resource, $params);
	}
	
	
	/**
	 * Return info on a single playlist
	 *
	 * @param array $data
	 * @return object
	 */
	public function fetchPlaylist( $playlist_id ) {
		$this->handleAccessToken();
		$part = 'snippet,contentDetails';
		$params = [ 'id' => $playlist_id ];
		$playlistResponse= $this->youtube->playlists->listPlaylists( $part, $params );
		$snippet = json_decode(json_encode($playlistResponse['items'][0]['snippet']), true);
		
		$playlistItemsResponse = $this->youtube->playlistItems->listPlaylistItems('snippet', ['playlistId' => $playlist_id]);
		$list = [];
		foreach ($playlistItemsResponse['items'] as $playlistItem) {
			$list[ $playlistItem['snippet']['resourceId']['videoId'] ] = $playlistItem['snippet']['title'];
		}
		$snippet['videos'] = $list;
		return $snippet;
	}
	
	/**
	 *
	 * @param unknown $data
	 * @return Google_Service_YouTube_PlaylistListResponse[]
	 */
	public function listPlaylists($data) {
		$this->handleAccessToken();
		$part = 'snippet,contentDetails';
		$params = [ 'mine' => true ];
		$playlistItemsResponse= $this->youtube->playlists->listPlaylists( $part, $params );
		$list = [];
		foreach ($playlistItemsResponse['items'] as $playlistItem) {
			$list[ $playlistItem['id'] ] = $playlistItem['snippet']['title'];
		}
		return $list;
	}
	
	/**
	 *
	 * @param string $id
	 * @return Google_Http_Request|expectedClass
	 */
	public function deletePlaylist($id) {
		$this->handleAccessToken();
		$params = [];
		return $this->youtube->playlists->delete( $id, $params );
	}
	
	/**
	 * Add video to playlist
	 *
	 * @param string $playlist_id
	 * @param string $video_id
	 * @param string $video_title
	 */
	public function addVideoToPlaylist( $playlist_id, $video_id, $video_title = null ) {
		$resourceId = new \Google_Service_YouTube_ResourceId();
		$resourceId->setVideoId($video_id);
		$resourceId->setKind('youtube#video');
		
		$playlistItemSnippet = new \Google_Service_YouTube_PlaylistItemSnippet();
		if( $video_title != null ) $playlistItemSnippet->setTitle($video_title);
		$playlistItemSnippet->setPlaylistId($playlist_id);
		$playlistItemSnippet->setResourceId($resourceId);
		
		$playlistItem = new \Google_Service_YouTube_PlaylistItem();
		$playlistItem->setSnippet($playlistItemSnippet);
		$path = 'snippet,contentDetails';
		$params = [];
		$playlistItemResponse = $this->youtube->playlistItems->insert( $path, $playlistItem, $params );
	}
	
	//
	// From Youtube PHP examples https://developers.google.com/youtube/v3/docs/
	//
	
	// create resource
	private function createResource($properties) {
		$this->handleAccessToken();
		
		$resource = array();
		foreach ($properties as $prop => $value) {
			if ($value) {
				$this->addPropertyToResource($resource, $prop, $value);
			}
		}
		return $resource;
	}
	
	// Add a property to the resource.
	private function addPropertyToResource(&$ref, $property, $value) {
		$keys = explode(".", $property);
		$is_array = false;
		foreach ($keys as $key) {
			// Convert a name like "snippet.tags[]" to "snippet.tags" and
			// set a boolean variable to handle the value like an array.
			if (substr($key, -2) == "[]") {
				$key = substr($key, 0, -2);
				$is_array = true;
			}
			$ref = &$ref[$key];
		}
		
		// Set the property value. Make sure array values are handled properly.
		if ($is_array && $value) {
			$ref = $value;
			$ref = explode(",", $value);
		} elseif ($is_array) {
			$ref = array();
		} else {
			$ref = $value;
		}
	}
}