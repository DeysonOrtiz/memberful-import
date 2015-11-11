<?php
/**
 * Plugin Name: MMT Memberful import
 */


if ( isset($_GET['mmt-memberful-import']) )
{
	
	add_action('wp', 'memberful_import');
}


function memberful_import ()
{
	if ( !is_user_logged_in() ) return;
	$now_dt = new DateTime();

	$current_dir = dirname(__FILE__);

	if ( !is_file($current_dir . '/memberful.json') ) exit('file not found. make sure you put the memberful export in the plugin directory and name it memberful.json');



	// get memberful export
	$json = file_get_contents($current_dir . '/memberful.json');

	// convert to an array
	$member_data = json_decode($json);



	$i = 0;
	foreach ($member_data as $member)
	{
		// 1. Loop through each customer in the export file and detect if a WordPress user account needs to be created.

		// The get_user_by( 'email', $email ) function can be used for this.

		$user_test = get_user_by( 'email', $member->email);


		echo $member->email . "\n<br />";



		if ( !empty($user_test) )
		{
			echo "- already imported<i>[Inspect to see member data]<!-- member:\n";
			print_r($member);
			echo "\n--></i>\n";

			$user_id = $user_test->ID;
		}
		else
		{
			// If a user does not exist, create one using the wp_insert_user() function.
			echo "-> Adding <i>[Inspect to see member data]<!-- member:\n";
			print_r($member);
			echo "\n--></i>\n";



			$created_dt = new DateTime($member->created_at);


			$userdata = array(
			    'user_login'  =>  sanitize_user($member->full_name),
			    'user_email'    =>  $member->email,
			    'display_name' => $member->full_name,
			    'user_registered' => $created_dt->format('Y-m-d H:i:m'),
			    'user_pass'   =>  NULL  // When creating an user, `user_pass` is expected.
			);

			echo '<pre>- User data:';
			print_r($userdata);
			echo "</pre><br>\n";

			$user_id = wp_insert_user( $userdata ) ;
		} // add user



		// 2. If the customer has a "stripe_customer_id" set in the export file, add it to the user account created in step 1.

		if ( !empty($member->stripe_customer_id) )
		{
			echo '-> stripe_customer_id' . "\n<br />";

			update_user_meta( $user_id, '_edd_recurring_id', $member->stripe_customer_id );

			echo '- _edd_recurring_id set' . "\n<br />";

			// For customers with a Stripe customer ID, you also need to set the user role to "edd_subscriber":

			$user = new WP_User( $user_id );
			$user->add_role( 'edd_subscriber' );

			echo '- role edd_subscriber added' . "\n<br />";
		}
		else
		{
			echo '- no stripe_customer_id' . "\n<br />";
		}// stripe_customer_id



		// 3. If the member record in the export file has purchased products included, you need to add a payment record for those products. This is a bit cumbersome at the moment but looks like this:



		if ( !empty($member->products) )
		{
			foreach ($member->products as $product)
			{
				echo '-> adding a product' . "\n<br />";

				$edd_product = get_page_by_title($product->name, OBJECT);

				if ( empty($edd_product) )
				{
					echo sprintf('! product %s not found for user %s', $product->name, $user_id) . "\n<br />";
					continue;
				}

				$created_at_dt = new DateTime($product->created_at);

				$purchase_data     = array(
					'price'        => $product->price_cents*100,
					'tax'          => 0,
					'post_date'    => $created_at_dt->format('Y-m-d H:i:s'),
					'purchase_key' => strtolower( md5( uniqid() ) ), // random key
					'user_email'   => $member->email,
					'user_info'    => array(
						'id' 			=> $user_id,
						'email' 		=> $member->email,
						'first_name'	=> $member->full_name,
						'last_name'		=> $member->full_name,
						'discount'		=> 'none' // discount code if any. Should be none
					),
					'currency'     => edd_get_currency(),
					'downloads'    => array(
						array(
							'id' => $edd_product->ID, // in EDD
							'options' => array()
						)
					),
					'cart_details' => array(
						array(
								'name'        => $product->name,
								'id'          => $edd_product->ID, // in EDD
								'item_number' => array(
									'id' => $product_id, // in EDD
									'options' => array()
								),
								'price'       => $product->price_cents*100,
								'subtotal'    => $product->price_cents*100,
								'quantity'    => 1,
								'tax'         => 0,
						)
					),
					'status'       => 'pending'
				);

				$payment_id = edd_insert_payment( $purchase_data );

				echo sprintf('- purchase %s added', $payment_id) . "\n<br />";

				// 	// Now set status as appropriate. Could be "complete", "refunded", "revoked", "pending", or "abandoned"

				edd_update_payment_status( $payment_id, 'complete' ) ;

				echo sprintf('- edd_update_payment_status set for purchase %s', $payment_id) . "\n<br />";

			} // payments
		}
		else
		{
			echo '- no products' . "\n<br />";
		} // payments




		// 4. If the member has a subscription associated with their membership, the following additional details need to be set:
		if ( !empty($member->subscriptions) )
		{
			foreach ($member->subscriptions as $subscription)
			{
				echo '-> adding a subscription' . "\n<br />";

 				if ( !empty($payment_id) )
				{
					// // Store the ID of the payment created above. This ensures that future payments can be tracked
					update_user_meta( $user_id, '_edd_recurring_user_parent_payment_id', $payment_id );

					echo sprintf('- _edd_recurring_user_parent_payment_id set for user %s', $user_id) . "\n<br />";
				}
				else
				{
					echo sprintf('! _edd_recurring_user_parent_payment_id NOT set for user %s. No payment specified.', $user_id) . "\n<br />";
				}

				$subscription_expires_at_dt = new DateTime($subscription->expires_at);

				// // Membership status. active, expired, or cancelled
				if ( $subscription->{'active?'} == 1 )
				{
					update_user_meta( $user_id, '_edd_recurring_status', 'active' );
					$recurring_status = 'active';
				}
				elseif ( $subscription_expires_at_dt > $now_dt )
				{
					// if not active and expires in the future, assume they cancelled
					update_user_meta( $user_id, '_edd_recurring_status', 'cancelled' );
					$recurring_status = 'cancelled';
				}
				else
				{
					update_user_meta( $user_id, '_edd_recurring_status', 'expired' );
					$recurring_status = 'expired';
				}

				echo sprintf('- _edd_recurring_status set to %s for user %s', $recurring_status, $user_id) . "\n<br />";

				// // member's expiration date. This should be set for all members that have a subscription, even if it does not autorenew or has been cancelled
				if ( !empty($subscription->expires_at) )
				{
					update_user_meta( $user_id, '_edd_recurring_exp', $subscription_expires_at_dt->format('U') );

					echo sprintf('- _edd_recurring_exp set for user %s', $user_id) . "\n<br />";
				}
			} // member subscriptions
		}
		else
		{
			echo '- no subscriptions' . "\n<br />";
		} // member subscriptions



		echo '- end of member' . "\n<br />";



		$i++;
		break;
	} // $member_data

exit('done');

} // memberful_import






