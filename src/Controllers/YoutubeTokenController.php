<?php
/**
 * add route to fetch method of this controller to get user's channel's token
 * 
 * will check for token in session, 
 * if not found then redirect you to youtube,
 * log in and grant access, 
 * then you're redirected back to same route
 * and user token json is displayed on screen.
 * 
 * ?reset=1 to ignore existing token
 * 
 * path to this method must match allowed redirect defined with google_client_id
 * 
 */
namespace App\Http\Controllers;

use App\Http\Controllers\Traits\RestOutputTrait;
use App\Http\Controllers\Traits\RestIdTrait;
use App\Platforms\PlatformFactory;
use App\Youtube\Facades\Youtube as YT;
use Illuminate\Http\Request;

class YoutubeTokenController extends Controller
{
	use RestOutputTrait;
	
	public function fetch( Request $request ) {
		$reset = $request->input('reset',0);
		session_start();
		
		$REDIRECT = filter_var('http://' . $_SERVER['HTTP_HOST'] . "/youtube/token", FILTER_SANITIZE_URL);
		$APPNAME = "D2D Social Media Portal";
		
		$client = new \Google_Client();
		$client->setClientId(config('youtube.client_id'));
		$client->setClientSecret(config('youtube.client_secret'));
		$client->setScopes(config('youtube.scopes'));
		$client->setRedirectUri($REDIRECT);
		$client->setApplicationName($APPNAME);
		$client->setAccessType('offline');
		$client->setApprovalPrompt('force');
		
		// Define an object that will be used to make all API requests.
		$youtube = new \Google_Service_YouTube($client);
		
		if (isset($_GET['code'])) {
			if (strval($_SESSION['state']) !== strval($_GET['state'])) {
				die('The session state did not match.');
			}
			
			$client->fetchAccessTokenWithAuthCode($_GET['code']);
			$_SESSION['token'] = $client->getAccessToken();
		}
		
		if ($reset == 0 && isset($_SESSION['token'])) {
			// on d2d-server, store token in database tables youtube_channels
			return response()->json( $_SESSION['token']);
		} else {
			$state = mt_rand();
			$client->setState($state);
			$_SESSION['state'] = $state;
			
			$authUrl = $client->createAuthUrl();
			return redirect( $authUrl );
		}
	}
	
	public function list() {
		$token = env('YOUTUBE_CHANNEL_TOKEN');
		return $this->listResponse( YT::listVideos($token) );
	}
}