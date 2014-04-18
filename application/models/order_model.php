<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Order Model
 *
 * This class handles the database queries relating to orders.
 * 
 * @package		BitWasp
 * @subpackage	Models
 * @category	Order
 * @author		BitWasp
 * 
 */
class Order_model extends CI_Model {
	
	/**
	 * Constructor
	 *
	 * @access	public
	 * @see		Models/Items_Model
	 */		
	public function __construct() {
		parent::__construct();
		$this->load->model('items_model');
	}
	
	/**
	 * Add
	 * 
	 * Adds an order to the database. Columns are specified by array keys.
	 * Returns a boolean.
	 * 
	 * @param	array	$order
	 * @return	bool
	 */
	public function add($order) {
		$order['time'] = time();
		$order['created_time'] = time();
		return ($this->db->insert('orders', $order) == TRUE) ? TRUE : FALSE;
	}
	
	/**
	 * My Orders
	 * 
	 * Loads the current vendors orders.
	 * Returns an array on success and FALSE on failure.
	 * 
	 * @return	array/FALSE
	 */
	public function vendor_orders() {
		$this->db->where('vendor_hash', $this->current_user->user_hash);
		$this->db->where('progress >','0');
		$this->db->order_by('progress ASC, time desc');
		$query = $this->db->get('orders');
		if($query->num_rows() > 0) {
			$row = $query->result_array();
			return $this->build_array($row);
		} else {
			return array();
		}
	}
	
	/**
	 * My Purchases
	 * 
	 * Returns the current buyers purchases on success, and FALSE if there
	 * are none.
	 * 
	 * @return	array/FALSE
	 */
	public function buyer_orders() {
		$this->db->where('buyer_id', $this->current_user->user_id)
				 ->order_by('progress asc, time desc');
		$query = $this->db->get('orders');
		return ($query->num_rows() > 0) ? $this->build_array($query->result_array()) : array();
		
	}
	
	/**
	 * Load
	 * 
	 * Buyer can load an order about them, as specified by $vendor_hash,
	 * and a progress $progress.
	 * This is needed when the buyer is making a purchase with a vendor, 
	 * to see if any order exists already.
	 * 
	 * @param	string	$vendor_hash
	 * @param	int		$progress
	 * @return	array/FALSE
	 */
	public function load($vendor_hash, $progress) {
		$this->db->where('vendor_hash', $vendor_hash);
		$this->db->where('buyer_id', $this->current_user->user_id);
		$this->db->where('progress', $progress);
		$query = $this->db->get('orders');
		$result = $this->build_array($query->result_array());
		return $result[0];
	}

	/**
	 * Load Order
	 * 
	 * Load an order, specified by it's $id, and the current $progress.
	 * Calculates whether it's a buyer or a vendor who is making the
	 * request.
	 * 
	 * @param	int	$id
	 * @param	array	$allowed_progress
	 * @return	array/FALSE
	 */
	public function load_order($id, $allowed_progress = array()) {
		switch($this->current_user->user_role) {
			case 'Vendor':
				$this->db->where('vendor_hash', $this->current_user->user_hash);
				break;
			case 'Buyer':
				$this->db->where('buyer_id', $this->current_user->user_id);
				break;
			default:
				return FALSE;
				break;
		}
		$this->db->where('id', "$id");
		$query = $this->db->get('orders');
		if($query->num_rows() > 0) {
			$result = $query->result_array();$i = 1;
			foreach($result as $res) {
				if($this->general->matches_any($res['progress'], $allowed_progress) == TRUE) {
					$row = $this->build_array($query->result_array());
					return $row[0];
				}
			}
		}
		return FALSE;
	}

	/**
	 * Get
	 * 
	 * Loads an order by it's $order_id. Does not require the user to
	 * be a specific role.
	 * 
	 * @param	int	$order_id
	 * @return	array/FALSE
	 */
	public function get($order_id) {
		$this->db->where('id', $order_id);
		$query =$this->db->get('orders');
		if($query->num_rows() > 0) {
			$row = $this->build_array($query->result_array());
			return $row[0];
		}
		return FALSE;
	}

	/**
	 * Delete
	 * 
	 * Deletes an order as specified by it's $order_id. Does not
	 * require that the user has a specific role.
	 * Returns a boolean.
	 * 
	 * @param	int	$order_id
	 * @return	bool
	 */
	public function delete($order_id) {
		$this->db->where('id', $order_id);
		return  ($this->db->delete('orders') == TRUE) ? TRUE : FALSE;
	}
	
