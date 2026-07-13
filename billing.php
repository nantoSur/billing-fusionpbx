<?php

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	if (!permission_exists('billing_view')) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	$document['title'] = $text['title-billing'];
	require_once "resources/header.php";

	//summary queries
	$domain_where = "where (c.domain_uuid = :domain_uuid) ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];

	$sql = "select count(*) from v_xml_cdr c " . $domain_where;
	$total_calls = $database->select($sql, $parameters, 'column') ?? 0;

	$sql_out = "select count(*) from v_xml_cdr c " . $domain_where . " and c.direction = 'outbound' and c.billsec > 0";
	$outbound_calls = $database->select($sql_out, $parameters, 'column') ?? 0;

	$sql_unrated = "select count(*) from v_xml_cdr c " . $domain_where . " and c.direction = 'outbound' and c.billsec > 0
		and not exists (select 1 from v_cdr_rated r where r.xml_cdr_uuid = c.xml_cdr_uuid)";
	$unrated_cdrs = $database->select($sql_unrated, $parameters, 'column') ?? 0;

	$sql_cost = "select coalesce(sum(r.total_cost), 0) from v_cdr_rated r
		inner join v_xml_cdr c on c.xml_cdr_uuid = r.xml_cdr_uuid
		" . $domain_where . "
		and date_trunc('month', c.start_stamp) = date_trunc('month', now())";
	$monthly_cost = $database->select($sql_cost, $parameters, 'column') ?? 0;

	$sql_unrated_outbound = "select xml_cdr_uuid, destination_number from v_xml_cdr c
		" . $domain_where . " and c.direction = 'outbound' and c.billsec > 0
		and not exists (select 1 from v_cdr_rated r where r.xml_cdr_uuid = c.xml_cdr_uuid)
		limit 1";
	$has_unrated = $database->select($sql_unrated_outbound, $parameters, 'row');

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-billing']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['label-providers'],'icon'=>'users','link'=>'billing_providers.php']);
	echo button::create(['type'=>'button','label'=>$text['label-rates'],'icon'=>'dollar-sign','link'=>'billing_rates.php']);
	echo button::create(['type'=>'button','label'=>$text['label-call_types'],'icon'=>'phone','link'=>'billing_call_types.php']);
	echo button::create(['type'=>'button','label'=>$text['title-billing_report'],'icon'=>'file-text','link'=>'billing_report.php']);
	echo button::create(['type'=>'button','label'=>$text['title-billing_invoice'],'icon'=>'file-invoice','link'=>'billing_invoice.php']);
	if ($has_unrated) {
		echo button::create(['type'=>'button','label'=>$text['button-rate_cdrs'],'icon'=>'play','link'=>'billing_report.php']);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<div class='card' style='display: flex; flex-wrap: wrap; gap: 20px; padding: 20px;'>\n";

	$cards = [
		['label' => $text['label-total_calls'], 'value' => number_format($total_calls), 'color' => '#2196F3'],
		['label' => $text['label-outbound_calls'], 'value' => number_format($outbound_calls), 'color' => '#4CAF50'],
		['label' => $text['label-unrated_cdrs'], 'value' => number_format($unrated_cdrs), 'color' => $unrated_cdrs > 0 ? '#FF9800' : '#999'],
		['label' => $text['label-monthly_cost'], 'value' => 'Rp ' . number_format($monthly_cost, 0, ',', '.'), 'color' => '#f44336'],
	];

	foreach ($cards as $card) {
		echo "	<div style='flex: 1; min-width: 200px; background: ".$card['color']."; color: white; border-radius: 8px; padding: 20px; text-align: center;'>\n";
		echo "		<div style='font-size: 14px; opacity: 0.9; margin-bottom: 8px;'>".escape($card['label'])."</div>\n";
		echo "		<div style='font-size: 32px; font-weight: bold;'>".$card['value']."</div>\n";
		echo "	</div>\n";
	}

	echo "</div>\n";

	require_once "resources/footer.php";

?>
