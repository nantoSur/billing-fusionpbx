<?php

/**
 * CLI cron script for automated CDR rating.
 * 
 * Usage:
 *   php /var/www/fusionpbx/app/billing/billing_cron.php [--start YYYY-MM-DD] [--end YYYY-MM-DD] [--domain UUID]
 * 
 * Default: rates unrated outbound CDRs from the start of the current month to today.
 */

	$_SERVER["DOCUMENT_ROOT"] = dirname(__DIR__, 2);
	require_once $_SERVER["DOCUMENT_ROOT"] . "/resources/require.php";
	require_once $_SERVER["DOCUMENT_ROOT"] . "/resources/paging.php";

	$domain_uuid = '';
	$start_date = date('Y-m-01');
	$end_date = date('Y-m-d');
	$end_date_plus_1 = date('Y-m-d', strtotime($end_date . ' +1 day'));

	foreach ($argv as $i => $arg) {
		if (strpos($arg, '--start=') === 0) {
			$start_date = substr($arg, 8);
		} elseif (strpos($arg, '--end=') === 0) {
			$end_date = substr($arg, 6);
			$end_date_plus_1 = date('Y-m-d', strtotime($end_date . ' +1 day'));
		} elseif (strpos($arg, '--domain=') === 0) {
			$domain_uuid = substr($arg, 9);
		}
	}

	echo "[" . date('Y-m-d H:i:s') . "] Rating CDRs from $start_date to $end_date\n";

	if (!empty($domain_uuid)) {
		echo "[" . date('Y-m-d H:i:s') . "] Domain: $domain_uuid\n";
	}

	$sql = "select c.xml_cdr_uuid, c.domain_uuid, c.destination_number, c.billsec, c.last_arg, c.domain_name
		from v_xml_cdr c
		where c.direction = 'outbound'
		and c.billsec > 0
		and c.hangup_cause not in ('ORIGINATOR_CANCEL', 'CALL_REJECTED', 'NO_ANSWER', 'UNALLOCATED_NUMBER')
		and c.start_stamp >= :start_date
		and c.start_stamp < :end_date
		and not exists (select 1 from v_cdr_rated r where r.xml_cdr_uuid = c.xml_cdr_uuid)";

	$parameters = [
		'start_date' => $start_date,
		'end_date' => $end_date_plus_1,
	];

	if (!empty($domain_uuid) && is_uuid($domain_uuid)) {
		$sql .= " and c.domain_uuid = :domain_uuid";
		$parameters['domain_uuid'] = $domain_uuid;
	}

	$sql .= " order by c.start_epoch asc";

	$cdrs = $database->select($sql, $parameters, 'all');

	if (empty($cdrs)) {
		echo "[" . date('Y-m-d H:i:s') . "] No unrated CDRs found.\n";
		exit(0);
	}

	echo "[" . date('Y-m-d H:i:s') . "] Found " . count($cdrs) . " unrated CDRs\n";

	$rated_count = 0;

	foreach ($cdrs as $cdr) {
		$destination = $cdr['destination_number'] ?? '';
		$last_arg = $cdr['last_arg'] ?? '';
		$domain_uuid = $cdr['domain_uuid'] ?? '';

		if (empty($destination)) {
			continue;
		}

		$gateway_name = '';
		if (preg_match('/sofia\/gateway\/([^\/]+)/i', $last_arg, $m)) {
			$gateway_name = $m[1];
		}

		$provider_uuid = null;
		if (!empty($gateway_name)) {
			$sql_gw = "select provider_uuid from v_gateways where (gateway = :gateway_name or gateway_uuid = :gateway_name2) and provider_uuid is not null limit 1";
			$provider_uuid = $database->select($sql_gw, ['gateway_name' => $gateway_name, 'gateway_name2' => $gateway_name], 'column');
		}

		$rate_sql = "
			select r.provider_rate_uuid, r.rate_prefix, r.rate_cost, r.rate_sale_cost, r.rate_setup_fee, r.rate_increment, r.rate_currency, r.provider_uuid
			from v_provider_rates r
			where r.rate_enabled = true
		";
		$rate_params = [];

		if ($provider_uuid) {
			$rate_sql .= " and r.provider_uuid = :provider_uuid";
			$rate_params['provider_uuid'] = $provider_uuid;
		}

		$rate_sql .= " and :destination like r.rate_prefix || '%'";
		$rate_params['destination'] = $destination;
		$rate_sql .= " order by length(r.rate_prefix) desc limit 1";

		$rate = $database->select($rate_sql, $rate_params, 'row');

		if (empty($rate)) {
			$database->execute(
				"insert into v_cdr_rated (cdr_rated_uuid, domain_uuid, xml_cdr_uuid, total_cost, invoiced, insert_date) " .
				"values (:cdr_rated_uuid, :domain_uuid, :xml_cdr_uuid, :total_cost, :invoiced, now())",
				[
					'cdr_rated_uuid' => uuid(),
					'domain_uuid' => $domain_uuid,
					'xml_cdr_uuid' => $cdr['xml_cdr_uuid'],
					'total_cost' => 0,
					'invoiced' => 'false',
				]
			);
			continue;
		}

		$increment = (int)($rate['rate_increment'] ?? 60);
		if ($increment < 1) $increment = 60;

		$billsec = (int)$cdr['billsec'];
		$billable_seconds = ceil($billsec / $increment) * $increment;

		$rate_cost_per_min = (float)($rate['rate_cost'] ?? 0);
		$rate_sale_cost_per_min = (float)($rate['rate_sale_cost'] ?? 0);
		$setup_fee = (float)($rate['rate_setup_fee'] ?? 0);

		$total_cost = ($rate_cost_per_min / 60) * $billable_seconds + $setup_fee;
		$total_cost = round($total_cost, 2);

		$sale_cost = $rate_sale_cost_per_min > 0 ? ($rate_sale_cost_per_min / 60) * $billable_seconds + $setup_fee : 0;
		$sale_cost = round($sale_cost, 2);
		$profit = $sale_cost > 0 ? round($sale_cost - $total_cost, 2) : 0;

		$database->execute(
			"insert into v_cdr_rated (cdr_rated_uuid, domain_uuid, xml_cdr_uuid, provider_uuid, provider_rate_uuid, " .
			"rate_prefix, rate_cost, rate_increment, billable_seconds, setup_fee, total_cost, sale_cost, profit, currency, invoiced, insert_date) " .
			"values (:cdr_rated_uuid, :domain_uuid, :xml_cdr_uuid, :provider_uuid, :provider_rate_uuid, " .
			":rate_prefix, :rate_cost, :rate_increment, :billable_seconds, :setup_fee, :total_cost, :sale_cost, :profit, :currency, :invoiced, now())",
			[
				'cdr_rated_uuid' => uuid(),
				'domain_uuid' => $domain_uuid,
				'xml_cdr_uuid' => $cdr['xml_cdr_uuid'],
				'provider_uuid' => $rate['provider_uuid'],
				'provider_rate_uuid' => $rate['provider_rate_uuid'],
				'rate_prefix' => $rate['rate_prefix'],
				'rate_cost' => $rate_cost_per_min,
				'rate_increment' => $increment,
				'billable_seconds' => $billable_seconds,
				'setup_fee' => $setup_fee,
				'total_cost' => $total_cost,
				'sale_cost' => $sale_cost,
				'profit' => $profit,
				'currency' => $rate['rate_currency'] ?? 'IDR',
				'invoiced' => 'false',
			]
		);

		$rated_count++;
	}

	echo "[" . date('Y-m-d H:i:s') . "] Rated $rated_count CDRs successfully.\n";

?>
