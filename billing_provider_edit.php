<?php

	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	if (!(permission_exists('provider_add') || permission_exists('provider_edit'))) {
		echo "access denied";
		exit;
	}

	$language = new text;
	$text = $language->get();

	$order_by = preg_replace('#[^a-zA-Z0-9_\-]#', '', ($_REQUEST["order_by"] ?? ''));
	$order = $_REQUEST["order"] ?? 'asc';

	if (!empty($_REQUEST["id"])) {
		$action = "update";
		$provider_uuid = $_REQUEST["id"];
	} else {
		$action = "add";
		$provider_uuid = uuid();
	}

	if (!empty($_POST)) {
		$provider_name = $_POST["provider_name"] ?? '';
		$provider_description = $_POST["provider_description"] ?? '';
		$provider_enabled = $_POST["provider_enabled"] ?? 'true';
	}

	if (!empty($_POST) && empty($_POST["persistformvar"])) {
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add($text['message-invalid_token'],'negative');
			header('Location: billing_providers.php');
			exit;
		}

		$msg = '';
		if (empty($provider_name)) {
			$msg .= $text['message-required']." ".$text['label-provider_name']."<br>\n";
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
			$array['providers'][$x]["provider_uuid"] = $provider_uuid;
			$array['providers'][$x]["domain_uuid"] = $_SESSION['domain_uuid'];
			$array['providers'][$x]["provider_name"] = $provider_name;
			$array['providers'][$x]["provider_description"] = $provider_description;
			$array['providers'][$x]["provider_enabled"] = $provider_enabled;

			if (is_uuid($provider_uuid)) {
				$database->uuid($provider_uuid);
			}
			$database->save($array);

			if ($action == "add") {
				message::add($text['message-provider_added']);
			} else {
				message::add($text['message-provider_updated']);
			}
			header('Location: billing_providers.php');
			exit;
		}
	}

	if (!empty($_GET["id"]) && is_uuid($_GET["id"]) && empty($_POST["persistformvar"])) {
		$sql = "select * from v_providers where provider_uuid = :provider_uuid and domain_uuid = :domain_uuid ";
		$parameters['provider_uuid'] = $_GET["id"];
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$row = $database->select($sql, $parameters, 'row');
		if (!empty($row)) {
			$provider_name = $row["provider_name"];
			$provider_description = $row["provider_description"];
			$provider_enabled = $row["provider_enabled"];
		}
		unset($sql, $parameters, $row);
	}

	$provider_name = $provider_name ?? '';
	$provider_description = $provider_description ?? '';
	$provider_enabled = $provider_enabled ?? true;

	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

	$document['title'] = $text['title-provider_edit'];
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-provider_edit']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'billing_providers.php']);
	echo button::create(['type'=>'button','label'=>$text['button-save'],'icon'=>'check','id'=>'btn_save','style'=>'margin-left: 15px;','onclick'=>'document.frm.submit();']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<form name='frm' method='post'>\n";
	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-provider_name']."\n";
	echo "</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "    <input class='formfld' type='text' name='provider_name' maxlength='255' value=\"".escape($provider_name)."\" required='required'>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-provider_description']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "    <input class='formfld' type='text' name='provider_description' maxlength='255' value=\"".escape($provider_description)."\">\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>\n";
	echo "    ".$text['label-provider_enabled']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='provider_enabled'>\n";
	$selected = ($provider_enabled === true || $provider_enabled === 't' || $provider_enabled === 'true') ? 'true' : 'false';
	echo "		<option value='true' ".($selected == 'true' ? "selected='selected'" : null).">".$text['option-true']."</option>\n";
	echo "		<option value='false' ".($selected == 'false' ? "selected='selected'" : null).">".$text['option-false']."</option>\n";
	echo "	</select>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";

	if ($action == "update") {
		echo "<input type='hidden' name='id' value='".escape($provider_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

	require_once "resources/footer.php";

?>
