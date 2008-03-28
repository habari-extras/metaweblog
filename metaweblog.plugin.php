<?php
class metaWeblog extends Plugin
{

	public $user= NULL;

	public function info() {
		return array(
			'name' => 'metaWeblog',
			'version' => '0.8.3',
			'url' => 'http://habariproject.org/',
			'author' =>	'Habari Community',
			'authorurl' => 'http://habariproject.org/',
			'license' => 'Apache License 2.0',
			'description' => 'Adds support metaWeblog methods to the XML-RPC server.',
			'copyright' => '2007'
		);
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
	
	public function action_xmlrpcexception_get_messages( $code ) {
		switch ( $code ) {
			case 801:
				return _t( 'Login Error (probably bad username/password combination)' );
			case 806:
				return _t( 'No Such Item' );
		}
	}
	
	public function is_auth( $username, $password, $force= FALSE ) {
		if ( ( $this->user == $username ) && ( $force != TRUE ) ) {
			return $this->user;
		}
		else {
			if ( $this->user= User::authenticate( $username, $password ) ) {
				return $this->user;
			}
			else {
				$exception = new XMLRPCException(801);
				$exception->output_fault_xml();
			}
		}
	}

	public function xmlrpc_metaWeblog__getPost( $params ) {
		$user= $this->is_auth( $params[1], $params[2] );

		$post= Posts::get( array( 'id' => $params[0], 'limit' => 1 ) );
		$post= $post[0];
		$struct= new XMLRPCStruct();

		$struct->dateCreated= date( 'c', strtotime( $post->pubdate ) );
		$struct->description= $post->content_out;
		$struct->title= $post->title_out;
		$struct->link= $post->permalink;
		$struct->categories= $post->tags;
		$struct->permalink= $post->permalink;
		$struct->postid= $post->id;
		$struct->userid= $post->author->id;
		$struct->mt_allow_comments= (isset($post->info->comments_disabled)) ? 0 : 1;
		$struct->mt_allow_pings= 1;

		return $struct;
	}
	
	public function xmlrpc_metaWeblog__getRecentPosts( $params ) {
		$user= $this->is_auth( $params[1], $params[2] );

		$posts= Posts::get( array( 'limit' => $params[3], 'status' => Post::status('published') ) );
		$structCollection= array();

		foreach ( $posts as $post ) {
			$struct= new XMLRPCStruct();
			$struct->dateCreated= date( 'c', strtotime( $post->pubdate ) );
			$struct->description= $post->content_out;
			$struct->title= $post->title_out;
			$struct->link= $post->permalink;
			$struct->categories= $post->tags;
			$struct->permalink= $post->permalink;
			$struct->postid= $post->id;
			$struct->userid= $post->author->id;
			$struct->mt_allow_comments= (isset($post->info->comments_disabled)) ? 0 : 1;
			$struct->mt_allow_pings= 1;

			$structCollection[]= $struct;
		}

		return $structCollection;
	}
	
	public function xmlrpc_metaWeblog__getCategories( $params ) {
		$user= $this->is_auth( $params[1], $params[2] );

		$tags= DB::get_results( 'SELECT * FROM ' . DB::table( 'tags' ) );
		$structCollection= array();

		foreach ( $tags as $tag ) {
			$struct= new XMLRPCStruct();
			$struct->description= $tag->tag_text;
			$struct->htmlUrl= URL::get( 'atom_feed_tag', array( 'tag' => $tag->tag_slug ) );
			$struct->rssUrl= URL::get( 'display_entries_by_tag', array( 'tag' => $tag->tag_slug ) );
			$struct->title= $tag->tag_text;
			$struct->categoryid= $tag->id;
			$structCollection[]= $struct;
		}

		return $structCollection;
	}
	
	public function xmlrpc_metaWeblog__editPost( $params ) { 
		$user= $this->is_auth( $params[1], $params[2] );

		$post= Post::get( array( 'id' => $params[0], 'status' => Post::status( 'any' ) ) );

		if ( !empty( $post ) ) { 
			$post->title= $params[3]->title;
			$post->slug= $params[3]->title;
			
			if ( isset($params[3]->categories) ) { 
				$post->tags= implode( ',', $params[3]->categories );
			}
			
			if ( isset($params[3]->enclosure) ) {

			}
			
			$post->content= $params[3]->description;
			$post->content_type= Post::type('entry');
			$post->status= ( $params[4] ) ? Post::status('published') : Post::status('draft');
			$post->updated= date( 'Y-m-d H:i:s' );
			$post->update();
			return true;
		}
		else {
			$exception = new XMLRPCException(806);
			$exception->output_fault_xml();
		}
	}

	public function xmlrpc_metaWeblog__newPost( $params ) { 
		$user= $this->is_auth( $params[1], $params[2] );

		$postdata= array(
			'slug' => $params[3]->title,
			'title' => $params[3]->title,
			'content' => $params[3]->description,
			'user_id' => User::identify()->id,
			'pubdate' => date( 'Y-m-d H:i:s' ),
			'status' => ( $params[4] ) ? Post::status('published') : Post::status('draft'),
			'content_type' => Post::type('entry'),
		);
		
		if ( isset($params[3]->categories) ) { 
			$postdata['tags']= implode( ',', $params[3]->categories );
		}
		
		if ( isset($params[3]->enclosure) ) {

		}
		
		$post= Post::create( $postdata );

		$struct= new XMLRPCStruct();
		$struct->postid= $post->id;

		return $struct;
	}

	public function xmlrpc_blogger__deletePost( $params ) {
		$user= $this->is_auth( $params[2], $params[3] );

		$post= Post::get( array( 'id' => $params[1] ) );

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
		$user= $this->is_auth( $params[1], $params[2] );

		$struct= array(
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
