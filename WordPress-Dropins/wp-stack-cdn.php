<?php
/*
Plugin Name: WP Stack CDN
Version: 1.0
Author: Mark Jaquith, Matthew Sigley
*/

// Convenience methods
if(!class_exists('WP_Stack_Plugin')){class WP_Stack_Plugin{function hook($h){$p=10;$m=$this->sanitize_method($h);$b=func_get_args();unset($b[0]);foreach((array)$b as $a){if(is_int($a))$p=$a;else $m=$a;}return add_action($h,array($this,$m),$p,999);}private function sanitize_method($m){return str_replace(array('.','-'),array('_DOT_','_DASH_'),$m);}}}

// The plugin
class WP_Stack_CDN_Plugin extends WP_Stack_Plugin {
	public static $instance;
	public $site_domain;
	public $cdn_domain;
	public $production;
	public $filter_urls;
	public $extensions;

	public function __construct() {
		self::$instance = $this;
		$this->hook( 'plugins_loaded' );
	}

	public function plugins_loaded() {
		$domain_set_up = get_option( 'wp_stack_cdn_domain' ) || ( defined( 'WP_STACK_CDN_DOMAIN' ) && WP_STACK_CDN_DOMAIN );
		$staging = defined( 'WP_STAGE' ) && WP_STAGE === 'staging';
		if ( $domain_set_up && !$staging )
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
		
		$this->hook( 'template_redirect' );
		$this->hook( 'wp_head', 'prefetch' );
	}

	public function filter_urls( $content ) {
		foreach( $this->filter_urls as $url ) {
			$domain = preg_quote( parse_url( $url, PHP_URL_HOST ), '#' );
			$path = untrailingslashit( parse_url( $url, PHP_URL_PATH ) );
			$preg_path = preg_quote( $path, '#' );

			// Targeted replace just on URL
			$content = preg_replace( "#([\"'])(https?://{$domain})?$preg_path/((?:(?!\\1]).)+)\.(" . implode( '|', $this->extensions ) . ")(\?((?:(?!\\1).)+))?\\1#", '$1//' . $this->cdn_domain . $path . '/$3.$4$5$1', $content );
		}
		return $content;
	}

	public function filter( $content ) { 
		return preg_replace( "#([\"'])(https?://{$this->site_domain})?/([^/](?:(?!\\1).)+)\.(" . implode( '|', $this->extensions ) . ")(\?((?:(?!\\1).)+))?\\1#", '$1//' . $this->cdn_domain . '/$3.$4$5$1', $content );
	}

	public function prefetch(){
		echo '<link rel="dns-prefetch" href="//'.$this->cdn_domain.'">';
	}

	public function template_redirect() {
		ob_start( array( $this, 'ob' ) );
	}

	public function ob( $contents ) {
			return apply_filters( 'wp_stack_cdn_content', $contents, $this );
	}
}

new WP_Stack_CDN_Plugin;