	/**
	 * Update Items
	 * 
	 * Updates the items in $order_it, as specified by $update.
	 * If $act == 'update' then we update the order with the new $update['quantity'],
	 * otherwise it's creating the item in the order.
	 * 
	 * @param	int	$order_id
	 * @param	array	$update
	 * @param	string	$act
	 * @return	bool
	 */
	public function update_items($order_id, $update, $act = 'update') {
		
		$order_info = $this->get($order_id);
		if($order_info == FALSE)
			return FALSE;
			
		$found_item = FALSE;	
		$item_string = '';
		$place = 0;
		
		// Process items already on the order.
		foreach($order_info['items'] as $item) {
			if($item['hash'] == $update['item_hash']) {
				$found_item = TRUE;
				$quantity = ($act == 'update') ? ($item['quantity']+$update['quantity']) : ($update['quantity']);
			} else {
				$quantity = $item['quantity'];
			}
			
			if($quantity > 0) {
				if($place++ !== 0)		$item_string .= ":";
					
				$item_string .= $item['hash']."-".$quantity;
			}
		}
		// If we haven't encountered the item on the list, add it now.
		if($found_item == FALSE) {
			if($update['quantity'] > 0)
				$item_string .= ":".$update['item_hash']."-".$update['quantity'];
		}
		
		// Delete order if the item_string is empty.
		if(empty($item_string)) {
			$this->delete($order_id);
			return TRUE;
		}
			
		$order = array(	'items' => $item_string,
						'price' => $this->calculate_price($item_string),
						'time' => time());
						
		$this->db->where('id', $order_id)
				 ->where('progress', '0');
		return ($this->db->update('orders', $order) == TRUE)  ? TRUE : FALSE;
		
	}
	
	/**
	 * Set User Public Key
	 * 
	 * This function will set the {$user_type}_public_key for $order_id, 
	 * to the supplied $public_key.
	 * 
	 * @param	int	$order_id
	 * @param	string	$user_type
	 * @param	string	$public_key
	 * @return	boolean
	 */
	public function set_user_public_key($order_id, $user_type, $public_key) {
		$user_type = strtolower($user_type);
		if(!in_array($user_type, array('buyer','vendor','admin')))
			return FALSE;
		$index = $user_type.'_public_key';
		$update = array($index => $public_key);
		return $this->update_order($order_id, $update);
	}
	
	
	/**
	 * Send Order Message
	 * 
	 * Sends a message to $recipient - the vendors name. The $order_id is 
	 * specified, as well as the $message and $subject.
	 */
	public function send_order_message($order_id, $recipient, $subject, $message) {
		$this->load->library('bw_messages');
		$this->load->model('messages_model');
		$this->load->model('accounts_model');
		
		$admin = $this->accounts_model->get(array('user_name' => 'admin'));
		$details = array(	'username' => $recipient,
							'subject' => $subject,
							'message' => $message);
		$message = $this->bw_messages->prepare_input(array('from' => $admin['id']), $details);
		$message['order_id'] = $order_id;
		$this->messages_model->send($message);
					
	}
	
	/**
	 * Get Order By Address
	 * 
	 * Loads order details when given a multisig $address. Returns FALSE
	 * if no such order exists, otherwise returns the order array.
	 * 
	 * @param	string	$address
	 * @return	array/FALSE
	 */
	public function get_order_by_address($address) {
		$this->db->where('address', $address);
		$query = $this->db->get('orders');
		if($query->num_rows() == 0){
			return FALSE;
		} else {
			$build = $this->build_array($query->result_array());
			return $build[0];
		}
	}
	
	/**
	 * Calculate Price
	 * 
	 * Recalculates the price based on an order's item string.
	 * 
	 * @param	string	$item_string
	 * @return	int	
	 */
	public function calculate_price($item_string) {
		$array = explode(":", $item_string);
		$price = 0;
		foreach($array as $item_code) {
			$info = explode("-", $item_code);
			$quantity = $info[1];
			$item_info = $this->items_model->get($info[0]);
			$price +=  $quantity*$item_info['price_b'];
		}
		
		return $price;
	}

