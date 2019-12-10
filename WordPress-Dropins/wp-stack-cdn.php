<?php
/*
Plugin Name: WP Stack CDN
Version: 1.0.2
Author: Mark Jaquith, Matthew Sigley
*/

// Convenience methods
if(!class_exists('WP_Stack_Plugin')){class WP_Stack_Plugin{function hook($h){$p=10;$m=$this->sanitize_method($h);$b=func_get_args();unset($b[0]);foreach((array)$b as $a){if(is_int($a))$p=$a;else $m=$a;}return add_action($h,array($this,$m),$p,999);}private function sanitize_method($m){return str_replace(array('.','-'),array('_DOT_','_DASH_'),$m);}}}

// The plugin
class WP_Stack_CDN_Plugin extends WP_Stack_Plugin {
	private static $object = null;

	public static $instance;
	public $site_domain;
	public $cdn_domain;
	public $force_https;
	public $production;
	public $staging;
	public $filter_urls;
	public $extensions;
	public $resource_key;

	private function __construct() {
		self::$instance = $this;
		$this->hook( 'plugins_loaded' );
	}

	static function &object() {
		if ( ! self::$object instanceof WP_Stack_CDN_Plugin ) {
			self::$object = new WP_Stack_CDN_Plugin();
		}
		return self::$object;
	}

	public function plugins_loaded() {
		$domain_set_up = get_option( 'wp_stack_cdn_domain' ) || ( defined( 'WP_STACK_CDN_DOMAIN' ) && WP_STACK_CDN_DOMAIN );
		$this->staging = defined( 'WP_STAGE' ) && WP_STAGE === 'staging';
		if ( $domain_set_up && !$this->staging )
			$this->hook( 'init' );
	}

	public function init() {
		if( is_admin() )
			return; //Don't filter admin pages
		
		$this->production = defined( 'WP_STAGE' ) && WP_STAGE === 'production';

		if ( $this->production ) {
			$this->hook( 'wp_stack_cdn_content', 'filter' );
		} else {
			$this->filter_urls = array();
			$uploads = apply_filters( 'wp_stack_cdn_uploads', defined( 'WP_STACK_CDN_UPLOADS' ) ? WP_STACK_CDN_UPLOADS : false );
			if ( $uploads ) {
				$upload_dir = wp_upload_dir();
				$this->filter_urls[] = $upload_dir['baseurl'];
			}
			$theme = apply_filters( 'wp_stack_cdn_theme', defined( 'WP_STACK_CDN_THEME' ) ? WP_STACK_CDN_THEME : false );
			if ( $theme )
				$this->filter_urls[] = get_template_directory_uri();
			
			$this->filter_urls = apply_filters( 'wp_stack_cdn_filter_urls', $this->filter_urls );
			if( empty( $this->filter_urls ) )
				return; //Nothing to filter

			$this->hook( 'wp_stack_cdn_content', 'filter_urls' );
		}
		
		$this->extensions = apply_filters( 'wp_stack_cdn_extensions', array( 'jpe?g', 'gif', 'png', 'css', 'bmp', 'js', 'ico', 'svg', 'webp' ) );
		$this->site_domain = parse_url( get_bloginfo( 'url' ), PHP_URL_HOST );
		$this->cdn_domain = defined( 'WP_STACK_CDN_DOMAIN' ) ? WP_STACK_CDN_DOMAIN : get_option( 'wp_stack_cdn_domain' );
		$this->force_https = defined( 'WP_STACK_CDN_FORCE_HTTPS' ) ? WP_STACK_CDN_FORCE_HTTPS : true;
		$this->resource_key = defined( 'WP_STACK_CDN_RESOURCE_KEY' ) ? WP_STACK_CDN_RESOURCE_KEY : date('Ymd', current_time('timestamp'));

		$this->hook( 'template_redirect' );
		$this->hook( 'wp_head', 'prefetch' );
	}

