<?php
class metaWeblog extends Plugin
{

	private $user = NULL;

	public function info() {
		return array(
			'name' => 'metaWeblog',
			'version' => '1.0',
			'url' => 'http://habariproject.org/',
			'author' =>	'Habari Community',
			'authorurl' => 'http://habariproject.org/',
			'license' => 'Apache License 2.0',
			'description' => 'Adds support metaWeblog methods to the XML-RPC server.',
			'copyright' => '2007'
		);
	}
	
	public function action_plugin_activation( $file ) {
		if( Plugins::id_from_file($file) == Plugins::id_from_file(__FILE__) ) {
			// Let's make sure we at least have the default paths set
			$this->default_paths();
			
			// Also, check if the upload directory exist and are writable
			if (!$this->check_upload_dir()) {
				Session::error( _t('Failed to create the upload directory for metaWeblog.') );
			}
		}
	}
	
	private function default_paths($force= false) {
		// If either the upload directory or the upload URL is empty, fallback to the defaults
		// This behavior can also be forced when needed (for example, resetting the configuration)
		if ($force || empty($this->upload_dir) || empty($this->upload_url)) {
			// By default, we use the same directory as the Habari media silo (user/files)
			$user_path = HABARI_PATH . '/' . Site::get_path('user', true);
			Options::set('metaweblog__upload_dir', $user_path . 'files' );
			Options::set('metaweblog__upload_url', Site::get_url('user', true) . 'files' );
			
			// Also, check if the upload directory exist and are writable
			$this->check_upload_dir();
		}
	}
	
	private function check_upload_dir($fake= false, $upload_dir= null) {
		// Use the configured upload directory if none supplied
		if (!$fake || empty($upload_dir)) {
			$upload_dir= Options::get('metaweblog__upload_dir');
		}
		
		if ( !is_dir( $upload_dir ) ) {
			// Directory does not exist, let's see if its parent is writable
			if ( is_writable( dirname( rtrim($upload_dir,"\/") ) ) ) {
				// We have permissions, create the directory and chmod properly
				// If $fake is true, we don't create the directory but return true because we have permissions
				return ($fake) ? true : mkdir( $upload_dir, 0755 );
			} else {
				// We do not have permissions
				return false;
			}
		}
		
		// Directory exists
		return true;
	}
	
	public function validate_upload_dir( $path ) {
		// Can't use check_upload_dir as callback because FormUI expects an array not a boolean
		if (!$this->check_upload_dir(true, $path)) {
			return array( _t('Failed to create the upload directory for metaWeblog.') );
		}
		else {
			return array();
		}
	}
	
	public function filter_xmlrpcexception_get_message($default, $code) {
		// In the future, use a class variable to store more info for error messages, $this->last_error
		switch ($code) {
			case 801:
				return _t( 'Login Error (probably bad username/password combination)' );
			case 802:
				return _t( 'No Such Blog' );
			case 803:
				return _t( 'Not a Team Member' );
			case 804:
				return _t( 'Cannot add Empty Items' );
			case 805:
				return _t( 'Amount parameter must be in range 1..20' );
			case 806:
				return _t( 'No Such Item' );
			case 807:
				return _t( 'Not Allowed to Alter Item' );
			case 808:
				return _t( 'Invalid media type' );
			case 809:
				return _t( 'File is too large' );
			case 810:
				return _t( 'Cannot open file' );
			case 811:
				return _t( 'Cannot write to file' );
			case 812:
				return _t( 'Failed to delete post' );
			case 813:
				return _t( 'Failed to edit post' );
			case 814:
				return _t( 'Failed to create post' );
			default:
				return $default;
		}
	}
	
	public function filter_plugin_config($actions, $plugin_id) {
		if ($plugin_id == $this->plugin_id()){
			$actions[] = 'Configure';
			$actions[] = 'Reset Configuration';
		}

		return $actions;
	}

	public function action_plugin_ui($plugin_id, $action) {
		if ($plugin_id == $this->plugin_id()){
			switch ($action){
				case 'Reset Configuration':
					// Force the reset of default paths to Habari media silo's
					$this->default_paths(true);
					echo _t("Upload directory has been set to use Habari Media Silo's.");
					break;
				case 'Configure' :
					$ui = new FormUI( strtolower( get_class( $this ) ) );
					$upload_dir= $ui->append( 'text', 'metaweblog_upload_dir', 'option:metaweblog__upload_dir', _t( 'Directory to use when uploading new media:' ) );
					
					// Make sure upload_dir is an existing directory, otherwise try creating it
					$upload_dir->add_validator( array( $this, 'validate_upload_dir' ) );
					
					$ui->append( 'text', 'metaweblog_upload_url', 'option:metaweblog__upload_url', _t( 'URL to upload directory:' ) );
					$ui->append( 'checkbox', 'metaweblog_preferred', 'option:metaweblog__preferred', _t( 'Prefer metaWeblog to other APIs, making it auto-detectable. Otherwise, manually select metaWeblog as API in your editor.' ) );
					$ui->append( 'submit', 'save', _t( 'Save' ) );
					$ui->set_option( 'success_message', _t('Options saved') );
					$ui->out();
					break;
			}
		}
	}
	
