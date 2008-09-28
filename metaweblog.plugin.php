<?php
class metaWeblog extends Plugin
{

	public $user = NULL;
	public $upload_dir = NULL;
	public $upload_url = NULL;

	public function info() {
		return array(
			'name' => 'metaWeblog',
			'version' => '0.9',
			'url' => 'http://habariproject.org/',
			'author' =>	'Habari Community',
			'authorurl' => 'http://habariproject.org/',
			'license' => 'Apache License 2.0',
			'description' => 'Adds support metaWeblog methods to the XML-RPC server.',
			'copyright' => '2007'
		);
	}
	
	/**
	 * This should be improved, better error messages, more codes
	 */
	function filter_xmlrpcexception_get_message($default, $code) {
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
				return _t( 'Amount parameter must be in range 1..20' ); // getRecentItems
			case 806:
				return _t( 'No Such Item' );
			case 807:
				return _t( 'Not Allowed to Alter Item' );
			case 808:
				return _t( 'Invalid media type' );
			case 809:
				return _t( 'File is too large (max. upload filesize)' );
			case 810:
				return _t( 'Other error on newMediaObject' );
			default:
				return $default;
		}
	}
	
	function action_init() {
		$this->upload_dir= Options::get('metaweblog__upload_dir');
		$this->upload_url= Options::get('metaweblog__upload_url');
	}
	
	function action_plugin_activation( $file ) {
		if( Plugins::id_from_file($file) == Plugins::id_from_file(__FILE__) ) {
			$this->default_paths();
			if (!$this->check_files()) {
				Session::error( _t('Failed to create the upload directory for metaWeblog.') );
			}
		}
	}

	function action_plugin_deactivation( $file ) {
		if( Plugins::id_from_file($file) == Plugins::id_from_file(__FILE__) ) {
		}
	}
	
	public function default_paths($force= false) {
		if ($force | empty($this->upload_dir) | empty($this->upload_url)) {
			$user_path = HABARI_PATH . '/' . Site::get_path('user', true);
			Options::set('metaweblog__upload_dir', $user_path . 'files' );
			Options::set('metaweblog__upload_url', Site::get_url('user', true) . 'files' );
		}
	}
	
	public function check_files() {
		if ( !is_dir( $this->upload_dir ) ) {
			if ( is_writable( dirname( rtrim($this->upload_dir,"\/") ) ) ) {
				mkdir( $this->upload_dir, 0755 );
			} else {
				return false;
			}
		}
		
		return true;
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
					$this->default_paths(true);
					echo _t("Upload directory has been set to use Habari Media Silo's.");
					break;
				case 'Configure' :
					$ui = new FormUI( strtolower( get_class( $this ) ) );
					$upload_dir= $ui->append( 'text', 'metaweblog_upload_dir', 'option:metaweblog__upload_dir', _t( 'Directory to use when uploading new media:' ) );
					$ui->append( 'text', 'metaweblog_upload_url', 'option:metaweblog__upload_url', _t( 'URL to upload directory:' ) );
					$upload_dir->add_validator( array( $this, 'validate_upload_dir' ) );
					$ui->append( 'submit', 'save', _t( 'Save' ) );
					$ui->set_option( 'success_message', _t('Options saved') );
					$ui->out();
					break;
			}
		}
	}
	
	// Need to use check_files somehow
	public function validate_upload_dir( $path ) {
		if ( !is_dir( $path ) ) {
			if ( is_writable( dirname( $path ) ) ) {
				mkdir( $path, 0755 );
			} else {
				return array( _t('Failed to create the upload directory for metaWeblog.') );
			}
		}
		
		return array();
	}
	
	public function filter_rsd_api_list( $list ) {
		$list['MetaWeblog']= array(
			'preferred' => 'true',
			'apiLink' => URL::get( 'xmlrpc' ),
			'blogID' => '1',
		);
		$list['Atom']['preferred']= 'false';
		return $list;
	}
	
	public function is_auth( $username, $password, $force = FALSE ) {
		if ( ( $this->user == $username ) && ( $force != TRUE ) ) {
			return $this->user;
		}
		else {
			if ( $this->user = User::authenticate( $username, $password ) ) {
				return $this->user;
			}
			else {
				$exception = new XMLRPCException(801);
				$exception->output_fault_xml();
			}
		}
	}

	public function xmlrpc_metaWeblog__getPost( $params ) {
		$user = $this->is_auth( $params[1], $params[2] );

		$post = Posts::get( array( 'id' => $params[0], 'limit' => 1 ) );
		$post = $post[0];
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

		return $struct;
	}
	
	public function xmlrpc_metaWeblog__getRecentPosts( $params ) {
		$user = $this->is_auth( $params[1], $params[2] );

		$posts = Posts::get( array( 'limit' => $params[3], 'status' => Post::status('published') ) );
		$structCollection = array();

		foreach ( $posts as $post ) {
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

			$structCollection[]= $struct;
		}

		return $structCollection;
	}
	
	public function xmlrpc_metaWeblog__getCategories( $params ) {
		$user = $this->is_auth( $params[1], $params[2] );

		$tags = DB::get_results( 'SELECT * FROM ' . DB::table( 'tags' ) );
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
	
	public function xmlrpc_metaWeblog__editPost( $params ) { 
		$user = $this->is_auth( $params[1], $params[2] );

		$post = Post::get( array( 'id' => $params[0], 'status' => Post::status( 'any' ) ) );

		if ( !empty( $post ) ) { 
			$post->title = $params[3]->title;
			$post->slug = $params[3]->title;
			
			if ( isset($params[3]->categories) ) { 
				$post->tags = implode( ',', $params[3]->categories );
			}
			
			if ( isset($params[3]->enclosure) ) {

			}
			
			$post->content = $params[3]->description;
			$post->content_type = Post::type('entry');
			$post->status = ( $params[4] ) ? Post::status('published') : Post::status('draft');
			$post->updated = HabariDateTime::date_create();
			$post->update();
			return true;
		}
		else {
			$exception = new XMLRPCException(806);
			$exception->output_fault_xml();
		}
	}

	public function xmlrpc_metaWeblog__newPost( $params ) { 
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
		
		if ( isset($params[3]->enclosure) ) {

		}
		
		$post = Post::create( $postdata );

		$struct = new XMLRPCStruct();
		$struct->postid = $post->id;

		return $struct;
	}
	
	/**
	 * Needs work, validate media type, max file size
	 */
	public function xmlrpc_metaWeblog__newMediaObject( $params ) {
		$user = $this->is_auth( $params[1], $params[2] );
		
		$name = strtolower(basename($params[3]->name));
		
		do {
			if ($dot = strrpos( $name, '.' )) {
				$filename = substr_replace( $name, '_' . date('omdhis'), $dot, 0 );
			}
			else {
				$filename = $name . '_' . date('omdhis');
			}
		} while (file_exists($filename));
		
		if ( !file_put_contents(rtrim($this->upload_dir,'\/') . '/' . $filename_new, base64_decode($params[3]->bits), LOCK_EX) ) {
			$exception = new XMLRPCException(810);
			$exception->output_fault_xml();
		}
		
		$struct = new XMLRPCStruct();
		$struct->url = rtrim($this->upload_url,'\/') . '/' . $filename;
		
		return $struct;
	}

	public function xmlrpc_blogger__deletePost( $params ) {
		$user = $this->is_auth( $params[2], $params[3] );

		$post = Post::get( array( 'id' => $params[1] ) );

		if ( !empty( $post ) ) {
			$post->delete();
			return true;
		}
		else {
			$exception = new XMLRPCException(806);
			$exception->output_fault_xml();
		}
	}

	public function xmlrpc_blogger__getUsersBlogs( $params ) {
		$user = $this->is_auth( $params[1], $params[2] );

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