	/**
	 * Increase Users Order Count
	 * 
	 * Takes an $order_id, and increases the buyer/vendors order count.
	 * 
	 * @param	int	$order_id
	 */
	public function increase_users_order_count($order_id){
		$this->load->model('users_model');
		
		$order = $this->get($order_id);
		$this->users_model->increase_order_count($order['buyer']['id']);
		$this->users_model->increase_order_count($order['vendor']['id']);
	}

	/**
	 * Order Paid Callback
	 * 
	 * Loads orders marked as paid, and generates the transaction 
	 * for each. This is done at the end of the callback function, since 
	 * it will have prepared all the information in the payments table.
	 * 
	 */
	public function order_paid_callback() {
		$query = $this->db->get('paid_orders_cache');
		$paid = $query->result_array();
			
		$this->load->model('transaction_cache_model');
		$this->load->model('accounts_model');			
		$this->load->model('currencies_model');
		$coin = $this->currencies_model->get('0');		
		$this->load->library('bw_bitcoin');
		$this->load->library('BitcoinLib');
		$this->load->library('bw_transaction');

		foreach($paid as $record) {
			// Check that the bitcoin daemon is active before creating a transaction.
			// This will preserve the paid_orders information until it's on.
			if(!is_array($this->bw_bitcoin->getinfo()))
				break;
				
			$order = $this->get($record['order_id']);
			$vendor_address = BitcoinLib::public_key_to_address($order['vendor_public_key'], $coin['crypto_magic_byte']);
			$admin_address = BitcoinLib::public_key_to_address($order['admin_public_key'], $coin['crypto_magic_byte']);

			// Load inputs
			$payments = $this->transaction_cache_model->payments_to_address($order['address']);

			// Create the transaction inputs
			$tx_ins = array();
			$value = 0.00000000;
			foreach($payments as $pmt) {
				$tx_ins[] = array(	'txid' => $pmt['tx_id'],
									'vout' => $pmt['vout']);
				$value += (float)$pmt['value'];
			}
			
			// Create the transaction outputs
			$tx_outs = array(	$admin_address => (float)($order['fees']+$order['extra_fees']-0.0001),
								$vendor_address => (float)($order['price']+$order['shipping_costs']-$order['extra_fees'])
							);
							
			// Store json inputs.
			$json = $this->bw_bitcoin->get_inputs_pkscripts($tx_ins);
			foreach($json as &$ref) {
				$ref['redeemScript'] = $order['redeemScript'];
			}
			$json = json_encode($json);
			
			$raw_transaction = Raw_transaction::create($tx_ins, $tx_outs);
			if($raw_transaction == FALSE) {
				echo 'error :(';
			} else {
				$decoded_transaction = Raw_transaction::decode($raw_transaction);
				$this->transaction_cache_model->log_transaction($decoded_transaction['vout'], $order['address'], $order['id']);
				$update = array('unsigned_transaction' => $raw_transaction." ",
								'json_inputs' => "'$json'",
								'paid_time' => time());
				
				$next_progress = ($order['vendor_selected_escrow'] == '1') ? '4' : '3';
				$this->progress_order($order['id'], '2', $next_progress, $update);
			}				
			$this->transaction_cache_model->delete_finalized_record($order['id']);
		}
	}
	
	/**
	 * Order Finalized Callback
	 * 
	 * This function is called with an array of information when an input
	 * in an order has been spent. If this happens, it either corresponds
	 * to an escrow or up-front payment going through. We need to check
	 * that the spend was expected - matches a hash of the expected
	 * outcome for that input we store on transaction creation.
	 * It can also happen when the order is disputed - in which case it 
	 * simply progresses to complete.
	 * 
	 * Updates order information where necessary.
	 */
	public function order_finalized_callback($array) {

		$this->load->model('disputes_model');
		foreach($array as $record) {
			$order = $this->get_order_by_address($record['address']);
			
			$complete = false;
			// If progress is 6, then a disputed order is completed.
			if($order['progress'] == '6') {
				if($this->progress_order($order['id'], '6', '7') == TRUE) {
					$dispute_update = array('posting_user_id' => '',
											'order_id' => $order_id,
											'dispute_id' => $data['dispute']['id'],
											'message' => 'Dispute closed, payment was broadcast.');
					$this->disputes_model->post_dispute_update($dispute_update);
					// Set final response. Prevents further posts in the 
					// dispute. This is the only way an escrow dispute
					// can be finalized. 
					$this->disputes_model->set_final_response($order['id']);
					
					$complete = true;
				}
				
			}  else {
				// Otherwise, progress depending on whether the transaction is escrow, or upfront.
				
				// Escrow
				if($order['vendor_selected_escrow'] == '1') {
					$update = array('received_time' => time());
					if($this->progress_order($order['id'], '5', '7', $update) == TRUE)
						$complete = true;
				}
				
				// Upfront payment. Vendor takes money to confirm dispatch.
				if($order['vendor_selected_escrow'] == '0') {
					$update = array('dispatched_time' => time(),
									'dispatched' => '1');
					if($this->progress_order($order['id'], '4', '5', $update) == TRUE)
						$complete = true;
				} 
			}
			
			// If complete, then record the details.
			if($complete) {
				$update = array('finalized' => '1',
								'finalized_time' => time(),
								'final_transaction_id' => $record['final_id']);
				if($record['valid'] == TRUE)
					$update['finalized_correctly'] = '1';
				$this->update_order($order['id'], $update);
			}
		}
	}
	
