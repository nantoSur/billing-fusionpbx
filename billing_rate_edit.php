<?php

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	if (!(permission_exists('billing_rates_add') || permission_exists('billing_rates_edit'))) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	$order_by = preg_replace('#[^a-zA-Z0-9_\-]#', '', ($_REQUEST["order_by"] ?? ''));
	$order = $_REQUEST["order"] ?? 'asc';

	if (!empty($_REQUEST["id"])) {
		$action = "update";
		if (is_uuid($_REQUEST["id"])) {
			$provider_rate_uuid = $_REQUEST["id"];
		}
	} else {
		$action = "add";
		$provider_rate_uuid = uuid();
	}

	if (!empty($_POST)) {
		$provider_uuid = $_POST["provider_uuid"] ?? '';
		$call_type_uuid = $_POST["call_type_uuid"] ?? '';
		$rate_prefix = $_POST["rate_prefix"] ?? '';
		$rate_name = $_POST["rate_name"] ?? '';
		$rate_cost = $_POST["rate_cost"] ?? 0;
		$rate_sale_cost = $_POST["rate_sale_cost"] ?? 0;
		$rate_setup_fee = $_POST["rate_setup_fee"] ?? 0;
		$rate_increment = $_POST["rate_increment"] ?? 60;
		$rate_currency = $_POST["rate_currency"] ?? 'IDR';
		$rate_enabled = $_POST["rate_enabled"] ?? 'true';
	}

	if (!empty($_POST) && empty($_POST["persistformvar"])) {
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add($text['message-invalid_token'],'negative');
			header('Location: billing_rates.php');
			exit;
		}

		$msg = '';
		if (empty($provider_uuid) || !is_uuid($provider_uuid)) {
			$msg .= $text['message-required']." ".$text['label-provider']."<br>\n";
		}
		if (empty($rate_prefix)) {
			$msg .= $text['message-required']." ".$text['label-rate_prefix']."<br>\n";
		}
		if (!is_numeric($rate_cost)) {
			$msg .= $text['message-required']." ".$text['label-rate_cost']."<br>\n";
		}

		if (!empty($msg) && empty($_POST["persistformvar"])) {
			require_once "resources/header.php";
			require_once "resources/persist_form_var.php";
			echo "<div align='center'>\n";
			echo "<table><tr><td>\n";
			echo $msg."<br />";
			echo "</td></tr></table>\n";
			persistformvar($_POST);
			echo "</div>\n";
			require_once "resources/footer.php";
			return;
		}

		if (empty($_POST["persistformvar"]) || $_POST["persistformvar"] != "true") {
			$x = 0;
			$array['provider_rates'][$x]["provider_rate_uuid"] = $provider_rate_uuid;
			$array['provider_rates'][$x]["domain_uuid"] = $_SESSION['domain_uuid'];
			$array['provider_rates'][$x]["provider_uuid"] = $provider_uuid;
			$array['provider_rates'][$x]["call_type_uuid"] = $call_type_uuid;
			$array['provider_rates'][$x]["rate_prefix"] = $rate_prefix;
			$array['provider_rates'][$x]["rate_name"] = $rate_name;
			$array['provider_rates'][$x]["rate_cost"] = $rate_cost;
			$array['provider_rates'][$x]["rate_sale_cost"] = $rate_sale_cost;
			$array['provider_rates'][$x]["rate_setup_fee"] = $rate_setup_fee;
			$array['provider_rates'][$x]["rate_increment"] = $rate_increment;
			$array['provider_rates'][$x]["rate_currency"] = $rate_currency;
			$array['provider_rates'][$x]["rate_enabled"] = $rate_enabled;

			if (is_uuid($provider_rate_uuid)) {
				$database->uuid($provider_rate_uuid);
			}
			$database->save($array);

			if ($action == "add") {
				message::add($text['message-rate_added']);
			} else {
				message::add($text['message-rate_updated']);
			}
			header('Location: billing_rates.php'.(!empty($order_by) ? '?order_by='.$order_by.'&order='.$order : ''));
			exit;
		}
	}

	if (!empty($_GET["id"]) && is_uuid($_GET["id"]) && empty($_POST["persistformvar"])) {
		$sql = "select * from v_provider_rates where provider_rate_uuid = :provider_rate_uuid and domain_uuid = :domain_uuid ";
		$parameters['provider_rate_uuid'] = $_GET["id"];
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$row = $database->select($sql, $parameters, 'row');
		if (!empty($row)) {
			$provider_uuid = $row["provider_uuid"];
			$call_type_uuid = $row["call_type_uuid"] ?? '';
			$rate_prefix = $row["rate_prefix"];
			$rate_name = $row["rate_name"];
			$rate_cost = $row["rate_cost"];
			$rate_sale_cost = $row["rate_sale_cost"] ?? 0;
			$rate_setup_fee = $row["rate_setup_fee"];
			$rate_increment = $row["rate_increment"];
			$rate_currency = $row["rate_currency"];
			$rate_enabled = $row["rate_enabled"];
		}
		unset($sql, $parameters, $row);
	}

	$provider_uuid = $provider_uuid ?? '';
	$call_type_uuid = $call_type_uuid ?? '';
	$rate_prefix = $rate_prefix ?? '';
	$rate_name = $rate_name ?? '';
	$rate_cost = $rate_cost ?? 0;
	$rate_sale_cost = $rate_sale_cost ?? 0;
	$rate_setup_fee = $rate_setup_fee ?? 0;
	$rate_increment = $rate_increment ?? 60;
	$rate_currency = $rate_currency ?? 'IDR';
	$rate_enabled = $rate_enabled ?? true;

	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

	$document['title'] = $text['title-billing_rate_edit'];
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-billing_rate_edit']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'billing_rates.php'.(!empty($order_by) ? '?order_by='.$order_by.'&order='.$order : '')]);
	echo button::create(['type'=>'button','label'=>$text['button-save_rate'],'icon'=>'check','id'=>'btn_save','style'=>'margin-left: 15px;','onclick'=>'document.frm.submit();']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<form name='frm' method='post'>\n";
	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-provider']."\n";
	echo "</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='provider_uuid' required='required'>\n";
	echo "		<option value=''>".$text['label-choose']."</option>\n";
	$sql_providers = "select provider_uuid, provider_name from v_providers where provider_enabled = true and domain_uuid = :domain_uuid order by provider_name asc";
	$providers = $database->select($sql_providers, ['domain_uuid' => $_SESSION['domain_uuid']], 'all');
	if (!empty($providers)) {
		foreach ($providers as $p) {
			$sel = ($p['provider_uuid'] == $provider_uuid) ? "selected='selected'" : '';
			echo "		<option value='".escape($p['provider_uuid'])."' $sel>".escape($p['provider_name'])."</option>\n";
		}
	}
	echo "	</select>\n";
	echo "<br />\n";
	if (empty($providers)) {
		echo "<span style='color: #cc0000;'>".$text['label-no_providers']." <a href='billing_provider_edit.php'>".$text['label-add_provider']."</a></span>\n";
	}
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-call_type']."\n";
	echo "</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='call_type_uuid'>\n";
	echo "		<option value=''>".$text['label-choose']."</option>\n";
	$sql_ct = "select call_type_uuid, call_type_name from v_call_types where call_type_enabled = true and domain_uuid = :domain_uuid order by call_type_name asc";
	$call_types = $database->select($sql_ct, ['domain_uuid' => $_SESSION['domain_uuid']], 'all');
	if (!empty($call_types)) {
		foreach ($call_types as $ct) {
			$sel = ($ct['call_type_uuid'] == $call_type_uuid) ? "selected='selected'" : '';
			echo "		<option value='".escape($ct['call_type_uuid'])."' $sel>".escape($ct['call_type_name'])."</option>\n";
		}
	}
	echo "	</select>\n";
	echo "<br />\n";
	echo "Optional. Select call type for this rate.\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-rate_prefix']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <input class='formfld' type='text' name='rate_prefix' maxlength='20' value=\"".escape($rate_prefix)."\" required='required'>\n";
	echo "<br />\n";
	echo "e.g. 62812, 336, 1415\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-rate_name']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <input class='formfld' type='text' name='rate_name' maxlength='255' value=\"".escape($rate_name)."\">\n";
	echo "<br />\n";
	echo "e.g. Indonesia Mobile, France Mobile\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-rate_cost']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <input class='formfld' type='number' name='rate_cost' value='".escape($rate_cost)."' min='0' step='0.01' required='required'>\n";
	echo "<br />\n";
	echo "Cost in IDR per minute (e.g. 150 for Rp 150/menit)\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-sale_price']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <input class='formfld' type='number' name='rate_sale_cost' value='".escape($rate_sale_cost)."' min='0' step='0.01'>\n";
	echo "<br />\n";
	echo "Sale price in IDR per minute. Profit = (sale_price - purchase_cost) * billable_minutes\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-rate_setup_fee']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <input class='formfld' type='number' name='rate_setup_fee' value='".escape($rate_setup_fee)."' min='0' step='1'>\n";
	echo "<br />\n";
	echo "One-time connection fee in IDR (e.g. 500 for Rp 500 per sambungan)\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-rate_increment']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='number' name='rate_increment' value='".escape($rate_increment)."' min='1' step='1' list='inc_options'>\n";
	echo "	<datalist id='inc_options'>\n";
	$incs = [1, 6, 10, 15, 30, 60];
	foreach ($incs as $inc) {
		echo "		<option value='$inc'>$inc second</option>\n";
	}
	echo "	</datalist>\n";
	echo "<br />\n";
	echo "Billing rounding increment. 60 = per menit, 6 = per 6 detik. Pilih dari daftar atau ketik manual.\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-rate_currency']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <input class='formfld' type='text' name='rate_currency' maxlength='10' value=\"".escape($rate_currency)."\">\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-rate_enabled']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='rate_enabled'>\n";
	$sel_enabled = ($rate_enabled === true || $rate_enabled === 't' || $rate_enabled === 'true') ? 'true' : 'false';
	echo "		<option value='true' ".($sel_enabled == 'true' ? "selected='selected'" : null).">".$text['option-true']."</option>\n";
	echo "		<option value='false' ".($sel_enabled == 'false' ? "selected='selected'" : null).">".$text['option-false']."</option>\n";
	echo "	</select>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";

	if ($action == "update") {
		echo "<input type='hidden' name='id' value='".escape($provider_rate_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

	require_once "resources/footer.php";

?>
