<?php
/*
 * Plugin name: Simple Multisite Crossposting – WPML
 * Description: Allows to connect translated posts to their originals.
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Version: 1.2
 * Plugin URI: https://rudrastyh.com
 * Network: true
 */

if( ! class_exists( 'Rudr_Simple_Multisite_Crosspost_WPML' ) ) {

	class Rudr_Simple_Multisite_Crosspost_WPML{

		public function __construct(){
			add_action( 'rudr_crosspost_publish', array( $this, 'do_connection' ), 10, 3 );
			add_action( 'rudr_crosspost_update', array( $this, 'do_connection' ), 10, 3 );
			add_action( 'save_post', array( $this, 'save_meta_boxes' ), 9999, 2 );

		}

		public function save_meta_boxes( $post_id, $post ) {

			if( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if( empty( $_REQUEST[ 'meta-box-loader' ] ) ) {
				return;
			}

			if( ! class_exists( 'Rudr_Simple_Multisite_Crosspost' ) ) {
				return;
			}

			// file_put_contents( __DIR__ . '/log.txt' , 'hello' . print_r( $translation_data, true ) );

			if( ! $blogs = Rudr_Simple_Multisite_Crosspost::get_blogs() ) {
				return;
			}

			foreach( $blogs as $blog_id => $blog ) {
				if( ! $new_post_id = Rudr_Simple_Multisite_Crosspost::is_crossposted( $post_id, $blog_id ) ) {
					continue;
				}
				switch_to_blog( $blog_id );
				$this->do_connection( $new_post_id, $blog_id, array( 'post_id' => $post_id ) );
				restore_current_blog();
			}

		}

		// get translation data
		private function get_translation_data( $post_id ) {

			global $wpdb;

			$post_id = absint( $post_id );
			$post_type = get_post_type( $post_id );

			$tr_data = $wpdb->get_row(
				"
				SELECT element_type, trid, language_code, source_language_code
				FROM {$wpdb->prefix}icl_translations
				WHERE element_id = {$post_id}
				AND element_type = 'post_{$post_type}'
				",
				ARRAY_A
			);

			if( empty( $tr_data ) || ! $tr_data || false === $tr_data ) {
				return false;
			}

			return $tr_data;

		}


		public function do_connection( $new_post_id, $blog_id, $data ) {
			// ini_set('display_errors', 1);
			// ini_set('display_startup_errors', 1);
			// error_reporting(E_ALL);
			// we need to come back to original blog in order to get post translations
			restore_current_blog();

			global $wpdb;

			// this array will contain all the post and their translatinos from current blog
			// Array( post_id => translation_data
			$translations = array();

			// let's get translation data from current post
			// Array( 'element_type' => 'trid' => ,'language_code'=>, 'source_language_code' => )
			$translation_data = $this->get_translation_data( $data[ 'post_id' ] );

			if( ! $translation_data ) {
				return;
			}

			// our first post in translations arrat
			$translations[ $new_post_id ] = $translation_data;

			// now it is time to get trid from $blog_id
			// get all translations(posts in this language on this site) from current blog first
			$translated_ids = $wpdb->get_col(
				"
				SELECT element_id
				FROM {$wpdb->prefix}icl_translations
				WHERE trid = {$translation_data[ 'trid' ]}
				"
			);

			if( $translated_ids ) {
				foreach( $translated_ids as $translated_id ) {
					// our current post is already in the array
					if( $data[ 'post_id' ] === $translated_id ) {
						continue;
					}
					$c = get_post_meta( $translated_id, '_crosspost_to_data', true );
					if( is_array( $c ) && array_key_exists( $blog_id, $c ) ) {
						$crossposted_id = $c[ $blog_id ];
						$translations[ $crossposted_id ] = $this->get_translation_data( $translated_id );
					}
				}
			}

			// Perfect!
			// now we have an array Array( new_post_id => old_translation_data ) gonna push it

			switch_to_blog( $blog_id );

			// we don't know our current translation id!
			$trid = 0;
			// let's try to update any of existing translations first and maybe we find out $trid!
			foreach( $translations as $element_id => $translation ) {

				$translation_data = $this->get_translation_data( $element_id );

				if( ! $translation_data ) {
					continue;
				}
				// what? we have translation data? get its ID!
				$trid = $translation_data[ 'trid' ];
				//what? we have translation data? let's update it!
				$wpdb->update(
					$wpdb->prefix . 'icl_translations',
					array(
						'language_code' => $translation[ 'language_code' ],
						'source_language_code' => $translation[ 'source_language_code' ],
					),
					array( // where
						'trid' => $trid,
						'element_id' => $element_id,
						'element_type' => $translation[ 'element_type' ],
					),
					array(
						'%s',
						'%s'
					),
					array(
						'%d',
						'%d',
						'%s'
					)
				);
			}

			// wow, we updated the existing translations (I doubt we need it but in case)
			// now we have to find out what is the last existing trid!
			if( ! $trid ) {
				$max_trid = $wpdb->get_var(
					"
					SELECT MAX(trid)
					FROM {$wpdb->prefix}icl_translations
					"
				);
				$trid = $max_trid + 1;
			}

			// next stop, inserting new translations!
			foreach( $translations as $element_id => $translation ) {

				$wpdb->insert(
					$wpdb->prefix . 'icl_translations',
					array(
						'element_type' => $translation[ 'element_type' ],
						'element_id' => $element_id,
						'trid' => $trid,
						'language_code' => $translation[ 'language_code' ],
						'source_language_code' => $translation[ 'source_language_code' ],
					),
					array( '%s', '%d', '%d', '%s', '%s' )
				);

			}

		}


	}

	new Rudr_Simple_Multisite_Crosspost_WPML();

}