	public function filter_rsd_api_list( $list ) {
		$list['metaWeblog']= array(
			'preferred' => 'false',
			'apiLink' => URL::get( 'xmlrpc' ),
			'blogID' => '1',
		);

		if (Options::get('metaweblog__preferred')) {
			// Make metaWeblog API preferred
			array_walk($list, create_function('&$value,$key', '$value[\'preferred\'] = \'false\';'));
			$list['metaWeblog']['preferred'] = 'true';
		}

		return $list;
	}
	
	private function is_auth( $username, $password, $force = FALSE ) {
		if ( ( $this->user == $username ) && ( $force != TRUE ) ) {
			// User is already authenticated
			return $this->user;
		}
		else {
			if ( $this->user = User::authenticate( $username, $password ) ) {
				// Authentication successful
				return $this->user;
			}
			else {
				// Authentication failed
				$exception = new XMLRPCException(801);
				$exception->output_fault_xml();
			}
		}
	}
	
	private function add_posts( $posts, $array = true ) {
		// Create a new array struct for posts
		foreach ( (array) $posts as $post) {
			$struct = new XMLRPCStruct();
			$struct->dateCreated = new XMLRPCDate($post->pubdate->int);
			$struct->description = $post->content;
			$struct->title = $post->title;
			$struct->link = $post->permalink;
			$struct->categories = $post->tags;
			$struct->permalink = $post->permalink;
			$struct->postid = $post->id;
			$struct->userid = $post->author->id;
			$struct->mt_allow_comments = (isset($post->info->comments_disabled)) ? 0 : 1;
			$struct->mt_allow_pings = 1;
			
			$collection[]= $struct;
		}
		
		// Do not send an array struct for getPost, clients don't expect it
		return ($array) ? $collection : end($collection);
	}
	
	public function xmlrpc_metaWeblog__getRecentPosts( $params ) {
		// Authentication
		$user = $this->is_auth( $params[1], $params[2] );

		// Retrieve the posts
		$posts = Posts::get( array( 'limit' => $params[3], 'status' => Post::status('published') ) );

		return $this->add_posts( $posts );
	}

	public function xmlrpc_metaWeblog__getPost( $params ) {
		// Authentication
		$user = $this->is_auth( $params[1], $params[2] );

		// Retrieve the post
		$post = Posts::get( array( 'id' => $params[0], 'limit' => 1 ) );
		
		if ( !empty( $post ) ) {
			// Post exists, retrieve it
			return $this->add_posts( $post, false );
		}
		else {
			// Post does not exist or failed to retrieve the post
			$exception = new XMLRPCException(806);
			$exception->output_fault_xml();
		}
	}
	
	public function xmlrpc_metaWeblog__getCategories( $params ) {
		// Authentication
		$user = $this->is_auth( $params[1], $params[2] );

		// Fetch all tags from the database
		$tags = DB::get_results( 'SELECT * FROM ' . DB::table( 'tags' ) );
		
		// Create a new array struct for tags
		$structCollection = array();
		foreach ( $tags as $tag ) {
			$struct = new XMLRPCStruct();
			$struct->description = $tag->tag_text;
			$struct->htmlUrl = URL::get( 'atom_feed_tag', array( 'tag' => $tag->tag_slug ) );
			$struct->rssUrl = URL::get( 'display_entries_by_tag', array( 'tag' => $tag->tag_slug ) );
			$struct->title = $tag->tag_text;
			$struct->categoryid = $tag->id;
			$structCollection[]= $struct;
		}

		return $structCollection;
	}

