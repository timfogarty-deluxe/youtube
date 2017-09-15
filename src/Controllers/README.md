### How to have user authorize access to their youtube channel

Copy YoutubeTokenController.php to your project's app/Http/Controllers folder. Modify to store the received token (a json string) in your database with the channel name. Then add the following to your routes

```
$app->get ( '/youtube/token', 'YoutubeTokenController@fetch' );		// ?reset=1 to force reauthorization at youtube
```


Then in your app, where your users can create or update their youtube channels, have a button to authorize acces that calls the above route (almost certainly with the ?reest=1 option.