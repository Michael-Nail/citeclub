<?php

class MediaRequestHandler extends RecordsRequestHandler
{
	// RecordRequestHandler configuration
	static public $recordClass = 'Media';

	// configurables
	public static $defaultPage = 'browse';
	public static $defaultThumbnailWidth = 100;
	public static $defaultThumbnailHeight = 100;
	public static $uploadFileFieldName = 'mediaFile';
	public static $responseMode = 'html';
	
	static public $searchConditions = array(
		'Caption' => array(
			'qualifiers' => array('any','caption')
			,'points' => 2
			,'sql' => 'Caption LIKE "%%%s%%"'
		)
		,'CaptionLike' => array(
			'qualifiers' => array('caption-like')
			,'points' => 2
			,'sql' => 'Caption LIKE "%s"'
		)
		,'CaptionNot' => array(
			'qualifiers' => array('caption-not')
			,'points' => 2
			,'sql' => 'Caption NOT LIKE "%%%s%%"'
		)
		,'CaptionNotLike' => array(
			'qualifiers' => array('caption-not-like')
			,'points' => 2
			,'sql' => 'Caption NOT LIKE "%s"'
		)
	);

	public static function handleRequest()
	{
		// handle json response mode
		if(static::peekPath() == 'json')
		{
			static::$responseMode = static::shiftPath();
		}
		
		
		// handle action
		switch ($action = static::shiftPath())
		{
			
			case 'media':
			{
				return static::handleMediaRequest();
			}
			
			case 'upload':
			{
				return static::handleUploadRequest();
			}
			
			case 'open':
			{
				$mediaID = static::shiftPath();
				
				return static::handleOpenRequest($mediaID);
			}
			
			case 'download':
			{
				$mediaID = static::shiftPath();
				$filename = urldecode(static::shiftPath());
				
				return static::handleDownloadRequest($mediaID, $filename);
			}
			
			case 'caption':
			{
				$mediaID = static::shiftPath();
				
				return static::handleCaptionRequest($mediaID);
			}
			
			case 'delete':
			{
				return static::handleDeleteRequest();
			}
			
			case 'thumbnail':
			{
				return static::handleThumbnailRequest();
			}
			
			case 'manage':
			{
				return MediaManagerRequestHandler::handleRequest();
			}
			
			case false:
			case '':
			case 'browse':
			{
				return static::handleBrowseRequest();
			}

			default:
			{
				if(is_numeric($action))
				{
					return static::handleOpenRequest($action);
				}
				else
				{
					return parent::handleRecordsRequest($action);
				}
			}
		}
	}
	
	
	public static function handleUploadRequest($options = array(), $authenticationRequired = true)
	{
		global $Session;
		
		// require authentication
		if($authenticationRequired)
		{
			$Session->requireAuthentication();
		}
		
		if($_SERVER['REQUEST_METHOD'] == 'POST') {
		
			// init options
			$options = array_merge(array(
				'fieldName' => static::$uploadFileFieldName
			), $options);
			
	
			// check upload
			if(empty($_FILES[$options['fieldName']]))
			{
				return static::throwError('You did not select a file to upload');
			}
			
			// handle upload errors
			if($_FILES[$options['fieldName']]['error'] != UPLOAD_ERR_OK)
			{
				switch($_FILES[$options['fieldName']]['error'])
				{
					case UPLOAD_ERR_NO_FILE:
						return static::throwError('You did not select a file to upload');
						
					case UPLOAD_ERR_INI_SIZE:
					case UPLOAD_ERR_FORM_SIZE:
						return static::throwError('Your file exceeds the maximum upload size. Please try again with a smaller file.');
						
					case UPLOAD_ERR_PARTIAL:
						return static::throwError('Your file was only partially uploaded, please try again.');
						
					default:
						return static::throwError('There was an unknown problem while processing your upload, please try again.');
				}
			}
			
			// init caption
			if(!isset($options['Caption']))
			{
				if(!empty($_REQUEST['Caption']))
				{
					$options['Caption'] = $_REQUEST['Caption'];
				}
				else
				{
					$options['Caption'] = preg_replace('/\.[^.]+$/', '', $_FILES[$options['fieldName']]['name']);
				}
			}
					
			// create media
			$Media = Media::createFromUpload($_FILES[$options['fieldName']]['tmp_name'], $options);
		}
		if($_SERVER['REQUEST_METHOD'] == 'PUT') {
			$put = fopen("php://input", "r"); // open input stream
			
			$tmp = tempnam("/tmp", "emr");  // use PHP to make a temporary file
			$fp = fopen($tmp, "w"); // open write stream to temp file
			
			// write
			while ($data = fread($put, 1024)) {
			  fwrite($fp, $data);
			}
			
			// close handles
			fclose($fp);
			fclose($put);
			
			// create media
			$Media = Media::createFromFile($tmp, $options);
		}
		
		
		// assign tag
		if(!empty($_REQUEST['Tag']) && ($Tag = Tag::getByHandle($_REQUEST['Tag'])))
		{
			$Tag->assignItem('Media', $Media->ID);
		}
		
		// assign context
		if(!empty($_REQUEST['ContextClass']) && !empty($_REQUEST['ContextID']))
		{
			if(!is_subclass_of($_REQUEST['ContextClass'], 'ActiveRecord')
				|| !in_array($_REQUEST['ContextClass']::$rootClass, Media::$fields['ContextClass']['values'])
				|| !is_numeric($_REQUEST['ContextID']))
			{
				return static::throwError('Context is invalid');
			}
			elseif(!$Media->Context = $_REQUEST['ContextClass']::getByID($_REQUEST['ContextID']))
			{
				return static::throwError('Context class not found');
			}
			
			$Media->save();
		}
		
		return static::respond('uploadComplete', array(
			'success' => true
			,'data' => $Media
			,'TagID' => isset($Tag) ? $Tag->ID : null
		));
	}
	
	
	public static function handleOpenRequest($media_id)
	{
		if(empty($media_id) || !is_numeric($media_id))
		{
			static::throwError('Missing or invalid media_id');
		}
		
		// get media
		try
		{
			$Media = Media::getById($media_id);
		}
		catch(UserUnauthorizedException $e)
		{
			return static::throwUnauthorizedError('You are not authorized to download this media');
		}
		
		
		if(!$Media)
		{
			static::throwNotFoundError('Media ID #%u was not found', $media_id);
		}
		
		if(static::$responseMode == 'json')
		{
			JSON::translateAndRespond(array(
				'success' => true
				,'data' => $Media
			));
		}
		else
		{
			$expires = 60*60*24*365;
			header('Cache-Control: public, max-age='.$expires);
			header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time()+$expires));
			header('Pragma: public');
			header('Content-Type: ' . $Media->MIMEType);
			header('Content-Length: ' . filesize($Media->FilesystemPath));
			
