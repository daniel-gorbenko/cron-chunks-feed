<?php
class ControllerExtensionFeedFacebookMerchant extends Controller {
	public function index() {
		/*
			SETTINGS
		*/

		define("CHUNK_SIZE", 500);

		define("STATUS_FILE_PATH", DIR_FEED . 'fb/fb_status.csv');
		define("TMP_FILE_PATH", DIR_FEED . 'fb/fb_tmp.csv');
		define("FEED_FILE_PATH", DIR_FEED . 'fb/fb.csv');
		define("LOG_FILE_PATH", DIR_FEED . 'fb/fb.log');

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

		$row = $this->load->view('extension/feed/facebook_merchant/row');

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

			// Gender and custom_label_1
			$gender = '';
			$custom_label_1 = '';

			$product_top_category = false;

			if(!empty($product_main_category)) {
				$product_top_category = $this->model_catalog_product->getTopCategory($product_main_category['category_id']);
			}

			if($product_top_category) {
				$product_top_category_lc = strtolower($product_top_category['name']);

				if($product_top_category_lc === 'women') {
					$gender = 'female';
					$custom_label_1 = 'Women';
				}

				if($product_top_category_lc === 'men') {
					$gender = 'male';
					$custom_label_1 = 'Men';
				}
			}

			// custom_label_0
			$temp_custom_label_0 = str_replace(",","",$result['description']); // some retail prices have "," in them
            preg_match('~\$(.*?)\D~', $temp_custom_label_0, $match);

			$custom_label_0 = '';

			if(isset($match[1])) {
				$custom_label_0 = str_replace(",","",$match[1]);
			}

			// Product type
			$product_category_bread = '';

			if(!empty($product_main_category)) {
				$product_category_bread = implode(" -> ", array_reverse($this->model_catalog_product->getBreadcrumbs($product_main_category['category_id'])));
			}

			$replace_data = array(
				'{id}' => $result['product_id'],
				'{brand}' => $result['manufacturer'],
				'{product_type}' => html_entity_decode($product_category_bread),
				'{google_category}' => $google_category,
				'{custom_label_1}' => $custom_label_1,
				'{link}' => html_entity_decode($this->url->link('product/product', 'product_id=' . $result['product_id'])),
				'{image_link}' => html_entity_decode($image),
				'{title}' => substr(str_replace(array("\r\n", "\r", "\n", "\t"), '', $result['name']), 0, 150),
				'{price}' => $price,
				'{sale_price}' => $special ? $special : '',
				'{description}' => str_replace('&nbsp;', '', str_replace("\n", '', strip_tags($result['description']))),
				'{upc}' => $result['upc'],
				'{code}' => $result['model'],
				'{condition}' => 'new',
				'{availability}' => 'in stock',
				'{custom_label_0}' => $custom_label_0,
				'{additional_image_link}' => implode(",", $images),
				'{inventory}' => (int)$result['quantity'],
				'{color}' => $colors
			);

			$rows[] = str_replace(array_keys($replace_data), array_values($replace_data), $row);
		}

		$output = $this->load->view('extension/feed/facebook_merchant/rows', array('rows' => $rows));

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

			$output = $this->load->view('extension/feed/facebook_merchant/index', array('rows' => file_get_contents(TMP_FILE_PATH)));

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
