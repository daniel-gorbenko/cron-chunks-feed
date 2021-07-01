<?php
class ControllerExtensionFeedGoogleMerchant extends Controller {
	public function index() {
		/*
			SETTINGS
		*/

		define("CHUNK_SIZE", 500);

		define("STATUS_FILE_PATH", DIR_FEED . 'google/google_status.csv');
		define("TMP_FILE_PATH", DIR_FEED . 'google/google_tmp.csv');
		define("FEED_FILE_PATH", DIR_FEED . 'google/onemodagoogle.txt');
		define("LOG_FILE_PATH", DIR_FEED . 'google/google.log');

		/*
			OPEN LOG FILE
		*/

		$fp_log = fopen(LOG_FILE_PATH, 'a');

		fwrite($fp_log, date('d.m.Y G:i:s') . ': started' . "\r\n");

		/*
			CREATE STATUS FILE IF NOT EXIST
		*/

		if(!file_exists(STATUS_FILE_PATH)) {
			$f = fopen(STATUS_FILE_PATH, 'w');

			fwrite($f, '0,0');
			fclose($f);
		}

		/*
			GET START POSITION AND BUSY FLAG
		*/

		preg_match('/(\d)\,(\d)/', file_get_contents(STATUS_FILE_PATH), $matches);

		$blocked = (int)$matches[1];
		$current_step = (int)$matches[2];

		/*
			IF THIS SCRIPT IS EXECUTING BY ANOTHER REQUEST - STOP,
			ELSE - SET BUSY FLAG TO 1.
		*/

		if($blocked === 1) {
			fwrite($fp_log, date('d.m.Y G:i:s') . ': the script is executing by another request - stop' . "\r\n");

			die();
		} else {
			$fb_status = fopen(STATUS_FILE_PATH, 'w+');

			fwrite($fb_status, sprintf("%d,%d", 1, $current_step));
			fclose($fb_status);
		}

		/*
			PREPARE DATA FOR FEED
		*/

		$this->load->model('catalog/product');
		$this->load->model('catalog/category');
		$this->load->model('tool/image');

		$results = $this->model_catalog_product->getProducts(array("limit" => CHUNK_SIZE, "start" => ((int)$current_step + 1) * CHUNK_SIZE));
		$rows = array();

		$row = $this->load->view('extension/feed/google_merchant/row');

		foreach ($results as $result) {
			if ($result['image'] && file_exists(DIR_IMAGE . $result['image'])) {
			  $image = $this->model_tool_image->resize($result['image'], $this->config->get($this->config->get('config_theme') . '_image_related_width'), $this->config->get($this->config->get('config_theme') . '_image_related_height'));
			} else {
			  $image = $this->model_tool_image->resize('placeholder.png', $this->config->get($this->config->get('config_theme') . '_image_related_width'), $this->config->get($this->config->get('config_theme') . '_image_related_height'));
			}

			if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
			  $price = $this->format_currency($this->tax->calculate($result['price'], $result['tax_class_id'], $this->config->get('config_tax')));
			} else {
			  $price = false;
			}

			if ((float)$result['special']) {
			  $special = $this->format_currency($this->tax->calculate($result['special'], $result['tax_class_id'], $this->config->get('config_tax')));
			} else {
			  $special = false;
			}

			$images = array();
			$product_images = $this->model_catalog_product->getProductImages($result['product_id']);

			foreach ($product_images as $key => $product_image) {
				if ($product_image['image'] && file_exists(DIR_IMAGE . $product_image['image'])) {
				  $images[] = $this->model_tool_image->resize($product_image['image'], $this->config->get($this->config->get('config_theme') . '_image_related_width'), $this->config->get($this->config->get('config_theme') . '_image_related_height'));
				} else {
				  $images[] = $this->model_tool_image->resize('placeholder.png', $this->config->get($this->config->get('config_theme') . '_image_related_width'), $this->config->get($this->config->get('config_theme') . '_image_related_height'));
				}
			}

			// Sizes
			$product_options = $this->model_catalog_product->getProductOptions($result['product_id']);
			$product_sizes = isset($product_options[0]) ? $product_options[0]['product_option_value'] : array();

			$sizes = '';

			foreach ($product_sizes as $size) {
			  $sizes .= ' ' . $size['name'];
			}
			// Colors
			$product_colors = $this->model_catalog_product->getProductColors($result['product_id']);

			$colors = '';

			foreach ($product_colors as $color) {
			  $colors .= ' ' . $color['name'];
			}

			// Product main category for other functions calls
			$product_main_category = $this->model_catalog_product->getMainCategory($result['product_id']);

			// Google Category
			$google_category = '';
			if(!empty($product_main_category)) {
				$product_category = $this->model_catalog_category->getCategory($product_main_category['category_id']);

				if($product_category) {
					$google_category = $product_category['google_category'];
				}
			}

			// Gender
			$gender = '';

			$product_top_category = false;

			if(!empty($product_main_category)) {
				$product_top_category = $this->model_catalog_product->getTopCategory($product_main_category['category_id']);
			}

			if($product_top_category) {
				$product_top_category_lc = strtolower($product_top_category['name']);

				if($product_top_category_lc === 'women') {
					$gender = 'female';
				}

				if($product_top_category_lc === 'men') {
					$gender = 'male';
				}
			}

			// Product type
			$product_category_bread = '';

			if(!empty($product_main_category)) {
				$product_category_bread = implode(" -> ", array_reverse($this->model_catalog_product->getBreadcrumbs($product_main_category['category_id'])));
			}

			$replace_data = array(
				'{del}' => "\t",
				'{title}' => str_replace(array("\r\n", "\r", "\n", "\t"), '', $result['name']),
				'{condition}' => 'new',
				'{brand}' => str_replace(array("\r\n", "\r", "\n", "\t"), '', $result['manufacturer']),
				'{mpn}' => str_replace(array("\r\n", "\r", "\n", "\t"), '', $result['model']),
				'{gtin}' => $result['upc'],
				'{shipping}' => "US::USPS First Class:4.99 USD\tUS::USPS Priority Mail:9.99 USD",
				'{google_product_category}' => $google_category,
				'{product_type}' => html_entity_decode($product_category_bread),
				'{price}' => $price,
				'{sale_price}' => $special ? $special : '',
				'{link}' => html_entity_decode($this->url->link('product/product', 'product_id=' . $result['product_id'])),
				'{mobile_link}' => html_entity_decode($this->url->link('product/product', 'product_id=' . $result['product_id'])),
				'{image_link}' => $image,
				'{additional_image_link}' => implode(",", $images),
				'{id}' => $result['product_id'],
				'{description}' => trim(preg_replace('/\s+/', ' ', strip_tags($result['description']))),
				'{payment_accepted}' => "Visa, MasterCard, PayPal, Amazon Pay",
				'{payment_notes}' => "Fast Shipping in US next business day",
				'{availability}' => "in stock",
				'{tax}' => "US:NJ:6.625:y",
				'{age_group}' => "adult",
				'{color}' => $colors,
				'{gender}' => $gender,
				// '{size}' => $sizes
				'{size}' => 'M L'
			);

			$rows[] = str_replace(array_keys($replace_data), array_values($replace_data), $row);
		}

