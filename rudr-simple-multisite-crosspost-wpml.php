<?php
/*
 * Plugin name: Simple Multisite Crossposting – WPML
 * Description: Allows to connect translated posts to their originals.
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Version: 2.1
 * Plugin URI: https://rudrastyh.com
 * Network: true
 */

if( ! class_exists( 'Rudr_Simple_Multisite_Crosspost_WPML' ) ) {

	class Rudr_Simple_Multisite_Crosspost_WPML{

		public function __construct(){

			// everyting save_post
			// we can not use more performant hooks because WPML can update language data outside REST
			add_action( 'save_post', array( $this, 'save' ), 999, 2 );

			// WooCommerce
			add_filter( 'rudr_crosspost_is_crossposted_product', array( $this, 'get_crossposted_product_id' ), 99, 2 );
			add_filter( 'wc_product_has_unique_sku', '__return_false' );
			add_filter( 'rudr_pre_crosspost_product_data', array( $this, 'add_language_info' ), 10 );
			add_filter( 'rudr_pre_crosspost_product_data', array( $this, 'add_translated_info' ), 15 );

		}

		// Documentation
		// https://wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference/
		// https://wpml.org/documentation/related-projects/woocommerce-multilingual/wcml-hooks-reference


		/*******************/
		/*     HELPERS     */
		/*******************/
		/**
		 * It is better to double checked whther the WPML is active to prevent unneded errors
		 */
		private function is_wpml_active(){
			return is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' );
		}

		/**
		 * Allows to get all the object translations on the current blog
		 *
		 * @param $object_id post ot term ID
		 * @param $element_type either a post type or a taxonomy name
		 */
		public function get_translations( $object_id, $post_type_or_taxonomy_name = 'post' ) {

			$type = apply_filters( 'wpml_element_type', $post_type_or_taxonomy_name );
			$trid = apply_filters( 'wpml_element_trid', false, $object_id, $type );
			$translations = apply_filters( 'wpml_get_element_translations', NULL, $trid, $type );

			return $translations;

		}

		/**
		 * Allows to original post ID in case from a translation object data
		 */
		public function get_source_object_id( $object_data ) {

			if( ! isset( $object_data[ 'source_language_code' ] ) || ! $object_data[ 'source_language_code' ] ) {
				return 0;
			}

			$lang = $object_data[ 'language_code' ];
			$source_lang = $object_data[ 'source_language_code' ];

			// get translations
			$translations = $this->get_translations( $object_data[ 'post_id' ], $object_data[ 'post_type' ] );

			// if main translation does not exist for some reason
			if( ! isset( $translations[ $source_lang ] ) || ! $translations[ $source_lang ] ) {
				return 0;
			}

			return $translations[ $source_lang ]->element_id;

		}

		/**
		 * Just gets a product or variation ID based both on SKU and Language Code
		 */
		public static function get_product_id_by_sku_and_lang( $sku, $lang ) {

			global $wpdb;
			// technically there are could be multiple entries
			return $wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT posts.ID
					FROM {$wpdb->posts} as posts
					INNER JOIN {$wpdb->postmeta} AS meta ON posts.ID = meta.post_id
					INNER JOIN {$wpdb->prefix}icl_translations AS tr ON posts.ID = tr.element_id
					WHERE
					posts.post_type IN ( 'product', 'product_variation' )
					AND posts.post_status != 'trash'
					AND meta.meta_key = '_sku'
					AND meta.meta_value = %s
					AND tr.language_code = %s
					LIMIT 1
					",
					$sku,
					$lang
				)
			);

		}

		/**
		 * Just gets a crossposted post ID
		 * or a crossposted product or variation ID based both on SKU and Language Code
		 */
		public function is_crossposted( $post_id, $blog_id, $language_code ) {
			if( 'product' === get_post_type( $post_id ) && function_exists( 'wc_get_product' ) ) {
				if( $sku = get_post_meta( $post_id, '_sku', true ) ) {
					switch_to_blog( $blog_id );
					// no need for extra use of Rudr_Simple_Multisite_Woo_Crosspost::is_crossposted_product()
					$product_id = $this->get_product_id_by_sku_and_lang( $sku, $language_code );
					restore_current_blog();
					return $product_id;
				}
			} else {
				// not a big deal here, meta fields are great
				return Rudr_Simple_Multisite_Crosspost::is_crossposted( $post_id, $blog_id );
			}
			return 0;
		}


		/***********************************/
		/*     LANGUAGE DATA INSERTERS     */
		/***********************************/
		/**
		 * Adds the language information into the array of an object data
		 */
		public function add_language_info( $object_data ) {

			if( ! $this->is_wpml_active() ) {
				return $object_data;
			}

			$language_data = apply_filters(
				'wpml_element_language_details',
				null,
				array(
					'element_id' => $object_data[ 'post_id' ],
					'element_type' => apply_filters( 'wpml_element_type', get_post_type( $object_data[ 'post_id' ] ) ),
				)
			);
			// stdClass Object ( [element_id] => 44 [trid] => 107 [language_code] => en [source_language_code] => )

			$object_data[ 'language_code' ] = $language_data->language_code;
			$object_data[ 'source_language_code' ] = $language_data->source_language_code;

			return $object_data;

		}

		/**
		 * Adds the translated information into the array of an object data
		 * Currently only translated attributes and variations
		 */
		public function add_translated_info( $object_data ) {
			if( ! $this->is_wpml_active() ) {
				return $object_data;
			}
//echo '<pre>';print_r( $object_data );exit;
			// we get our translated info from the original post, yes
			$source_product_id = $this->get_source_object_id( $object_data );

			$source_product = wc_get_product( $source_product_id );
			if( ! $source_product ) {
				return $object_data;
			}

			// product attributes and default attributes
			$object_data = $this->add_translated_attributes( $object_data, $source_product->get_attributes(), $source_product->get_default_attributes() );

			// variations
			if( 'variable' === $object_data[ 'type' ] && isset( $object_data[ 'variations' ] ) && $object_data[ 'variations' ] ) {
				$object_data = $this->add_translated_variations( $object_data, $source_product->get_children() );
			}
//echo '<pre>';print_r( $object_data );exit;
			return $object_data;

		}

		// Adds translated product attributes
		public function add_translated_attributes( $object_data, $attributes, $default_attributes ) {
//echo '<pre>';print_r( $object_data );exit;

			$lang = $object_data[ 'language_code' ];

			$translated_attributes = array();
			$translated_default_attributes = array();
			foreach( $attributes as $attribute ) {
				// all right now we loop throw all the available options and find the translations
				if( $attribute->get_options() && $attribute->is_taxonomy() ) {
					$attribute_taxonomy_name = $attribute->get_taxonomy();
					$options = array();
					foreach( $attribute->get_options() as $id ) {
						$term = get_term( $id );

						$attibute_translations = $this->get_translations( $id, $attribute_taxonomy_name );
						if( empty( $attibute_translations[ $lang ] ) ) {
							continue;
						}
						$translated_term = get_term( $attibute_translations[ $lang ]->element_id );
						if( ! $translated_term ) {
							continue;
						}
						$options[] = $translated_term->slug;
						// default attribute
						if( isset( $default_attributes[ $attribute_taxonomy_name ] ) && $term->slug == $default_attributes[ $attribute_taxonomy_name ] ) {
							$translated_default_attributes[ $attribute_taxonomy_name ] = $translated_term->slug;
						}
					}
					// don't forget about
				} else {
					$options = $attribute->get_options();
				}
				if( $options ) {
					$translated_attributes[] = array(
						'id' => $attribute->get_id(),
						'name' => $attribute->get_name(),
						'options' => $options,
						'position' => $attribute->get_position(),
						'visible' => $attribute->get_visible(),
						'variation' => $attribute->get_variation(),
					);
				}
			}
			$object_data[ 'attributes' ] = $translated_attributes;
			$object_data[ 'default_attributes' ] = $translated_default_attributes;
//echo '<pre>';print_r( $object_data );exit;
			return $object_data;

		}

		// Adds translated product variations
		public function add_translated_variations( $object_data, $variation_ids ) {
//echo '<pre>';print_r( $object_data );exit;

			$lang = $object_data[ 'language_code' ];

			// get product variations
			// loop and translate their attributes
			foreach( $variation_ids as $variation_id ) {

				$variation = wc_get_product( $variation_id );

				$translated_attributes = array();
				foreach( $variation->get_attributes() as $attribute_taxonomy => $slug ) {

					$term = get_term_by( 'slug', $slug, $attribute_taxonomy );
					if( ! $term ) {
						continue;
					}

					$attibute_translations = $this->get_translations( $term->term_id, $attribute_taxonomy );
					if( empty( $attibute_translations[ $lang ] ) ) {
						continue;
					}

					$translated_term = get_term( $attibute_translations[ $lang ]->element_id );
					if( ! $translated_term ) {
						continue;
					}

					$translated_attributes[ $attribute_taxonomy ] = $translated_term->slug;

				}

				// now let's loop and replace
				foreach( $object_data[ 'variations' ] as &$object_data_variation ) {
					// only for the same variation
					if( $object_data_variation[ 'sku' ] !== $variation->get_sku() ) {
						continue;
					}
					$object_data_variation[ 'attributes' ] = $translated_attributes + $object_data_variation[ 'attributes' ];
				}

			}
//echo '<pre>';print_r( $object_data );exit;
			return $object_data;

		}


		/*****************/
		/*     HOOKS     */
		/*****************/
		// the only hook that is needed for regular posts ahaha, everything else is for WooCommerce
		public function save( $post_id, $post ) {

			if( ! class_exists( 'Rudr_Simple_Multisite_Crosspost' ) ) {
				return;
			}

			if( ! $this->is_wpml_active() ) {
				return;
			}

			if( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
			}

			$post_type = get_post_type_object( $post->post_type );
			if ( ! current_user_can( $post_type->cap->edit_post, $post_id ) ) {
				return;
			}

			if( ! is_multisite() ) {
				return;
			}

			$allowed_post_statuses = ( $allowed_post_statuses = get_site_option( 'rudr_smc_post_statuses' ) ) ? $allowed_post_statuses : array( 'publish' );
			if ( ! in_array( $post->post_status, $allowed_post_statuses ) ) {
				return;
	    }

			if( in_array( $post->post_type, array( 'product_variation', 'attachment' ) ) ) {
				return;
			}
			$allowed_post_types = ( ( $allowed_post_types = get_site_option( 'rudr_smc_post_types', array() ) ) && is_array( $allowed_post_types ) ) ? $allowed_post_types : get_post_types( array( 'public' => true ) );
			if( ! in_array( $post->post_type, $allowed_post_types ) ) {
				return;
			}

			if( ! $blogs = Rudr_Simple_Multisite_Crosspost::get_blogs() ) {
				return;
			}

			foreach( array_keys( $blogs ) as $blog_id ) {
				// if checkbox is unchecked do nothing
				if( ! get_post_meta( $post_id, Rudr_Simple_Multisite_Crosspost::META_KEY . $blog_id, true ) ) {
					continue;
				}

				$this->update_translations( $post_id, $blog_id );

			}

		}

		public function update_translations( $post_id, $blog_id ) {

			// get the necessary information first
			$type = apply_filters( 'wpml_element_type', get_post_type( $post_id ) );
			// post_post
			$language_data = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $post_id, 'element_type' => $type ) );
			// stdClass Object ( [element_id] => 44 [trid] => 107 [language_code] => en [source_language_code] => )
