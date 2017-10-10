# Laravel/Lumen 5 - YouTube Video Upload

### Fork of JoeDawson/youtube

1. remove session info as Lumen is stateless
2. pass account tokens to methods to support multiple accounts
3. remove database
4. for uploads, pass in filesize as path may be remote
5. add methods to create, modify, list, and delete playlists


## Installation

To install, add the following to composer.json

```
    "repositories" : [
    	{ "type": "vcs", "url":"https://github.com/timfogarty-deluxe/youtube" }
    ],
    "require": {
    	...
    	"dawson/youtube": "d4.0.1"
    }
```

You may need to do composer clearcache and composer update

You may need to generate a new github token on composer install


Now register the Service provider in `bootstrap/app.php`

```php
	$app->register(Dawson\Youtube\YoutubeServiceProvider::class);
```

And also add the alias to the same file.

```php
	class_alias(Dawson\Youtube\Facades\Youtube::class, 'Youtube' );
```

## Configuration

Now copy `vendor/dawson/youtube/config/youtube.php` to `/config`


### Obtaining your Credentials

If you haven't already, you'll need to create an application on [Google's Developer Console](https://console.developers.google.com/project). You then need to head into **Credentials** within the Console to create Server key.

You will be asked to enter your Authorised redirect URIs. When installing this package, the default redirect URI is `http://laravel.dev/youtube/callback`. Of course, replacing the domain (`laravel.dev`) with your applications domain name.

**You can add multiple redirect URIs, for example you may want to add the URIs for your local, staging and production servers.**

Once you are happy with everything, create the credentials and you will be provided with a **Client ID** and **Client Secret**. These now need to be added to your `.env` file.

```
GOOGLE_CLIENT_ID=YOUR_CLIENT_ID
GOOGLE_CLIENT_SECRET=YOUR_SECRET
```

### Authentication


### Reviewing your Token


# Upload a Video

To upload a video, you simply need to pass the **full** path to your video you wish to upload and specify your video information.

Here's an example:

```php
$video = Youtube::setAccessToken($accessToken)
	->setChunksize( 4*1024*1024 )
	->upload($fullPathToVideo, [
	    'title'       => 'My Awesome Video',
	    'description' => 'You can also specify your video description here.',
	    'tags'	      => ['foo', 'bar', 'baz'],
	    'category_id' => 10,
	    'filesize'	  => get_file_size($fullPathToVideo)
]);

return $video->getVideoId();
```

The above will return the ID of the uploaded video to YouTube. (*i.e dQw4w9WgXcQ*)

By default, video uploads are public. If you would like to change the privacy of the upload, you can do so by passing a third parameter to the upload method.

For example, the below will upload the video as `unlisted`.

```php
$video = Youtube::setAccessToken($accessToken)->upload($fullPathToVideo, $params, 'unlisted');
```

### Custom Thumbnail

If you would like to set a custom thumbnail for for upload, you can use the `withThumbnail()` method via chaining.

```php
$fullpathToImage = storage_path('app/public/thumbnail.jpg');

$video = Youtube::setAccessToken($accessToken)->upload($fullPathToVideo, $params)->withThumbnail($fullpathToImage);

return $youtube->getThumbnailUrl();
```

**Please note, the maxiumum filesize for the thumbnail is 2MB**. Setting a thumbnail will not work if you attempt to use a thumbnail that exceeds this size.

# Deleting a Video

If you would like to delete a video, which of course is uploaded to your authorized channel, you will also have the ability to delete it:

```php
Youtube::setAccessToken($accessToken)->delete($videoId);
```

When deleting a video, it will check if exists before attempting to delete.

# Questions

Should you have any questions, please feel free to submit an issue.