		$output = $this->load->view('extension/feed/google_merchant/rows', array('rows' => $rows));

		/*
			APPEND CHUNK TO TEMPORARY FILE
		*/

		$mode = $current_step === 0 ? 'w' : 'a';
		$fp_tmp = fopen(TMP_FILE_PATH, $mode);

		fwrite($fp_tmp, $output);
		fclose($fp_tmp);

		fwrite($fp_log, date('d.m.Y G:i:s') . ': appended chunk #' . $current_step . "\r\n");

		/*
			IF WE HAVE COLLECTED ALL CHUNKS,
			MOVE DATA FROM TEMPORARY FILE TO ORIGINAL
		*/

		if(count($results) < CHUNK_SIZE) {
			$fp = fopen(FEED_FILE_PATH, 'w');

			$output = $this->load->view('extension/feed/google_merchant/index', array('rows' => file_get_contents(TMP_FILE_PATH)));

			fwrite($fp, $output);
			fclose($fp);

			fwrite($fp_log, date('d.m.Y G:i:s') . ': moved tmp => original' . "\r\n");

			$current_step = 0;
		} else {
			$current_step++;
		}

		/*
			UPDATE STATUS FILE.
			SET NEXT STEP AND SET FLAG THAT SCRIPT IS EXECUTED
		*/

		$fb_status = fopen(STATUS_FILE_PATH, 'w');
		fwrite($fb_status, sprintf("%d,%d", 0, $current_step));
		fclose($fb_status);

		/*
			SEND SOME RESPONSE TO BROWSER
		*/

		fwrite($fp_log, date('d.m.Y G:i:s') . ': finished' . "\r\n\r\n");
	}

	private function format_currency($price) {
		return $price . ' USD';
	}
}
