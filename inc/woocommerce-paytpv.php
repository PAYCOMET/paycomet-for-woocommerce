<?php
	/**
	 * Pasarela PAYCOMET Gateway Class
	 * 
	 */
	class woocommerce_paytpv extends WC_Payment_Gateway {

		private $ws_client;

		private function write_log( $log ) {
			if ( true === WP_DEBUG ) {
				if ( is_array( $log ) || is_object( $log ) ) {
					error_log( print_r( $log, true ) );
				} else {
					error_log( $log );
				}
			}
		}

		public function __construct() {
			$this->id = 'paytpv';
			$this->icon = PAYTPV_PLUGIN_URL . 'images/paycomet.png';
			$this->has_fields = false;
			$this->method_title = 'PAYCOMET';
            $this->method_description = __('Payment gateway for credit card payment.', 'wc_paytpv' );
			$this->supports = array(
				'products',
				'refunds',
				'subscriptions',
				'subscription_cancellation',
				'subscription_suspension',
				'subscription_reactivation',
				'subscription_amount_changes',
				'subscription_date_changes'
			);
			// Load the form fields
			$this->init_form_fields();

			// Load the settings.
			$this->init_settings();

			$this->iframeurl = 'https://api.paycomet.com/gateway/ifr-bankstore';

			// Get setting values
			$this->enabled = $this->settings[ 'enabled' ];
			$this->title = $this->settings[ 'title' ];
			$this->description = $this->settings[ 'description' ];
			$this->clientcode = $this->settings[ 'clientcode' ];
			$this->paytpv_terminals = get_option( 'woocommerce_paytpv_terminals',
				array(
					array(
						'term'   => $this->get_option( 'term' ),
						'pass' => $this->get_option( 'pass' ),
						'terminales'      => $this->get_option( 'terminales' ),
						'dsecure'      => $this->get_option( 'dsecure' ),
						'moneda'           => $this->get_option( 'moneda' ),
						'tdmin'            => $this->get_option( 'tdmin' )
					)
				)
			);

			$this->disable_offer_savecard = $this->settings[ 'disable_offer_savecard' ];

			

			// Hooks
			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			//add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_terminals_details' ) );
			add_action( 'woocommerce_api_woocommerce_' . $this->id, array( $this, 'check_' . $this->id . '_resquest' ) );
			
			add_action('admin_notices', array( $this, 'validate_paytpv' ));

			// Subscriptions
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
			add_filter( 'wcs_resubscribe_order_created', array( $this, 'store_renewal_order_id' ), 10, 4 );

		}
		

		/**
		 * Loads the my-subscriptions.php template on the My Account page.
		 *
		 * @since 1.0
		 */
		public static function get_my_cards_template() {

			$user_id = get_current_user_id();

			$saved_cards = Paytpv::savedCards($user_id);

			$operation = 107;
			// Obtenemos el terminal para el pedido
			// El primer terminal configurado
			$gateway = new self();
			$terminal = $gateway->paytpv_terminals[0];
			$term = $terminal["term"];
			$pass = $terminal["pass"];
		

			$order = $user_id;

			$secure_pay = 0;
			
			$signature = hash('sha512',$gateway->clientcode.$term.$operation.$order.md5($pass));
			$fields = array
			(
				'MERCHANT_MERCHANTCODE' => $gateway->clientcode,
				'MERCHANT_TERMINAL' => $term,
				'OPERATION' => $operation,
				'LANGUAGE' => $gateway->_getLanguange(),
				'MERCHANT_MERCHANTSIGNATURE' => $signature,
				'MERCHANT_ORDER' => $order,
				'URLOK' => wc_get_page_permalink( 'myaccount' ),
			    'URLKO' => wc_get_page_permalink( 'myaccount' ),
			    '3DSECURE' => $secure_pay
			);

			$query = http_build_query($fields);

			$vhash = hash('sha512', md5($query.md5($pass)));

			$disable_offer_savecard = $gateway->disable_offer_savecard;


			$url_paytpv = $gateway->getIframeUrl($secure_pay) . $query . "&VHASH=".$vhash;
			
			wc_get_template( 'myaccount/my-cards.php', array( 'disable_offer_savecard' => $disable_offer_savecard, 'saved_cards' => $saved_cards, 'user_id' => get_current_user_id(), 'url_paytpv'=> $url_paytpv), '', PAYTPV_PLUGIN_DIR . 'template/' );

						

		}
		
		public function validate_paytpv(){
			if (empty($this->paytpv_terminals))
		    	echo '<div class="error"><p>'.__('You must define at least one terminal', 'wc_paytpv' ).'</p></div>';
		}

		/**
		 * There are no payment fields for PAYCOMET, but we want to show the description if set.
		 * */
		function payment_fields() {
			if ( $this->description )
				echo wpautop( wptexturize( $this->description ) );
		}

		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 * */
		public function admin_options() {
			?>
			<h3><?php _e( 'PAYCOMET Payment Gateway', 'wc_paytpv' ); ?></h3>
			<p>
				<?php _e( '<a href="https://www.paycomet.com">PAYCOMET Online</a> payment gateway for Woocommerce enables credit card payment in your shop. Al you need is a PAYCOMET merchant account and access to <a href="https://dashboard.paycomet.com/cp_control">customer area</a>', 'wc_paytpv'  ); ?>
			</p>
			<p>
				<?php _e( 'There you should configure "Tipo de notificación del cobro:" as "Notificación por URL" set ther teh following URL:', 'wc_paytpv'  ); ?> <?php echo add_query_arg( 'tpvLstr', 'notify', add_query_arg( 'wc-api', 'woocommerce_' . $this->id, home_url( '/' ) ) ); ?></p>
			</p>
			<table class="form-table">
				<?php $this->generate_settings_html(); ?>
			</table><!--/.form-table-->
			<?php

		}


		public function process_admin_options()
        {
            $settings = new WC_Admin_Settings();
			$postData = $this->get_post_data();
			$error = false;
			
			
			// Si se activa el Módulo se verifican los datos
			if (isset($_REQUEST["woocommerce_paytpv_enabled"]) && $_REQUEST["woocommerce_paytpv_enabled"]==1) {
				
				// Validate required fields
				if (empty($postData['woocommerce_paytpv_clientcode']) || 
				$postData['term'][0] == "" ||
				$postData['pass'][0] == ""
				) {
					$error = true;
					$settings->add_error(__('ERROR: Unable to activate payment method.','wc_paytpv')  . " " . __('Please fill in required fields: Client Code, Terminal Number, Password.','wc_paytpv'));					
				}
				
				// Validate info Paycomet
				if (!$error) {				
					$arrValidatePaycomet = $this->validatePaycomet($postData);
					if ($arrValidatePaycomet["error"] != 0) {
						$error = true;
						$settings->add_error(__('ERROR: Unable to activate payment method.','wc_paytpv') . " " . $arrValidatePaycomet["error_txt"]);
					}
				}

			}
			
			// Si hay error guardamos los datos pero no dejamos habilitar el método de pago			
			if ($error) {
				unset($_POST["woocommerce_paytpv_enabled"]);				
			}
			$this->save_terminals_details();
            return parent::process_admin_options();
		}
	

		private function validatePaycomet($postData)
		{			
			
			$api = new PaytpvApi();		

			$arrDatos = array();
			$arrDatos["error"] = 0;
			
			// Validación de los datos en Paycomet
			foreach (array_keys($postData["term"]) as $key) {
				$term = ($postData['term'][$key] == '') ? "" : $postData['term'][$key];				

				switch ($_POST['terminales'][$key]) {
					case 0:  // Seguro
						$terminales_txt = "CES";
						$terminales_info = "Secure";						
						break;
					case 1: // No Seguro
						$terminales_txt = "NO-CES";
						$terminales_info = "Non-Secure";
						
						break;
					case 2: // Ambos
						$terminales_txt = "BOTH";
						$terminales_info = "Both";
						
						break;
				}
				$resp = $api->validatePaycomet(
					$postData['woocommerce_paytpv_clientcode'],
					$term,
					$postData['pass'][$key],
					$terminales_txt
				);
				
				if ($resp["DS_RESPONSE"] != 1) {
					$arrDatos["error"] = 1;
					switch ($resp["DS_ERROR_ID"]) {
						case 1121:  // No se encuentra el cliente
						case 1130:  // No se encuentra el producto
						case 1003:  // Credenciales inválidas
						case 127:   // Parámetro no válido.
							$arrDatos["error_txt"] = __('Check that the Client Code, Terminal and Password are correct','wc_paytpv');
							break;
						case 1337:  // Ruta de notificación no configurada
							$arrDatos["error_txt"] = __('Notification URL is not defined in the product configuration of your account PAYCOMET account.','wc_paytpv');
							break;
						case 28:    // Curl
						case 1338:  // Ruta de notificación no responde correctamente						
							$arrDatos["error_txt"] = __('The notification URL defined in the product configuration of your PAYCOMET account does not respond correctly. Verify that it has been defined as: ','wc_paytpv') 
							. add_query_arg( 'tpvLstr', 'notify', add_query_arg( 'wc-api', 'woocommerce_' . $this->id, home_url( '/' ) ) );
							break;
						case 1339:  // Configuración de terminales incorrecta
							$arrDatos["error_txt"] = __('Your Product in PAYCOMET account is not set up with the Available Terminals option: ','wc_paytpv') . $terminales_info;
							break;
					}
					return $arrDatos;
				}
			}

			return $arrDatos;
		}


		/**
		 * Save account details table
		 */
		public function save_terminals_details() {		
			
			$terminals = array();

			if ( isset( $_POST['term'] ) ) {

				$term   = array_map( 'wc_clean', $_POST['term'] );
				$pass = array_map( 'wc_clean', $_POST['pass'] );
				$terminales      = array_map( 'wc_clean', $_POST['terminales'] );
				$dsecure      = array_map( 'wc_clean', $_POST['dsecure'] );
				$moneda           = array_map( 'wc_clean', $_POST['moneda'] );
				$tdmin            = array_map( 'wc_clean', $_POST['tdmin'] );

				foreach ( $term as $i => $name ) {
					if ( ! isset( $term[ $i ] ) ) {
						continue;
					}

					$terminals[] = array(
						'term'   => $term[ $i ],
						'pass' => $pass[ $i ],
						'terminales'      => $terminales[ $i ],
						'dsecure'      => $dsecure[ $i ],
						'moneda'           => $moneda[ $i ],
						'tdmin'            => $tdmin[ $i ]
					);
				}
			}


			update_option( 'woocommerce_paytpv_terminals', $terminals );

		}

		

		public static function load_resources() {
			global $hook_suffix;

			wp_register_style( 'lightcase.css', PAYTPV_PLUGIN_URL . 'css/lightcase.css', PAYTPV_VERSION );
			wp_enqueue_style( 'lightcase.css');

			wp_register_style( 'paytpv.css', PAYTPV_PLUGIN_URL . 'css/paytpv.css', PAYTPV_VERSION );
			wp_enqueue_style( 'paytpv.css');

			wp_register_script( 'paytpv.js', PAYTPV_PLUGIN_URL . 'js/paytpv.js', array('jquery'),  PAYTPV_VERSION );
			wp_enqueue_script( 'paytpv.js' );	


			wp_register_script( 'lightcase.js', PAYTPV_PLUGIN_URL . 'js/lightcase.js', array('jquery'), PAYTPV_VERSION );
			wp_enqueue_script( 'lightcase.js' );
			
		}


		public static function load_resources_conf() {
			global $hook_suffix;

			wp_register_style( 'paytpv.css', PAYTPV_PLUGIN_URL . 'css/paytpv.css', PAYTPV_VERSION );
			wp_enqueue_style( 'paytpv.css');

			wp_register_script( 'paytpv_conf.js', PAYTPV_PLUGIN_URL . 'js/paytpv_conf.js', array('jquery'),  PAYTPV_VERSION );
			wp_enqueue_script( 'paytpv_conf.js' );	

			
		}

		/**
		 * Initialize Gateway Settings Form Fields
		 */
		function init_form_fields() {

		
			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'wc_paytpv' ),
					'label' => __( 'Enable PAYCOMET gateway', 'wc_paytpv' ),
					'type' => 'checkbox',
					'description' => '',
					'default' => 'no'
				),
				'title' => array(
					'title' => __( 'Title', 'wc_paytpv' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'wc_paytpv' ),
					'default' => __( 'Credit Card', 'wc_paytpv' )
				),
				'description' => array(
					'title' => __( 'Description', 'wc_paytpv' ),
					'type' => 'textarea',
					'class' => 'description',
					'description' => __( 'This controls the description which the user sees during checkout.', 'wc_paytpv' ),
					'default' => __( 'Pay using your credit card in a secure way', 'wc_paytpv' ),
				),
				'clientcode' => array(
					'title' => __( 'Client code', 'wc_paytpv' ),
					'type' => 'text',
					'class' => 'clientcode',
					'description' => '',
					'default' => ''
				),

				'paytpv_terminals' => array(
					'type'        => 'paytpv_terminals'
				),
				
				'disable_offer_savecard' => array(
					'title' => __( 'Disable Offer to save card', 'wc_paytpv' ),
					'type' => 'select',
					'label' => '',
					
					'options' => array(
						0 => __( 'No', 'wc_paytpv' ),
						1 => __( 'Yes', 'wc_paytpv' )
					)
				)				
			);
		}



		/**
	 	* generate_account_details_html function.
	 	*/
		public function generate_paytpv_terminals_html() {

			ob_start();

			?>
			<tr valign="top">
				<th class="titledesc"><?php _e( 'Terminals', 'wc_paytpv' ); ?></th>
				<td colspan="2" class="forminp" id="paytpv_terminals">
					<table class="tblterminals widefat wc_input_table sortable" style="font-size:80%" cellspacing="0">
						<thead>
							<tr>
								<th class="sort">&nbsp;</th>
								<th><?php _e( 'Terminal Number', 'wc_paytpv' ); ?></th>
								<th><?php _e( 'Password', 'wc_paytpv' ); ?></th>
								<th><?php _e( 'Terminals available', 'wc_paytpv' ); ?></th>
								<th><?php _e( 'Use 3D Secure', 'wc_paytpv' ); ?></th>
								<th><?php _e( 'Currency', 'wc_paytpv' ); ?></th>
								<th><?php _e( 'Use 3D Secure on purchases over', 'wc_paytpv' ); ?></th>
							</tr>
						</thead>
						<tbody class="accounts">
							<?php
							$i = -1;
							
							$arrTerminals = array(__('Secure','wc_paytpv' ),__('Non-Secure','wc_paytpv' ),__('Both','wc_paytpv' ));
							$arrDsecure = array(__( 'No', 'wc_paytpv' ),__( 'Yes', 'wc_paytpv' ));
							$arrMonedas = get_woocommerce_currencies();

							// Un terminal por defecto en la moneda de woocommerce
							if (empty($this->paytpv_terminals)){
								$this->paytpv_terminals[0] = array("term"=>"","pass"=>"","terminales"=>0,"dsecure"=>0,"moneda"=> get_woocommerce_currency(),"tdmin"=>"");
							}

							if ( $this->paytpv_terminals){
								foreach ( $this->paytpv_terminals as $key=>$terminal){
									$i++;

									echo '<tr class="terminal">
										<td class="sort"></td>
										<td><input type="text" value="' . esc_attr( wp_unslash( $terminal['term'] ) ) . '" name="term[]" /></td>
										<td><input class="pass" type="text" value="' . esc_attr( wp_unslash( $terminal['pass'] ) ). '" name="pass[]" /></td>
										<td><select class="term" name="terminales[]" onchange="checkterminales(this);">
										';
									foreach ($arrTerminals as $key=>$val){
										$selected = ($key==$terminal['terminales'])?"selected":"";
										echo '<option value="'.$key.'" '.$selected.'>'.$val.'</option>';
									}
									echo '</select></td>';
									echo '<td><select class="dsecure" name="dsecure[]">
										';
										foreach ($arrDsecure as $key=>$val){
											$selected = ($key==$terminal['dsecure'])?"selected":"";
											echo '<option value="'.$key.'" '.$selected.'>'.$val.'</option>';
										}
									echo '</select></td>';
									echo '<td><select class="moneda" name="moneda[]">
										';
										foreach ($arrMonedas as $key=>$val){
											$selected = ($key==$terminal['moneda'] || ($terminal['moneda']=="" && $key==get_woocommerce_currency()))?"selected":"";
											echo '<option value="'.$key.'" '.$selected.'>'.$val.'</option>';
										}
									echo '</select></td>';
									echo '<td><input class="tdmin" type="number" value="' . esc_attr( $terminal['tdmin'] ) . '" name="tdmin[]" placeholder="0" /></td>
									</tr>';
								}
							}
							?>
						</tbody>
						<tfoot>
							<tr>
								<th colspan="7"><a href="#" class="add button"><?php _e( '+ Add Terminal', 'wc_paytpv' ); ?></a> <a href="#" class="remove_term button"><?php _e( '- Remove Terminal', 'wc_paytpv' ); ?></a></th>
							</tr>
						</tfoot>
					</table>
					
				</td>
			</tr>
			<p id="msg_1terminal" style="display:none"><?php print __('Must have at least one terminal configured to process payments.', 'wc_paytpv');?></p>
			<p id="msg_moneda_terminal" style="display:none"><?php print __('There can be two terminals configured with the same currency.', 'wc_paytpv');?></p>
			<?php
			return ob_get_clean();

		}

		
		/**
		 * Check for PAYCOMET IPN Response
		 * */
		function check_paytpv_resquest() {

			if ( !isset( $_REQUEST[ 'tpvLstr' ] ) )
				return;


			if (isset($_REQUEST['Order']) ){
				$datos_order = explode("_",$_REQUEST['Order']); // En los pagos de suscripcion viene {id_order}_{numpago}
				$ref = $datos_order[0];
				try{
					$order = new WC_Order( ( int ) substr( $ref, 0, 8 ) );
				}catch (exception $e){}
			}
			
			// Check Notification URL
			if (isset($_REQUEST['ping']) && $_REQUEST['ping'] == 1) {
				die("PING OK");
			}

			// Get Data
			if (isset($_REQUEST['paycomet_data']) && $_REQUEST['paycomet_data'] == 1) {			
				global $woocommerce;
				global $wp_version;
				if (isset($_REQUEST["clientcode"]) &&
					$_REQUEST["clientcode"] == $this->clientcode &&
					isset($_REQUEST["terminal"]) &&
					$_REQUEST["terminal"]==$this->paytpv_terminals[0]["term"]
				) {
					$arrDatos = array("module_v" => PAYTPV_VERSION, "wp_v" => $wp_version, "wc_v" => $woocommerce->version);
					exit(json_encode($arrDatos));
				}
			}

			if ( $_REQUEST[ 'tpvLstr' ] == 'pay' && $order->get_status() != 'completed' ) { //PAGO CON TARJETA GUARDADA

				$card = $_POST[ 'card' ];
				$saved_card = PayTPV::savedCard($order->get_user_id(),$card);
				

				// Obtenemos el terminal para el pedido
				$arrTerminalData = $this->TerminalCurrency($order);
				$importe = $arrTerminalData["importe"];
				$currency_iso_code = $arrTerminalData["currency_iso_code"];
				$term = $arrTerminalData["term"];
				$pass = $arrTerminalData["pass"];
				$paytpv_order_ref = $order->get_id();

				$MERCHANT_DATA = $this->getMerchantData($order);

				$secure_pay = $this->isSecureTransaction($order,$arrTerminalData,$card,$saved_card["paytpv_iduser"])?1:0;
				
				// PAGO SEGURO redireccionamos
				if ($secure_pay){

					$paytpv_order_ref = str_pad( $paytpv_order_ref, 8, "0", STR_PAD_LEFT );

					$URLOK = $this->get_return_url( $order );
					$URLKO = $order->get_cancel_order_url_raw();

					$OPERATION = "109"; 

					$signature = hash('sha512',$this->clientcode.$saved_card["paytpv_iduser"].$saved_card["paytpv_tokenuser"].$term.$OPERATION.$paytpv_order_ref.$importe.$currency_iso_code.md5($pass));
					
					$fields = array
						(
							'MERCHANT_MERCHANTCODE' => $this->clientcode,
							'MERCHANT_TERMINAL' => $term,
							'OPERATION' => $OPERATION,
							'LANGUAGE' => $this->_getLanguange(),
							'MERCHANT_MERCHANTSIGNATURE' => $signature,
							'MERCHANT_ORDER' => $paytpv_order_ref,
							'MERCHANT_AMOUNT' => $importe,
							'MERCHANT_CURRENCY' => $currency_iso_code,
							'IDUSER' => $saved_card["paytpv_iduser"],
							'TOKEN_USER' => $saved_card["paytpv_tokenuser"],
							'3DSECURE' => $secure_pay,
							'URLOK' => $URLOK,
							'URLKO' => $URLKO
						);

					if ($MERCHANT_DATA!=null)           $fields["MERCHANT_DATA"] = $MERCHANT_DATA;
					
					$query = http_build_query($fields);
					$vhash = hash('sha512', md5($query.md5($pass)));
				
					$salida = $this->getIframeUrl($secure_pay) . $query. "&VHASH=".$vhash;

					

					header('Location: '.$salida);
					exit;
				}
 
				// PAGO NO SEGURO --------------------------------------------------------------------------

			    $client = $this->get_client();
				//$charge = $client->execute_purchase( $order,$saved_card["paytpv_iduser"],$saved_card["paytpv_tokenuser"],$term,$pass,$currency_iso_code,$importe,$paytpv_order_ref,'','',$MERCHANT_DATA);
				$charge = $client->execute_purchase( $order,$saved_card["paytpv_iduser"],$saved_card["paytpv_tokenuser"],$term,$pass,$currency_iso_code,$importe,$paytpv_order_ref,'','','');
				
				
				if ( ( int ) $charge[ 'DS_RESPONSE' ] == 1 ) {
					
					// Se procesa en la notificacion
					/*
					$order->add_order_note( __( 'PAYCOMET payment completed', 'woocommerce' ) );
					$order->payment_complete($charge[ 'DS_MERCHANT_AUTHCODE' ]);
					update_post_meta( ( int ) $order->id, 'PayTPV_Referencia', $charge[ 'DS_MERCHANT_ORDER' ]);
					*/

					update_post_meta( ( int ) $order->get_id(), 'PayTPV_IdUser', $saved_card["paytpv_iduser"] );
					update_post_meta( ( int ) $order->get_id(), 'PayTPV_TokenUser', $saved_card["paytpv_tokenuser"] );

					$url = $this->get_return_url( $order );
				}else{
					$url = $order->get_cancel_order_url_raw();

				}
				wp_redirect( $url, 303 );
				
			}

			
			if ( $_REQUEST[ 'tpvLstr' ] == 'notify' && isset($_POST["TransactionType"])) {//NOTIFICACIÓN

				switch ($_POST["TransactionType"]){
					// add_User
					case 107:

						$terminal = $this->paytpv_terminals[0];
						$term = $terminal["term"];
						$pass = $terminal["pass"];

						$user_id = $_POST["Order"];
						$DateTime = (isset($_POST[ 'DateTime']))?$_POST[ 'DateTime']:"";
						$sign = (isset($_POST[ 'NotificationHash']))?$_POST[ 'NotificationHash']:"";

						$localSign = hash('sha512',$this->clientcode . $term . $_POST["TransactionType"] . $_POST[ 'Order' ] . $DateTime . md5($pass));

						if ( $_REQUEST[ 'TransactionType' ] == '107' && $_REQUEST[ 'Response' ] == 'OK' && ($sign == $localSign)) {
							
							if (isset($_REQUEST[ 'IdUser' ])){
								// Save User Card
								$result = $this->saveCard(null, $user_id,$_REQUEST[ 'IdUser' ],$_REQUEST[ 'TokenUser' ],$_POST["TransactionType"]);
							}
						}		

						print "PAYCOMET OK";
						exit;

					break;

					// execute_purchase
					case 1:
					case 109:
						$arrTerminalData = $this->TerminalCurrency($order);
						$currency_iso_code = $arrTerminalData["currency_iso_code"];
						$term = $arrTerminalData["term"];
						$pass = $arrTerminalData["pass"];

						$AMOUNT = round( $order->get_total() * 100 );
						
						$mensaje = $this->clientcode .
								$term .
								$_REQUEST[ 'TransactionType' ] .
								$_REQUEST[ 'Order' ] .
								$_REQUEST[ 'Amount' ] .
								$currency_iso_code;

						
						$localSign = hash('sha512', $mensaje . md5( $pass ) . $_REQUEST[ 'BankDateTime' ] . $_REQUEST[ 'Response' ] );
						if ( ($_REQUEST[ 'TransactionType' ] == '1' || $_REQUEST[ 'TransactionType' ] == '109')  && $_REQUEST[ 'Response' ] == 'OK' && ($_REQUEST[ 'NotificationHash' ] == $localSign)) {
							
							if (isset($_REQUEST[ 'IdUser' ])){

								$save_card = get_post_meta( ( int ) $order->get_id(), 'paytpv_savecard', true );
								// Guardamos el token cuando el cliente lo ha marcado y cuando la opción Deshabilitar Almacenar Tarjeta esta desactivada.
								if ($save_card!=="0" && $this->disable_offer_savecard==0){
									// Save User Card
									$result = $this->saveCard($order, $order->get_user_id(), $_REQUEST[ 'IdUser' ],$_REQUEST[ 'TokenUser' ],$_POST["TransactionType"]);
									$paytpv_iduser = $result["paytpv_iduser"];
									$paytpv_tokenuser = $result["paytpv_tokenuser"];
								}else{
									$paytpv_iduser = $_REQUEST[ 'IdUser' ];
									$paytpv_tokenuser = $_REQUEST[ 'TokenUser' ];
								}

								update_post_meta( ( int ) $order->get_id(), 'PayTPV_IdUser', $paytpv_iduser );
								update_post_meta( ( int ) $order->get_id(), 'PayTPV_TokenUser', $paytpv_tokenuser );
								
							}

							$order->add_order_note( __( 'PAYCOMET payment completed', 'woocommerce' ) );
							$order->payment_complete($_REQUEST[ 'AuthCode' ]);

							update_post_meta( ( int ) $order->get_id(), 'PayTPV_Referencia', $_REQUEST[ 'Order' ] );

							print "PAYCOMET WC OK";
							exit;
						}else{
							print "PAYCOMET WC KO";
							exit;
						}

					break;
				}
				print "PAYCOMET WC ERROR";
				exit;
			}

			// Save Card in execute_purchase
			if ( $_REQUEST[ 'tpvLstr' ] == 'savecard' ) {//NOTIFICACIÓN

				update_post_meta( ( int ) $order->get_id(), 'paytpv_savecard', $_POST["paytpv_agree"] );
				exit;
			}

			// Save Card Description
			if ( $_REQUEST[ 'tpvLstr' ] == 'saveDesc' ) {//NOTIFICACIÓN
				$card_desc = $_POST["card_desc"];
				$id_card = $_GET["id"];

				$saved_cards = Paytpv::saveCardDesc($id_card,$card_desc);
				
				$res["resp"] = 0;
				print json_encode($res);
				exit;
			}

			// Remove User Card
			if ( $_REQUEST[ 'tpvLstr' ] == 'removeCard' ) {//NOTIFICACIÓN
				
				$id_card = $_GET["id"];

				$saved_cards = Paytpv::removeCard($id_card);
				
				$res["resp"] = 0;
				print json_encode($res);
				exit;
			}						

			print "PAYCOMET WC ERROR 2";
			exit;
			
		}

		/**
		 * Validate user password
		 * */
		public function validPassword($id,$passwd){
			
			$user = new WP_User( $id);

			if (wp_check_password($passwd, $user->user_pass, $user->ID)){
				return true;
			}return false;
		}

		/**
		 * Get PAYCOMET language code
		 * */
		function _getLanguange() {
			$lng = substr( get_bloginfo( 'language' ), 0, 2 );
			if ( function_exists( 'qtrans_getLanguage' ) )
				$lng = qtrans_getLanguage();
			if ( defined( 'ICL_LANGUAGE_CODE' ) )
				$lng = ICL_LANGUAGE_CODE;
			switch ( $lng ) {
				case 'en':
					return 'EN';
				case 'fr':
					return 'FR';
				case 'de':
					return 'DE';
				case 'it':
					return 'IT';
				case 'ca':
					return 'CA';
				default:
					return 'ES';
			}
			return 'ES';
		}

		/**
		 * Get PAYCOMET Args for passing to PP
		 * */
		function get_paytpv_args( $order ) {
			$paytpv_req_args = array( );
			$paytpv_args = array( );
			$paytpv_args = $this->get_paytpv_bankstore_args( $order );
			return array_merge( $paytpv_args, $paytpv_req_args );
		}

		public function isoCodeToNumber($code) 
		{
			try {
				$arrCode = array("AF" => "004", "AX" => "248", "AL" => "008", "DE" => "276", "AD" => "020", "AO" => "024", "AI" => "660", "AQ" => "010", "AG" => "028", "SA" => "682", "DZ" => "012", "AR" => "032", "AM" => "051", "AW" => "533", "AU" => "036", "AT" => "040", "AZ" => "031", "BS" => "044", "BD" => "050", "BB" => "052", "BH" => "048", "BE" => "056", "BZ" => "084", "BJ" => "204", "BM" => "060", "BY" => "112", "BO" => "068", "BQ" => "535", "BA" => "070", "BW" => "072", "BR" => "076", "BN" => "096", "BG" => "100", "BF" => "854", "BI" => "108", "BT" => "064", "CV" => "132", "KH" => "116", "CM" => "120", "CA" => "124", "QA" => "634", "TD" => "148", "CL" => "52", "CN" => "156", "CY" => "196", "CO" => "170", "KM" => "174", "KP" => "408", "KR" => "410", "CI" => "384", "CR" => "188", "HR" => "191", "CU" => "192", "CW" => "531", "DK" => "208", "DM" => "212", "EC" => "218", "EG" => "818", "SV" => "222", "AE" => "784", "ER" => "232", "SK" => "703", "SI" => "705", "ES" => "724", "US" => "840", "EE" => "233", "ET" => "231", "PH" => "608", "FI" => "246", "FJ" => "242", "FR" => "250", "GA" => "266", "GM" => "270", "GE" => "268", "GH" => "288", "GI" => "292", "GD" => "308", "GR" => "300", "GL" => "304", "GP" => "312", "GU" => "316", "GT" => "320", "GF" => "254", "GG" => "831", "GN" => "324", "GW" => "624", "GQ" => "226", "GY" => "328", "HT" => "332", "HN" => "340", "HK" => "344", "HU" => "348", "IN" => "356", "ID" => "360", "IQ" => "368", "IR" => "364", "IE" => "372", "BV" => "074", "IM" => "833", "CX" => "162", "IS" => "352", "KY" => "136", "CC" => "166", "CK" => "184", "FO" => "234", "GS" => "239", "HM" => "334", "FK" => "238", "MP" => "580", "MH" => "584", "PN" => "612", "SB" => "090", "TC" => "796", "UM" => "581", "VG" => "092", "VI" => "850", "IL" => "376", "IT" => "380", "JM" => "388", "JP" => "392", "JE" => "832", "JO" => "400", "KZ" => "398", "KE" => "404", "KG" => "417", "KI" => "296", "KW" => "414", "LA" => "418", "LS" => "426", "LV" => "428", "LB" => "422", "LR" => "430", "LY" => "434", "LI" => "438", "LT" => "440", "LU" => "442", "MO" => "446", "MK" => "807", "MG" => "450", "MY" => "458", "MW" => "454", "MV" => "462", "ML" => "466", "MT" => "470", "MA" => "504", "MQ" => "474", "MU" => "480", "MR" => "478", "YT" => "175", "MX" => "484", "FM" => "583", "MD" => "498", "MC" => "492", "MN" => "496", "ME" => "499", "MS" => "500", "MZ" => "508", "MM" => "104", "NA" => "516", "NR" => "520", "NP" => "524", "NI" => "558", "NE" => "562", "NG" => "566", "NU" => "570", "NF" => "574", "NO" => "578", "NC" => "540", "NZ" => "554", "OM" => "512", "NL" => "528", "PK" => "586", "PW" => "585", "PS" => "275", "PA" => "591", "PG" => "598", "PY" => "600", "PE" => "604", "PF" => "258", "PL" => "616", "PT" => "620", "PR" => "630", "GB" => "826", "EH" => "732", "CF" => "140", "CZ" => "203", "CG" => "178", "CD" => "180", "DO" => "214", "RE" => "638", "RW" => "646", "RO" => "642", "RU" => "643", "WS" => "882", "AS" => "016", "BL" => "652", "KN" => "659", "SM" => "674", "MF" => "663", "PM" => "666", "VC" => "670", "SH" => "654", "LC" => "662", "ST" => "678", "SN" => "686", "RS" => "688", "SC" => "690", "SL" => "694", "SG" => "702", "SX" => "534", "SY" => "760", "SO" => "706", "LK" => "144", "SZ" => "748", "ZA" => "710", "SD" => "729", "SS" => "728", "SE" => "752", "CH" => "756", "SR" => "740", "SJ" => "744", "TH" => "764", "TW" => "158", "TZ" => "834", "TJ" => "762", "IO" => "086", "TF" => "260", "TL" => "626", "TG" => "768", "TK" => "772", "TO" => "776", "TT" => "780", "TN" => "788", "TM" => "795", "TR" => "792", "TV" => "798", "UA" => "804", "UG" => "800", "UY" => "858", "UZ" => "860", "VU" => "548", "VA" => "336", "VE" => "862", "VN" => "704", "WF" => "876", "YE" => "887", "DJ" => "262", "ZM" => "894", "ZW" => "716");
				return $arrCode[$code];
			} catch (exception $e) {}
			
			return "";
		}
		
		public function isoCodePhonePrefix($code)
		{
			try {
				$arrCode = array("AC" => "247", "AD" => "376", "AE" => "971", "AF" => "93","AG" => "268", "AI" => "264", "AL" => "355", "AM" => "374", "AN" => "599", "AO" => "244", "AR" => "54", "AS" => "684", "AT" => "43", "AU" => "61", "AW" => "297", "AX" => "358", "AZ" => "374", "AZ" => "994", "BA" => "387", "BB" => "246", "BD" => "880", "BE" => "32", "BF" => "226", "BG" => "359", "BH" => "973", "BI" => "257", "BJ" => "229", "BM" => "441", "BN" => "673", "BO" => "591", "BR" => "55", "BS" => "242", "BT" => "975", "BW" => "267", "BY" => "375", "BZ" => "501", "CA" => "1", "CC" => "61", "CD" => "243", "CF" => "236", "CG" => "242", "CH" => "41", "CI" => "225", "CK" => "682", "CL" => "56", "CM" => "237", "CN" => "86", "CO" => "57", "CR" => "506", "CS" => "381", "CU" => "53", "CV" => "238", "CX" => "61", "CY" => "392", "CY" => "357", "CZ" => "420", "DE" => "49", "DJ" => "253", "DK" => "45", "DM" => "767", "DO" => "809", "DZ" => "213", "EC" => "593", "EE" => "372", "EG" => "20", "EH" => "212", "ER" => "291", "ES" => "34", "ET" => "251", "FI" => "358", "FJ" => "679", "FK" => "500", "FM" => "691", "FO" => "298", "FR" => "33", "GA" => "241", "GB" => "44", "GD" => "473", "GE" => "995", "GF" => "594", "GG" => "44", "GH" => "233", "GI" => "350", "GL" => "299", "GM" => "220", "GN" => "224", "GP" => "590", "GQ" => "240", "GR" => "30", "GT" => "502", "GU" => "671", "GW" => "245", "GY" => "592", "HK" => "852", "HN" => "504", "HR" => "385", "HT" => "509", "HU" => "36", "ID" => "62", "IE" => "353", "IL" => "972", "IM" => "44", "IN" => "91", "IO" => "246", "IQ" => "964", "IR" => "98", "IS" => "354", "IT" => "39", "JE" => "44", "JM" => "876", "JO" => "962", "JP" => "81", "KE" => "254", "KG" => "996", "KH" => "855", "KI" => "686", "KM" => "269", "KN" => "869", "KP" => "850", "KR" => "82", "KW" => "965", "KY" => "345", "KZ" => "7", "LA" => "856", "LB" => "961", "LC" => "758", "LI" => "423", "LK" => "94", "LR" => "231", "LS" => "266", "LT" => "370", "LU" => "352", "LV" => "371", "LY" => "218", "MA" => "212", "MC" => "377", "MD"  > "533", "MD" => "373", "ME" => "382", "MG" => "261", "MH" => "692", "MK" => "389", "ML" => "223", "MM" => "95", "MN" => "976", "MO" => "853", "MP" => "670", "MQ" => "596", "MR" => "222", "MS" => "664", "MT" => "356", "MU" => "230", "MV" => "960", "MW" => "265", "MX" => "52", "MY" => "60", "MZ" => "258", "NA" => "264", "NC" => "687", "NE" => "227", "NF" => "672", "NG" => "234", "NI" => "505", "NL" => "31", "NO" => "47", "NP" => "977", "NR" => "674", "NU" => "683", "NZ" => "64", "OM" => "968", "PA" => "507", "PE" => "51", "PF" => "689", "PG" => "675", "PH" => "63", "PK" => "92", "PL" => "48", "PM" => "508", "PR" => "787", "PS" => "970", "PT" => "351", "PW" => "680", "PY" => "595", "QA" => "974", "RE" => "262", "RO" => "40", "RS" => "381", "RU" => "7", "RW" => "250", "SA" => "966", "SB" => "677", "SC" => "248", "SD" => "249", "SE" => "46", "SG" => "65", "SH" => "290", "SI" => "386", "SJ" => "47", "SK" => "421", "SL" => "232", "SM" => "378", "SN" => "221", "SO" => "252", "SO" => "252", "SR"  > "597", "ST" => "239", "SV" => "503", "SY" => "963", "SZ" => "268", "TA" => "290", "TC" => "649", "TD" => "235", "TG" => "228", "TH" => "66", "TJ" => "992", "TK" =>  "690", "TL" => "670", "TM" => "993", "TN" => "216", "TO" => "676", "TR" => "90", "TT" => "868", "TV" => "688", "TW" => "886", "TZ" => "255", "UA" => "380", "UG" =>  "256", "US" => "1", "UY" => "598", "UZ" => "998", "VA" => "379", "VC" => "784", "VE" => "58", "VG" => "284", "VI" => "340", "VN" => "84", "VU" => "678", "WF" => "681", "WS" => "685", "YE" => "967", "YT" => "262", "ZA" => "27","ZM" => "260", "ZW" => "263");
				return $arrCode[$code];
			} catch (exception $e) {}
			return "";
		}

		public function numPurchaseCustomer($id_customer,$valid=1,$interval=1,$intervalType="DAY") {
			global $wpdb;
			
			

			$table_prefix = $wpdb->prefix ? $wpdb->prefix : 'wp_';

			$date_now = new DateTime("now");
			$date_now = $date_now->format("Y-m-d h:m:s");
			
			if ($valid==1) {
				$post_status = implode("','", array('wc-processing', 'wc-completed') );
			} else {
				$post_status = implode("','", array('wc-processing', 'wc-completed', 'wc-pending') );
			}

			$result = $wpdb->get_row( "SELECT count(*) as num_orders FROM $wpdb->posts 
						WHERE post_type = 'shop_order'
						AND post_author = " . $id_customer . " 
						AND post_status IN ('{$post_status}')
						AND post_date > '".$date_now . "' -interval " . $interval . " " . $intervalType);

			
		
			return $result->num_orders;
			
		}

		public function firstAddressDelivery($id_customer,$order) {
			global $wpdb;
			
			$date_now = new DateTime("now");
			$date_now = $date_now->format("Y-m-d h:m:s");
			
			$post_status = implode("','", array('wc-processing', 'wc-completed') );
			

			$result = $wpdb->get_row( "SELECT * FROM " . $wpdb->posts . " p INNER JOIN " . $wpdb->postmeta . " pm on p.ID = pm.post_id 
						WHERE p.post_type = 'shop_order'
						AND p.post_author = " . $id_customer . " 
						AND p.post_status IN ('{$post_status}')
						AND p.ID < " . $order->get_id() . "
						AND pm.meta_key = '_shipping_address_1' and pm.meta_value = '" . $order->get_shipping_address_1() . "'
						order by p.post_date asc limit 1");
			if ($result) {
				return $result->post_date;
			} else {
				return "";
			}
			
		}

		public function acctInfo($order) {

			$acctInfoData = array();
			$date_now = new DateTime("now");

			$customer = wp_get_current_user();
	
			$isGuest = !is_user_logged_in();
			if ($isGuest){
				$acctInfoData["chAccAgeInd"] = "01";
				
			} else {
							
				$date_customer = new DateTime(strftime('%Y%m%d', strtotime($customer->user_registered)));
				
				$diff = $date_now->diff($date_customer);
				$dias = $diff->days;
				
				if ($dias==0) {
					$acctInfoData["chAccAgeInd"] = "02";
				} else if ($dias < 30) {
					$acctInfoData["chAccAgeInd"] = "03";
				} else if ($dias < 60) {
					$acctInfoData["chAccAgeInd"] = "04";
				} else {
					$acctInfoData["chAccAgeInd"] = "05";
				}
				
								
				$acctInfoData["chAccChange"] = date('Ymd', get_user_meta( get_current_user_id(), 'last_update', true ));

				$date_customer_upd = new DateTime();
				$date_customer_upd->setTimestamp(get_user_meta( get_current_user_id(), 'last_update', true ));

				$diff = $date_now->diff($date_customer_upd);
				$dias_upd = $diff->days;

				if ($dias_upd==0) {
					$acctInfoData["chAccChangeInd"] = "01";
				} else if ($dias_upd < 30) {
					$acctInfoData["chAccChangeInd"] = "02";
				} else if ($dias_upd < 60) {
					$acctInfoData["chAccChangeInd"] = "03";
				} else {
					$acctInfoData["chAccChangeInd"] = "04";
				}

				$acctInfoData["chAccDate"] = strftime('%Y%m%d', strtotime($customer->user_registered));
							
				$acctInfoData["nbPurchaseAccount"] = $this->numPurchaseCustomer(get_current_user_id(),1,6,"MONTH");
				
				$acctInfoData["txnActivityDay"] = $this->numPurchaseCustomer(get_current_user_id(),0,1,"DAY");				
				$acctInfoData["txnActivityYear"] = $this->numPurchaseCustomer(get_current_user_id(),0,1,"YEAR");

				if ( ($customer->first_name != $order->get_billing_first_name()) ||
				($customer->last_name != $order->get_billing_last_name())) { 
					$acctInfoData["shipNameIndicator"] = "02";
				} else {
					$acctInfoData["shipNameIndicator"] = "01";
				}
				
			}
			
			$firstAddressDelivery = $this->firstAddressDelivery(get_current_user_id(),$order);
			if ($firstAddressDelivery!="") {
				$acctInfoData["shipAddressUsage"] = date("Ymd",strtotime($firstAddressDelivery));

				$date_firstAddressDelivery = new DateTime(strftime('%Y%m%d', strtotime($firstAddressDelivery)));
				$diff = $date_now->diff($date_firstAddressDelivery);
				$dias_firstAddressDelivery = $diff->days;
				if ($dias_firstAddressDelivery==0) {
					$acctInfoData["shipAddressUsageInd"] = "01";
				} else if ($dias_upd < 30) {
					$acctInfoData["shipAddressUsageInd"] = "02";
				} else if ($dias_upd < 60) {
					$acctInfoData["shipAddressUsageInd"] = "03";
				} else {
					$acctInfoData["shipAddressUsageInd"] = "04";
				}
			}
			
			$acctInfoData["suspiciousAccActivity"] = "01";
	
			return $acctInfoData;
		}


		public function threeDSRequestorAuthenticationInfo() {
			
			$logged = is_user_logged_in();
	
			$threeDSRequestorAuthenticationInfo = array();
			$threeDSRequestorAuthenticationInfo["threeDSReqAuthData"] = "";
			$threeDSRequestorAuthenticationInfo["threeDSReqAuthMethod"] = ($logged)?"02":"01";
	
			return $threeDSRequestorAuthenticationInfo;
		}

		public function getEMV3DS($order) {

			$Merchant_EMV3DS = array();			

			$Merchant_EMV3DS["customer"]["id"] = get_current_user_id();
			$Merchant_EMV3DS["customer"]["name"] = $order->get_billing_first_name();
			$Merchant_EMV3DS["customer"]["surname"] = $order->get_billing_last_name();
			$Merchant_EMV3DS["customer"]["email"] = $order->get_billing_email();

			// Billing info
			$billing = $order->get_address('billing'); 
			if ($billing) {
					
				$Merchant_EMV3DS["billing"]["billAddrCity"] = $order->get_billing_city();								
				$Merchant_EMV3DS["billing"]["billAddrCountry"] = $order->get_billing_country();						
				if ($Merchant_EMV3DS["billing"]["billAddrCountry"]!="") {
					$Merchant_EMV3DS["billing"]["billAddrCountry"] = $this->isoCodeToNumber($Merchant_EMV3DS["billing"]["billAddrCountry"]);
				}
				$Merchant_EMV3DS["billing"]["billAddrLine1"] = $order->get_billing_address_1();						
				$Merchant_EMV3DS["billing"]["billAddrLine2"] = $order->get_billing_address_2();						
															
				$Merchant_EMV3DS["billing"]["billAddrPostCode"] = $order->get_billing_postcode();								

				$Merchant_EMV3DS["billing"]["billAddrState"] = $order->get_billing_state();

				$billAddState = explode("-",$order->get_billing_state());
				$billAddState = end($billAddState);
				$Merchant_EMV3DS["billing"]["billAddrState"] = $billAddState;

				
				if ($order->get_billing_phone()!="") {
					if ($order->get_billing_country()!="" && $this->isoCodePhonePrefix($order->get_billing_country())!="") {
						$arrDatosHomePhone["cc"] = $this->isoCodePhonePrefix($order->get_billing_country());
						$arrDatosHomePhone["subscriber"] = $order->get_billing_phone();
					
						$Merchant_EMV3DS["customer"]["homePhone"] = $arrDatosHomePhone;							
					}
				}
			}
			
			$shipping = $order->get_address('shipping'); 
			if ($shipping) {
			
				$Merchant_EMV3DS["shipping"]["shipAddrCity"] = $order->get_shipping_city();								
				$Merchant_EMV3DS["shipping"]["shipAddrCountry"] = $order->get_shipping_country();		
				if ($Merchant_EMV3DS["shipping"]["shipAddrCountry"]!="") {
					$Merchant_EMV3DS["shipping"]["shipAddrCountry"] = $this->isoCodeToNumber($Merchant_EMV3DS["shipping"]["shipAddrCountry"]);
				}
				$Merchant_EMV3DS["shipping"]["shipAddrLine1"] = $order->get_shipping_address_1();							
				$Merchant_EMV3DS["shipping"]["shipAddrLine2"] = $order->get_shipping_address_2();						
				$Merchant_EMV3DS["shipping"]["shipAddrPostCode"] = $order->get_shipping_postcode();						
				$Merchant_EMV3DS["shipping"]["shipAddrState"] = $order->get_shipping_state();	
				
			}
			
			// acctInfo
			$Merchant_EMV3DS["acctInfo"] = $this->acctInfo($order);
			
			
			// threeDSRequestorAuthenticationInfo
			$Merchant_EMV3DS["threeDSRequestorAuthenticationInfo"] = $this->threeDSRequestorAuthenticationInfo(); 

			// AddrMatch	
			$Merchant_EMV3DS["addrMatch"] = ( ($order->get_shipping_city() == $order->get_billing_city()) &&
											  ($order->get_shipping_country() == $order->get_billing_country()) &&
											  ($order->get_shipping_address_1() == $order->get_billing_address_1()) &&
											  ($order->get_shipping_address_2() == $order->get_billing_address_2()) )?"Y":"N";

			$Merchant_EMV3DS["challengeWindowSize"] = 05; 
				
			return $Merchant_EMV3DS;

		}

		public function getShoppingCart($order) {

			$shoppingCartData = array();

			// The loop to get the order items which are WC_Order_Item_Product objects since WC 3+
			foreach( $order->get_items() as $item_id => $item ){

				
				//Get the product ID
				$product_id = $item->get_product_id();

				//Get the WC_Product object
				$product = $item->get_product();

				$terms = get_the_terms( $product_id, 'product_cat' );
				// echo var_dump($terms);
				$arrCategories = array();
				foreach ( $terms as $term ) {
					// Categories by slug
					$arrCategories[] = $term->slug;
				}
			
				$shoppingCartData[$item_id]["sku"] = $product->get_sku();
				$shoppingCartData[$item_id]["quantity"] = $item->get_quantity();
				
				$shoppingCartData[$item_id]["unitPrice"] = number_format($product->get_price()*100, 0, '.', '');

				
				$shoppingCartData[$item_id]["name"] = $item->get_name();

				$shoppingCartData[$item_id]["category"] = implode("|",$arrCategories);
			}	

			return array("shoppingCart"=>array_values($shoppingCartData));

		}


		public function getMerchantData($order) {
			return null; // De momento no hacemos nada

			$MERCHANT_EMV3DS = $this->getEMV3DS($order);
			$SHOPPING_CART = $this->getShoppingCart($order);

			$datos = array_merge($MERCHANT_EMV3DS,$SHOPPING_CART);			

			return urlencode(base64_encode(json_encode($datos)));
		}

		function get_paytpv_bankstore_args( $order ) {

			// Obtenemos el terminal para el pedido
			$arrTerminalData = $this->TerminalCurrency($order);
			
			$importe = $arrTerminalData["importe"];
			$currency_iso_code = $arrTerminalData["currency_iso_code"];
			$term = $arrTerminalData["term"];
			$pass = $arrTerminalData["pass"];

			$secure_pay = $this->isSecureTransaction($order,$arrTerminalData,0,0)?1:0;

			$OPERATION = '1';
			//$URLOK		= add_query_arg('tpvLstr','notify',add_query_arg( 'wc-api', 'woocommerce_'. $this->id, home_url( '/' ) ) );
			$MERCHANT_ORDER = str_pad( $order->get_id(), 8, "0", STR_PAD_LEFT );
			$MERCHANT_AMOUNT = $importe;
			$MERCHANT_CURRENCY = $currency_iso_code;
			$URLOK = $this->get_return_url( $order );
			$URLKO = $order->get_cancel_order_url_raw();
			$paytpv_req_args = array( );
			$mensaje = $this->clientcode . $term . $OPERATION . $MERCHANT_ORDER . $MERCHANT_AMOUNT . $MERCHANT_CURRENCY;
			$MERCHANT_MERCHANTSIGNATURE = hash ('sha512', $mensaje . md5( $pass ) );

			
			
			$MERCHANT_DATA = $this->getMerchantData($order);

			$paytpv_args = array(
				'MERCHANT_MERCHANTCODE' => $this->clientcode,
				'MERCHANT_TERMINAL' => $term,
				'OPERATION' => $OPERATION,
				'LANGUAGE' => $this->_getLanguange(),
				'MERCHANT_MERCHANTSIGNATURE' => $MERCHANT_MERCHANTSIGNATURE,
				'MERCHANT_ORDER' => $MERCHANT_ORDER,
				'MERCHANT_AMOUNT' => $MERCHANT_AMOUNT,
				'MERCHANT_CURRENCY' => $MERCHANT_CURRENCY,
				'URLOK' => $URLOK,
				'URLKO' => $URLKO,
				'3DSECURE' => $secure_pay,
				''
			);

			if ($MERCHANT_DATA!=null)           $paytpv_args["MERCHANT_DATA"] = $MERCHANT_DATA;

			$query = http_build_query($paytpv_args);
			$vhash = hash('sha512', md5($query.md5($pass)));

			$paytpv_args["VHASH"] = $vhash;

			return array_merge( $paytpv_args, $paytpv_req_args );
		}

		
		
		function process_payment( $order_id ) {
			global $woocommerce;
			$this->write_log( 'Process payment: ' . $order_id );
			$order = new WC_Order($order_id);

			return array(
				'result' => 'success',
				'redirect'	=> $order->get_checkout_payment_url( true )
			);
		}	
		

		/**
		 * Safe transaction
		 * */

		public function isSecureTransaction($order,$arrTerminalData,$card,$paytpv_iduser){
			$importe = $order->get_total();

	        $terminales = $arrTerminalData["terminales"];
	        $tdfirst = $arrTerminalData["tdfirst"];
	        $tdmin = $arrTerminalData["tdmin"];
	        // Transaccion Segura:
	        
	        // Si solo tiene Terminal Seguro
	        if ($terminales==0)
	            return true;   

	        // Si esta definido que el pago es 3d secure y no estamos usando una tarjeta tokenizada
	        if ($tdfirst && $card==0){
	        	
	            return true;
	        }

	        // Si se supera el importe maximo para compra segura
	        if ($terminales==2 && ($tdmin>0 && $tdmin < $importe)){
	        	
	            return true;
	          }

	         // Si esta definido como que la primera compra es Segura y es la primera compra aunque este tokenizada
	        if ($terminales==2 && $tdfirst && $card>0 && $this->isFirstPurchaseToken($order->get_user_id(),$paytpv_iduser)){
	            return true;
	        }
	        
	        
	        return false;
	    }


	    /**
		 * Safe transaction
		 * */
	    public function isFirstPurchaseToken($id_customer,$paytpv_iduser){
	    	global $wpdb;
	    	$saved_card = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %d", 'PayTPV_IdUser', $paytpv_iduser ), ARRAY_A );

	        if ( null !== $saved_card ) {
			  return false;
			} else {
			  return true;
			}
	    }


		/**
		 * return array data of order currency
		 */
		public function TerminalCurrency($order){
			$order_currency = $order->get_currency();
			// PENDIENTE: Aqui habría que buscar un terminal en la moneda del pedido
			foreach ( $this->paytpv_terminals as $terminal){
				if ($terminal["moneda"]==$order_currency)
					$terminal_currency = $terminal;
			}

			// Not exists terminal in user currency
			if (empty($terminal_currency) === true){

				// Search for terminal in merchant default currency
				foreach ( $this->paytpv_terminals as $terminal){
					if ($terminal["moneda"]==get_woocommerce_currency())
						$terminal_currency = $terminal;
				}

				// If not exists terminal in default currency. Select first terminal defined
				if (empty($terminal_currency) === true){
					$terminal_currency = $this->paytpv_terminals[0];
				}
			}

			$arrTerminalData["term"] = $terminal_currency["term"];
			$arrTerminalData["pass"] = $terminal_currency["pass"];
			$arrTerminalData["terminales"] = $terminal_currency["terminales"];
			$arrTerminalData["tdfirst"] = $terminal_currency["dsecure"];
			$arrTerminalData["currency_iso_code"] = $terminal_currency["moneda"];
			$arrTerminalData["importe"] = number_format($order->get_total() * 100, 0, '.', '');
			$arrTerminalData["tdmin"] = $terminal_currency["tdmin"];
			
	        return $arrTerminalData;
		}

		/**
		 * receipt_page
		 * */
		function receipt_page( $order_id ) {			
			echo '<p>' . __( 'Thanks for your order, please fill the data below to process the payment.', 'wc_paytpv' ) . '</p>';

			echo $this->savedCardsHtml($order_id);
			
		}


		/**
		 * Html saved Cards 
		 */
		function savedCardsHtml($order_id){
			$order = new WC_Order( $order_id );
			$saved_cards = Paytpv::savedCards($order->get_user_id());
			$store_card = (sizeof($saved_cards)==0)?"":"";

			// Tarjetas almacenadas
			$store_card = (sizeof($saved_cards)==0)?"none":"";
			print '<form id="form_paytpv" method="post" action="'.add_query_arg(array("wc-api"=> 'woocommerce_' . $this->id)) . '" class="form-inline">
					<div id="saved_cards" style="display:'.$store_card.'">
	                    <div class="form-group">
	                        <label for="card">'.__('Card', 'wc_paytpv' ).':</label>
	                        <select name="card" id="card" onChange="checkCard()" class="form-control">';
                        	

        	
        	foreach ($saved_cards as $card){
        		$card_desc = ($card["card_desc"]!="")?(" - " . $card["card_desc"]):"";
        		print 		"<option value='".$card['id']."'>".$card["paytpv_cc"]. $card_desc. "</option>";

        	}
                            
            print '      <option value="0">'.__('NEW CARD', 'wc_paytpv' ).'</option></select>
                    </div>
                </div>';

            if (sizeof($saved_cards)>0){
	        					
				// Pago directo
				print  '<input type="submit" id="direct_pay" value="'.__( 'Pay', 'wc_paytpv' ).'" class="button alt">';
				print  '<img src="'.PAYTPV_PLUGIN_URL . 'images/clockpayblue.gif" alt="'.__( 'Wait, please...', 'wc_paytpv' ).'" width="41" height="30" id="clockwait" style="display:none; margin-top:5px;" />';
				print '<input type="hidden" name="tpvLstr" value="pay">';
				
			}
			print '<input type="hidden" id="order_id" name="Order" value="'.$order_id.'">';



			// Comprobacion almacenar tarjeta
			if ($order->get_user_id()>0 && $this->disable_offer_savecard==0){
				print '
				<div id="storingStep" class="box" style="display:'.$store_card.'">
	                <h4>'.__('STREAMLINE YOUR FUTURE PURCHASES!', 'wc_paytpv' ).'</h4>
	                <label class="checkbox"><input type="checkbox" name="savecard" id="savecard" onChange="saveOrderInfoJQ()" checked>'.__('Yes, remember my card accepting the', 'wc_paytpv' ).' <a id="open_conditions" href="#conditions" class="link"> '.__('terms and conditions of the service', 'wc_paytpv' ).'.</a>.</label>';
	        }else{
	        	print '<div id="ifr-paytpv-container" class="box">';
	        }
	        print  $this->generate_paytpv_form( $order_id );
            print '</div>';

            print '</form>';

            wc_get_template( 'myaccount/conditions.php', array( ), '', PAYTPV_PLUGIN_DIR . 'template/' );



		}

		public function getIframeUrl($dsecure){
			return $this->iframeurl . "?";			
		}


		/**
		 * Generate the paytpv button link
		 * */
		function generate_paytpv_form( $order_id ) {
			global $woocommerce;

			$order = new WC_Order( $order_id );
			$paytpv_args = $this->get_paytpv_args( $order );

			$iframe_url = $this->getIframeUrl(0);
			

			$html = '';
			$html .= '<iframe class="ifr-paytpv" id="paytpv_iframe" src="' . $iframe_url . '' . http_build_query( $paytpv_args ) . '"
	name="paytpv" style="width: 670px; border-top-width: 0px; border-right-width: 0px; border-bottom-width: 0px; border-left-width: 0px; border-style: initial; border-color: initial; border-image: initial; height: 340px; " marginheight="0" marginwidth="0" scrolling="no"></iframe>';
			
			return $html;
			
		}

		function get_client() {
			if ( !isset( $this->ws_client ) ) {
				require_once PAYTPV_PLUGIN_DIR . '/ws_client.php';
				$this->ws_client = new WS_Client( $this->settings );
			}
			return $this->ws_client;
		}


		public function saveCard($order,$user_id,$paytpv_iduser,$paytpv_tokenuser,$TransactionType){
			// Si es una operción de add_user o no existe el token asociado al usuario lo guardamos
			if ($TransactionType==107 || !PayTPV::existsCard($paytpv_iduser,$user_id)){
				
				if ($order!=null){
					// Obtenemos el terminal para el pedido
					$arrTerminalData = $this->TerminalCurrency($order);
				}else{
					$arrTerminalData = $this->paytpv_terminals[0];
				}
				
				$term = $arrTerminalData["term"];
				$pass = $arrTerminalData["pass"];
				

				$client = $this->get_client();
				$result = $client->info_user( $paytpv_iduser, $paytpv_tokenuser, $term, $pass);
			

				return PayTPV::saveCard($user_id,$paytpv_iduser,$paytpv_tokenuser,$result['DS_MERCHANT_PAN'],$result['DS_CARD_BRAND']);

			}else{
				$result["paytpv_iduser"] = $paytpv_iduser;
				$result["paytpv_tokenuser"] = $paytpv_tokenuser;
				return $result;
			}
			
		}

		/**
		 * Operaciones sucesivas
		 * */
		
		function scheduled_subscription_payment( $amount_to_charge, $order ) {
			

			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
			$subscription  = array_pop( $subscriptions );
			if ( false == $subscription->get_parent_id() ) { // There is no original order
				$parent_order = null;
			} else {
				
				$importe =  number_format($amount_to_charge * 100, 0, '.', '');

				// Obtenemos el terminal para el pedido
				$arrTerminalData = $this->TerminalCurrency($order);
				$currency_iso_code = $arrTerminalData["currency_iso_code"];
				$term = $arrTerminalData["term"];
				$pass = $arrTerminalData["pass"];
				
				$parent_order = $subscription->get_parent();

				$orders = $subscription->get_related_orders('ids','parent');
				$num_orders = sizeof($orders);

				$paytpv_order_ref = $order->get_id();
				
				$payptv_iduser = get_post_meta( ( int ) $parent_order->get_id(), 'PayTPV_IdUser', true );
				$payptv_tokenuser = get_post_meta( ( int ) $parent_order->get_id(), 'PayTPV_TokenUser', true );
						
				$MERCHANT_DATA = $this->getMerchantData($order);

				$client = $this->get_client();
				
				// $result = $client->execute_purchase( $order,$payptv_iduser,$payptv_tokenuser,$term,$pass,$currency_iso_code,$importe,$paytpv_order_ref,'MIT','R',$MERCHANT_DATA);
				$result = $client->execute_purchase( $order,$payptv_iduser,$payptv_tokenuser,$term,$pass,$currency_iso_code,$importe,$paytpv_order_ref,'','','');


				if ( ( int ) $result[ 'DS_RESPONSE' ] == 1 ) {
					update_post_meta($order->get_id(), 'PayTPV_Referencia', $result[ 'DS_MERCHANT_ORDER' ]);
					update_post_meta($order->get_id(), '_transaction_id', $result['DS_MERCHANT_AUTHCODE']);	
					update_post_meta($order->get_id(), 'PayTPV_IdUser', $payptv_iduser);
					update_post_meta($order->get_id(), 'PayTPV_TokenUser', $payptv_tokenuser);

					WC_Subscriptions_Manager::process_subscription_payments_on_order( $order );
				}

			}

		}

		
		function store_renewal_order_id( $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role ) {
			if ( 'parent' == $new_order_role )
				$order_meta_query .= " AND `meta_key` NOT LIKE 'PayTPV_IdUser' "
						. " AND `meta_key` NOT LIKE 'PayTPV_TokenUser' "
						. " AND `meta_key` NOT LIKE 'PayTPV_Referencia' ";

			return $order_meta_query;
		}

		/**
		 * Can the order be refunded via PayTPV?
		 * @param  WC_Order $order
		 * @return bool
		 */
		public function can_refund_order( $order ) {
			return $order && $order->get_transaction_id() && get_post_meta( ( int ) $order->get_id(), 'PayTPV_IdUser', true );
		}

		
		/**
		 * Process a refund if supported
		 * @param  int $order_id
		 * @param  float $amount
		 * @param  string $reason
		 * @return  boolean True or false based on success, or a WP_Error object
		 */
		public function process_refund( $order_id, $amount = null, $reason = '' ) {
			

			$order = wc_get_order( $order_id );

			if ( ! $this->can_refund_order( $order ) ) {
				$this->write_log( 'Refund Failed: No transaction ID' );
				return false;
			}

			
			$client = $this->get_client();
			// Obtenemos el terminal para el pedido
			$arrTerminalData = $this->TerminalCurrency($order);
			$currency_iso_code = $arrTerminalData["currency_iso_code"];
			$term = $arrTerminalData["term"];
			$pass = $arrTerminalData["pass"];

			$importe = number_format($amount * 100, 0, '.', '');

			$paytpv_order_ref = get_post_meta( ( int ) $order->get_id(), 'PayTPV_Referencia', true );
			$payptv_iduser = get_post_meta( ( int ) $order->get_id(), 'PayTPV_IdUser', true );
			$payptv_tokenuser = get_post_meta( ( int ) $order->get_id(), 'PayTPV_TokenUser', true );
			$transaction_id = $order->get_transaction_id();



			$result = $client->execute_refund($payptv_iduser, $payptv_tokenuser, $paytpv_order_ref, $term,$pass,$currency_iso_code,  $transaction_id, $importe);
			
			if ( ( int ) $result[ 'DS_RESPONSE' ] != 1 ) {
				$this->write_log( 'Refund Failed: ' . $result[ 'DS_ERROR_ID' ] );
				return false;
			}else{
				$order->add_order_note( sprintf( __( 'Refunded %s - Refund ID: %s', 'woocommerce' ), $amount, $result['DS_MERCHANT_AUTHCODE'] ) );
				return true;
			}

		}

		
	}