	public function xmlrpc_metaWeblog__newPost( $params ) { 
		// Authentication
		$user = $this->is_auth( $params[1], $params[2] );

		$postdata = array(
			'slug' => $params[3]->title,
			'title' => $params[3]->title,
			'content' => $params[3]->description,
			'user_id' => User::identify()->id,
			'pubdate' => HabariDateTime::date_create(),
			'status' => ( $params[4] ) ? Post::status('published') : Post::status('draft'),
			'content_type' => Post::type('entry'),
		);
		
		if ( isset($params[3]->categories) ) { 
			$postdata['tags']= implode( ',', $params[3]->categories );
		}
		
		if ( $post = Post::create( $postdata ) ) {
			// Post created, return its ID to the client so it can getPost
			$struct = new XMLRPCStruct();
			$struct->postid = $post->id;

			return $struct;
		}
		else {
			// Failed to create post, no way to know the error yet
			$exception = new XMLRPCException(814);
			$exception->output_fault_xml();
		}
	}
	
	/**
	 * Needs work, validate media type, max file size
	 */
	public function xmlrpc_metaWeblog__newMediaObject( $params ) {
		// Authentication
		$user = $this->is_auth( $params[1], $params[2] );
		
		// Required upload directory and url
		$upload_dir= Options::get('metaweblog__upload_dir');
		$upload_url= Options::get('metaweblog__upload_url');
		
		// Lowercase file name with extension
		$name = strtolower(basename($params[3]->name));
		
		// Generate a first filename to use, it should be unique, otherwise wait a second then try again
		do {
			if ($dot = strrpos( $name, '.' )) {
				$filename = substr_replace( $name, '_' . date('omdhis'), $dot, 0 );
			}
			else {
				$filename = $name . '_' . date('omdhis');
			}
		} while (file_exists($filename));
		
		// Final file path to the uploaded media
		$filepath = rtrim($upload_dir,'\/') . '/' . $filename;
		
		// Create a file handle in binary write-only for the uploaded media
	    if (!$handle = fopen($filepath, 'wb')) {
			 $exception = new XMLRPCException(810);
			 $exception->output_fault_xml();
	    }

		// Received media is encoded in base64
		// Since Habari doesn't decode base64 automatically we need to do it
	    if (fwrite($handle, base64_decode($params[3]->bits)) === FALSE) {
			$exception = new XMLRPCException(811);
			$exception->output_fault_xml();
	    }

		// Close the file handle
	    fclose($handle);
	
		// Return to the client the URL to the uplaoded media
		$struct = new XMLRPCStruct();
		$struct->url = rtrim($upload_url,'\/') . '/' . $filename;

		return $struct;
	}
	
	public function xmlrpc_metaWeblog__editPost( $params ) { 
		// Authentication
		$user = $this->is_auth( $params[1], $params[2] );

		// Retrieve post to edit
		$post = Post::get( array( 'id' => $params[0], 'status' => Post::status( 'any' ) ) );

		if ( !empty( $post ) ) { 
			// Post exists, update it
			$post->title = $params[3]->title;
			$post->slug = $params[3]->title;
			$post->content = $params[3]->description;
			$post->content_type = Post::type('entry');
			$post->status = ( $params[4] ) ? Post::status('published') : Post::status('draft');
			$post->updated = HabariDateTime::date_create();
			if ( isset($params[3]->categories) ) { 
				$post->tags = implode( ',', $params[3]->categories );
			}
			
			if ($post->update()) {
				return true;
			}
			else {
				// Failed to edit post, no way to know the error yet
				$exception = new XMLRPCException(813);
				$exception->output_fault_xml();
			}
		}
		else {
			// Post does not exist or failed to retrieve the post
			$exception = new XMLRPCException(806);
			$exception->output_fault_xml();
		}
	}

	public function xmlrpc_blogger__deletePost( $params ) {
		// Authentication
		$user = $this->is_auth( $params[2], $params[3] );

		// Retrieve the post to delete
		$post = Post::get( array( 'id' => $params[1] ) );

		if ( !empty( $post ) ) {
			// Post exists, try deleting it
			if ($post->delete()) {
				return true;
			}
			else {
				// Failed to delete post, no way to know the error yet
				$exception = new XMLRPCException(812);
				$exception->output_fault_xml();	
			}
		}
		else {
			// Post does not exist or failed to retrieve the post
			$exception = new XMLRPCException(806);
			$exception->output_fault_xml();
		}
	}

	public function xmlrpc_blogger__getUsersBlogs( $params ) {
		// Authentication
		$user = $this->is_auth( $params[1], $params[2] );

		// Send a list of available blogs
		$struct = array(
			new XMLRPCStruct(
					array(
						'url' => Site::get_url( 'habari' ),
						'blogid' => '1',
						'blogName' => Options::get( 'title' ),
					)
				)
			);

		return $struct;
	}
	
}
?>