	public function update_order($order_id, array $update = array()) {
		if(count($update) == 0)
			return FALSE;
			
		$this->db->where('id', $order_id);
		
		return ($this->db->update('orders', $update) == TRUE) ? TRUE : FALSE;
	}
	
	/**
	 * Set Last Updated Time
	 */
	public function set_last_updated_time($order_id) {
		$this->db->where('id', $order_id);
		return ($this->db->update('orders', array('time' => time())) == TRUE) ? TRUE : FALSE;
	}
	
	/**
	 * Progress Order
	 * 
	 * Used to progress an order by $order_id. Can either be a vendor or a buyer.
	 * Controls the flow of the order.
	 * 
	 * @param	int	$order_id
	 * @param	int	$current_progress
	 * @param	int	$set_progress
	 * @return	bool
	 * 
	 */
	public function progress_order($order_id, $current_progress, $set_progress = 0, array $changes = array()) {
		$current_order = $this->get($order_id);
		
		if($current_order == FALSE || (isset($current_order['progress']) && $current_order['progress'] !== $current_progress))
			return FALSE;
			
		$update['time'] = time();
		if($current_progress == '2' && in_array($set_progress, array('3','4'))) {
			$update['progress'] = ($set_progress == '3') ? '3' : '4';
		} else if($current_progress == '3' && $set_progress == '6') {
			$update['progress'] = '6';
		} else if($current_progress == '4' && $this->general->matches_any($set_progress, array('5','6')) == TRUE) {
			$update['progress'] = ($set_progress == '5') ? '5' : '6';
		} else if($current_progress == '5' && $this->general->matches_any($set_progress, array('6','7')) == TRUE) {
			$update['progress'] = ($set_progress == '6') ? '6' : '7';			
		} else {
			$update['progress'] = ($current_progress+1);
		}
		$update['time'] = time();
		$this->db->where('id', $order_id);
		if($this->db->update('orders', $update) == TRUE) {
			if($update['progress'] == '7') {
				$this->increase_users_order_count($order_id);
				$this->load->model('review_auth_model');
				$this->review_auth_model->issue_tokens_for_order($order_id);
			}
			
			$this->update_order($order_id, $changes);
			return TRUE;
		} else {
			return FALSE;
		}
	}
	