	public function filter_urls( $content ) {
		$cache_key = hash( 'sha256', $content ) . '_' . $_SERVER['REQUEST_URI'] . '_' . serialize( $this->filter_urls );
		$cached_content = wp_cache_get( $cache_key, 'wp_stack_cdn_filter_urls' );
		if( false !== $cached_content )
			return $cached_content;
		
		foreach( $this->filter_urls as $url ) {
			$domain = preg_quote( parse_url( $url, PHP_URL_HOST ), '#' );
			$path = untrailingslashit( parse_url( $url, PHP_URL_PATH ) );
			$preg_path = preg_quote( $path, '#' );

			// Targeted replace just on URL
			// Replace URLs with query strings
			$replacement = '=$1//' . $this->cdn_domain . $path . '/$3.$4$5&amp;resource_key='.$this->resource_key.'$1';
			if( $this->force_https ) //Force https for HTTP/2 and SPDY support
				$replacement = '=$1https://' . $this->cdn_domain . $path . '/$3.$4$5&amp;resource_key='.$this->resource_key.'$1';
			$content = preg_replace( "#=([\"'])(https?://{$domain})?$preg_path/([^(?:\\1)\]\?]+)\.(" . implode( '|', $this->extensions ) . ")(\?((?:(?!\\1).)+))\\1#", $replacement, $content );

			//Replace URLs without query strings
			$replacement = '=$1//' . $this->cdn_domain . $path . '/$3.$4?&amp;resource_key='.$this->resource_key.'$1';
			if( $this->force_https ) //Force https for HTTP/2 and SPDY support
				$replacement = '=$1https://' . $this->cdn_domain . $path . '/$3.$4?&amp;resource_key='.$this->resource_key.'$1';
			$content = preg_replace( "#=([\"'])(https?://{$domain})?$preg_path/([^(?:\\1)\]\?]+)\.(" . implode( '|', $this->extensions ) . ")(?:\?)?\\1#", $replacement, $content );
		}

		wp_cache_set( $cache_key, $content, 'wp_stack_cdn_filter_urls', WEEK_IN_SECONDS );
		return $content;
	}

	public function filter( $content ) { 
		$cache_key = hash( 'sha256', $content ) . '_' . $_SERVER['REQUEST_URI'];
		$cached_content = wp_cache_get( $cache_key, 'wp_stack_cdn_filter_urls' );
		if( false !== $cached_content )
			return $cached_content;
		
		// Replace URLs with query strings
		$replacement = '=$1//' . $this->cdn_domain . '/$3.$4$5&amp;resource_key='.$this->resource_key.'$1';
		if( $this->force_https ) //Force https for HTTP/2 and SPDY support
			$replacement = '=$1https://' . $this->cdn_domain . '/$3.$4$5&amp;resource_key='.$this->resource_key.'$1';
		$content = preg_replace( "#=([\"'])(https?://{$this->site_domain})?/([^/][^(?:\\1)\?]+)\.(" . implode( '|', $this->extensions ) . ")(\?((?:(?!\\1).)+))\\1#", $replacement, $content );

		//Replace URLs without query strings
		$replacement = '=$1//' . $this->cdn_domain . '/$3.$4?&amp;resource_key='.$this->resource_key.'$1';
		if( $this->force_https ) //Force https for HTTP/2 and SPDY support
			$replacement = '=$1https://' . $this->cdn_domain . '/$3.$4?&amp;resource_key='.$this->resource_key.'$1';
		$content = preg_replace( "#=([\"'])(https?://{$this->site_domain})?/([^/][^(?:\\1)\?]+)\.(" . implode( '|', $this->extensions ) . ")(?:\?)?\\1#", $replacement, $content );

		wp_cache_set( $cache_key, $content, 'wp_stack_cdn_filter', WEEK_IN_SECONDS );
		return $content;
	}

	public function prefetch(){
		echo '<link rel="preconnect" href="' . ( $this->force_https ? 'https' : '' ) . '//'.$this->cdn_domain.'" crossorigin>' . "\n";
	}

	public function template_redirect() {
		ob_start( array( $this, 'ob' ) );
	}

	public function ob( $contents ) {
		return apply_filters( 'wp_stack_cdn_content', $contents, $this );
	}
}

$WP_Stack_CDN_Plugin = WP_Stack_CDN_Plugin::object();

//API Functions
function WP_Stack_CDN_get_url( $url ) {
	$WP_Stack_CDN_Plugin = WP_Stack_CDN_Plugin::object();
	if ( !empty( $WP_Stack_CDN_Plugin->cdn_domain ) && !$WP_Stack_CDN_Plugin->staging ) {
		list($protocol, $uri) = explode('://', $url, 2);
		if( $WP_Stack_CDN_Plugin->force_https )
			$protocol = 'https'; //Force https for HTTP/2 and SPDY support
		$url = $protocol . '://' . $WP_Stack_CDN_Plugin->cdn_domain;
		$path_pos = stripos( $uri, '/' );
		if( false !== $path_pos )
			$url .= substr( $uri, $path_pos );
	}
	return $url;
}