			readfile($Media->FilesystemPath);
			MICS::terminate();
		}
	}
	
	public static function handleDownloadRequest($media_id, $filename = false)
	{
		if(empty($media_id) || !is_numeric($media_id))
		{
			static::throwError('Missing or invalid media_id');
		}
		
		// get media
		try
		{
			$Media = Media::getById($media_id);
		}
		catch(UserUnauthorizedException $e)
		{
			return static::throwUnauthorizedError('You are not authorized to download this media');
		}
		
		
		if(!$Media)
		{
			static::throwNotFoundError('Media ID #%u was not found', $media_id);
		}
		
		// determine filename
		if(empty($filename))
		{
			$filename = $Media->Caption ? $Media->Caption : sprintf('%s_%u', $Media->ContextClass, $Media->ContextID);
			
			// add extension
			$filename .= '.'.$Media->Extension;
		}
		
		header('Content-Type: ' . $Media->MIMEType);
		header('Content-Disposition: attachment; filename="'.str_replace('"', '', $filename).'"');
		header('Content-Length: ' . filesize($Media->FilesystemPath));
		
		readfile($Media->FilesystemPath);
		exit();
	}
	
	public static function handleCaptionRequest($media_id)
	{
		// require authentication
		$GLOBALS['Session']->requireAccountLevel('Staff');
		
		if(empty($media_id) || !is_numeric($media_id))
		{
			static::throwError('Missing or invalid media_id');
		}
		
		// get media
		try
		{
			$Media = Media::getById($media_id);
		}
		catch(UserUnauthorizedException $e)
		{
			return static::throwUnauthorizedError('You are not authorized to download this media');
		}
		
		
		if(!$Media)
		{
			static::throwNotFoundError('Media ID #%u was not found', $media_id);
		}
		
		if($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$Media->Caption = $_REQUEST['Caption'];
			$Media->save();
			
			return static::respond('mediaCaptioned', array(
				'success' => true
				,'data' => $Media
			));
			
		}
		
		return static::respond('mediaCaption', array(
			'data' => $Media
		));
		
		
	}
	
	public static function handleDeleteRequest()
	{
		// require authentication
		$GLOBALS['Session']->requireAccountLevel('Staff');
		
		if($mediaID = static::peekPath())
		{
			$mediaIDs = array($mediaID);
		}
		elseif(!empty($_REQUEST['mediaID']))
		{
			$mediaIDs = array($_REQUEST['mediaID']);
		}
		elseif(is_array($_REQUEST['media']))
		{
			$mediaIDs = $_REQUEST['media'];
		}
		
		$deleted = array();
		foreach($mediaIDs AS $mediaID)
		{
			if(!is_numeric($mediaID))
			{
				static::throwError('Invalid mediaID');
			}
			
			// get media
			$Media = Media::getByID($mediaID);			
			
			if(!$Media)
			{
				static::throwNotFoundError('Media ID #%u was not found', $mediaID);
			}
			
			if($Media->destroy())
			{
				$deleted[] = $Media;
			}
		}
		
		return static::respond('mediaDeleted', array(
			'success' => true
			,'data' => $deleted
		));
	}
	
	
	
	
	
	
	public static function handleThumbnailRequest()
	{
		// get context
		if (!is_numeric(static::peekPath()))
		{
			$contextClass = static::shiftPath();
			$contextID = is_numeric(static::peekPath()) ? static::shiftPath() : false;
			$mediaID = false;
		}
		else
		{
			$contextClass = false;
			$contextID = false;
			$mediaID = static::shiftPath();
		}
		
		// get format
		if (preg_match('/^(\d+)x(\d+)(x([0-9A-F]{6})?)?$/i', static::peekPath(), $matches))
		{
			$maxWidth = $matches[1];
			$maxHeight = $matches[2];
			$fillColor = !empty($matches[4]) ? $matches[4] : false;
		}
		else
		{
			$maxWidth = static::$defaultThumbnailWidth;
			$maxHeight = static::$defaultThumbnailHeight;
			$fillColor = false;
		}
		
		// load media
		if ($mediaID)
		{
			if(!$Media = Media::getByID($mediaID))
			{
				static::throwNotFoundError('Media not found');
			}
		}
		elseif ($contextClass && $contextID)
		{
			if(!$Media = Media::getByContext($contextClass, $contextID))
			{
				$Media = Media::getBlank($contextClass);
			}
		}
		elseif ($contextClass)
		{
			if(!$Media = Media::getBlank($contextClass))
			{
				static::throwNotFoundError('Media not found');
			}
		}
		else
		{
			static::throwError('Invalid request');
		}
		
		
		//MICS::dump($Media,'Getting thumbnail for');

		// get thumbnail
		$thumbPath = $Media->getThumbnail($maxWidth, $maxHeight, $fillColor);


		// dump it out
		$expires = 60*60*24*365;
		header('Cache-Control: public, max-age='.$expires);
		header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time()+$expires));
		header('Pragma: public');
		header('Content-Type: ' . $Media->ThumbnailMIMEType);
		header('Content-Length: ' . filesize($thumbPath));
		
		readfile($thumbPath);
		exit();
	}
	
	

	public static function handleManageRequest()
	{
		// access control
		$GLOBALS['Session']->requireAccountLevel('Staff');
		
		return static::respond('manage');
	}
	

	
	static public function handleBrowseRequest($options = array(), $conditions = array(), $responseID = null, $responseData = array())
	{
				
		// apply tag filter
		if(!empty($_REQUEST['tag']))
		{
			// get tag
			if(!$Tag = Tag::getByHandle($_REQUEST['tag']))
			{
				return static::throwNotFoundError('Tag not found');
			}
			
			$conditions[] = 'ID IN (SELECT ContextID FROM tag_items WHERE TagID = '.$Tag->ID.' AND ContextClass = "Product")';
		}
		
		
		// apply context filter
		if(!empty($_REQUEST['ContextClass']))
		{
			$conditions['ContextClass'] = $_REQUEST['ContextClass'];
		}
		
		if(!empty($_REQUEST['ContextID']) && is_numeric($_REQUEST['ContextID']))
		{
			$conditions['ContextID'] = $_REQUEST['ContextID'];
		}
		
		
		// apply sort
		if(empty($options['order']))
		{
			switch($_REQUEST['sort'])
			{
				case 'caption':
					$options['order'] = array('Caption' => 'ASC');
					break;
				
				case 'recent':
				default:
					$_REQUEST['sort'] = 'recent';
					$options['order'] = array('ID' => 'DESC');
					break;
					
					break;
			}
		}
		
		// process limit
		if(!empty($_REQUEST['limit']) && is_numeric($_REQUEST['limit']))
		{
			$options['limit'] = $_REQUEST['limit'];
		}
	
		// process page
		if(!empty($_REQUEST['page']) && is_numeric($_REQUEST['page']) && $options['limit'])
		{
			$options['offset'] = ($_REQUEST['page']-1) * $options['limit'];
		}
				
		return parent::handleBrowseRequest($options, $conditions, $responseID, $responseData);
	}



	public static function handleMediaRequest()
	{
		if(static::peekPath() == 'delete')
		{
			return static::handleMediaDeleteRequest();
		}
		
		
		// get media
		$media = JSON::translateRecords(Media::getAll(), true);
						
		// get tag media assignments
		$media_tags = Tag::getAllItems('media');
				
		// inject album assignments to photo records
		foreach($media_tags AS $media_id => $tags)
		{	
			foreach($tags AS $tag)
			{
				$media[$media_id]['tags'][] = $tag['tag_id'];
			}
		}
		
		return static::respond('media', array(
			'success' => true
			,'data' => array_values($media)
		));
	}
	
	
	public static function handleMediaDeleteRequest()
	{
		// sanity check
		if(empty($_REQUEST['media']) || !is_array($_REQUEST['media']))
		{
			static::throwError('Invalid request');
		}
		
		// retrieve photos
		$media_array = array();
		foreach($_REQUEST['media'] AS $media_id)
		{
			if(!is_numeric($media_id))
			{
				static::throwError('Invalid request');
			}
			
			if($Media = Media::getById($media_id))
			{
				$media_array[$Media->ID] = $Media;
			}
		}
		
		// delete
		$deleted = array();
		foreach($media_array AS $media_id => $Media)
		{
			if($Media->delete())
			{
				$deleted[] = $media_id;
			}
		}
		
		return static::respond('mediaDeleted', array(
			'success' => true
			,'deleted' => $deleted
		));
	}

}