//print_r( $language_data );exit;
			// now we need to get a crossposted ID, it is different for regular posts and WooCommerce products
			$crossposted_id = $this->is_crossposted( $post_id, $blog_id, $language_data->language_code );
			if( ! $crossposted_id ) {
				return;
			}

			switch_to_blog( $blog_id );
			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id' => $crossposted_id,
					'element_type' => $type,
					'trid' => null, // if post already has 'trid' it won't be changed
					'language_code' => $language_data->language_code,
					'source_language_code' => $language_data->source_language_code, // by providing this value we are making a post a translation
				)
			);
			// let's get trid
			$crossposted_trid = apply_filters( 'wpml_element_trid', false, $crossposted_id, $type );
			restore_current_blog();


			// get all the post translations from the current blog
			$translations = $this->get_translations( $post_id, get_post_type( $post_id ) );
			// Array ( [en] => stdClass Object ( [translation_id] => 48 [language_code] => en [element_id] => 17 [source_language_code] => [element_type] => post_product [original] => 1 [post_title] => test product es [post_status] => publish ) )
//echo '<pre>';print_r($translations);exit;
			$crossposted_translations = array();

			foreach( $translations as $translation ) {
				if( ! $crossposted_element_id = $this->is_crossposted( $translation->element_id, $blog_id, $translation->language_code ) ) {
					continue;
				}
				$crossposted_translations[] = array(
					'language_code' => $translation->language_code,
					'source_language_code' => $translation->source_language_code,
					'trid' => $crossposted_trid,
					'element_id' => $crossposted_element_id,
					'element_type' => $translation->element_type,
				);
			}
