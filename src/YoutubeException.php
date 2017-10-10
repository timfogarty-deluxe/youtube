<?php

namespace Dawson\Youtube;

use Exception;

class YoutubeException extends Exception
{
	public function __construct( $msg, $previousException=null )
	{
		parent::__construct($msg, 422, $previousException);
	}
}