	/**
	 * Build Array
	 * 
	 * Used to build an array of orders into a more readable array.
	 * Contains information about the vendor, the items (removes vendor
	 * entry from each item).
	 * 
	 * @param	array $orders
	 * return 	array/FALSE
	 * 
	 * 					fix 	me
	 */
	public function build_array($orders ) {
		$this->load->model('currencies_model');
		if(count($orders) > 0) {
			$i = 0;
			$item_array = array();
			
			// Loop through each order.
			foreach($orders as $order) {
				// Extract product hash/quantities.
				$items = $order['items'];
				$items = explode(":", $items);
				$j = 0;
				
				$price_b = 0.00000000;
				$price_l = 0.00000000;
				foreach($items as $item) {
					// Load each item & quantity.
					$array = explode("-", $item);
					$item_info = $this->items_model->get($array[0]);
					$quantity = $array[1];
					
					// If the item no longer exists, display a message.
					if($item_info == FALSE) {
						$message = "Item ";
						$message .= (strtolower($this->current_user->user_role) == 'vendor') ? 'has been removed' : 'was removed, contact your vendor' ;
						$item_array[$j] = array('hash' => 'removed',
												'name' => $message);
					} else {
						// Remove the vendor array, reduces the size of responses.
						unset($item_info['vendor']);
						$item_array[$j] = $item_info;

						// Convert from whatever currency the item's price is in
						// to bitcoin, and add this up. Convert to local currency later.
						$price_b_tmp = $item_info['price']/$item_info['currency']['rate'];
						$price_b += $price_b_tmp*$quantity;
					}			
					$item_array[$j++]['quantity'] = $quantity;					
				}
				
				// Determine the progress message. Contains a status update
				// for the order, and lets the user progress to the next step.
				switch($order['progress']) {
					case '0':	// Buyer choses items. (1)
						$buyer_progress_message = '<input type="submit" class="btn btn-mini" name="recount['.$order['id'].']" value="Update" /> <input type="submit" class="btn btn-mini" name="place_order['.$order['id'].']" value="Proceed with Order" />';
						$vendor_progress_message = '';
						// no vendor progress message
						break;
					case '1':	// Vendor must chose escrow, or up-front. (2)
						$buyer_progress_message = 'Awaiting vendor response. <input type="submit" class="btn btn-mini" name="cancel['.$order['id'].']" value="Cancel" /> ';
						$vendor_progress_message = anchor('orders/accept/'.$order['id'], 'Accept Order', 'class="btn btn-mini"');
						break;
					case '2':	// Buyer must pay to address. Escrow: 4. Upfront: 3.
						$buyer_progress_message = 'Pay to address. ';
						$vendor_progress_message = 'Waiting for buyer to pay to the order address. <input type="submit" class="btn btn-mini" name="cancel['.$order['id'].']" value="Cancel" /> ';
						break;
					case '3':	// An up-front payment. Buyer signs first.
						$buyer_progress_message = "Please sign transaction.";
						$vendor_progress_message = "Waiting on buyer to sign";
						break;
					case '4':	// Awaiting dispatch. Vendor must sign to indicate dispatch. (5)
						$buyer_progress_message = "Awaiting Dispatch. ".anchor('purchases/dispute/'.$order['id'], 'View Dispute', 'class="btn btn-mini"');
						$vendor_progress_message= "Sign transaction to confirm the items dispatch."; 
						break;
					case '5':	// Awaiting delivery. Escrow: buyer finalizes or disputes. 
								// Upfront: buyer can dispute or mark received.
						$buyer_progress_message = 'Order dispatched. '.(($order['vendor_selected_escrow'] == '0') ? '<input type="submit" name="received['.$order['id'].']" value="Confirm Receipt" class="btn btn-mini" /> or ' : 'Sign when the order is received, or ').anchor('purchases/dispute/'.$order['id'], 'Raise Dispute', 'class="btn btn-mini"');
						$vendor_progress_message = 'Buyer awaiting delivery. '.anchor('orders/dispute/'.$order['id'], 'Raise Dispute', 'class="btn btn-mini"');
						break;
					case '6':	// Disputed transaction.
						$buyer_progress_message = "Disputed transaction. ".anchor('purchases/dispute/'.$order['id'], 'View Dispute', 'class="btn btn-mini"');
						$vendor_progress_message = "Disputed transaction. ".anchor('orders/dispute/'.$order['id'], 'View Dispute', 'class="btn btn-mini"');
						break;
					case '7':
						$buyer_progress_message = "Purchase complete.";
						$vendor_progress_message = "Order complete.";
						break;
				}
				$currency = $this->currencies_model->get($order['currency']);

				// Work out what price to display for the current user.
				($this->current_user->user_role == 'Vendor') ? $order_price = ($order['price']+$order['shipping_costs']-$order['extra_fees']) : $order_price = ($order['price']+$order['shipping_costs']+$order['fees']);
				
				$order_price = ($currency['id'] !== '0') ? $order_price/$currency['rate'] : number_format($order_price,8);
				
				// Load the users local currency.
				$local_currency = $this->currencies_model->get($this->current_user->currency['id']);
				// Convert the order's price into the users own currency.
				$price_l = ($order_price*$local_currency['rate']);
				$price_l = ($this->current_user->currency['id'] !== '0') ? number_format($price_l, 2) : number_format($price_l, 8);
				
				// Add extra details to the order.
				$tmp = $order;
				$tmp['vendor'] = $this->accounts_model->get(array('user_hash' => $order['vendor_hash']));
				$tmp['buyer'] = $this->accounts_model->get(array('id' => $order['buyer_id']));
				$tmp['items'] = $item_array;
				$tmp['order_price'] = $order_price;
				$tmp['price_l'] = $price_l;
				$tmp['currency'] = $currency;
				$tmp['time_f'] = $this->general->format_time($order['time']);
				$tmp['created_time_f'] = $this->general->format_time($order['created_time']);		// 0
				$tmp['confirmed_time_f'] = $this->general->format_time($order['confirmed_time']);	// 2
				$tmp['paid_time_f'] = $this->general->format_time($order['paid_time']);				// 3
				$tmp['dispatched_time_f'] = $this->general->format_time($order['dispatched_time']); // 5
				$tmp['disputed_time_f'] = $this->general->format_time($order['disputed_time']);		// 6
				$tmp['finalized_time_f'] = $this->general->format_time($order['dispatched_time']);	// 7
				$tmp['progress_message'] = ($this->current_user->user_role == 'Vendor') ? $vendor_progress_message : $buyer_progress_message;
				
				$orders[$i++] = $tmp;
				unset($item_array);
				unset($tmp);
			}
			return $orders;
			
		} else {
			return FALSE;
		}
	}

