<?php
/*
 * Plugin name: Simple Multisite Crossposting – WPML
 * Description: Allows to connect translated posts to their originals.
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Version: 1.0
 * Plugin URI: https://rudrastyh.com/support/wpml-compatibility
 * Network: true
 */

if( ! class_exists( 'Rudr_Simple_Multisite_Crosspost_WPML' ) ) {

	class Rudr_Simple_Multisite_Crosspost_WPML{

		public function __construct(){
			add_action( 'rudr_crosspost_publish', array( $this, 'do_connection' ), 10, 3 );
		}

		// get translation data
		public function get_tr_data( $post_id ) {

			global $wpdb;

			$post_id = absint( $post_id );
			$post_type = get_post_type( $post_id );

			$tr_data = $wpdb->get_row(
				"
				SELECT trid, language_code, source_language_code
				FROM {$wpdb->prefix}icl_translations
				WHERE element_id = {$post_id}
				AND element_type = 'post_{$post_type}'
				",
				ARRAY_A
			);

			return $tr_data;

		}

		public function do_connection( $new_post_id, $blog_id, $data ) {
			// we need to come back to original blog in order to get post translations
			restore_current_blog();

			global $wpdb;

			// let's get translation data from current post
			// Array( 'trid' => ,'language_code'=>, 'source_language_code' => )
			$tr_data = $this->get_tr_data( $data[ 'post_id' ] );

			if( empty( $tr_data ) || ! $tr_data ) {
				return;
			}

			// now it is time to get trid from $blog_id
			// 1. get all translations from current blog first
			$translated_ids = $wpdb->get_col(
				"
				SELECT element_id
				FROM {$wpdb->prefix}icl_translations
				WHERE trid = {$tr_data[ 'trid' ]}
				"
			);

			// 2. get their crossposted ids on $blog_id
			$crossposted_ids = array();
			foreach( $translated_ids as $translated_id ) {
				// do nothing for current post
				if( $data[ 'post_id' ] === $translated_id ) {
					continue;
				}
				$c = get_post_meta( $translated_id, '_crosspost_to_data', true );
				if( is_array( $c ) && array_key_exists( $blog_id, $c ) ) {
					$crossposted_ids[] = $c[ $blog_id ];
				}
			}

			// 3. on $blog_id let's find a trid
			switch_to_blog( $blog_id );
			// most likely the first post should work
			$crossposted_id = reset( $crossposted_ids );
			// get its translation data on subsite
			$crossposted_tr_data = $this->get_tr_data( $crossposted_id );
			if( ! empty( $crossposted_tr_data[ 'trid' ] ) ) {
				// update
				$wpdb->update(
					$wpdb->prefix . 'icl_translations',
					array(
						'trid' => $crossposted_tr_data[ 'trid' ],
						'source_language_code' => $tr_data[ 'source_language_code' ]
					),
					array( // where
						'element_id' => $new_post_id,
						'element_type' => 'post_'.$data[ 'post_data' ][ 'post_type' ]
					),
					array(
						'%d',
						'%s'
					),
					array(
						'%d',
						'%s'
					)
				);
			}

		}


	}

	new Rudr_Simple_Multisite_Crosspost_WPML();

}