//echo '<pre>';print_r($crossposted_translations);exit;
			switch_to_blog( $blog_id );
			global $wpdb;
			foreach( $crossposted_translations as $crossposted_translation ) {
				$wpdb->update(
					$wpdb->prefix . 'icl_translations',
					array(
						'language_code' => $crossposted_translation[ 'language_code' ],
						'source_language_code' => $crossposted_translation[ 'source_language_code' ],
						'trid' => $crossposted_translation[ 'trid' ],
					),
					array( // where
						'element_id' => $crossposted_translation[ 'element_id' ],
						'element_type' => $crossposted_translation[ 'element_type' ],
					),
					array(
						'%s',
						'%s',
						'%d',
					),
					array(
						'%d',
						'%s',
					)
				);

			}
			restore_current_blog();

		}


		// hooks which modifies Rudr_Simple_Multisite_Woo_Crosspost::is_crossposted_product()
		// makes it work for both languages and SKUs, not only for SKUs
		public function get_crossposted_product_id( $product_id, $product_data ) {
			if( ! $this->is_wpml_active() ) {
				return $product_id;
			}
			// language code is not provided when we delete a post and also for upsells and cross-sales
			if( empty( $product_data[ 'language_code' ] ) ) {
				$original_blog_id = get_current_blog_id();
				restore_current_blog();
				$product_data = $this->add_language_info( $product_data );
				switch_to_blog( $original_blog_id );
			}

			return $this->get_product_id_by_sku_and_lang( $product_data[ 'sku' ], $product_data[ 'language_code' ] );

		}


	}

	new Rudr_Simple_Multisite_Crosspost_WPML();

}