	public function buyer_cancel($order_id) {
		$changes = array(	'progress' => '0',
							'shipping_costs' => 0.00000000,
							'fees' => 0.00000000,
							'confirmed_time' => '',
							'buyer_public_key' => '');
		$this->db->where('id', $order_id);
		return ($this->db->update('orders', $changes) == TRUE) ? TRUE : FALSE;
	}

	public function vendor_cancel($order_id) {
		$changes = array(	'progress' => '0',
							'shipping_costs' => 0.00000000,
							'fees' => 0.00000000,
							'extra_fees' => 0.00000000,
							'selected_payment_type_time' => '',
							'buyer_public_key' => '',
							'vendor_public_key' => '',
							'admin_public_key' => '',
							'address' => '',
							'redeemScript' => '',
							'confirmed_time' => '');
		$this->db->where('id', $order_id);
		return ($this->db->update('orders', $changes) == TRUE) ? TRUE : FALSE;
	}

	/**
	 * Admin Orders By Progress
	 * 
	 * This function is used by autorun jobs. Loads all orders which have 
	 * progress=$progress, and finalized=$finalized.  Returns a n
	 * multidimensional array if any records exist, or FALSE on failure.
	 * 
	 * @param	int	$progress
	 * @param	int	$finalized
	 * @return	array/FALSE
	 */
	public function admin_orders_by_progress($progress, $finalized) {
		$this->db->where('progress', "$progress");
		$this->db->where('finalized', "$finalized");
		$query = $this->db->get('orders');
		return ($query->num_rows() > 0) ? $query->result_array() : FALSE;
		
	}
	
	/**
	 * Admin Set Progress
	 * 
	 * This function is used by autorun jobs to set the progress of
	 * the order $order_id to $progress. Unlike the normal progress_order()
	 * function, which requires the current progress and calculates the next
	 * progress number accordingly, this function can arbitrarily set
	 * an order to any stage in the order process.
	 * 
	 * @param	int	$order_id
	 * @param	int	$progress
	 * @return	boolean
	 */
	public function admin_set_progress($order_id, $progress) {
		$this->db->where('id', "$order_id");
		return ($this->db->update('orders', array('progress' => $progress)) == TRUE) ? TRUE : FALSE;
	}

	public function admin_count_orders() {
		$this->db->select('id');
		$this->db->from('orders');
		$this->db->where('progress >','0');
		return $this->db->count_all_results();
	}
	public function admin_order_page($per_page, $start) {
		$this->db->where('progress >','0');
		$this->db->limit($per_page, $start);
		$this->db->order_by('id', 'desc');
		$get = $this->db->get('orders');
		return $this->build_array($get->result_array());
	}
	public function admin_order_details($order_id) {
		$this->db->where('progress >','0');
		$this->db->where('id', "$order_id");
		$query = $this->db->get('orders');
		if($query->num_rows() == 0){
			return FALSE;
		} else {
			return $this->build_array($query->result_array());
		}
			
	}


};

/* End Of File: order_model.php */