if ( isset($_GET['mmt-memberful-report']) )
{
	add_action('wp', 'memberful_report');
}


function memberful_report ()
{
	if ( !is_user_logged_in() ) return;
	$now_dt = new DateTime();

	$current_dir = dirname(__FILE__);

	if ( !is_file($current_dir . '/memberful.json') ) exit('file not found. make sure you put the memberful export in the plugin directory and name it memberful.json');



	// get memberful export
	$json = file_get_contents($current_dir . '/memberful.json');

	// convert to an array
	$member_data = json_decode($json);


	$member_recordset = array();


	$i = 0;
	foreach ($member_data as $member)
	{

		$member_record = (object) array();
		// 1. Loop through each customer in the export file and detect if a WordPress user account needs to be created.

		// The get_user_by( 'email', $email ) function can be used for this.


		$member_record->id = '';
		$member_record->is_user = FALSE;
		$member_record->email = $member->email;
		$member_record->login = sanitize_user($member->full_name);
		$member_record->display_name = $member->full_name;
		$member_record->registered = str_replace('T', ' ', $member->created_at);
		$member_record->stripe = $member->stripe_customer_id;
		$member_record->products = array();
		$member_record->subscriptions = array();
		$member_record->status = '';



		$user_test = get_user_by( 'email', $member->email);



		if ( !empty($user_test) )
		{
			$member_record->is_user = TRUE;
			$member_record->id = $user_test->ID;
		}



		// 3. If the member record in the export file has purchased products included, you need to add a payment record for those products. This is a bit cumbersome at the moment but looks like this:


		if ( !empty($member->products) )
		{
			foreach ($member->products as $product)
			{
				$member_record->products[] = $product->name;
			}
		}

		$member_record->products = implode(', ', $member_record->products);



		// 4. If the member has a subscription associated with their membership, the following additional details need to be set:

		if ( !empty($member->subscriptions) )
		{
			foreach ($member->subscriptions as $subscription)
			{
				$subscription_expires_at_dt = new DateTime($subscription->expires_at);

				// // Membership status. active, expired, or cancelled
				if ( $subscription->{'active?'} == 1 )
				{
					$member_record->status = 'active';
				}
				elseif ( $subscription_expires_at_dt > $now_dt )
				{
					$member_record->status = 'cancelled';
				}
				else
				{
					$member_record->status = 'expired';
				}

				$member_record->subscriptions[] = $subscription->plan->name;

			} // member subscriptions
		}

		$member_record->subscriptions = implode(', ', $member_record->subscriptions);



		$member_recordset[] = $member_record;



		$i++;
		// break;
	} // $member_data






?>
<html>
<head>
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css">
<link rel="stylesheet" href="//cdn.datatables.net/1.10.8/css/jquery.dataTables.min.css">

<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js"></script>
<!-- <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js"></script> -->
<script type="text/javascript" src="//cdn.datatables.net/1.10.8/js/jquery.dataTables.min.js"></script> 

<style>
table {font-size: 80%;}
</style>

<script>
$(document).ready(function() {
    $('table').DataTable();
} );
</script>

</head>
<body>
<div class="container">
<table class="table table-bordered table-condensed table-striped">
<thead>
	<tr>
<?php foreach ($member_recordset[0] as $key => $field) : ?>
		<th>
			<?php echo $key ?>
		</th>
<?php endforeach ?>
	</tr>
</thead>
<tbody>
<?php foreach ($member_recordset as $record) : ?>
	<tr>
<?php foreach ($record as $field) : ?>
		<td>
			<?php echo $field ?>
		</td>
<?php endforeach ?>
	</tr>
<?php endforeach ?>
</tbody>
</table>
</div><!-- container -->
</body>
</html>
<?



exit;

} // memberful_import



